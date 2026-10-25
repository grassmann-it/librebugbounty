<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\CheckedRegex;
use App\Cribrum\Detector\DetectorRun;
use App\Cribrum\Detector\RegexFailure;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class DetectorRunTest extends TestCase
{
    public function testLaterRegexFailureRetainsQualifiedEvidenceAndContinuesIndependentFamily(): void
    {
        $input = $this->input();
        $run = new DetectorRun($input, 'test-v1', ['sql', 'waf']);
        $signal = $this->signal($input);
        $run->stage('sql', 'diagnostic', 'raw', function () use ($run, $signal): void {
            $run->count('sql', 'candidatesConsidered');
            $run->add($signal);
        });
        $run->stage('sql', 'structure', 'raw', function () use ($run): void {
            $run->candidate('raw', 0, 5);
            CheckedRegex::match('~[~', 'PRIVATE-SOURCE-CANARY');
        });
        $run->stage('waf', 'scan', 'raw', static fn () => CheckedRegex::match('~not-present~', 'hello'));
        $evaluation = $run->finish();
        self::assertSame([$signal], $evaluation->signals);
        self::assertSame('failed', $evaluation->status);
        self::assertSame('failed', $evaluation->familyOutcomes[0]['status']);
        self::assertSame('completed', $evaluation->familyOutcomes[1]['status']);
        self::assertSame(1, $evaluation->familyOutcomes[0]['counters']['signalsQualified']);
        $issue = $evaluation->familyOutcomes[0]['stages'][1]['issues'][0];
        self::assertSame('regex_error', $issue['code']);
        self::assertSame('structure', $issue['stage']);
        self::assertSame(['representation' => 'raw', 'representationSha256' => hash('sha256', 'hello'), 'start' => 0, 'length' => 5], $issue['candidate']);
        self::assertStringNotContainsString('PRIVATE-SOURCE-CANARY', json_encode([$evaluation->toArray(), $evaluation->diagnostics], JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('signals', $evaluation->toArray());
    }

    public function testAllIncompleteReasonsSurviveStatusPrecedence(): void
    {
        $run = new DetectorRun($this->input(), 'test-v1', ['sql']);
        $run->unavailable('sql', 'comparison', 'raw', 'invalid_representation');
        $run->limited('sql', 'signature_search', 'raw', 'match_budget', ['limit' => 32, 'remaining' => null]);
        $run->failed('sql', 'parser', 'raw', 'parser_error');
        $result = $run->finish();
        self::assertSame('failed', $result->status);
        self::assertCount(3, $result->familyOutcomes[0]['stages']);
        self::assertSame(['unavailable', 'limited', 'failed'], array_column($result->familyOutcomes[0]['stages'], 'status'));
        self::assertNull($result->familyOutcomes[0]['stages'][1]['issues'][0]['counters']['remaining']);
        self::assertCount(1, $result->diagnostics);
    }

    public function testInvalidEvidenceCannotBecomeQualifiedAndDoesNotRemoveEarlierSignal(): void
    {
        $input = $this->input();
        $run = new DetectorRun($input, 'test-v1', ['sql']);
        $signal = $this->signal($input);
        $run->stage('sql', 'first', 'raw', fn () => $run->add($signal));
        $bad = $signal;
        $bad['spans'][0]['excerpt'] = 'PRIVATE-SECRET';
        $unreached = false;
        $run->stage('sql', 'second', 'raw', function () use ($run, $bad, &$unreached): void {
            $run->add($bad);
            $unreached = true;
        });
        $result = $run->finish();
        self::assertFalse($unreached);
        self::assertSame([$signal], $result->signals);
        self::assertSame('invalid_evidence_candidate', $result->familyOutcomes[0]['stages'][1]['issues'][0]['code']);
        self::assertStringNotContainsString('PRIVATE-SECRET', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testCandidatesAreBoundToOriginalAndNeverLeakAcrossIndependentStages(): void
    {
        $run = new DetectorRun($this->input(), 'test-v1', ['sql']);
        $run->stage('sql', 'first', 'raw', function () use ($run): void {
            $run->candidate('raw', 0, 5);
            $run->limited('sql', 'first', 'raw', 'match_budget');
        });
        $run->stage('sql', 'second', 'raw', fn () => $run->failed('sql', 'second', 'raw', 'parser_error'));
        $run->stage('sql', 'third', 'raw', function () use ($run): void {
            $run->candidate('raw', 4, 3);
            $run->limited('sql', 'third', 'raw', 'context_budget');
        });
        $stages = $run->finish()->familyOutcomes[0]['stages'];
        self::assertArrayHasKey('candidate', $stages[0]['issues'][0]);
        self::assertArrayNotHasKey('candidate', $stages[1]['issues'][0]);
        self::assertArrayNotHasKey('candidate', $stages[2]['issues'][0]);
    }

    public function testSharedPrerequisiteFailureMarksEachDependentFamily(): void
    {
        $run = new DetectorRun($this->input(), 'test-v1', ['disclosure', 'waf', 'error_response']);
        $run->stage(['disclosure', 'waf'], 'comparison', 'raw', static fn () => throw new \RuntimeException('PRIVATE-SECRET'));
        $run->stage('error_response', 'status', null, static fn () => true);
        self::assertSame(['failed', 'failed', 'completed'], array_column($run->finish()->familyOutcomes, 'status'));
        self::assertStringNotContainsString('PRIVATE-SECRET', json_encode($run->finish()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testMissingStageCannotCertifyCompletion(): void
    {
        $result = (new DetectorRun($this->input(), 'test-v1', ['sql']))->finish();
        self::assertSame('unavailable', $result->status);
        self::assertSame('missing_evaluation_stage', $result->familyOutcomes[0]['stages'][0]['issues'][0]['code']);
    }

    public function testCheckedNoMatchIsSuccessfulAndUtf8ErrorIsExplicit(): void
    {
        self::assertSame(0, CheckedRegex::match('~absent~u', 'hello', $matches));
        self::assertSame([], $matches);
        self::assertSame(0, CheckedRegex::matchAll('~absent~u', 'hello', $matches));
        self::assertSame(['hello'], CheckedRegex::split('~absent~u', 'hello'));
        self::assertSame('hello', CheckedRegex::replace('~absent~u', '', 'hello'));
        self::assertSame('hello', CheckedRegex::replaceCallback('~absent~u', static fn () => '', 'hello'));
        try {
            CheckedRegex::match('~.~u', "\xff");
            self::fail('Malformed UTF-8 must raise an explicit checked failure.');
        } catch (RegexFailure $failure) {
            self::assertSame('match', $failure->operation);
            self::assertSame(PREG_BAD_UTF8_ERROR, $failure->pcreCode);
        }
    }

    public function testAllCheckedOperationsThrowWithoutExposingSubject(): void
    {
        foreach ([
            static fn () => CheckedRegex::matchAll('~[~', 'PRIVATE-SECRET'),
            static fn () => CheckedRegex::split('~[~', 'PRIVATE-SECRET'),
            static fn () => CheckedRegex::replace('~[~', '', 'PRIVATE-SECRET'),
            static fn () => CheckedRegex::replaceCallback('~[~', static fn () => '', 'PRIVATE-SECRET'),
        ] as $operation) {
            try {
                $operation();
                self::fail('Invalid pattern must not masquerade as no match.');
            } catch (RegexFailure $failure) {
                self::assertSame(PREG_INTERNAL_ERROR, $failure->pcreCode);
                self::assertStringNotContainsString('PRIVATE-SECRET', $failure->getMessage());
            }
        }
    }

    #[RunInSeparateProcess]
    public function testLowPcreLimitCannotPreventRecordingItsOwnFailure(): void
    {
        ini_set('pcre.backtrack_limit', '1');
        $run = new DetectorRun($this->input(), 'test-v1', ['sql']);
        $run->stage('sql', 'signature_search', 'raw', static fn () => CheckedRegex::match('~(a+)+$~', str_repeat('a', 200).'!'));
        $result = $run->finish();
        self::assertSame('failed', $result->status);
        self::assertSame('regex_error', $result->familyOutcomes[0]['stages'][0]['issues'][0]['code']);
        self::assertSame(PREG_BACKTRACK_LIMIT_ERROR, $result->familyOutcomes[0]['stages'][0]['issues'][0]['pcreCode']);
    }

    public function testArrayReplacementMustNotSilentlyOmitFailedSubjects(): void
    {
        foreach ([
            static fn () => CheckedRegex::replace('~.~u', '', ['bad' => "\xff", 'valid_after_bad' => 'hello']),
            static fn () => CheckedRegex::replaceCallback('~.~u', static fn () => '', ['bad' => "\xff", 'valid_after_bad' => 'hello']),
        ] as $operation) {
            try {
                $operation();
                self::fail('A failed array element must be visible before a later subject clears the error.');
            } catch (RegexFailure $failure) {
                self::assertSame(PREG_BAD_UTF8_ERROR, $failure->pcreCode);
            }
        }
        self::assertSame(['a' => 'hll', 'b' => 'wrld'], CheckedRegex::replace('~[eo]~', '', ['a' => 'hello', 'b' => 'world'], -1, $count));
        self::assertSame(3, $count);
        self::assertSame(['a' => 'hll', 'b' => 'wrld'], CheckedRegex::replaceCallback('~[eo]~', static fn () => '', ['a' => 'hello', 'b' => 'world'], -1, $count));
        self::assertSame(3, $count);
    }

    public function testNativeOutputArgumentsOverwritePreviouslyScalarVariables(): void
    {
        foreach ([17, 'previous scalar value', null] as $initial) {
            foreach (['~(ell)~', '~absent~'] as $pattern) {
                $native = $checked = $initial;
                self::assertSame(preg_match($pattern, 'hello', $native), CheckedRegex::match($pattern, 'hello', $checked));
                self::assertSame($native, $checked);
                $native = $checked = $initial;
                self::assertSame(preg_match_all($pattern, 'hello hello', $native), CheckedRegex::matchAll($pattern, 'hello hello', $checked));
                self::assertSame($native, $checked);
            }
            foreach (['hello', ['first' => 'hello', 'second' => 'world']] as $subject) {
                $native = $checked = $initial;
                self::assertSame(preg_replace('~[eo]~', '', $subject, -1, $native), CheckedRegex::replace('~[eo]~', '', $subject, -1, $checked));
                self::assertSame($native, $checked);
                $native = $checked = $initial;
                $callback = static fn () => '';
                self::assertSame(preg_replace_callback('~[eo]~', $callback, $subject, -1, $native), CheckedRegex::replaceCallback('~[eo]~', $callback, $subject, -1, $checked));
                self::assertSame($native, $checked);
            }
        }
    }

    private function input(): ObservationInput
    {
        return ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => 'hello']);
    }

    private function signal(ObservationInput $input): array
    {
        return EvidenceSignal::make('sql_error_observed', $input, [EvidenceSignal::span('hello', 0, 5)], [], [], 'test-v1');
    }
}
