<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\ReflectionTriageDetector;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ReflectionEvaluationTest extends TestCase
{
    private function input(?string $raw, string $url = 'https://fixture.test/?q=probeVALUE', ?string $dom = null, ?string $payload = null, ?string $marker = null): ObservationInput
    {
        return new ObservationInput($url, $raw, $dom, 'text/html', 200, null, $payload, $marker, false, null);
    }

    private function codes(object $result): array
    {
        return array_merge(...array_map(static fn (array $stage): array => array_column($stage['issues'], 'code'), $result->familyOutcomes[0]['stages']));
    }

    public function testRawOnlyOptionalInputsAndKnownEmptySourceSetAreComplete(): void
    {
        $detector = new ReflectionTriageDetector();
        foreach ([$this->input('probeVALUE'), $this->input('', 'https://fixture.test/'), $this->input('plain text', 'https://fixture.test/')] as $input) {
            $result = $detector->evaluate($input);
            self::assertSame('completed', $result->status);
            self::assertSame([], $result->diagnostics);
            self::assertSame($result->signals, $detector->detect($input));
        }
        self::assertSame('unavailable', $detector->evaluate($this->input(null))->status);
    }

    public function testInvalidRawPreservesIndependentlyBoundDomAndReverse(): void
    {
        $detector = new ReflectionTriageDetector();
        foreach ([$this->input("\xff", dom: '前🙂 probeVALUE'), $this->input('前🙂 probeVALUE', dom: "\xff")] as $input) {
            $result = $detector->evaluate($input);
            self::assertSame('unavailable', $result->status);
            self::assertContains('invalid_representation', $this->codes($result));
            self::assertCount(1, $result->signals);
            self::assertSame(3, $result->signals[0]['spans'][0]['start']);
            EvidenceSignal::validateAgainstInput($result->signals[0], $input);
        }
    }

    public function testInvalidInputSourceDoesNotEraseIndependentValidSources(): void
    {
        $detector = new ReflectionTriageDetector();
        foreach ([$this->input('probeVALUE', payload: "\xff"), $this->input('probeVALUE', 'https://fixture.test/?%FF=broken', marker: 'probeVALUE')] as $input) {
            $result = $detector->evaluate($input);
            self::assertSame('unavailable', $result->status);
            self::assertContains('invalid_representation', $this->codes($result));
            self::assertCount(1, $result->signals);
            EvidenceSignal::validateAgainstInput($result->signals[0], $input);
        }
    }

    public function testQueryPairLimitDistinguishesKnownExhaustionAndIgnoredRest(): void
    {
        $detector = new ReflectionTriageDetector();
        foreach ([31, 32, 33] as $count) {
            $pairs = array_map(static fn (int $n): string => 'p'.$n.'=value'.$n, range(1, $count));
            $result = $detector->evaluate($this->input('value1', 'https://fixture.test/?'.implode('&', $pairs)));
            self::assertSame($count > 32 ? 'limited' : 'completed', $result->status);
            self::assertCount(1, $result->signals);
            if ($count > 32) self::assertContains('query_pair_budget', $this->codes($result));
        }
    }

    public function testDistinctSourceAndValueBudgetsRetainOtherQualifiedEvidence(): void
    {
        $pairs = array_map(static fn (int $n): string => 'p'.$n.'=value'.$n, range(1, 32));
        $detector = new ReflectionTriageDetector();
        $result = $detector->evaluate($this->input('payloadVALUE markerVALUE', 'https://fixture.test/?'.implode('&', $pairs), payload: 'payloadVALUE', marker: 'markerVALUE'));
        self::assertSame('limited', $result->status);
        self::assertContains('input_budget', $this->codes($result));
        self::assertSame(['payloadVALUE', 'markerVALUE'], array_column($result->signals, 'phrase'));
        foreach ([1024, 1025] as $length) {
            $result = $detector->evaluate($this->input('probeVALUE', payload: str_repeat('ä', $length)));
            self::assertSame($length > 1024 ? 'limited' : 'completed', $result->status);
            self::assertCount(1, $result->signals);
            if ($length > 1024) self::assertContains('input_value_budget', $this->codes($result));
        }
    }

    public function testSignalCeilingKeepsRawFirstAndReportsUnknownRemainder(): void
    {
        $detector = new ReflectionTriageDetector();
        foreach ([23, 24, 25] as $count) {
            $input = $this->input(str_repeat('probeVALUE ', $count));
            $result = $detector->evaluate($input);
            self::assertCount(min(24, $count), $result->signals);
            self::assertSame($count >= 24 ? 'limited' : 'completed', $result->status);
            foreach ($result->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
            if ($count >= 24) {
                $issues = array_merge(...array_column($result->familyOutcomes[0]['stages'], 'issues'));
                $issue = array_values(array_filter($issues, static fn (array $issue): bool => $issue['code'] === 'match_budget'))[0];
                self::assertNull($issue['counters']['remaining']);
            }
        }
        $result = $detector->evaluate($this->input(str_repeat('probeVALUE ', 24), dom: 'probeVALUE'));
        self::assertSame(['raw'], array_values(array_unique(array_column($result->signals, 'representation'))));
    }

    public function testContextBudgetOnlyReportsUnresolvedContext(): void
    {
        $detector = new ReflectionTriageDetector();
        $result = $detector->evaluate($this->input(str_repeat('x', 8193).' probeVALUE'));
        self::assertSame('limited', $result->status);
        self::assertContains('context_budget', $this->codes($result));
        self::assertSame('unknown', $result->signals[0]['attributes']['context']);
        $result = $detector->evaluate($this->input(str_repeat('x', 8193).'<!-- probeVALUE -->'));
        self::assertSame('completed', $result->status);
        self::assertSame('html_comment', $result->signals[0]['attributes']['context']);
    }

    #[RunInSeparateProcess]
    public function testPcreErrorsPreserveQualifiedSignalsAndRejectFailedContextCandidates(): void
    {
        $input = $this->input('<input value="'.str_repeat('a', 500).' probeVALUE', dom: 'probeVALUE');
        $detector = new ReflectionTriageDetector();
        ini_set('pcre.backtrack_limit', '5');
        $result = $detector->evaluate($input);
        self::assertSame('failed', $result->status);
        self::assertContains('regex_error', $this->codes($result));
        self::assertSame(['raw', 'dom_text'], array_column($result->signals, 'representation'));
        foreach ($result->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
        ini_set('pcre.backtrack_limit', '2');
        $result = $detector->evaluate($input);
        self::assertSame('failed', $result->status);
        self::assertSame(['dom_text'], array_column($result->signals, 'representation'));
        $contextStage = array_values(array_filter($result->familyOutcomes[0]['stages'], static fn (array $stage): bool => $stage['stage'] === 'context' && $stage['representation'] === 'raw'))[0];
        self::assertSame('regex_error', $contextStage['issues'][0]['code']);
        self::assertSame(515, $contextStage['issues'][0]['candidate']['start']);
        self::assertSame(hash('sha256', $input->rawHtml), $contextStage['issues'][0]['candidate']['representationSha256']);
    }
}
