<?php

namespace App\Tests\Cribrum;

use App\Cribrum\ClassificationResult;
use App\Cribrum\CribrumClassifier;
use App\Cribrum\EvaluationCoverage;
use App\Cribrum\ObservationInput;
use App\Cribrum\RulePackCompiler;
use PHPUnit\Framework\TestCase;

final class CandidateEvaluationTest extends TestCase
{
    private function input(string $body, array $extra = []): ObservationInput
    {
        return ObservationInput::fromArray(array_replace([
            'url' => 'https://fixture.test/', 'rawHtml' => $body, 'contentType' => 'text/html',
            'httpStatus' => 500, 'coverage' => ['status' => 'complete'],
        ], $extra));
    }

    private function classifier(string $mode = 'active'): CribrumClassifier
    {
        return new CribrumClassifier('/nonexistent', dirname(__DIR__, 2).'/resources/cribrum/candidates', $mode);
    }

    public function testRawOnlyNegativeResultHasActualCompletionProofAndRoundtrips(): void
    {
        $explain = [];
        $result = $this->classifier()->classify($this->input('<p>Ordinary page.</p>'), $explain);
        self::assertSame('complete', $result->coverage['status']);
        self::assertSame([], $result->diagnostics);
        self::assertSame([], EvaluationCoverage::validationErrors($result->coverage['evaluation'], $result->packs));
        self::assertCount(4, $result->coverage['evaluation']['runs']);
        foreach ($result->coverage['evaluation']['runs'] as $run) {
            self::assertSame('active', $run['mode']);
            self::assertSame('behavior-triage', $run['pack']);
            self::assertNotEmpty($run['outputs']);
            foreach ($run['familyOutcomes'] as $family) {
                self::assertSame('completed', $family['status']);
                self::assertNotEmpty($family['stages']);
            }
        }
        self::assertSame($result->toArray(), ClassificationResult::fromArray($result->toArray())->toArray());
    }

    public function testLimitDoesNotEraseIndependentQualifiedEvidenceOrBecomeEngineError(): void
    {
        $raw = str_repeat('<pre>Example: You have an error in your SQL syntax</pre>', 33)
            .'<p>Fatal error: broken in /var/www/private/index.php on line 42</p>';
        $result = $this->classifier()->classify($this->input($raw));
        self::assertSame('partial', $result->coverage['status']);
        self::assertSame([], $result->diagnostics);
        self::assertContains('internal_information_exposed', array_column($result->signals, 'code'));
        self::assertNotContains('sql_input_structure_indication', array_column($result->signals, 'code'));
        $families = [];
        foreach ($result->coverage['evaluation']['runs'] as $run) foreach ($run['familyOutcomes'] as $family) $families[$family['family']] = $family['status'];
        self::assertSame('limited', $families['sql']);
        self::assertSame('completed', $families['disclosure']);
    }

    public function testSubsetSelectionExplainsFoundButUnselectedEvidence(): void
    {
        $pack = (new RulePackCompiler())->compile(['schema' => 2, 'id' => 'sql-subset', 'version' => '1', 'rules' => [
            ['id' => 'structure', 'detector' => 'sql-triage-v1', 'outputs' => ['sql_input_structure_indication']],
        ]]);
        $classifier = new CribrumClassifier('/nonexistent', null, 'shadow', [$pack]);
        $explain = [];
        $result = $classifier->classify($this->input('<pre>You have an error in your SQL syntax</pre>'), $explain);
        $row = array_values(array_filter($explain, static fn ($row) => ($row['detector'] ?? null) === 'sql-triage-v1'))[0];
        self::assertGreaterThan(0, $row['evidenceCount']);
        self::assertSame(0, $row['selectedEvidenceCount']);
        self::assertSame('excluded', $row['selectedOutcome']);
        self::assertSame('completed', $row['evaluationStatus']);
        self::assertSame('partial', $result->coverage['status']);
        self::assertSame([], array_filter($result->signals, static fn ($signal) => ($signal['kind'] ?? null) === 'observation_evidence'));
    }

