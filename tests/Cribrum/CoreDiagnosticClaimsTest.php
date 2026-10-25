<?php

namespace App\Tests\Cribrum;

use App\Cribrum\ClassificationResult;
use App\Cribrum\CribrumClassifier;
use App\Cribrum\ObservationInput;
use App\Cribrum\RulePackCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoreDiagnosticClaimsTest extends TestCase
{
    public static function diagnostics(): iterable
    {
        yield 'SQL at HTTP 200' => ['<p>Database Error: SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax near a value at line 1</p>', ['error_disclosure']];
        yield 'PHP notice' => ['<br><b>Notice</b>: Undefined index: exampleKey in <b>/container/demo/controller.php</b> on line <b>27</b>', ['error_disclosure', 'information_disclosure']];
        yield 'PHP warning' => ['<b>Warning</b>: Undefined array key "exampleKey" in <b>/srv/demo/page.php</b> on line <b>19</b>', ['error_disclosure', 'information_disclosure']];
        yield 'Classic ASP' => ["Microsoft VBScript runtime error '800a000d'<br>Type mismatch<br>/booking/example.asp, line 92", ['error_disclosure']];
        yield 'Hidden delivered stack' => ['<div class="hidden">at Demo.Renderer.Execute() in C:\\demo\\Renderer.cs:line 31</div>', ['information_disclosure']];
    }

    #[DataProvider('diagnostics')]
    public function testConcreteDiagnosticsAtSuccessfulHttpStatusAreObserved(string $body, array $expected): void
    {
        $input = $this->input('Ä😀 '.$body);
        $result = $this->classify($input);
        $claims = array_column($result->toArray()['suggestions'], 'state', 'claim');
        foreach ($expected as $claim) self::assertSame('observed', $claims[$claim] ?? null);
        self::assertArrayNotHasKey('sql_injection', $claims);
        self::assertSame([], $result->coverage, 'Narrow builtin evaluation must not certify complete candidate coverage.');
        self::assertSame([], array_filter($result->signals, static fn ($signal) => ($signal['kind'] ?? null) === 'observation_evidence'));
        foreach ($result->suggestions as $suggestion) foreach ($suggestion->spans as $span) {
            self::assertSame(mb_substr($input->rawHtml, $span['start'], $span['length'], 'UTF-8'), $span['excerpt']);
        }
        self::assertSame($result->toArray()['suggestions'], $this->classify($input, true)->toArray()['suggestions']);
        $subset = (new RulePackCompiler())->compile(['schema' => 2, 'id' => 'diagnostic-subset', 'version' => '1', 'rules' => [
            ['id' => 'structure', 'detector' => 'sql-triage-v1', 'outputs' => ['sql_input_structure_indication']],
        ]]);
        $partial = (new CribrumClassifier('/nonexistent', null, 'shadow', [$subset]))->classify($input);
        self::assertSame($result->toArray()['suggestions'], $partial->toArray()['suggestions'],
            'Selecting only part of a shadow pack must not change independently observed core claims.');
        self::assertSame('partial', $partial->coverage['status']);
        ClassificationResult::fromArray($result->toArray());
    }

    public function testUnrelatedErrorsAndSuppliedDiagnosticsDoNotCreateClaims(): void
    {
        $notice = 'Notice: Undefined index: sample in /srv/demo/example.php on line 27';
        foreach (['<h1>Not Found</h1>', '<h1>Internal Server Error</h1>', '<script>const labels='.json_encode([$notice]).';</script>',
            '<code>'.$notice.'</code>', '<section class="documentation">'.$notice.'</section>',
            json_encode(['labels' => ['sample' => $notice]]),
        ] as $body) {
            self::assertSame([], $this->classify($this->input($body))->suggestions, $body);
        }
        $input = new ObservationInput('https://fixture.test/?q='.rawurlencode($notice), '<p>'.$notice.'</p>', null, 'text/html', 200, null, $notice, null, false, null);
        self::assertSame([], $this->classify($input)->suggestions);
        $formatted = '<b>Notice</b>: Undefined index: sample in <b>/srv/demo/example.php</b> on line <b>27</b>';
        $input = new ObservationInput('https://fixture.test/?q='.rawurlencode($notice), $formatted, null, 'text/html', 200, null, $notice, null, false, null);
        self::assertSame([], $this->classify($input)->suggestions, 'Formatting a wholly supplied diagnostic adds no independent disclosure.');
    }

    public function testSqlCatalogsAndExamplesAreNotObservedErrors(): void
    {
        $sql = 'SQLSTATE[42000]: Syntax error or access violation';
        foreach (['<script>const messages='.json_encode([$sql]).';</script>',
            json_encode(['labels' => ['sample' => $sql]]), '<section class="documentation">'.$sql.'</section>',
        ] as $body) self::assertSame([], $this->classify($this->input($body))->suggestions, $body);
    }

    public function testInvalidRawDoesNotSilentlyBorrowValidDom(): void
    {
        $input = new ObservationInput('https://fixture.test/', "bad\xff", 'Notice: Undefined index: sample in /srv/demo/example.php on line 27', 'text/html', 200, null, null, null, false, null);
        $result = $this->classify($input);
        self::assertSame([], $result->suggestions);
        self::assertNotEmpty($result->diagnostics);
    }

    public function testDomOnlyDiagnosticPreservesTextCoordinatesAndUnknownCoverage(): void
    {
        $text = 'Ä😀 Notice: Undefined index: sample in /srv/demo/example.php on line 27';
        $input = new ObservationInput('https://fixture.test/', null, $text, 'text/html', 200, null, null, null, false, null);
        $result = $this->classify($input);
        self::assertContains('information_disclosure', array_column($result->toArray()['suggestions'], 'claim'));
        foreach ($result->suggestions as $suggestion) foreach ($suggestion->spans as $span) {
            self::assertSame('text', $span['representation']);
            self::assertSame(mb_substr($text, $span['start'], $span['length'], 'UTF-8'), $span['excerpt']);
        }
        self::assertSame([], $result->coverage);
        ClassificationResult::fromArray($result->toArray());
    }

    private function input(string $body): ObservationInput
    {
        return new ObservationInput('https://fixture.test/', $body, null, 'text/html', 200, null, null, null, false, null);
    }

    private function classify(ObservationInput $input, bool $shadow = false): ClassificationResult
    {
        return (new CribrumClassifier('/nonexistent', $shadow ? dirname(__DIR__, 2).'/resources/cribrum/candidates' : null))->classify($input);
    }
}
