<?php

declare(strict_types=1);

namespace App\Tests\Cribrum;

use App\Cribrum\CribrumClassifier;
use App\Cribrum\ObservationInput;
use App\Cribrum\SqlDisclosedQueryInputSuspicion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Source-bound interpolation observations; never structural or execution facts. */
final class SqlDisclosedQueryInputSuspicionTest extends TestCase
{
    private const VALUE = '\'"><img src=0 onerror=alert(\'fixtureMARK\')>';
    private const QUERY = "SELECT V.* FROM fixture_valores V\n LEFT JOIN fixture_seo S ON V.seo_id = S.id\n WHERE 1 AND S.url_es = '".self::VALUE."'";

    private function helper(): SqlDisclosedQueryInputSuspicion
    {
        return new SqlDisclosedQueryInputSuspicion();
    }

    private function input(string $body, array $changes = []): ObservationInput
    {
        return ObservationInput::fromArray([
            'url' => 'https://fixture.invalid/', 'rawHtml' => $body, 'domText' => null,
            'contentType' => 'text/html; charset=UTF-8', 'httpStatus' => 200, 'finalUrl' => null,
            'payload' => null, 'expectedEvidence' => 'fixtureMARK', 'dialogSeen' => false, 'dialogText' => null,
            'coverage' => ['status' => 'complete', 'truncated' => false],
            ...$changes,
        ]);
    }

    private function body(string $query, string $before = '', string $after = "\n\n"): string
    {
        return '<!DOCTYPE html><html lang="en"><body><div class="md:flex"><div class="max-w-sm">'
            .'<div class="font-black">Debug output</div>'.$before
            .'<p class="leading-normal">'."\n".$query."\n".'</p>'.$after.'</div></div></body></html>';
    }

    public function testDisclosedStatementWithUnescapedPathValueIsSuspicionOnly(): void
    {
        $input = $this->input($this->body(self::QUERY), ['url' => 'https://fixture.invalid/'.rawurlencode(self::VALUE)]);
        $result = $this->helper()->evaluate($input, $this->needles($input));
        self::assertCount(1, $result['suggestions']);
        self::assertSame([], $result['issues']);
        $suggestion = $result['suggestions'][0];
        self::assertSame('sql_injection', $suggestion->claim);
        self::assertSame('suspected', $suggestion->state);
        self::assertSame(['builtin/sql-disclosed-query-input'], $suggestion->ruleIds);
        self::assertSame(['query', 'input'], array_column($suggestion->spans, 'role'));
        self::assertSame(self::QUERY, $suggestion->spans[0]['excerpt']);
        self::assertSame(self::VALUE, $suggestion->spans[1]['excerpt']);
        self::assertSame(strpos($input->rawHtml, self::VALUE), $suggestion->spans[1]['start']);
        $this->assertExactSpans($input, $suggestion->spans);
    }

    public function testClassifierReportsDisclosureAndSuspicionForTheSameSource(): void
    {
        $url = 'https://fixture.invalid/'.rawurlencode(self::VALUE);
        $classifier = new CribrumClassifier('/nonexistent');
        $result = $classifier->classify($this->input($this->body(self::QUERY), ['url' => $url]))->toArray();
        self::assertSame('cribrum-1.3.8', $result['classifierVersion']);
        self::assertSame([], $result['diagnostics']);
        $claims = array_column($result['suggestions'], null, 'claim');
        self::assertSame('suspected', $claims['sql_injection']['state']);
        self::assertSame(['builtin/sql-disclosed-query-input'], $claims['sql_injection']['ruleIds']);
        self::assertSame('observed', $claims['information_disclosure']['state']);
        self::assertSame(['builtin/sql-query-echo'], $claims['information_disclosure']['ruleIds']);
        self::assertSame('suspected', $claims['xss']['state']);
    }

    #[DataProvider('requestCarriers')]
    public function testQueryStringFinalUrlAndPayloadCarriersQualify(array $changes, array $values): void
    {
        $input = $this->input($this->body(self::QUERY), $changes);
        $suggestions = $this->helper()->evaluate($input, $values)['suggestions'];
        self::assertCount(1, $suggestions);
        self::assertSame('sql_injection', $suggestions[0]->claim);
    }

