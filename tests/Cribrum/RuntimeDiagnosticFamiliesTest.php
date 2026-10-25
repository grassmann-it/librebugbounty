<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\RuntimeDiagnosticFamilies;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\TestCase;

final class RuntimeDiagnosticFamiliesTest extends TestCase
{
    public static function cases(): iterable
    {
        foreach (['runtime-diagnostic-families.json', 'runtime-error-carriers.json'] as $file) {
            foreach (json_decode(file_get_contents(__DIR__.'/Fixtures/'.$file), true, 32, JSON_THROW_ON_ERROR) as $case) yield $case['id'] => [$case];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testConcreteBoundedFamilyAndCounterexamples(array $case): void
    {
        $input = $this->input('Ä'.$case['body'], 200);
        // Structured short responses keep their actual whole-response carrier.
        if (isset($case['expectedStatus']) || str_starts_with($case['body'], '{') || !str_starts_with($case['body'], '<')) $input = $this->input($case['body']);
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($input);
        self::assertSame($case['expectedStatus'] ?? 'completed', $evaluation->status, json_encode($evaluation->familyOutcomes));
        self::assertCount(1, $evaluation->signals);
        $signal = $evaluation->signals[0];
        EvidenceSignal::validateAgainstInput($signal, $input);
        self::assertSame('error_response', $signal['code']);
        self::assertSame($case['expected'], $signal['attributes']['runtimeDetails']);
        self::assertArrayNotHasKey('claim', $signal);
        self::assertNotEmpty($signal['spans'][0]['excerpt']);
        foreach ($case['negativeBodies'] as $body) {
            self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($this->input($body))->signals, $case['id'].': '.$body);
        }
    }

    public function testStructuredTailAfterInterruptedCarriersAndHtmlExamples(): void
    {
        $case = iterator_to_array(self::cases())['php_syntax_error'][0];
        foreach (['<html><body><img src="', '<html><body><script>const slides=['] as $prefix) {
            $input = $this->input($prefix.$case['body']);
            $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($input);
            self::assertCount(1, $evaluation->signals);
            EvidenceSignal::validateAgainstInput($evaluation->signals[0], $input);
        }
        foreach (['<code><img src="', '<section class="documentation"><script>const slides=[', '<template><img src="', '<!--'] as $prefix) self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($this->input($prefix.$case['body']))->signals);
        foreach (['{"catalog":[', '{"catalog":invalid,', '<script>const catalog=', '<script>const catalog=[', '<div data-example="'] as $prefix) self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($this->input($prefix.$case['body']))->signals);
        self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($this->input('{"catalog":['.$case['body'].']}'))->signals);
        self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($this->input('<div>{"catalog":invalid,'.$case['body']))->signals);
    }

    public function testDuplicateJsonMembersAndLimitsStayVisible(): void
    {
        $case = iterator_to_array(self::cases())['php_syntax_error'][0];
        $duplicate = str_replace('"type":4', '"type":1,"type":4', $case['body']);
        self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($this->input($duplicate))->signals);
        $deep = (new RuntimeDiagnosticFamilies())->evaluate($this->input(str_repeat('<div>', 129).$case['body']));
        self::assertSame('limited', $deep->status);
        self::assertSame([], $deep->signals);
        $oversized = str_replace('"message":"A critical error occurred"', '"message":"'.str_repeat('x', 66000).'"', $case['body']);
        self::assertSame('limited', (new RuntimeDiagnosticFamilies())->evaluate($this->input($oversized))->status);
        $malformed = str_replace('"type":4', '"type":no', $case['body']);
        self::assertSame('failed', (new RuntimeDiagnosticFamilies())->evaluate($this->input($malformed))->status);
        self::assertSame('failed', (new RuntimeDiagnosticFamilies())->evaluate($this->input(substr($case['body'], 0, -2)))->status);
    }

    public function testActualDiagnosticCannotBeAssembledFromSourceAndNeighborBlocks(): void
    {
        foreach ([
            '<div class="error-message"><code>Call to undefined function synthetic_lookup()</code></div>',
            '<div class="error-message"><script>const x="Call to undefined function synthetic_lookup()";</script></div>',
            '<pre class="stacktrace">function sample() { return "Call to undefined function synthetic_lookup()"; }</pre>',
            '<p class="error-message">$message = "Call to undefined function synthetic_lookup()";</p>',
            '<p class="error-message"><span class="example">Call to undefined function synthetic_lookup()</span></p>',
            '<pre>javax.jcr.query.InvalidQueryException: Encountered "x" at line 1, column 2.</pre><pre>Was expecting: ")"</pre>',
            '<p>CloudException: Invalid expression: Syntax error: expected token</p><p>Parameter name: $filter</p><p class="location">Microsoft.Azure.Search.DocumentsOperations.Search()</p>',
            '{"errors":[{"status":"500","title":"Internal Server Error","detail":"TypeError"}]}',
        ] as $body) self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($this->input($body))->signals);
        $input = $this->input('<code>Fatal Error Can\'t read file: sample.html</code><div>Fatal Error Can\'t read file: actual.html</div>');
        $signals = (new RuntimeDiagnosticFamilies())->evaluate($input)->signals;
        self::assertCount(1, $signals);
        self::assertSame("Fatal Error Can't read file: actual.html", $signals[0]['spans'][0]['excerpt']);
    }

    public function testErrorArrayBindsDetailAndRawEscapesOnlyOnce(): void
    {
        $json = '{"errors":[{"status":"500","title":"Internal Server Error","detail":"Synthetic::find(): Argument #3 ($limit) must be of type ?int, str\\u0069ng given"}]}';
        $input = $this->input($json);
        $signals = (new RuntimeDiagnosticFamilies())->evaluate($input)->signals;
        self::assertCount(1, $signals);
        self::assertSame('errors.0.detail', $signals[0]['attributes']['runtimeDetails']['fieldPath']);
        self::assertStringContainsString('str\\u0069ng given', $signals[0]['spans'][0]['excerpt']);
        EvidenceSignal::validateAgainstInput($signals[0], $input);
        self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($this->input(str_replace('"status":"500"', '"status":"404"', $json)))->signals);
    }

    public function testNuxtReferenceAndPreviousBudgetsRemainVisible(): void
    {
        $case = iterator_to_array(self::cases())['nuxt_header'][0];
        $body = $case['body'];
        $jsonStart = strpos($body, '>') + 1;
        $table = json_decode(substr($body, $jsonStart, strpos($body, '</script>') - $jsonStart), true, 32, JSON_THROW_ON_ERROR);
        $oversized = '<script type="application/json" id="__NUXT_DATA__" data-ssr="true">'.json_encode([...$table, ...array_fill(0, 1025, null)], JSON_THROW_ON_ERROR).'</script>';
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($this->input($oversized));
        self::assertSame('limited', $evaluation->status);
        self::assertSame([], $evaluation->signals);
        self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($this->input(str_replace('id="__NUXT_DATA__"', 'id="__NUXT_DATA__" id="catalog"', $body)))->signals);
        $dup = str_replace('"error":2', '"error":1,"error":2', $body);
        self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($this->input($dup))->signals);
        $deep = ['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'syntax error, unexpected token "sample"'];
        for ($i = 0; $i < 6; ++$i) $deep = ['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Rendering failed', 'meta' => ['previous' => [$deep]]];
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($this->input(json_encode(['errors' => [$deep]], JSON_THROW_ON_ERROR)));
        self::assertSame('limited', $evaluation->status);
        self::assertSame([], $evaluation->signals);
    }

    public function testNuxtNeedsRealAttributesAndExactClosingTag(): void
    {
        foreach (['nuxt_graphql', 'nuxt_header'] as $id) {
            $body = iterator_to_array(self::cases())[$id][0]['body'];
            $data = substr($body, strpos($body, '>') + 1);
            $fake = '<script data-note=\' id="__NUXT_DATA__" type="application/json" data-ssr="true"\'>'.$data;
            self::assertSame([], (new RuntimeDiagnosticFamilies())->evaluate($this->input($fake))->signals);
            $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($this->input(str_replace('</script>', '</scripture>', $body)));
            self::assertSame('failed', $evaluation->status);
            self::assertSame([], $evaluation->signals);
            $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($this->input(str_replace('<script ', '<script data-note="x > y" ', $body)));
            self::assertSame('completed', $evaluation->status);
            self::assertCount(1, $evaluation->signals);
        }
    }

    public function testMalformedNuxtCandidateDoesNotSuppressLaterActualDiagnostic(): void
    {
        $good = iterator_to_array(self::cases())['nuxt_header'][0]['body'];
        $open = '<script type="application/json" id="__NUXT_DATA__" data-ssr="true">';
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($this->input($open.'{"bad":</script>'.$good));
        self::assertSame('failed', $evaluation->status);
        self::assertCount(1, $evaluation->signals);
        $evaluation = (new RuntimeDiagnosticFamilies())->evaluate($this->input($open.str_repeat('[', 40).'0'.str_repeat(']', 40).'</script>'.$good));
        self::assertSame('limited', $evaluation->status);
        self::assertCount(1, $evaluation->signals);
    }

    private function input(string $body, int $status = 200): ObservationInput
    {
        return new ObservationInput('https://fixture.test/', $body, null, 'text/html', $status, null, null, null, false, null);
    }
}
