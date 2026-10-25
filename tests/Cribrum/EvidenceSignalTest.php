<?php

namespace App\Tests\Cribrum;

use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceSignalTest extends TestCase
{
    public function testPublishedSyntheticContractExampleMatchesCapturedInput(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/cribrum/evidence-signal-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['evidence'], EvidenceSignal::validateAgainstInput($fixture['evidence'], ObservationInput::fromArray($fixture['input'])));
        self::assertSame('diagnostic_only', $fixture['evidence']['attributes']['qualification']);
    }

    private function input(?string $raw = 'Ä🙂 SQL error'): ObservationInput
    {
        return new ObservationInput('https://fixture.invalid/?q=SQL', $raw, 'Captured DOM', 'text/html', 500, null, null, null, false, null);
    }

    public function testUnicodeOffsetsAndRawByteHashArePreserved(): void
    {
        $raw = "Ä🙂 SQL error\r\n";
        $span = EvidenceSignal::span($raw, 3, 3);
        self::assertSame('SQL', $span['excerpt']);
        self::assertSame(hash('sha256', $raw), $span['representationSha256']);
        self::assertSame(14, $span['representationLength']);
        $signal = EvidenceSignal::make('sql_error_observed', $this->input($raw), [$span]);
        self::assertSame($signal, EvidenceSignal::validateAgainstInput($signal, $this->input($raw)));
        self::assertSame('observation_evidence', $signal['kind']);
        self::assertArrayNotHasKey('findingId', $signal);
        self::assertArrayNotHasKey('raw', $signal);
        self::assertTrue($signal['shadow']);
    }

    public function testIdentityIsStableAcrossMapOrderingAndJsonRoundtrip(): void
    {
        $span = EvidenceSignal::span('Ä🙂 SQL error', 3, 3);
        $first = EvidenceSignal::make('sql_error_observed', $this->input(), [$span], ['vendor' => 'synthetic', 'context' => ['b' => 2, 'a' => 1]]);
        $second = EvidenceSignal::make('sql_error_observed', $this->input(), [$span], ['context' => ['a' => 1, 'b' => 2], 'vendor' => 'synthetic']);
        self::assertSame($first['evidenceId'], $second['evidenceId']);
        self::assertSame($first, EvidenceSignal::validate(json_decode(json_encode($first, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)));
    }

    public function testRelationsResolveIndicesAndKeepTransformationEvidence(): void
    {
        $raw = 'error SQL';
        $signal = EvidenceSignal::make('input_in_sql_error', $this->input($raw), [EvidenceSignal::span($raw, 0, 9), EvidenceSignal::span($raw, 6, 3)], [], [
            ['type' => 'contains_input', 'from' => 0, 'to' => 1, 'transformations' => ['url_query_decode_once']],
        ]);
        self::assertSame('s0', $signal['relations'][0]['from']);
        self::assertSame('s1', $signal['relations'][0]['to']);
        self::assertSame(['url_query_decode_once'], $signal['relations'][0]['transformations']);
    }

    public function testActualCapturedDomCanBeBoundWithoutInventingRaw(): void
    {
        $span = EvidenceSignal::span('Captured DOM', 0, 8);
        $span['representation'] = 'dom_text';
        $signal = EvidenceSignal::make('product_context', $this->input(null), [$span]);
        self::assertNull($signal['artifactSha256']);
        self::assertSame('dom_text', $signal['representation']);
        self::assertSame($signal, EvidenceSignal::validateAgainstInput($signal, $this->input(null)));
    }

    public function testNoRawArtifactCannotUseSyntheticDomAsRaw(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EvidenceSignal::make('product_context', $this->input(null), [EvidenceSignal::span('Captured DOM', 0, 8)]);
    }

    public function testChangedSourceWithIdenticalExcerptIsRejected(): void
    {
        $signal = EvidenceSignal::make('sql_error_observed', $this->input(), [EvidenceSignal::span('Ä🙂 SQL error', 3, 3)]);
        $this->expectException(\InvalidArgumentException::class);
        EvidenceSignal::validateAgainstInput($signal, $this->input('Ä🙂 SQL other'));
    }

    public function testMakeRejectsIncorrectOriginalOffsetsEvenWithValidExcerptHash(): void
    {
        $span = EvidenceSignal::span('Ä🙂 SQL error', 3, 3);
        $span['start'] = 2;
        $this->expectException(\InvalidArgumentException::class);
        EvidenceSignal::make('sql_error_observed', $this->input(), [$span]);
    }

    #[DataProvider('invalidSpans')]
    public function testInvalidSpanIsRejected(string $raw, int $start, int $length): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EvidenceSignal::span($raw, $start, $length);
    }

    public static function invalidSpans(): iterable
    {
        yield 'invalid utf8' => ["\xff", 0, 1];
        yield 'negative start' => ['abc', -1, 1];
        yield 'empty span' => ['abc', 1, 0];
        yield 'past end' => ['abc', 2, 2];
        yield 'integer overflow bound' => ['abc', PHP_INT_MAX, PHP_INT_MAX];
    }

    #[DataProvider('tampering')]
    public function testPersistedTamperingIsRejected(string $kind): void
    {
        $signal = EvidenceSignal::make('sql_error_observed', $this->input(), [EvidenceSignal::span('Ä🙂 SQL error', 3, 3)]);
        match ($kind) {
            'hash' => $signal['artifactSha256'] = str_repeat('0', 64),
            'excerpt' => $signal['spans'][0]['excerpt'] = 'BAD',
            'offset' => $signal['spans'][0]['start'] = 500,
            'mode' => $signal['mode'] = 'active',
            'behavior' => $signal['kind'] = 'behavior',
            'relation' => $signal['relations'][] = ['type' => 'contains', 'from' => 's0', 'to' => 'missing'],
            'duplicate' => $signal['spans'][] = $signal['spans'][0],
        };
        $this->expectException(\InvalidArgumentException::class);
        EvidenceSignal::validate($signal);
    }

    public static function tampering(): iterable
    {
        foreach (['hash', 'excerpt', 'offset', 'mode', 'behavior', 'relation', 'duplicate'] as $kind) yield $kind => [$kind];
    }
}
