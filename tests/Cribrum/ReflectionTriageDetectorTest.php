<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\ReflectionTriageDetector;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReflectionTriageDetectorTest extends TestCase
{
    private function input(?string $body, string $url = 'https://fixture.test/?q=probeVALUE', ?string $dom = null, ?string $payload = null, ?string $marker = null, ?string $contentType = 'text/html'): ObservationInput
    {
        return new ObservationInput($url, $body, $dom, $contentType, 200, null, $payload, $marker, false, null);
    }

    #[DataProvider('contexts')]
    public function testOriginalLocalContext(string $body, string $context): void
    {
        $input = $this->input($body);
        $signals = (new ReflectionTriageDetector())->detect($input);
        self::assertCount(1, $signals);
        self::assertSame($context, $signals[0]['attributes']['context']);
        self::assertSame('input_reflected', $signals[0]['code']);
        self::assertSame('literal', $signals[0]['attributes']['encoding']);
        self::assertFalse($signals[0]['attributes']['executionProven']);
        self::assertFalse($signals[0]['attributes']['xssEstablished']);
        self::assertArrayNotHasKey('claim', $signals[0]);
        EvidenceSignal::validateAgainstInput($signals[0], $input);
    }

    public static function contexts(): iterable
    {
        yield ['<p>probeVALUE</p>', 'text'];
        yield ['<input value="probeVALUE">', 'attribute'];
        yield ['<input value="prefix > probeVALUE">', 'attribute'];
        yield ["<input value='probeVALUE'>", 'attribute'];
        yield ['<input value=probeVALUE>', 'attribute'];
        yield ['<script>process(probeVALUE)</script>', 'script'];
        yield ['<script>const value = "probeVALUE";</script>', 'script_string'];
        yield ["<script>const value = 'probeVALUE';</script>", 'script_string'];
        yield ['<script>const value = `probeVALUE`;</script>', 'script_string'];
        yield ['<!-- probeVALUE -->', 'html_comment'];
        yield ['SQLSTATE invalid input "probeVALUE"', 'diagnostic_quote'];
        yield ['<style>.probeVALUE { color: red; }</style>', 'style'];
        yield ['<input probeVALUE>', 'unknown'];
    }

    #[DataProvider('encoded')]
    public function testSingleLayerEntityComparisonRetainsRawBytes(string $body, string $value): void
    {
        $input = $this->input('前置 🙂 '.$body, 'https://fixture.test/?q='.rawurlencode($value));
        $signals = (new ReflectionTriageDetector())->detect($input);
        self::assertCount(1, $signals);
        self::assertSame('html_entity_encoded', $signals[0]['attributes']['encoding']);
        self::assertSame(5, $signals[0]['spans'][0]['start']);
        self::assertSame($body, $signals[0]['spans'][0]['excerpt']);
        self::assertSame(['html_entity_decode_once'], $signals[0]['attributes']['comparisonTransformations']);
        EvidenceSignal::validateAgainstInput($signals[0], $input);
    }

    public static function encoded(): iterable
    {
        yield ['&lt;probeVALUE&gt;', '<probeVALUE>'];
        yield ['&#60;probeVALUE&#62;', '<probeVALUE>'];
        yield ['&#x3C;probeVALUE&#x3e;', '<probeVALUE>'];
        yield ['&quot;probeVALUE&#039;', '"probeVALUE\''];
        yield ['&#112;robeVALUE', 'probeVALUE'];
    }

    public function testUrlIsDecodedExactlyOnceAndFinalUrlIsNotUsed(): void
    {
        $detector = new ReflectionTriageDetector();
        self::assertCount(1, $detector->detect($this->input('%3CprobeVALUE%3E', 'https://fixture.test/?q=%253CprobeVALUE%253E')));
        self::assertSame([], $detector->detect($this->input('<probeVALUE>', 'https://fixture.test/?q=%253CprobeVALUE%253E')));
        self::assertSame([], $detector->detect($this->input('&amp;lt;probeVALUE&amp;gt;', 'https://fixture.test/?q=%3CprobeVALUE%3E')));
        $input = new ObservationInput('https://fixture.test/', 'probeVALUE', null, 'text/html', 200, 'https://redirect.test/?q=probeVALUE', null, null, false, null);
        self::assertSame([], $detector->detect($input));
    }

    public function testPayloadAndExpectedMarkersAreNotUrlDecoded(): void
    {
        foreach (['payload', 'marker'] as $source) {
            $input = $this->input('probeVALUE', 'https://fixture.test/', payload: $source === 'payload' ? 'probeVALUE' : null, marker: $source === 'marker' ? 'probeVALUE' : null);
            self::assertCount(1, (new ReflectionTriageDetector())->detect($input));
        }
        self::assertSame([], (new ReflectionTriageDetector())->detect($this->input('<probeVALUE>', 'https://fixture.test/', payload: '%3CprobeVALUE%3E')));
    }

    #[DataProvider('ambiguousSources')]
    public function testAmbiguousInputDoesNotPretendUniqueMapping(string $url, ?string $payload, ?string $marker): void
    {
        $signals = (new ReflectionTriageDetector())->detect($this->input('probeVALUE', $url, payload: $payload, marker: $marker));
        self::assertCount(1, $signals);
        self::assertTrue($signals[0]['attributes']['contextRequired']);
        self::assertContains('ambiguous_input_source', $signals[0]['attributes']['reviewReasons']);
    }

    public static function ambiguousSources(): iterable
    {
        yield ['https://fixture.test/?q=probeVALUE&q=otherVALUE', null, null];
        yield ['https://fixture.test/?q=probeVALUE&x=probeVALUE', null, null];
        yield ['https://fixture.test/?q=probeVALUE', 'probeVALUE', null];
        yield ['https://fixture.test/', 'probeVALUE', 'probeVALUE'];
    }

    public function testShortValuesRequireBoundariesAndReview(): void
    {
        $signals = (new ReflectionTriageDetector())->detect($this->input('cat 12 cat12 123 12', 'https://fixture.test/?q=12'));
        self::assertCount(2, $signals);
        self::assertTrue($signals[0]['attributes']['contextRequired']);
        self::assertContains('short_input_value', $signals[0]['attributes']['reviewReasons']);
    }

    public function testCapturedDomIsNeverAdvertisedAsRawContext(): void
    {
        $input = $this->input(null, dom: 'Ä🙂 probeVALUE');
        $signals = (new ReflectionTriageDetector())->detect($input, 'active');
        self::assertCount(1, $signals);
        self::assertSame('dom_text', $signals[0]['spans'][0]['representation']);
        self::assertSame(3, $signals[0]['spans'][0]['start']);
        self::assertSame('captured_dom_text', $signals[0]['attributes']['context']);
        self::assertTrue($signals[0]['attributes']['contextRequired']);
        self::assertSame('active', $signals[0]['mode']);
        EvidenceSignal::validateAgainstInput($signals[0], $input);
    }

    public function testBoundsAndMissingContextAreExplicit(): void
    {
        $detector = new ReflectionTriageDetector();
        self::assertCount(24, $detector->detect($this->input(str_repeat('probeVALUE ', 5000))));
        $signals = $detector->detect($this->input(str_repeat('x', 9000).' probeVALUE'));
        self::assertSame('unknown', $signals[0]['attributes']['context']);
        self::assertContains('context_window_exhausted', $signals[0]['attributes']['reviewReasons']);
        $signals = $detector->detect($this->input('probeVALUE', contentType: 'application/json'));
        self::assertSame('unknown', $signals[0]['attributes']['context']);
        self::assertSame([], $detector->detect($this->input(null)));
        self::assertSame([], $detector->detect($this->input('probeVALUE', 'https://fixture.test/')));
    }
    public function testSingleBase64InputTransformationRetainsRawAttributeAndQuoteBoundary(): void
    {
        $value = "'><fixture-tag alt=\"\">-synthetic";
        $input = $this->input('<a href="/entry?returnto='.$value.'">open</a>', 'https://fixture.test/?returnto='.rawurlencode(base64_encode($value)));
        $signals = (new ReflectionTriageDetector())->detect($input);
        self::assertCount(1, $signals);
        $signal = $signals[0];
        self::assertSame($value, $signal['spans'][0]['excerpt']);
        self::assertSame(['base64_decode_once'], $signal['attributes']['comparisonTransformations']);
        self::assertSame('transformed_correspondence', $signal['attributes']['inputValueEquality']);
        self::assertSame('base64_decoded_input', $signal['attributes']['encoding']);
        self::assertSame('attribute', $signal['attributes']['context']);
        self::assertSame('href', $signal['attributes']['attributeName']);
        self::assertSame('double', $signal['attributes']['attributeQuote']);
        self::assertTrue($signal['attributes']['inputCrossesAttributeBoundary']);
        self::assertFalse($signal['attributes']['xssEstablished']);
        self::assertFalse($signal['attributes']['executionProven']);
        EvidenceSignal::validateAgainstInput($signal, $input);
    }

    public function testBase64IsCanonicalCompleteAndNotRepeated(): void
    {
        $detector = new ReflectionTriageDetector();
        $value = '<fixture>probeVALUE</fixture>';
        $base64 = base64_encode($value);
        foreach ([$base64.'!', substr($base64, 0, -1), base64_encode($base64), rawurlencode($base64)] as $encoded) {
            self::assertSame([], $detector->detect($this->input($value, 'https://fixture.test/?q='.rawurlencode($encoded))));
        }
        self::assertSame([], $detector->detect($this->input('probeVALUE', 'https://fixture.test/?q='.rawurlencode($base64))));
        self::assertSame([], $detector->detect($this->input(htmlspecialchars($value, ENT_QUOTES), 'https://fixture.test/?q='.rawurlencode($base64))));
        $signals = $detector->detect($this->input($base64, 'https://fixture.test/?q='.rawurlencode($base64)));
        self::assertSame('literal', $signals[0]['attributes']['encoding']);
        self::assertSame([], $signals[0]['attributes']['comparisonTransformations']);
        $signals = $detector->detect($this->input('probeVALUE', 'https://fixture.test/?q='.rawurlencode(base64_encode('probeVALUE'))));
        self::assertSame('text', $signals[0]['attributes']['context']);
        self::assertArrayNotHasKey('attributeName', $signals[0]['attributes']);
        $evaluation = $detector->evaluate($this->input(str_repeat('x', 1100), 'https://fixture.test/?q='.base64_encode(str_repeat('x', 1100))));
        self::assertSame('limited', $evaluation->status);
        self::assertSame([], $evaluation->signals);
    }

    public function testLiteralAndBase64CorrespondencesRetainTheirParameterIdentities(): void
    {
        $signals = (new ReflectionTriageDetector())->detect($this->input('probeVALUE', 'https://fixture.test/?a=probeVALUE&b=cHJvYmVWQUxVRQ%3D%3D'));
        self::assertCount(2, $signals);
        self::assertSame('a', $signals[0]['attributes']['inputSources'][0]['parameter']);
        self::assertSame([], $signals[0]['attributes']['comparisonTransformations']);
        self::assertSame('b', $signals[1]['attributes']['inputSources'][0]['parameter']);
        self::assertSame(['base64_decode_once'], $signals[1]['attributes']['comparisonTransformations']);
    }

}
