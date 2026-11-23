<?php

declare(strict_types=1);

namespace App\Tests\Cribrum;

use App\Cribrum\CribrumClassifier;
use App\Cribrum\Detector\SqlTriageDetector;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlQueryDiagnosticFamiliesTest extends TestCase
{
    private const ERROR = 'SQLSTATE[2201X]: <<Unknown error>>: 7 ERROR:  OFFSET must not be negative';
    private const QUERY = "SELECT distinct n.id, COALESCE(n.created_at,'2000/01/01') AS date_key,\r\n c.title\r\nFROM fixture_records n\r\n LEFT JOIN fixture_categories c ON c.id=n.category_id\r\nWHERE n.active=true\r\nORDER BY date_key DESC LIMIT 15 OFFSET -15";

    #[DataProvider('queryOrderings')]
    public function testNativeQueryDisclosureDoesNotNeedARequestBinding(string $query): void
    {
        $input = $this->input($this->body($query));
        $result = (new SqlTriageDetector())->evaluateQueryDisclosures($input, 'active');
        $signals = $this->familySignals($result->signals);
        self::assertCount(1, $signals);
        $signal = $signals[0];
        self::assertSame('internal_information_exposed', $signal['code']);
        self::assertSame('query', $signal['attributes']['detailKind']);
        self::assertTrue($signal['attributes']['concreteInternals']);
        self::assertSame('no_input_binding_claimed', $signal['attributes']['inputAssessment']);
        self::assertSame($query, $signal['spans'][1]['excerpt']);
        self::assertSame(['error', 'query'], array_column($signal['spans'], 'role'));
        self::assertSame('query_in_diagnostic', $signal['relations'][0]['type']);
        EvidenceSignal::validateAgainstInput($signal, $input);
        $this->assertExactSpans($input, $signal['spans']);
    }

    public static function queryOrderings(): iterable
    {
        yield 'limit then offset' => [self::QUERY];
        yield 'offset then limit multiline' => [str_replace('LIMIT 15 OFFSET -15', "OFFSET -15\t\r\n LIMIT 15", self::QUERY)];
        yield 'offset alone' => [str_replace('LIMIT 15 OFFSET -15', 'OFFSET -12', self::QUERY)];
        yield 'limit all and terminal semicolon' => [str_replace('LIMIT 15 OFFSET -15', 'LIMIT ALL OFFSET -3;', self::QUERY)];
    }

    public function testQueryRemainsIndependentOfHugeFollowingTraceAndRequestDump(): void
    {
        $body = $this->body().'<pre>Array'."\n(\n [0] =&gt; ".str_repeat('unrelated trace data ', 2400)."\n [request] =&gt; unbound_fixture_marker\n)</pre>";
        $input = $this->input($body);
        $result = (new SqlTriageDetector())->evaluateQueryDisclosures($input, 'active');
        $signals = $this->familySignals($result->signals);
        self::assertCount(1, $signals);
        self::assertSame(self::QUERY, $signals[0]['spans'][1]['excerpt']);
        self::assertStringNotContainsString('unbound_fixture_marker', json_encode($signals));
        self::assertSame('limited', $result->status, 'The independent large trace limit must remain visible.');
    }

    public function testUnicodeAndOneLocalEntityDecodePreserveRawSpans(): void
    {
        $query = str_replace('n.active=true', "n.title='München😀' AND n.active=true", self::QUERY);
        $encoded = htmlspecialchars($query, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = '<p>Ä😀 fixture</p>'.str_replace(self::ERROR, htmlspecialchars(self::ERROR, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $this->body($encoded));
        $input = $this->input($body);
        $signals = $this->familySignals((new SqlTriageDetector())->evaluateQueryDisclosures($input, 'active')->signals);
        self::assertCount(1, $signals);
        self::assertSame($encoded, $signals[0]['spans'][1]['excerpt']);
        self::assertSame(['html_entities_once_for_comparison'], $signals[0]['relations'][0]['transformations']);
        $this->assertExactSpans($input, $signals[0]['spans']);
        $twice = $this->body(htmlspecialchars($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        self::assertSame([], $this->familySignals((new SqlTriageDetector())->evaluateQueryDisclosures($this->input($twice), 'active')->signals));
    }

    #[DataProvider('nonQueries')]
    public function testQuotedCommentedMalformedAndUnrelatedQueryShapesRemainUnqualified(string $query): void
    {
        $result = (new SqlTriageDetector())->evaluateQueryDisclosures($this->input($this->body($query)), 'active');
        self::assertSame([], $this->familySignals($result->signals));
        self::assertContains('unsupported_grammar', $this->issueCodes($result->familyOutcomes));
    }

    public static function nonQueries(): iterable
    {
        yield 'prose' => ['SELECT your model FROM our catalogue'];
        yield 'no relation' => ['SELECT 1 OFFSET -15'];
        yield 'offset used as relation' => ['SELECT id FROM OFFSET -15'];
        yield 'quoted fake relation and offset as relation' => ["SELECT ' FROM fixtures' FROM OFFSET -15"];
        yield 'no negative offset' => [str_replace('OFFSET -15', 'OFFSET 15', self::QUERY)];
        yield 'inconsistent offset' => [str_replace('OFFSET -15', 'OFFSET -0', self::QUERY)];
        yield 'offset inside string' => ["SELECT id FROM fixtures WHERE title='OFFSET -15'"];
        yield 'offset inside bracketed identifier' => ['SELECT id FROM fixtures ORDER BY [OFFSET -15]'];
        yield 'offset inside qualified identifier' => ['SELECT id FROM fixtures ORDER BY fixtures.OFFSET -15'];
        yield 'offset inside whitespace qualified identifier' => ['SELECT id FROM fixtures ORDER BY fixtures. OFFSET -15'];
        yield 'offset following comparison operator' => ['SELECT id FROM fixtures WHERE id = OFFSET -15'];
        yield 'offset following arithmetic operator' => ['SELECT id FROM fixtures WHERE id + OFFSET -15'];
        yield 'offset following unfinished clause' => ['SELECT id FROM fixtures WHERE OFFSET -15'];
        yield 'offset glued to ascii name' => ['SELECT id FROM fixtures ORDER BY xOFFSET -15'];
        yield 'offset glued to unicode name' => ['SELECT id FROM fixtures ORDER BY äOFFSET -15'];
        yield 'FROM only inside string' => ["SELECT 'value FROM fixtures' OFFSET -15"];
        yield 'FROM only inside subquery' => ['SELECT (SELECT id FROM fixtures) OFFSET -15'];
        yield 'unclosed parentheses' => ['SELECT (id FROM fixtures OFFSET -15'];
        yield 'unbalanced closing parentheses' => ['SELECT id) FROM fixtures OFFSET -15'];
        yield 'unterminated string' => ["SELECT id FROM fixtures WHERE title='unterminated OFFSET -15"];
        yield 'closed block comment' => ['SELECT id FROM fixtures /* data */ OFFSET -15'];
        yield 'line comment' => ["SELECT id FROM fixtures -- data\n OFFSET -15"];
        yield 'hash comment' => ["SELECT id FROM fixtures # data\n OFFSET -15"];
        yield 'second statement' => ['SELECT id FROM fixtures; SELECT id FROM others OFFSET -15'];
        yield 'unsupported dollar quoting' => ['SELECT $$FROM fixtures$$ OFFSET -15'];
    }

    #[DataProvider('excludedCarriers')]
    public function testDocumentationScriptsAttributesCommentsAndQueryLiteralsStayExcluded(string $before, string $after): void
    {
        $result = (new SqlTriageDetector())->evaluateQueryDisclosures($this->input($before.$this->body().$after), 'active');
        self::assertSame([], $this->familySignals($result->signals));
    }

    public static function excludedCarriers(): iterable
    {
        yield 'script' => ['<script>const value="', '";</script>'];
        yield 'textarea' => ['<textarea>', '</textarea>'];
        yield 'template' => ['<template>', '</template>'];
        yield 'code' => ['<code>', '</code>'];
        yield 'samp' => ['<samp>', '</samp>'];
        yield 'docs class' => ['<section class="docs">', '</section>'];
        yield 'documentation role' => ['<section role="documentation">', '</section>'];
        yield 'comment' => ['<!-- ', ' -->'];
        yield 'attribute' => ['<div data-error="', '"></div>'];
        yield 'example across blank lines' => ["Example:\n\n", ''];
        yield 'query literal across blank lines' => ["SELECT '\n\n", "'"];
    }

    public function testOriginalFinalAndPayloadSuppliedQueriesCannotProveDisclosure(): void
    {
        $input = $this->input($this->body());
        foreach ([self::QUERY, self::ERROR, $this->body(), "prefix\n".self::QUERY."\nsuffix"] as $supplied) {
            foreach ([['payload' => $supplied], ['url' => 'https://fixture.invalid/?message='.rawurlencode($supplied)],
                ['finalUrl' => 'https://fixture.invalid/redirect?message='.rawurlencode($supplied)]] as $change) {
                $result = (new SqlTriageDetector())->evaluateQueryDisclosures($this->with($input, $change), 'active');
                self::assertSame([], $this->familySignals($result->signals));
            }
        }
    }

    public function testNativeHeaderMustStartARenderedLine(): void
    {
        $body = 'This is '.substr($this->body(), strlen('<br/>'));
        $result = (new SqlTriageDetector())->evaluateQueryDisclosures($this->input($body), 'active');
        self::assertSame([], $this->familySignals($result->signals));
        $longQueryPrefix = "SELECT '".str_repeat('padding ', 70)."\n".substr($this->body(), strlen('<br/>'));
        self::assertSame([], $this->familySignals((new SqlTriageDetector())->evaluateQueryDisclosures($this->input($longQueryPrefix), 'active')->signals));
    }

    public function testRequestNamesAndFormattingCannotSupplyTheWholeQuery(): void
    {
        $input = $this->input($this->body());
        foreach ([['url' => 'https://fixture.invalid/?'.rawurlencode(self::QUERY).'='],
            ['finalUrl' => 'https://fixture.invalid/?'.rawurlencode(self::QUERY).'='],
            ['payload' => str_replace('distinct n.id', 'distinct <b>n.id</b>', self::QUERY)]] as $change) {
            $result = (new SqlTriageDetector())->evaluateQueryDisclosures($this->with($input, $change), 'active');
            self::assertSame([], $this->familySignals($result->signals));
        }
    }

    public function testBadRequestContextCannotEraseEchoGuards(): void
    {
        $input = $this->input($this->body());
        foreach (['&bad=%FF', '&'.implode('&', array_fill(0, 129, 'other=value'))] as $suffix) {
            foreach (['url', 'finalUrl'] as $field) {
                $result = (new SqlTriageDetector())->evaluateQueryDisclosures($this->with($input, [$field => 'https://fixture.invalid/?message='.rawurlencode(self::QUERY).$suffix]), 'active');
                self::assertSame([], $this->familySignals($result->signals));
                self::assertContains($suffix === '&bad=%FF' ? 'input_invalid_encoding' : 'input_budget', $this->issueCodes($result->familyOutcomes));
            }
        }
    }

    public function testMissingBreakClippingAndMatchBudgetsRemainVisible(): void
    {
        foreach ([
            [substr($this->body(), 0, -4), 'context_incomplete'],
            [$this->body(self::QUERY.str_repeat(' ', 4200)), 'context_budget'],
            [$this->body(self::QUERY.' '.str_repeat('😀', 1100)), 'context_budget'],
            [str_repeat($this->body(), 13), 'match_budget'],
        ] as [$body, $code]) {
            $result = (new SqlTriageDetector())->evaluateQueryDisclosures($this->input($body), 'active');
            self::assertContains($code, $this->issueCodes($result->familyOutcomes));
            self::assertSame('limited', $result->status);
        }
    }

    public function testDefaultAndShadowGetOnlyQueryDisclosureAndActiveGetsNoSqlInjectionFact(): void
    {
        $input = $this->input($this->body().'<pre>[pag] =&gt; unbound_fixture_marker</pre>');
        foreach (['default', 'shadow', 'active'] as $mode) {
            $classifier = $mode === 'default' ? new CribrumClassifier('/nonexistent')
                : new CribrumClassifier('/nonexistent', dirname(__DIR__, 2).'/resources/cribrum/candidates', $mode);
            $result = $classifier->classify($input)->toArray();
            $claims = array_column($result['suggestions'], null, 'claim');
            self::assertSame('observed', $claims['information_disclosure']['state']);
            self::assertArrayNotHasKey('sql_injection', $claims);
            self::assertNotContains('input_in_sql_error', array_column($result['signals'], 'code'));
            self::assertNotContains('sql_input_structure_indication', array_column($result['signals'], 'code'));
            if ($mode === 'active') {
                $info = array_values(array_filter($result['signals'], static fn (array $s): bool => ($s['code'] ?? null) === 'internal_information_exposed'));
                self::assertNotEmpty($info);
            }
        }
    }

    private function body(string $query = self::QUERY): string
    {
        return '<br/>'.self::ERROR.', query was: '.$query.'<br>';
    }

    private function input(string $body): ObservationInput
    {
        return new ObservationInput('https://fixture.invalid/?pag=unbound_fixture_marker', $body, null, 'text/html', 200, null, null, null, false, null);
    }

    private function with(ObservationInput $input, array $changes): ObservationInput
    {
        return ObservationInput::fromArray([...$input->toArray(), ...$changes]);
    }

    private function familySignals(array $signals): array
    {
        return array_values(array_filter($signals, static fn (array $s): bool => ($s['attributes']['queryExtraction'] ?? null) === 'native_query_was_break'));
    }

    private function issueCodes(array $families): array
    {
        $codes = [];
        foreach ($families as $family) foreach ($family['stages'] as $stage) foreach ($stage['issues'] as $issue) $codes[] = $issue['code'];
        return $codes;
    }

    private function assertExactSpans(ObservationInput $input, array $spans): void
    {
        foreach ($spans as $span) {
            self::assertSame('raw', $span['representation']);
            self::assertSame(mb_substr($input->rawHtml, $span['start'], $span['length'], 'UTF-8'), $span['excerpt']);
        }
    }
}
