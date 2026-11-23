<?php

declare(strict_types=1);

namespace App\Tests\Cribrum;

use App\Cribrum\ClaimSuggestion;
use App\Cribrum\ObservationInput;
use App\Cribrum\SqlAccessExpressionSuspicion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlAccessExpressionSuspicionTest extends TestCase
{
    private const VALUE = '\'-->\'\"></title></noscript></script><img src=0 onerror=alert(\'fixtureMARK\')>';

    #[DataProvider('comparisonExpressions')]
    public function testNativeLocalizedExpressionSuppliesErrorAndSuspicion(string $expression): void
    {
        $input = $this->input(expression: $expression);
        $result = (new SqlAccessExpressionSuspicion())->evaluate($input);
        self::assertSame([], $result['issues']);
        $claims = array_column($result['suggestions'], null, 'claim');
        self::assertSame('observed', $claims['error_disclosure']->state);
        self::assertSame([SqlAccessExpressionSuspicion::DIAGNOSTIC_RULE_ID], $claims['error_disclosure']->ruleIds);
        self::assertSame('suspected', $claims['sql_injection']->state);
        self::assertSame([SqlAccessExpressionSuspicion::RULE_ID], $claims['sql_injection']->ruleIds);
        self::assertCount(4, $claims['sql_injection']->spans);
        self::assertSame(htmlspecialchars(self::VALUE, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $claims['sql_injection']->spans[2]['excerpt']);
        self::assertStringContainsString('decoded once', $claims['sql_injection']->reasons[0]);
        self::assertStringContainsString('no complete SQL statement', $claims['sql_injection']->reasons[0]);
        $this->assertExactSpans($input, $result['suggestions']);
    }

    public static function comparisonExpressions(): iterable
    {
        yield 'original position with boolean filters' => ['(record_id='.self::VALUE.') and ((hidden = false) and (published = true))'];
        yield 'comparison alone' => ['record_id='.self::VALUE];
        yield 'later operand after numeric filter' => ['enabled=1 AND (record_id = '.self::VALUE.')'];
        yield 'qualified column and OR' => ['(records.record_id <> '.self::VALUE.') OR hidden = false'];
        yield 'bracketed column and static string' => ["([record id]=".self::VALUE.") AND title='other text'"];
    }

    public function testNativeEnglishSiblingAndPlainCarrierAreSupported(): void
    {
        $base = $this->input();
        foreach (['Syntax error in string in query expression', 'Syntax error (missing operator) in query expression',
            'Syntax error in query expression'] as $grammar) {
            $raw = "Microsoft Access Database Engine error '80040e14'\n".$grammar." '"
                .htmlspecialchars('(record_id='.self::VALUE.')', ENT_QUOTES | ENT_HTML5, 'UTF-8')."'.\n/record.asp, line 42";
            $input = $this->with($base, ['rawHtml' => $raw]);
            self::assertNotNull((new SqlAccessExpressionSuspicion())->suggest($input), $grammar);
        }
    }

    public function testUnicodeAndSeparatePayloadEchoesRetainExactRawSpans(): void
    {
        $value = str_replace('fixtureMARK', 'fíxtureMARK😀', self::VALUE);
        $input = $this->input($value);
        $input = $this->with($input, ['rawHtml' => '<p>Ä😀</p><!-- echoed '.$value.' --><!-- echoed '.$value.' -->'.$input->rawHtml]);
        $result = (new SqlAccessExpressionSuspicion())->evaluate($input);
        self::assertCount(2, $result['suggestions']);
        $this->assertExactSpans($input, $result['suggestions']);
    }

    #[DataProvider('nonOperands')]
    public function testNonOperandAndUnsupportedExpressionsRetainOnlyNativeError(string $expression): void
    {
        $result = (new SqlAccessExpressionSuspicion())->evaluate($this->input(expression: $expression));
        self::assertSame(['error_disclosure'], array_column($result['suggestions'], 'claim'));
        self::assertSame([], $result['issues']);
    }

    public static function nonOperands(): iterable
    {
        yield 'quoted request data' => ["record_id='".self::VALUE."'"];
        yield 'double quoted request data' => ['record_id="'.self::VALUE.'"'];
        yield 'bracketed identifier' => ['record_id=['.self::VALUE.']'];
        yield 'commented input' => ['record_id=1 /* '.self::VALUE.' */'];
        yield 'input as column' => [self::VALUE.'=1'];
        yield 'function argument' => ['record_id=CLng('.self::VALUE.')'];
        yield 'unterminated prefix string' => ["title='unclosed AND record_id=".self::VALUE];
        yield 'identifier prefix' => ['record_id=prefix'.self::VALUE];
        yield 'identifier suffix' => ['record_id='.self::VALUE.'suffix'];
        yield 'repeated input' => ['record_id='.self::VALUE.' OR other_id='.self::VALUE];
        yield 'second statement' => ['record_id='.self::VALUE.'; DELETE FROM records'];
        yield 'complete SELECT is another family' => ['SELECT id FROM records WHERE record_id='.self::VALUE];
        yield 'missing closing expression' => ['(record_id='.self::VALUE];
        yield 'unknown expression tail' => ['record_id='.self::VALUE.' EXTRA'];
        yield 'unsupported function-only sibling' => ['record_id='.self::VALUE.' AND date_part=Year(Now())'];
    }

    #[DataProvider('ambiguousParameters')]
    public function testAmbiguousNonScalarAndUnboundRequestsRetainOnlyNativeError(string $query): void
    {
        $result = (new SqlAccessExpressionSuspicion())->evaluate($this->with($this->input(), ['url' => 'https://fixture.invalid/record.asp?'.$query]));
        self::assertSame(['error_disclosure'], array_column($result['suggestions'], 'claim'));
        self::assertSame([], $result['issues']);
    }

    public static function ambiguousParameters(): iterable
    {
        $value = rawurlencode(self::VALUE);
        yield 'duplicate key' => ['record_id='.$value.'&record_id=other'];
        yield 'encoded key collision' => ['record_id='.$value.'&%72ecord_id=other'];
        yield 'normalized key collision' => ['record_id='.$value.'&record.id=other'];
        yield 'array collision' => ['record_id='.$value.'&record_id[0]=other'];
        yield 'array leaf only' => ['record_id[0]='.$value];
        yield 'append array only' => ['record_id[]='.$value];
        yield 'same value different key' => ['record_id='.$value.'&other='.$value];
        yield 'another value contains input' => ['record_id='.$value.'&other='.rawurlencode('prefix'.self::VALUE)];
        yield 'requires second URL decode' => ['record_id='.rawurlencode($value)];
        yield 'not supplied in URL' => ['record_id=different'];
        yield 'no query' => [''];
    }

    public function testUnrelatedRepeatedAndArrayRootsDoNotDiscardUniqueScalar(): void
    {
        $input = $this->input();
        $input = $this->with($input, ['url' => $input->url.'&filter[active]=0&filter[active]=1&filter[nested][id]=2']);
        self::assertNotNull((new SqlAccessExpressionSuspicion())->suggest($input));
    }

    public function testSecondEntityDecodeDoesNotEstablishInputBinding(): void
    {
        $input = $this->input();
        $encoded = htmlspecialchars('(record_id='.self::VALUE.') AND hidden=false', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $twice = htmlspecialchars($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $result = (new SqlAccessExpressionSuspicion())->evaluate($this->with($input, ['rawHtml' => str_replace($encoded, $twice, $input->rawHtml)]));
        self::assertSame(['error_disclosure'], array_column($result['suggestions'], 'claim'));
    }

    public function testOrdinaryNumbersMarkersAndLongPlainNamesAreNotSuspicion(): void
    {
        foreach (['42', 'fixtureMARK', 'long_fixture_record_name_0123456789_example', str_repeat('a', 64)."'"] as $value) {
            $result = (new SqlAccessExpressionSuspicion())->evaluate($this->input($value));
            self::assertSame(['error_disclosure'], array_column($result['suggestions'], 'claim'), $value);
        }
    }

    public function testMissingNativeWrapperGrammarOrSourceLocationRejectsCarrier(): void
    {
        $input = $this->input();
        foreach ([
            str_replace('Microsoft Access Database Engine', 'Database Engine', $input->rawHtml),
            str_replace('80040e14', '80004005', $input->rawHtml),
            str_replace("error '80040e14'", "error '80040e14", $input->rawHtml),
            str_replace("Errore di sintassi nella stringa nell'espressione della query", 'Generic syntax error', $input->rawHtml),
            str_replace('/record.asp', '/record.txt', $input->rawHtml),
            str_replace(', line 42', ', line unknown', $input->rawHtml),
            str_replace('/record.asp', '', $input->rawHtml),
            $input->rawHtml.$input->rawHtml,
            str_replace("error '80040e14'", "error '80040e14' unrelated product text", $input->rawHtml),
            substr($input->rawHtml, 0, strpos($input->rawHtml, "'.</font>")),
        ] as $raw) {
            self::assertSame([], (new SqlAccessExpressionSuspicion())->evaluate($this->with($input, ['rawHtml' => $raw]))['suggestions'], $raw);
        }
        self::assertSame([], (new SqlAccessExpressionSuspicion())->evaluate($this->with($input, ['rawHtml' => null, 'domText' => strip_tags($input->rawHtml)]))['suggestions']);
    }

    #[DataProvider('nonDiagnosticCarriers')]
    public function testScriptsDocsCommentsAttributesAndQueryDataCannotSupplyNativeError(string $before, string $after): void
    {
        $input = $this->input();
        self::assertSame([], (new SqlAccessExpressionSuspicion())->evaluate($this->with($input, ['rawHtml' => $before.$input->rawHtml.$after]))['suggestions']);
    }

    public static function nonDiagnosticCarriers(): iterable
    {
        yield 'script data' => ['<script>const data="', '";</script>'];
        yield 'style data' => ['<style>', '</style>'];
        yield 'textarea' => ['<textarea>', '</textarea>'];
        yield 'code sample' => ['<code>', '</code>'];
        yield 'samp' => ['<samp>', '</samp>'];
        yield 'template' => ['<template>', '</template>'];
        yield 'docs ancestor' => ['<section class="docs">', '</section>'];
        yield 'documentation ancestor' => ['<section class="documentation">', '</section>'];
        yield 'example prefix' => ['Example: ', ''];
        yield 'example prefix blank lines' => ["Example:\n\n", ''];
        yield 'example prefix whitespace lines' => ["Example:\n ", ''];
        yield 'query literal prefix' => ["SELECT '", "'"];
        yield 'multiline query literal prefix' => ["SELECT 'retained query data\n", "'"];
        yield 'comment data' => ['<!-- ', ' -->'];
        yield 'attribute data' => ['<div data-value="', '"></div>'];
    }

    public function testRequestSuppliedWholeDiagnosticOrBodyDoesNotEstablishServerError(): void
    {
        $input = $this->input();
        $message = "Errore di sintassi nella stringa nell'espressione della query '(record_id=".self::VALUE.") AND hidden=false'.";
        foreach ([$input->rawHtml, strip_tags($input->rawHtml), $message] as $supplied) {
            foreach ([['payload' => $supplied], ['url' => $input->url.'&message='.rawurlencode($supplied)],
                ['finalUrl' => 'https://fixture.invalid/redirect.asp?message='.rawurlencode($supplied)]] as $change) {
                self::assertSame([], (new SqlAccessExpressionSuspicion())->evaluate($this->with($input, $change))['suggestions']);
            }
        }
    }

    public function testEachNativeCarrierPartMustRemainOutsideDocumentation(): void
    {
        $input = $this->input();
        foreach (["error '80040e14'", "Errore di sintassi nella stringa nell'espressione della query", '/record.asp'] as $part) {
            foreach (['class="docs"', 'id="source-code"', 'role="documentation"'] as $attribute) {
                $raw = str_replace($part, '<font '.$attribute.'>'.$part.'</font>', $input->rawHtml);
                self::assertSame([], (new SqlAccessExpressionSuspicion())->evaluate($this->with($input, ['rawHtml' => $raw]))['suggestions']);
            }
        }
    }

    public function testSuppliedNativeBuildingBlocksAndTerminatorsCannotAuthenticateCarrier(): void
    {
        $input = $this->input();
        foreach (['Microsoft Access Database Engine', "Errore di sintassi nella stringa nell'espressione della query",
            '<font>/fake.asp</font>, line 25'] as $supplied) {
            self::assertSame([], (new SqlAccessExpressionSuspicion())->evaluate($this->with($input,
                ['finalUrl' => $input->url.'&message='.rawurlencode($supplied)]))['suggestions']);
        }
    }

    public function testInvalidOrExcessiveRequestContextCannotBypassSuppliedDiagnosticGuard(): void
    {
        $input = $this->input();
        foreach (['&bad=%FF', '&'.implode('&', array_fill(0, 129, 'other=value'))] as $suffix) {
            foreach (['url', 'finalUrl'] as $field) {
                self::assertSame([], (new SqlAccessExpressionSuspicion())->evaluate($this->with($input,
                    [$field => $input->url.$suffix]))['suggestions']);
            }
        }
    }

    private function input(string $value = self::VALUE, ?string $expression = null): ObservationInput
    {
        $expression ??= '(record_id='.$value.') AND hidden=false';
        $raw = '<div><font face="Arial" size="2">Microsoft Access Database Engine</font> '
            ."<font face=\"Arial\" size=\"2\">error '80040e14'</font>\n<p>\n"
            ."<font face=\"Arial\" size=\"2\">Errore di sintassi nella stringa nell'espressione della query '"
            .htmlspecialchars($expression, ENT_QUOTES | ENT_HTML5, 'UTF-8')."'.</font>\n<p>\n"
            .'<font face="Arial" size="2">/record.asp</font><font face="Arial" size="2">, line 42</font>';
        return new ObservationInput('https://fixture.invalid/record.asp?record_id='.rawurlencode($value),
            $raw, null, 'text/html', 500, null, null, null, false, null);
    }

    private function with(ObservationInput $input, array $changes): ObservationInput
    {
        return ObservationInput::fromArray([...$input->toArray(), ...$changes]);
    }

    /** @param list<ClaimSuggestion> $suggestions */
    private function assertExactSpans(ObservationInput $input, array $suggestions): void
    {
        foreach ($suggestions as $suggestion) foreach ($suggestion->spans as $span) {
            self::assertSame('raw', $span['representation']);
            self::assertSame(mb_substr($input->rawHtml, $span['start'], $span['length'], 'UTF-8'), $span['excerpt']);
        }
    }
}
