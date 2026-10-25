<?php

namespace App\Tests\Cribrum;

use App\Cribrum\ClassificationResult;
use App\Cribrum\EvaluationCoverage;
use App\Cribrum\Rule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvaluationCoverageTest extends TestCase
{
    public function testValidNestedDetailsRoundTripWithoutChangingUnknownExtensionFields(): void
    {
        $coverage = $this->coverage();
        $coverage['evaluation']['runs'][0]['familyOutcomes'][0]['stages'][0]['futureDetail'] = ['nested' => ['raw' => 1.0]];
        $result = $this->classification($coverage);
        self::assertSame([], $result->diagnostics);
        self::assertTrue(EvaluationCoverage::certifiesComplete($coverage, 'cribrum-1.1.0'));
        self::assertSame($coverage, ClassificationResult::fromArray($result->toArray())->coverage);
    }

    public static function malformedDetails(): iterable
    {
        yield 'missing' => ['missing'];
        yield 'future schema' => ['future'];
        yield 'wrong run pack binding' => ['pack'];
        yield 'missing run rule' => ['rule'];
        yield 'wrong detector family binding' => ['family'];
        yield 'empty stage proof' => ['stages'];
        yield 'status disagrees with stages' => ['status'];
        yield 'negative counters' => ['counters'];
        yield 'wrong issue binding' => ['issue'];
        yield 'malformed detector field' => ['detector'];
        yield 'missing selected outputs' => ['outputs'];
        yield 'duplicate run' => ['duplicate'];
        yield 'missing mandatory work' => ['mandatory'];
        yield 'invented stage' => ['invented'];
        yield 'null engine issues' => ['nullEngine'];
        yield 'null canonical counter' => ['nullCounter'];
        yield 'completed stage with error issue' => ['completedIssue'];
    }

    #[DataProvider('malformedDetails')]
    public function testInvalidNewDetailsCannotCertifyCompleteAndAreRetained(string $case): void
    {
        $coverage = $this->coverage();
        switch ($case) {
            case 'missing': unset($coverage['evaluation']); break;
            case 'future': $coverage['evaluation'] = ['schemaVersion' => 99, 'unknownDetails' => ['keep' => 'all']]; break;
            case 'pack': $coverage['evaluation']['runs'][0]['version'] = 'wrong'; break;
            case 'rule': unset($coverage['evaluation']['runs'][0]['rule']); break;
            case 'family': $coverage['evaluation']['runs'][0]['familyOutcomes'][0]['family'] = 'sql'; break;
            case 'stages': $coverage['evaluation']['runs'][0]['familyOutcomes'][0]['stages'] = []; break;
            case 'status': $coverage['evaluation']['runs'][0]['familyOutcomes'][0]['status'] = 'failed'; break;
            case 'counters': $coverage['evaluation']['runs'][0]['familyOutcomes'][0]['counters']['signalsQualified'] = -1; break;
            case 'issue': $coverage['evaluation']['runs'][0]['familyOutcomes'][0]['stages'][0]['issues'] = [['code' => 'regex_error']]; break;
            case 'detector': $coverage['evaluation']['runs'][0]['detector'] = ['broken']; break;
            case 'outputs': unset($coverage['evaluation']['runs'][0]['outputs']); break;
            case 'mandatory': array_pop($coverage['evaluation']['runs'][0]['familyOutcomes'][0]['stages']); break;
            case 'invented': $coverage['evaluation']['runs'][0]['familyOutcomes'][0]['stages'][0]['stage'] = 'anything'; break;
            case 'nullEngine': $coverage['evaluation']['engineIssues'] = null; break;
            case 'nullCounter': $coverage['evaluation']['runs'][0]['familyOutcomes'][0]['counters']['signalsQualified'] = null; break;
            case 'completedIssue': $coverage['evaluation']['runs'][0]['familyOutcomes'][0]['stages'][0]['issues'][] = ['code' => 'regex_error', 'detector' => 'reflection-triage-v1', 'family' => 'reflection', 'stage' => 'input_sources', 'representation' => null]; break;
            case 'duplicate': $coverage['evaluation']['runs'][] = $coverage['evaluation']['runs'][0]; break;
        }
        $result = $this->classification($coverage);
        self::assertSame('partial', $result->coverage['status']);
        self::assertNotEmpty($result->diagnostics);
        self::assertSame($coverage['evaluation'] ?? null, $result->toArray()['coverage']['evaluation'] ?? null);
        self::assertFalse(EvaluationCoverage::certifiesComplete($coverage, 'cribrum-1.1.0'));
    }

    public function testValidIssueIsPartialWithoutBeingAContractErrorOrAHiddenSecondRunSuccess(): void
    {
        $coverage = $this->coverage();
        $run = $coverage['evaluation']['runs'][0];
        $run['rule'] = 'second-reflection-rule';
        $family = &$run['familyOutcomes'][0];
        $family['status'] = $family['stages'][0]['status'] = 'limited';
        $family['stages'][0]['issues'][] = ['code' => 'match_budget', 'detector' => $run['detector'], 'family' => $family['family'], 'stage' => 'input_sources', 'representation' => null, 'counters' => ['remaining' => null]];
        $coverage['evaluation']['runs'][] = $run;
        self::assertSame([], EvaluationCoverage::validationErrors($coverage['evaluation'], $coverage['packs']));
        $result = $this->classification($coverage);
        self::assertSame('partial', $result->coverage['status']);
        self::assertSame([], $result->diagnostics);
        self::assertNull($result->coverage['evaluation']['runs'][4]['familyOutcomes'][0]['stages'][0]['issues'][0]['counters']['remaining']);
    }

    public static function candidateRepresentations(): iterable
    {
        yield 'request-value stage keeps raw diagnostic trace' => [null, 'raw', true];
        yield 'raw stage keeps raw diagnostic trace' => ['raw', 'raw', true];
        yield 'raw stage cannot bind a DOM candidate' => ['raw', 'dom_text', false];
        yield 'DOM stage cannot bind a raw candidate' => ['dom_text', 'raw', false];
        yield 'request-value stage still requires valid candidate representation' => [null, 'invented', false];
    }

    #[DataProvider('candidateRepresentations')]
    public function testPrivateCandidateReferencesRespectTheStageScope(?string $stageRepresentation, string $candidateRepresentation, bool $valid): void
    {
        $coverage = $this->coverage();
        $family = &$coverage['evaluation']['runs'][2]['familyOutcomes'][0];
        $family['status'] = 'failed';
        $candidate = ['representation' => $candidateRepresentation, 'representationSha256' => hash('sha256', 'raw diagnostic'), 'start' => 0, 'length' => 14];
        $family['stages'][] = ['stage' => 'mapping_input', 'representation' => $stageRepresentation, 'status' => 'failed', 'issues' => [[
            'code' => 'input_invalid_encoding', 'detector' => 'file-triage-v1', 'family' => 'local_include',
            'stage' => 'mapping_input', 'representation' => $stageRepresentation, 'candidate' => $candidate,
        ]]];
        $errors = EvaluationCoverage::validationErrors($coverage['evaluation'], $coverage['packs']);
        if ($valid) self::assertSame([], $errors);
        else self::assertContains('invalid_issue_candidate', $errors);
        $result = $this->classification($coverage);
        self::assertSame('partial', $result->coverage['status']);
        self::assertSame($candidate, $result->coverage['evaluation']['runs'][2]['familyOutcomes'][0]['stages'][4]['issues'][0]['candidate']);
    }

    public function testHistoricalUninstrumentedResultAndPureCoreRemainUnchanged(): void
    {
        $coverage = $this->coverage(); unset($coverage['evaluation']);
        $historical = $this->classification($coverage, 'cribrum-1.0.0');
        self::assertSame($coverage, $historical->coverage);
        self::assertSame([], $historical->diagnostics);
        $core = new ClassificationResult([], [], ['core' => '2026.10.19'], [], 'cribrum-1.1.0');
        self::assertArrayNotHasKey('coverage', $core->toArray());
        self::assertSame([], $core->diagnostics);
    }

    public function testFeatureBoundaryAndPrivateCoreCompatibilityDoNotDependOnPcreBudget(): void
    {
        $previous = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '1');
            self::assertTrue(EvaluationCoverage::requiresEvaluation('cribrum-1.1.0', ['status' => 'complete']));
            self::assertTrue(EvaluationCoverage::requiresEvaluation('cribrum-1.1.0', [], ['behavior-triage' => '1.4.0']));
            self::assertFalse(EvaluationCoverage::requiresEvaluation('cribrum-1.0.0', ['status' => 'complete']));
            self::assertFalse(EvaluationCoverage::requiresEvaluation('cribrum-1.1.0', [], ['private-legacy-core' => '1']));
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
        $private = new ClassificationResult([], [], ['private-legacy-core' => '1'], [], 'cribrum-1.1.0');
        self::assertSame([], $private->diagnostics);
        self::assertArrayNotHasKey('coverage', $private->toArray());
    }

    public function testSelectedRunModeCannotCertifyAnotherMode(): void
    {
        $coverage = $this->coverage();
        $coverage['evaluation']['runs'][0]['mode'] = 'shadow';
        self::assertFalse(EvaluationCoverage::certifiesComplete($coverage, 'cribrum-1.1.0'));
    }

    public function testCorePreparationIssueCannotCertifyComplete(): void
    {
        $coverage = $this->coverage();
        $coverage['evaluation']['engineIssues'] = [['code' => 'regex_error', 'stage' => 'text_preparation', 'representation' => 'raw', 'operation' => 'replace', 'pcreCode' => 2]];
        self::assertSame([], EvaluationCoverage::validationErrors($coverage['evaluation']));
        self::assertFalse(EvaluationCoverage::certifiesComplete($coverage, 'cribrum-1.1.0'));
    }

    public function testCorePreparationResourceLimitIsPartialWithoutEngineFailureDiagnostics(): void
    {
        $coverage = $this->coverage();
        $coverage['evaluation']['engineIssues'] = [['code' => 'input_variable_budget', 'stage' => 'final_url_query', 'representation' => null, 'status' => 'limited', 'counters' => ['limit' => 1000, 'remaining' => null]]];
        self::assertSame([], EvaluationCoverage::validationErrors($coverage['evaluation']));
        self::assertFalse(EvaluationCoverage::certifiesComplete($coverage, 'cribrum-1.1.0'));
        $result = $this->classification($coverage);
        self::assertSame('partial', $result->coverage['status']);
        self::assertSame([], $result->diagnostics);
        self::assertSame($coverage['evaluation'], $result->coverage['evaluation']);
        $coverage['evaluation']['engineIssues'][0]['counters']['limit'] = -1;
        self::assertContains('invalid_engine_issue', EvaluationCoverage::validationErrors($coverage['evaluation']));
        $coverage['evaluation']['engineIssues'][0]['counters']['limit'] = 1000;
        $coverage['evaluation']['engineIssues'][0]['status'] = 'completed';
        self::assertContains('invalid_engine_issue', EvaluationCoverage::validationErrors($coverage['evaluation']));
        self::assertNotEmpty($this->classification($coverage)->diagnostics);
        $coverage['evaluation']['engineIssues'][0]['status'] = null;
        self::assertContains('invalid_engine_issue', EvaluationCoverage::validationErrors($coverage['evaluation']));
    }

    private function classification(array $coverage, string $version = 'cribrum-1.1.0'): ClassificationResult
    {
        return ClassificationResult::fromArray(['suggestions' => [], 'signals' => [], 'diagnostics' => [], 'packs' => ['behavior-triage' => '1.4.0'], 'classifierVersion' => $version, 'coverage' => $coverage]);
    }

    private function coverage(): array
    {
        $families = ['reflection-triage-v1' => ['reflection'], 'sql-triage-v1' => ['sql'], 'file-triage-v1' => ['local_include', 'remote_include'], 'context-triage-v1' => ['disclosure', 'waf', 'error_response']];
        $runs = [];
        foreach ($families as $detector => $names) {
            $outcomes = [];
            foreach ($names as $name) {
                $stages = [];
                foreach (EvaluationCoverage::requiredStages($detector, $name) as $stage) $stages[] = ['stage' => $stage, 'representation' => $stage === 'input_sources' ? null : 'raw', 'status' => 'completed', 'issues' => []];
                $outcomes[] = ['family' => $name, 'status' => 'completed', 'stages' => $stages, 'counters' => ['candidatesConsidered' => 0, 'signalsQualified' => 0, 'candidatesExcluded' => 0]];
            }
            $runs[] = ['pack' => 'behavior-triage', 'version' => '1.4.0', 'rule' => $detector, 'detector' => $detector, 'mode' => 'active', 'outputs' => Rule::detectorOutputs()[$detector], 'familyOutcomes' => $outcomes];
        }
        return ['status' => 'complete', 'mode' => 'active', 'packs' => ['behavior-triage' => '1.4.0'], 'requiredFamilies' => ['disclosure', 'local_include', 'reflection', 'remote_include', 'sql', 'waf'], 'evaluation' => ['schemaVersion' => 1, 'runs' => $runs]];
    }
}