    public function testDifferentPackSelectionsRemainDistinctWithTheirOwnEvidence(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/cribrum/candidates/behavior-triage.json'), true, 512, JSON_THROW_ON_ERROR);
        $compiler = new RulePackCompiler();
        $first = $compiler->compile($manifest);
        $manifest['id'] = 'second-triage';
        $second = $compiler->compile($manifest);
        $result = (new CribrumClassifier('/nonexistent', null, 'active', [$first, $second]))
            ->classify($this->input('<pre>You have an error in your SQL syntax</pre>'));
        self::assertSame('complete', $result->coverage['status']);
        self::assertCount(8, $result->coverage['evaluation']['runs']);
        $diagnoses = array_values(array_filter($result->signals, static fn ($signal) => ($signal['code'] ?? null) === 'sql_error_observed'));
        self::assertCount(2, $diagnoses);
        self::assertNotSame($diagnoses[0]['evidenceId'], $diagnoses[1]['evidenceId']);
    }

    public function testInvalidRawCannotEraseIndependentDomOrNativeProof(): void
    {
        $result = $this->classifier()->classify($this->input("bad \xff", [
            'url' => 'https://fixture.test/?q=OPENBUG', 'domText' => 'OPENBUG',
            'expectedEvidence' => 'OPENBUG', 'dialogSeen' => true, 'dialogText' => 'OPENBUG',
        ]));
        self::assertSame('partial', $result->coverage['status']);
        self::assertContains('input_reflected', array_column($result->signals, 'code'));
        $xss = array_values(array_filter($result->suggestions, static fn ($s) => $s->claim === 'xss'));
        self::assertCount(1, $xss);
        self::assertSame('observed', $xss[0]->state);
        self::assertSame([], EvaluationCoverage::validationErrors($result->coverage['evaluation'], $result->packs));
    }

    public function testAbsentRawBindingIsUnknownButCapturedDomEvidenceRemains(): void
    {
        $result = $this->classifier()->classify($this->input('', [
            'rawHtml' => null, 'domText' => 'OPENBUG', 'payload' => 'OPENBUG',
        ]));
        self::assertNull($result->coverage['artifactSha256']);
        self::assertSame('unknown', $result->coverage['status']);
        self::assertContains('input_reflected', array_column($result->signals, 'code'));
        self::assertSame([], $result->diagnostics);
    }

    public function testInvalidFinalUrlReportsParserFailureWithoutErasingValidInputEvidence(): void
    {
        $result = $this->classifier()->classify($this->input('<p>OPENBUG</p>', [
            'url' => 'https://fixture.test/?q=OPENBUG', 'finalUrl' => 'https://fixture.test:99999/',
            'expectedEvidence' => 'OPENBUG', 'dialogSeen' => true, 'dialogText' => 'OPENBUG',
        ]));
        self::assertSame('partial', $result->coverage['status']);
        self::assertContains('input_reflected', array_column($result->signals, 'code'));
        self::assertSame([['code' => 'parser_error', 'stage' => 'final_url_query', 'representation' => null]], $result->coverage['evaluation']['engineIssues']);
        self::assertContains('Engine stage final_url_query failed (parser_error).', $result->diagnostics);
        $xss = array_values(array_filter($result->suggestions, static fn ($s) => $s->claim === 'xss'));
        self::assertSame('observed', $xss[0]->state);
    }