    public static function requestCarriers(): iterable
    {
        yield 'query string' => [
            ['url' => 'https://fixture.invalid/search?pag='.rawurlencode(self::VALUE)],
            [self::VALUE],
        ];
        yield 'final url' => [
            ['finalUrl' => 'https://fixture.invalid/search?pag='.rawurlencode(self::VALUE)],
            [self::VALUE],
        ];
        yield 'explicit payload' => [['payload' => self::VALUE], [self::VALUE]];
        yield 'array leaf' => [
            ['url' => 'https://fixture.invalid/search?pag%5B%5D=7&pag%5B%5D='.rawurlencode(self::VALUE)],
            ['7', self::VALUE],
        ];
    }

    public function testSingleLineStatementCarrierQualifies(): void
    {
        // The generic single-line carrier stops at markup, so a value without
        // angle brackets keeps the statement complete. Reflected markup needs
        // the multi-line block carrier above.
        $single = "SELECT f.id FROM fixture_items f WHERE f.slug = 'fixture'O'Brien'";
        self::assertCount(1, $this->helper()->evaluate($this->input('<p>Debug: '.$single.'</p>'), ["fixture'O'Brien"])['suggestions']);
    }

    public function testEntityEncodedCarrierCannotProveVerbatimInterpolation(): void
    {
        $single = 'SELECT f.id FROM fixture_items f WHERE f.slug = \''.self::VALUE.'\'';
        $encoded = htmlspecialchars($single, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertSame([], $this->helper()->evaluate($this->input('<input type="hidden" name="squery" value="'.$encoded.'">'), [self::VALUE])['suggestions']);
    }

    #[DataProvider('unqualifiedValues')]
    public function testUnqualifiedValuesStayUnqualified(array $values, string $query): void
    {
        self::assertSame([], $this->helper()->evaluate($this->input($this->body($query)), $values)['suggestions']);
    }

    public static function unqualifiedValues(): iterable
    {
        yield 'no request value' => [[], self::QUERY];
        yield 'value without quoting' => [['fixture_shoes'], "SELECT f.id FROM fixture_items f WHERE f.slug = 'fixture_shoes'"];
        yield 'short quoted value' => [["a'b"], "SELECT f.id FROM fixture_items f WHERE f.slug = 'a'b'"];
        yield 'escaped backslash' => [[self::VALUE], "SELECT f.id FROM fixture_items f WHERE f.slug = '".str_replace("'", "\\'", self::VALUE)."'"];
        yield 'inside line comment' => [[self::VALUE], "SELECT f.id FROM fixture_items f -- ".self::VALUE."\n WHERE f.slug = 'x'"];
        yield 'inside block comment' => [[self::VALUE], "SELECT f.id /* ".self::VALUE." */ FROM fixture_items f WHERE f.slug = 'x'"];
        yield 'not abutting quoting' => [[self::VALUE], 'SELECT '.self::VALUE.' FROM fixture_items f WHERE f.slug = 1'];
        yield 'identifier position' => [[self::VALUE], "SELECT f.id FROM fixture_items f WHERE f.".self::VALUE." = 1"];
        yield 'entity encoded reflection' => [[self::VALUE], htmlspecialchars(self::QUERY, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
        yield 'no disclosed statement' => [[self::VALUE], '<p>No statement here, only a reflected '.self::VALUE.'</p>'];
    }

    public function testSuppliedStatementAndDocumentationCarriersStayExcluded(): void
    {
        $url = 'https://fixture.invalid/?message='.rawurlencode(self::QUERY);
        $input = $this->input($this->body(self::QUERY), ['url' => $url]);
        self::assertSame([], $this->helper()->evaluate($input, [self::QUERY, self::VALUE])['suggestions']);
        // A preformatted container stays a valid carrier: application dumps and
        // documentation share it. Inert and scripted carriers do not qualify.
        foreach ([['<code>', '</code>'], ['<samp>', '</samp>'], ['<script>', '</script>'], ['<section class="docs">', '</section>']] as [$before, $after]) {
            $body = $before.$this->body(self::QUERY).$after;
            self::assertSame([], $this->helper()->evaluate($this->input($body), [self::VALUE])['suggestions'], $before);
        }
    }

    public function testNestedPathAndSegmentValuesCollapseToOneOccurrence(): void
    {
        // A payload can contain path separators. The decoded path and its
        // segments then bind the same occurrence; the longest describes it.
        $value = '-->\'"></title><img src=0 onerror=alert(\'fixtureMARK\')>';
        $query = "SELECT f.id FROM fixture_items f WHERE f.note = '".$value."'";
        $input = $this->input($this->body($query), ['url' => 'https://fixture.invalid/'.rawurlencode($value)]);
        $suggestions = $this->helper()->evaluate($input, $this->needles($input))['suggestions'];
        self::assertCount(1, $suggestions);
        self::assertSame($value, $suggestions[0]->spans[1]['excerpt']);
    }

    public function testTwoCompetingBindingsStayUnqualified(): void
    {
        $other = 'SELECT o.id FROM fixture_orders o WHERE o.note = \''.self::VALUE.'\'';
        $body = $this->body(self::QUERY).$this->body($other);
        self::assertSame([], $this->helper()->evaluate($this->input($body), [self::VALUE])['suggestions']);
    }

    public function testStatementBudgetAndDuplicateCandidatesRemainVisible(): void
    {
        $repeated = str_repeat($this->body(self::QUERY), 40);
        $suggestions = $this->helper()->evaluate($this->input($repeated), [self::VALUE])['suggestions'];
        self::assertCount(1, $suggestions, 'Identical statements and values collapse to one binding.');
        $long = $this->body('SELECT '.str_repeat('fixture_padding, ', 900).'f.id FROM fixture_items f WHERE f.slug = \''.self::VALUE.'\'');
        self::assertSame([], $this->helper()->evaluate($this->input($long), [self::VALUE])['suggestions']);
    }

    public function testExplicitActiveModeBypassesTheHelper(): void
    {
        $url = 'https://fixture.invalid/'.rawurlencode(self::VALUE);
        $classifier = new CribrumClassifier('/nonexistent', dirname(__DIR__, 2).'/resources/cribrum/candidates', 'active');
        $result = $classifier->classify($this->input($this->body(self::QUERY), ['url' => $url]))->toArray();
        self::assertArrayNotHasKey('sql_injection', array_column($result['suggestions'], null, 'claim'));
        self::assertSame([], $result['diagnostics']);
    }

    public function testMalformedRegexSurfaceAsAnEngineIssue(): void
    {
        $input = $this->input($this->body(self::QUERY), ['url' => 'https://fixture.invalid/'.rawurlencode(self::VALUE)]);
        $result = $this->helper()->evaluate($input, ["\xFF'\"".str_repeat('invalid_needle_', 4)]);
        self::assertSame([['code' => 'regex_error', 'stage' => 'builtin_sql_disclosed_query_input', 'representation' => 'raw', 'status' => 'failed']], $result['issues']);
    }

    /** @return list<string> */
    private function needles(ObservationInput $input): array
    {
        $classifier = new CribrumClassifier('/nonexistent');
        $method = new \ReflectionMethod($classifier, 'sourceBoundNeedles');
        $method->setAccessible(true);
        $queryValues = new \ReflectionMethod($classifier, 'queryValues');
        $queryValues->setAccessible(true);
        $issues = [];
        $arguments = [$input->url, &$issues, 'test'];
        $values = [...$queryValues->invokeArgs($classifier, $arguments)];
        $arguments[0] = $input->finalUrl ?? '';
        $values = [...$values, ...$queryValues->invokeArgs($classifier, $arguments)];
        self::assertSame([], $issues);

        return $method->invoke($classifier, $input, $values);
    }

    private function assertExactSpans(ObservationInput $input, array $spans): void
    {
        $raw = $input->rawHtml;
        self::assertNotNull($raw);
        foreach ($spans as $span) {
            self::assertSame('raw', $span['representation']);
            self::assertSame(mb_substr($raw, $span['start'], $span['length'], 'UTF-8'), $span['excerpt']);
        }
    }
}
