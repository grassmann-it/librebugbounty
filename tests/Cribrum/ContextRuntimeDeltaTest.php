<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\ContextTriageDetector;
use App\Cribrum\Detector\JsonDiagnosticSpans;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\TestCase;

final class ContextRuntimeDeltaTest extends TestCase
{
    private const CALL_A = 'org.synthetic.http.CookieProcessor.validate(CookieProcessor.java:41)';
    private const CALL_B = 'org.synthetic.http.Response.addCookie(Response.java:82)';

    public function testCookieParagraphTraceAndBoundDiagnosticHaveExactSpans(): void
    {
        $body = 'Ä<h1>An error occurred</h1><p><b>Control character in cookie value or attribute.</b></p><div data-role="collapsible"><h2>Stacktrace</h2><p>URL: <span>http://synthetic.internal:8123/demo</span></p><p>Exception id: <span>EXID-synthetic</span></p><p>'.self::CALL_A.'</p><p>'.self::CALL_B.'</p></div>';
        $signals = $this->signals($body);
        $runtime = $this->runtime($signals);
        self::assertCount(1, $runtime);
        self::assertSame('cookie_validation', $runtime[0]['attributes']['runtimeDetails']['diagnosticKind']);
        self::assertSame('Control character in cookie value or attribute.', $runtime[0]['spans'][0]['excerpt']);
        self::assertArrayNotHasKey('exceptionClass', $runtime[0]['attributes']['runtimeDetails']);
        $stacks = $this->details($signals, 'stack_trace');
        self::assertCount(1, $stacks);
        self::assertStringContainsString(self::CALL_A, $stacks[0]['spans'][0]['excerpt']);
        self::assertStringNotContainsString('EXID', $stacks[0]['spans'][0]['excerpt']);
    }

    public function testSingleParagraphOrInterruptedListIsNotAStack(): void
    {
        foreach (['<p>'.self::CALL_A.'</p>', '<p>'.self::CALL_A.'</p><div><p>'.self::CALL_B.'</p></div>', '<p>'.self::CALL_A.'</p><p>explanation</p><p>'.self::CALL_B.'</p>'] as $body) {
            self::assertSame([], $this->details($this->signals($body), 'stack_trace'));
        }
    }

    public function testTransformerKeepsModulePrefixInsideDeliveredSampAndDeduplicatesDiagnostic(): void
    {
        $module = 'java.base/java.util.concurrent.ConcurrentHashMap.computeIfAbsent(ConcurrentHashMap.java:1708)';
        $body = '<div class="card-header">Error while creating Transformer!</div><div class="card-body"><pre><code><strong>Error while creating Transformer!</strong></code></pre><pre><samp>'.self::CALL_A.'<br>'.$module.'</samp></pre></div>';
        $signals = $this->signals($body);
        self::assertCount(1, $this->runtime($signals));
        self::assertSame('transformer_creation', $this->runtime($signals)[0]['attributes']['runtimeDetails']['diagnosticKind']);
        self::assertStringContainsString($module, $this->details($signals, 'stack_trace')[0]['spans'][0]['excerpt']);
        self::assertSame([], $this->details($signals, 'source_code'));
    }

    public function testNextBindsActualFieldPastLargePagePrefixAndKeepsStatusSeparate(): void
    {
        $body = str_repeat('x ', 280000).'<h1>404 Not Found</h1>'.$this->next(['props' => ['pageProps' => ['statusCode' => 500, 'error' => 'Failed to create resultsState']], 'gssp' => true]);
        $runtime = $this->runtime($this->signals($body));
        self::assertCount(1, $runtime);
        self::assertSame(['family' => 'next', 'diagnosticKind' => 'results_state_creation', 'contextKind' => 'structured_error', 'fieldPath' => 'props.pageProps.error', 'statusCode' => 500], $runtime[0]['attributes']['runtimeDetails']);
        self::assertGreaterThan(560000, $runtime[0]['spans'][0]['start']);
        self::assertSame('"Failed to create resultsState"', $runtime[0]['spans'][0]['excerpt']);
        self::assertSame(200, $runtime[0]['attributes']['httpStatus']);
    }

    public function testConcreteWordsAndWrongNextPathsDoNotBecomeRuntimeDiagnostics(): void
    {
        $good = $this->next(['props' => ['pageProps' => ['error' => 'Failed to create resultsState', 'statusCode' => 500]]]);
        $cookie = '<p><b>Control character in cookie value or attribute.</b></p><div><h2>Stacktrace</h2><p>'.self::CALL_A.'</p><p>'.self::CALL_B.'</p></div>';
        foreach ([
            'Control character in cookie value or attribute.',
            '<script>const catalog={error:"Control character in cookie value or attribute."};</script>',
            '<section class="documentation">'.$cookie.'</section>',
            '<code>'.$cookie.'</code>',
            '<script>const example=\''.str_replace("'", '\\x27', $good).'\';</script>',
            '<div data-code=\''.str_replace("'", '&#39;', $good).'\'></div>',
            $this->next(['error' => 'Failed to create resultsState', 'props' => ['pageProps' => ['statusCode' => 500]]]),
            $this->next(['props' => ['pageProps' => ['message' => 'Failed to create resultsState', 'statusCode' => 500]]]),
            $this->next(['props' => ['pageProps' => ['error' => 'Failed to create resultsState', 'statusCode' => 404]]]),
            str_replace('application/json', 'text/plain', $good),
            '<section class="tutorial">'.$good.'</section>',
            '<div class="card-header">Error while creating Transformer!</div><p>'.self::CALL_A.'</p>',
        ] as $body) self::assertSame([], $this->runtime($this->signals($body)), $body);
    }