    public function testNativeQueryParserLimitsRemainVisibleWithoutChangingParsedEvidence(): void
    {
        $max = (int) ini_get('max_input_vars');
        $pairs = [];
        for ($i = 0; $i <= $max; ++$i) $pairs[] = 'p'.$i.'=value'.$i;
        $depth = (int) ini_get('max_input_nesting_level');
        foreach ([
            ['https://fixture.test/?'.implode('&', $pairs), 'input_variable_budget', $max],
            ['https://fixture.test/?root'.str_repeat('[child]', $depth + 2).'=value', 'input_nesting_budget', $depth],
        ] as [$finalUrl, $code, $limit]) {
            $previous = static fn (): bool => false;
            set_error_handler($previous);
            try {
                $result = $this->classifier()->classify($this->input('<p>OPENBUG</p>', [
                    'url' => 'https://fixture.test/?q=OPENBUG', 'finalUrl' => $finalUrl,
                ]));
                $restored = set_error_handler(static fn (): bool => false);
                restore_error_handler();
                self::assertSame($previous, $restored);
            } finally { restore_error_handler(); }
            self::assertSame('partial', $result->coverage['status']);
            self::assertSame([], $result->diagnostics);
            self::assertSame([['code' => $code, 'stage' => 'final_url_query', 'representation' => null,
                'status' => 'limited', 'counters' => ['limit' => $limit]]], $result->coverage['evaluation']['engineIssues']);
            self::assertContains('input_reflected', array_column($result->signals, 'code'));
        }
    }

    public function testNestingCoverageObservesOnlyNativeKeyPathsAndPreservesIndependentValues(): void
    {
        $depth = (int) ini_get('max_input_nesting_level');
        $deep = 'root'.str_repeat('[child]', $depth + 1);
        foreach ([
            [$deep.'=hidden', true],
            [urlencode(' '.$deep).'=hidden', true],
            ['root'.str_repeat('[child]', $depth).'=accepted', false],
            ['root'.str_repeat('[child]', $depth).'[unfinished=hidden', true],
            ['root[child]suffix'.str_repeat('[child]', $depth + 1).'=accepted', false],
            ['root['.str_repeat('[child', $depth + 1).']=accepted', false],
            ['value='.urlencode($deep), false],
            ['%00'.$deep.'=ignored', false],
            [urlencode('  '.substr($deep, 4)).'=ignored', false],
        ] as [$query, $limited]) {
            $result = $this->classifier()->classify($this->input('<p>OPENBUG</p>', [
                'url' => 'https://fixture.test/?q=OPENBUG', 'finalUrl' => 'https://fixture.test/?q=OPENBUG&'.$query,
            ]));
            self::assertSame($limited ? 'partial' : 'complete', $result->coverage['status'], $query);
            self::assertSame($limited ? ['input_nesting_budget'] : [], array_column($result->coverage['evaluation']['engineIssues'], 'code'), $query);
            self::assertContains('input_reflected', array_column($result->signals, 'code'), $query);
            $issues = [];
            $values = (new \ReflectionMethod(CribrumClassifier::class, 'queryValues'))->invokeArgs($this->classifier(), [
                'https://fixture.test/?q=OPENBUG&'.$query, &$issues, 'final_url_query',
            ]);
            self::assertSame(str_starts_with($query, 'value=') ? ['OPENBUG', $deep] : ['OPENBUG'], $values, $query);
        }
    }

    public function testUnprocessedTailDoesNotInventNestingLimit(): void
    {
        $max = (int) ini_get('max_input_vars');
        if ($max < 1) self::markTestSkipped('Requires a finite positive native variable budget.');
        $query = 'q=OPENBUG&'.str_repeat('p=value&', $max - 1).'root'.str_repeat('[child]', (int) ini_get('max_input_nesting_level') + 1).'=hidden';
        $result = $this->classifier()->classify($this->input('<p>OPENBUG</p>', [
            'url' => 'https://fixture.test/?q=OPENBUG', 'finalUrl' => 'https://fixture.test/?'.$query,
        ]));
        self::assertSame(['input_variable_budget'], array_column($result->coverage['evaluation']['engineIssues'], 'code'));
        self::assertContains('input_reflected', array_column($result->signals, 'code'));
    }
}
