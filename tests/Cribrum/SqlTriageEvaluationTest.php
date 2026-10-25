<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\SqlEvaluationRegexFault;
use App\Cribrum\Detector\SqlTriageDetector;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SqlTriageEvaluationTest extends TestCase
{
    private const DIAGNOSTIC = "<p>Syntax error in query expression 'id=marker'.</p>";
    private const ALL = ['sql_error_observed', 'input_in_sql_error', 'sql_input_structure_indication'];

    private function input(?string $raw, string $query = '?q=marker'): ObservationInput
    {
        return ObservationInput::fromArray(['url' => 'https://fixture.test/'.$query, 'rawHtml' => $raw]);
    }

    #[DataProvider('signatureCounts')]
    public function testBudgetCountsSignaturesBeforeExclusions(int $examples, int $real, int $signals, string $status): void
    {
        $body = str_repeat('<code>'.self::DIAGNOSTIC.'</code>', $examples).str_repeat(self::DIAGNOSTIC, $real);
        $input = $this->input($body);
        $evaluation = (new SqlTriageDetector())->evaluate($input);
        self::assertSame($status, $evaluation->status);
        self::assertCount($signals, $evaluation->signals);
        self::assertSame(min(32, $examples + $real), $evaluation->familyOutcomes[0]['counters']['candidatesConsidered']);
        self::assertSame(min(32, $examples), $evaluation->familyOutcomes[0]['counters']['candidatesExcluded']);
        self::assertSame($evaluation->signals, (new SqlTriageDetector())->detect($input));
        foreach ($evaluation->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
        if ($status === 'limited') {
            $issue = $this->issues($evaluation->familyOutcomes)[0];
            self::assertSame('match_budget', $issue['code']);
            self::assertSame($examples + $real - 32, $issue['counters']['remaining']);
        }
    }

    public static function signatureCounts(): iterable
    {
        yield '31 signatures proven complete' => [0, 31, 93, 'completed'];
        yield '32 signatures proven complete' => [0, 32, 96, 'completed'];
        yield '33 signatures leave one unexamined' => [0, 33, 96, 'limited'];
        yield '31 examples before real diagnostic' => [31, 1, 3, 'completed'];
        yield '32 examples use budget before real diagnostic' => [32, 1, 0, 'limited'];
    }

    public function testOptionalContextAndUnsupportedGrammarAreCompleted(): void
    {
        foreach (['', '<p>Ordinary page</p>', self::DIAGNOSTIC] as $raw) {
            self::assertSame('completed', (new SqlTriageDetector())->evaluate($this->input($raw, ''))->status);
        }
        $input = $this->input("<p>Incorrect syntax near marker. Query: SELECT * FROM items WHERE id=? /* marker */</p>");
        $result = (new SqlTriageDetector())->evaluate($input);
        self::assertSame('completed', $result->status);
        self::assertSame(['sql_error_observed', 'input_in_sql_error'], array_column($result->signals, 'code'));
    }

    public function testInvalidRepresentationsAndParameterEncodingAreExplicit(): void
    {
        foreach ([null, "bad\xff"] as $raw) {
            $result = (new SqlTriageDetector())->evaluate($this->input($raw));
            self::assertSame('unavailable', $result->status);
            self::assertSame([], $result->signals);
        }
        $result = (new SqlTriageDetector())->evaluate($this->input(self::DIAGNOSTIC, '?q=%FF'));
        self::assertSame('unavailable', $result->status);
        self::assertSame(['sql_error_observed'], array_column($result->signals, 'code'));
    }

    #[DataProvider('queryLimits')]
    public function testQueryCaptureNeverQualifiesAcrossAnUnexaminedGuard(int $remainderLength, bool $outsidePlaceholder, string $status): void
    {
        $prefix = ' * FROM items WHERE id=marker AND padding=';
        $remainder = $prefix.str_repeat('x', $remainderLength - strlen($prefix));
        if ($outsidePlaceholder) $remainder .= ' AND bound=?';
        $raw = '<pre>Incorrect syntax near marker. Query: SELECT'.$remainder.'</pre>';
        $result = (new SqlTriageDetector())->evaluate($this->input($raw));
        self::assertSame($status, $result->status);
        self::assertSame($status === 'completed' ? self::ALL : ['sql_error_observed', 'input_in_sql_error'], array_column($result->signals, 'code'));
        if ($status === 'limited') {
            $issue = $this->issues($result->familyOutcomes)[0];
            self::assertSame('context_budget', $issue['code']);
            self::assertSame('structure', $issue['stage']);
            self::assertSame(hash('sha256', $raw), $issue['candidate']['representationSha256']);
        }
    }

    public static function queryLimits(): iterable
    {
        yield '1999 remainder closed' => [1999, false, 'completed'];
        yield '2000 remainder closed' => [2000, false, 'completed'];
        yield '2001 remainder clipped' => [2001, false, 'limited'];
        yield 'placeholder after capture invalidates prior qualification' => [2000, true, 'limited'];
    }

    public function testFrozenQueryGuardCounterexampleKeepsItsOriginalBinding(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/cribrum/k1-sql/query-capture-placeholder.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($fixture['rawHtmlSha256'], hash('sha256', $fixture['input']['rawHtml']));
        $input = ObservationInput::fromArray($fixture['input']);
        $result = (new SqlTriageDetector())->evaluate($input);
        self::assertSame($fixture['expectedSignalCodes'], array_column($result->signals, 'code'));
        self::assertSame('limited', $result->status);
        $issue = $this->issues($result->familyOutcomes)[0];
        self::assertSame('context_budget', $issue['code']);
        self::assertSame($fixture['rawHtmlSha256'], $issue['candidate']['representationSha256']);
        self::assertSame(5, $issue['candidate']['start']);
        foreach ($result->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
    }

    public function testBackwardContextBudgetPreservesCompleteLocalB0Evidence(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/cribrum/k1-sql/plain-prefix-budget.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($fixture['rawHtmlSha256'], hash('sha256', $fixture['input']['rawHtml']));
        $result = (new SqlTriageDetector())->evaluate(ObservationInput::fromArray($fixture['input']));
        self::assertSame('limited', $result->status);
        self::assertSame($fixture['expectedSignalCodes'], array_column($result->signals, 'code'));
        self::assertSame($fixture['expectedEvidenceIds'], array_column($result->signals, 'evidenceId'));
        self::assertSame($fixture['expectedSignalsSha256'], hash('sha256', json_encode($result->signals, JSON_THROW_ON_ERROR)));
        foreach ($result->signals as $signal) self::assertTrue($signal['attributes']['blockComplete']);
        $issue = $this->issues($result->familyOutcomes)[0];
        self::assertSame('context_budget', $issue['code']);
        self::assertSame(1024, $issue['counters']['limit']);
        self::assertSame($fixture['rawHtmlSha256'], $issue['candidate']['representationSha256']);
    }

    #[DataProvider('boundedRepresentations')]
    public function testBoundedRepresentationsReportOnlyActualExhaustion(string $raw, string $status, string $issueCode): void
    {
        $result = (new SqlTriageDetector())->evaluate($this->input($raw));
        self::assertSame($status, $result->status);
        self::assertContains($issueCode, array_column($this->issues($result->familyOutcomes), 'code'));
    }

    public static function boundedRepresentations(): iterable
    {
        yield 'JSON tail clipped' => [str_repeat('x', 32769), 'limited', 'representation_budget'];
        yield '17 possible JSON starts exhausted' => [str_repeat("{broken\n", 17), 'limited', 'input_budget'];
        yield 'JSON parser depth exhausted' => ['{"error":{"message":"ordinary"},"deep":'.str_repeat('[', 65).'0'.str_repeat(']', 65).'}', 'limited', 'parser_depth_budget'];
        yield 'unclosed local block' => [substr(self::DIAGNOSTIC, 0, -4), 'limited', 'context_incomplete'];
        yield 'plain local block backward window exhausted' => [str_repeat('x', 1025)."Syntax error in query expression 'id=marker'.", 'limited', 'context_budget'];
        yield 'local block forward window exhausted' => [substr(self::DIAGNOSTIC, 0, -4).str_repeat('x', 4096).'</p>', 'limited', 'context_incomplete'];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('regexFaults')]
    public function testErrorsRetainOnlyAlreadyQualifiedSignals(string $operation, string $needle, string $stage, array $codes, bool $json = false): void
    {
        require __DIR__.'/../Fixtures/cribrum/k1-sql/regex-faults.php';
        SqlEvaluationRegexFault::$operation = $operation;
        SqlEvaluationRegexFault::$needle = $needle;
        $body = $json ? json_encode(['error' => ['message' => "Syntax error in query expression 'id=marker'."]], JSON_THROW_ON_ERROR) : self::DIAGNOSTIC;
        $input = $this->input($body);
        $result = (new SqlTriageDetector())->evaluate($input);
        self::assertSame(1, SqlEvaluationRegexFault::$injected);
        self::assertSame('failed', $result->status);
        self::assertSame($codes, array_column($result->signals, 'code'));
        $issue = array_values(array_filter($this->issues($result->familyOutcomes), static fn (array $issue): bool => $issue['code'] === 'regex_error'))[0];
        self::assertSame('regex_error', $issue['code']);
        self::assertSame($stage, $issue['stage']);
        self::assertSame($operation, $issue['operation']);
        self::assertSame(PREG_INTERNAL_ERROR, $issue['pcreCode']);
        if (in_array($stage, ['diagnostic', 'input_mapping', 'structure'], true)) {
            self::assertSame(hash('sha256', $body), $issue['candidate']['representationSha256']);
        }
        self::assertStringNotContainsString('marker', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('marker', implode('', $result->diagnostics));
        foreach ($result->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
    }

    public static function regexFaults(): iterable
    {
        yield 'comparison token search' => ['match_all', '<!--', 'comparison', []];
        yield 'signature search' => ['match_all', '(?:(?:System', 'signature_search', []];
        yield 'example negative guard' => ['match_all', '~<code', 'diagnostic', []];
        yield 'prefix negative guard' => ['match', 'documentation|example error', 'diagnostic', []];
        yield 'block completeness guard' => ['match', '"message"', 'diagnostic', []];
        yield 'vendor split' => ['split', 'The SQL statement executed was', 'diagnostic', []];
        yield 'vendor replace' => ['replace', '~^SQLSTATE', 'diagnostic', []];
        yield 'vendor matcher' => ['match', '[ODBC Microsoft Access Driver', 'diagnostic', []];
        yield 'input word boundary' => ['match', '~[\\pL\\pN_]~u', 'input_mapping', ['sql_error_observed']];
        yield 'placeholder negative guard' => ['match', '~\\?|\\$\\d+', 'structure', ['sql_error_observed', 'input_in_sql_error']];
        yield 'operand matcher' => ['match', '(?(DEFINE)', 'structure', ['sql_error_observed', 'input_in_sql_error']];
        yield 'JSON token search' => ['match_all', '~"(?:', 'json_comparison', ['sql_error_observed'], true];
        yield 'JSON escape mapping' => ['match_all', 'u[dD]', 'json_string_comparison', ['sql_error_observed'], true];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLaterFailedGuardKeepsFirstDiagnosticAndContinuesToNext(): void
    {
        require __DIR__.'/../Fixtures/cribrum/k1-sql/regex-faults.php';
        SqlEvaluationRegexFault::$operation = 'match_all';
        SqlEvaluationRegexFault::$needle = '~<code';
        SqlEvaluationRegexFault::$occurrence = 2;
        $input = $this->input(str_repeat(self::DIAGNOSTIC, 3));
        $result = (new SqlTriageDetector())->evaluate($input);
        self::assertSame('failed', $result->status);
        self::assertSame([...self::ALL, ...self::ALL], array_column($result->signals, 'code'));
        self::assertSame(1, SqlEvaluationRegexFault::$injected);
        self::assertSame(mb_strpos($input->rawHtml, 'Syntax error', mb_strlen(self::DIAGNOSTIC)), $this->issues($result->familyOutcomes)[0]['candidate']['start']);
        foreach ($result->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRealPcreLimitRetainsDiagnosticAndInputEvidence(): void
    {
        ini_set('pcre.backtrack_limit', '10');
        $result = (new SqlTriageDetector())->evaluate($this->input(self::DIAGNOSTIC));
        self::assertSame('failed', $result->status);
        self::assertSame(['sql_error_observed', 'input_in_sql_error'], array_column($result->signals, 'code'));
        self::assertSame('structure', $this->issues($result->familyOutcomes)[0]['stage']);
        self::assertSame(PREG_BACKTRACK_LIMIT_ERROR, $this->issues($result->familyOutcomes)[0]['pcreCode']);
    }

    public function testMultibyteJsonTailKeepsIndependentRawDiagnosisWithoutRegexFailure(): void
    {
        $raw = self::DIAGNOSTIC.'😀'.str_repeat('x', 32765);
        $result = (new SqlTriageDetector())->evaluate($this->input($raw));
        self::assertSame('limited', $result->status);
        self::assertSame(self::ALL, array_column($result->signals, 'code'));
        $issues = $this->issues($result->familyOutcomes);
        self::assertContains('representation_budget', array_column($issues, 'code'));
        self::assertNotContains('regex_error', array_column($issues, 'code'));
    }

    private function issues(array $families): array
    {
        $issues = [];
        foreach ($families as $family) foreach ($family['stages'] as $stage) $issues = [...$issues, ...$stage['issues']];
        return $issues;
    }
}