    public function testJsonLexicalFieldRejectsDuplicateMembersAndKeepsEscapedRawValue(): void
    {
        self::assertNull(JsonDiagnosticSpans::field('{"props":{},"props":{"pageProps":{"error":"x"}}}', ['props', 'pageProps', 'error']));
        self::assertNull(JsonDiagnosticSpans::field('{"props":{"pageProps":{"error":"x","error":"y"}}}', ['props', 'pageProps', 'error']));
        $json = '{"noise":["closing brace }",{"x":"error"}],"props":{"pageProps":{"error":"Failed to create results\\u0053tate","statusCode":500}}}';
        $field = JsonDiagnosticSpans::field($json, ['props', 'pageProps', 'error']);
        self::assertSame('Failed to create resultsState', $field['value']);
        self::assertSame('"Failed to create results\\u0053tate"', $field['match'][0]);
    }

    public function testOversizedNextObjectHasVisiblePartialCoverage(): void
    {
        $body = $this->next(['padding' => str_repeat('x', 66000), 'props' => ['pageProps' => ['error' => 'Failed to create resultsState', 'statusCode' => 500]]]);
        $evaluation = (new ContextTriageDetector())->evaluate($this->input($body));
        self::assertSame('limited', $evaluation->status);
        self::assertSame([], $this->runtime($evaluation->signals));
        $issues = [];
        foreach ($evaluation->familyOutcomes as $family) foreach ($family['stages'] as $stage) foreach ($stage['issues'] as $issue) $issues[] = $issue;
        self::assertContains('context_budget', array_column($issues, 'code'));
    }

    public function testMalformedOrDepthLimitedTargetObjectRetainsPartialCoverage(): void
    {
        $noise = str_repeat('[', 33).'0'.str_repeat(']', 33);
        foreach (['{"props":', '{"noise":'.$noise.',"props":{"pageProps":{"error":"Failed to create resultsState","statusCode":500}}}'] as $json) {
            $body = '<script id="__NEXT_DATA__" type="application/json">'.$json.'</script>';
            $e = (new ContextTriageDetector())->evaluate($this->input($body));
            self::assertSame('limited', $e->status);
            self::assertSame([], $this->runtime($e->signals));
        }
    }

    public function testQuotedCookieAndTemplateCallsAreInertAndDeepNestingIsVisible(): void
    {
        $cookie = '<p><b>Control character in cookie value or attribute.</b></p><div><h2>Stacktrace</h2><p>'.self::CALL_A.'</p><p>'.self::CALL_B.'</p></div>';
        foreach (['<div data-x="'.$cookie.'">text</div>', '<template>'.$cookie.'</template>', '<!--'.$cookie.'-->'] as $body) {
            self::assertSame([], $this->runtime($this->signals($body)));
            self::assertSame([], $this->details($this->signals($body), 'stack_trace'));
        }
        $body = str_repeat('<div>',129).$cookie.str_repeat('</div>',129);
        $e = (new ContextTriageDetector())->evaluate($this->input($body));
        self::assertSame('limited', $e->status);
        self::assertSame([], $this->runtime($e->signals));
    }

    public function testJsonListIndicesArePositionalAndNeverCoerceObjectFields(): void
    {
        $json = '{"errors":[{"detail":"first"},{"detail":"second"}]}';
        self::assertSame('second', JsonDiagnosticSpans::field($json, ['errors', 1, 'detail'])['value']);
        self::assertNull(JsonDiagnosticSpans::field($json, ['errors', '1', 'detail']));
        self::assertNull(JsonDiagnosticSpans::field($json, ['errors', -1]));
        self::assertNull(JsonDiagnosticSpans::field($json, ['errors', 2]));
        self::assertNull(JsonDiagnosticSpans::field('{"errors":{"1":{"detail":"object"}}}', ['errors', 1, 'detail']));
    }

    private function signals(string $body): array
    {
        $input = $this->input($body);
        $signals = (new ContextTriageDetector())->detect($input);
        foreach ($signals as $signal) {
            EvidenceSignal::validateAgainstInput($signal, $input);
            self::assertArrayNotHasKey('claim', $signal);
        }
        return $signals;
    }

    private function runtime(array $signals): array
    {
        return array_values(array_filter($signals, static fn ($s) => $s['code'] === 'error_response' && isset($s['attributes']['runtimeDetails'])));
    }

    private function details(array $signals, string $kind): array
    {
        return array_values(array_filter($signals, static fn ($s) => $s['code'] === 'internal_information_exposed' && $s['attributes']['detailKind'] === $kind));
    }

    private function next(array $data): string
    {
        return '<script id="__NEXT_DATA__" type="application/json">'.json_encode($data, JSON_THROW_ON_ERROR).'</script>';
    }

    private function input(string $body): ObservationInput
    {
        return new ObservationInput('https://fixture.test/', $body, null, 'text/html', 200, null, null, null, false, null);
    }
}
