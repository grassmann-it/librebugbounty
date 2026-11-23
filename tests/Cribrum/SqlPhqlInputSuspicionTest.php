<?php

declare(strict_types=1);

namespace App\Tests\Cribrum;

use App\Cribrum\ClassificationResult;
use App\Cribrum\CribrumClassifier;
use App\Cribrum\Detector\SqlTriageDetector;
use App\Cribrum\ObservationInput;
use App\Cribrum\SqlPhqlInputSuspicion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlPhqlInputSuspicionTest extends TestCase
{
    private const VALUE = '-->\'\"></title></noscript></script><img src=0 onerror=alert(\'fixtureMARK\')>';

    #[DataProvider('sortPositions')]
    public function testCompletePhqlFamilySuppliesOnlyCoreSuspicion(string $before, string $after): void
    {
        $input = $this->input(query: $this->query(self::VALUE, $before, $after));
        $suggestion = (new SqlPhqlInputSuspicion())->suggest($input);
        self::assertNotNull($suggestion);
        self::assertSame('sql_injection', $suggestion->claim);
        self::assertSame('suspected', $suggestion->state);
        self::assertSame([SqlPhqlInputSuspicion::RULE_ID], $suggestion->ruleIds);
        self::assertCount(4, $suggestion->spans);
        self::assertContains(self::VALUE, array_column($suggestion->spans, 'excerpt'));
        self::assertStringContainsString('Phalcon PHQL ORM parser', $suggestion->reasons[0]);
        self::assertStringContainsString('does not establish native database execution', $suggestion->reasons[0]);
        $this->assertExactSpans($input, $suggestion->spans);
        self::assertSame([], (new SqlTriageDetector())->evaluate($input)->signals,
            'An ORM diagnostic must not be turned into a native SQL-error/structure fact.');
    }

    public static function sortPositions(): iterable
    {
        yield 'first sort operand and limit placeholder' => ['', ' LIMIT :APL0:'];
        yield 'later sort operand' => ['[enabled] ASC, ', ' LIMIT :APL0:'];
        yield 'direction and later sort term' => ['vehicle.enabled DESC, ', ' ASC, vehicle.id DESC LIMIT 15'];
        yield 'no limit' => ['', ''];
        yield 'numeric offset and limit' => ['', ' DESC LIMIT 20, 10'];
    }

    public function testUnicodeBeforeAndInsideTheQueryUsesByteLengthsAndExactRawSpans(): void
    {
        $value = str_replace('fixtureMARK', 'fíxtureMARK😀', self::VALUE);
        $query = $this->query($value);
        $input = $this->input($value, $query);
        self::assertGreaterThan(mb_strlen($query, 'UTF-8'), strlen($query));
        $suggestion = (new SqlPhqlInputSuspicion())->suggest($input);
        self::assertNotNull($suggestion);
        self::assertSame($value, $suggestion->spans[2]['excerpt']);
        $this->assertExactSpans($input, $suggestion->spans);
        $wrong = str_replace(' ('.strlen($query).')<br>', ' ('.mb_strlen($query, 'UTF-8').')<br>', $input->rawHtml);
        self::assertNull((new SqlPhqlInputSuspicion())->suggest($this->with($input, ['rawHtml' => $wrong])));
    }

    public function testOneEntityDecodeRetainsRawQueryAndInputBoundaries(): void
    {
        $value = str_replace('fixtureMARK', 'fíxtureMARK😀', self::VALUE);
        $input = $this->input($value, encoded: true);
        $suggestion = (new SqlPhqlInputSuspicion())->suggest($input);
        self::assertNotNull($suggestion);
        self::assertSame(htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $suggestion->spans[2]['excerpt']);
        self::assertStringContainsString('decoded once', $suggestion->reasons[0]);
        $this->assertExactSpans($input, $suggestion->spans);
        $rawQuery = htmlspecialchars($this->query($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $double = str_replace($rawQuery, htmlspecialchars($rawQuery, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $input->rawHtml);
        self::assertNull((new SqlPhqlInputSuspicion())->suggest($this->with($input, ['rawHtml' => $double])));
    }

    public function testMultibyteContextBudgetCannotSplitAnOtherwiseValidResponse(): void
    {
        $input = $this->input();
        $input = $this->with($input, ['rawHtml' => str_repeat('Ä😀', 50).'x'.$input->rawHtml]);
        $suggestion = (new SqlPhqlInputSuspicion())->suggest($input);
        self::assertNotNull($suggestion);
        $this->assertExactSpans($input, $suggestion->spans);
    }

    public function testNamespacedModelsAndUnrelatedNamedBindingsAreNormalPhql(): void
    {
        $query = str_replace('enabled=1 AND', 'enabled BETWEEN :from: AND :to: AND', $this->query(self::VALUE));
        $query = str_replace('Fixture\\Models', 'Fixture\\\\Models', $query);
        $input = $this->input(query: $query);
        self::assertNotNull((new SqlPhqlInputSuspicion())->suggest($input));
    }

    public function testOnlyTheBoundRootMustBeUniqueAndSimple(): void
    {
        foreach (['orden', 'orden[value]', 'orden[0]', 'orden[]'] as $name) {
            $input = $this->input();
            $url = 'https://fixture.invalid/search?'.rawurlencode($name).'='.rawurlencode(self::VALUE)
                .'&params[active]=0&params[active]=1&params[model][1]=2&params[model][2]=3';
            self::assertNotNull((new SqlPhqlInputSuspicion())->suggest($this->with($input, ['url' => $url])), $name);
        }
    }

    #[DataProvider('ambiguousSources')]
    public function testDuplicateNestedConflictingAndUnboundRequestValuesAreExcluded(string $query): void
    {
        $input = $this->input();
        self::assertNull((new SqlPhqlInputSuspicion())->suggest($this->with($input, ['url' => 'https://fixture.invalid/search?'.$query])));
    }

    public static function ambiguousSources(): iterable
    {
        $value = rawurlencode(self::VALUE);
        yield 'duplicate scalar' => ['orden='.$value.'&orden=other'];
        yield 'scalar array collision' => ['orden='.$value.'&orden[0]=other'];
        yield 'duplicate explicit index' => ['orden[0]='.$value.'&orden[0]=other'];
        yield 'multiple leaves in one root' => ['orden[0]='.$value.'&orden[1]=other'];
        yield 'implicit explicit collision' => ['orden[]='.$value.'&orden[0]=other'];
        yield 'nested bound leaf' => ['orden[group][0]='.$value];
        yield 'encoded duplicate key' => ['orden='.$value.'&%6frden=other'];
        yield 'different param with identical value' => ['orden='.$value.'&other='.$value];
        yield 'other param contains value' => ['orden='.$value.'&other='.rawurlencode('prefix'.self::VALUE)];
        yield 'requires second URL decode' => ['orden='.rawurlencode($value)];
        yield 'no request value' => ['orden=ordinary'];
    }

    #[DataProvider('nonOperands')]
    public function testLiteralCommentWhereAndMalformedSurroundingsAreExcluded(string $query): void
    {
        self::assertNull((new SqlPhqlInputSuspicion())->suggest($this->input(query: $query)));
    }

    public static function nonOperands(): iterable
    {
        $base = 'SELECT [Fixture\\Models\\Vehicle].* FROM [Fixture\\Models\\Vehicle] WHERE enabled=1 ';
        $value = self::VALUE;
        yield 'single quoted operand' => [$base."ORDER BY '".$value."'"];
        yield 'double quoted operand' => [$base.'ORDER BY "'.$value.'"'];
        yield 'bracket quoted operand' => [$base.'ORDER BY ['.$value.']'];
        yield 'block comment lookalike' => [$base.'/* ORDER BY '.$value.' */'];
        yield 'line comment before operand' => [$base.'ORDER BY enabled -- '."\n".$value];
        yield 'closed unrelated comment' => [$base.'/* debug */ ORDER BY '.$value];
        yield 'where expression' => [$base.'AND name='.$value];
        yield 'order phrase inside literal' => [$base."AND note='ORDER BY ".$value."'"];
        yield 'function argument' => [$base.'ORDER BY COALESCE('.$value.', enabled)'];
        yield 'unclosed parentheses' => [$base.'AND (id=1 ORDER BY '.$value];
        yield 'unclosed bracket identifier' => [$base.'ORDER BY [name, '.$value];
        yield 'query repeated value' => [$base.'ORDER BY '.$value.', '.$value];
        yield 'unvalidated tail' => [$base.'ORDER BY '.$value.' /* trailing */'];
        yield 'second statement' => [$base.'ORDER BY '.$value.'; SELECT other FROM items'];
        yield 'identifier prefix before input' => [$base.'ORDER BY fixture'.$value];
        yield 'identifier suffix after input' => [$base.'ORDER BY '.$value.'fixture'];
        yield 'order keyword glued to number' => ['SELECT f FROM [Fixture] WHERE n=123ORDER BY '.$value];
        yield 'order keyword glued to unicode identifier' => ['SELECT f FROM [Fixture] WHERE n=äORDER BY '.$value];
        yield 'order keyword in qualified name' => ['SELECT f FROM [Fixture].ORDER BY '.$value];
        yield 'space in qualified order name' => ['SELECT f FROM [Fixture]. ORDER BY '.$value];
        yield 'tab in qualified order name' => ['SELECT f FROM [Fixture].'."\t".'ORDER BY '.$value];
        yield 'newline in qualified order name' => ['SELECT f FROM [Fixture].'."\n".'ORDER BY '.$value];
        yield 'no complete FROM relation' => ['SELECT 1 ORDER BY '.$value];
    }

    public function testCompleteCarrierQueryLengthAndPhqlFrameAreMandatory(): void
    {
        $input = $this->input();
        $query = $this->query(self::VALUE);
        foreach ([
            str_replace(' ('.strlen($query).')<br>', ' ('.(strlen($query) - 1).')<br>', $input->rawHtml),
            str_replace(' ('.strlen($query).')<br>', '<br>', $input->rawHtml),
            str_replace('parsePHQL()', 'parse()', $input->rawHtml),
            str_replace('Phalcon\\Mvc\\Model\\Query\\Lang', 'Other\\Parser', $input->rawHtml),
            str_replace('Syntax error, unexpected token >', 'Generic parser error', $input->rawHtml),
            str_replace('</pre>', '', $input->rawHtml),
            str_replace('<br><pre>', '</div><div><pre>', $input->rawHtml),
            str_replace("', when parsing:", "'</div><div>, when parsing:", $input->rawHtml),
            $input->rawHtml.$input->rawHtml,
        ] as $raw) self::assertNull((new SqlPhqlInputSuspicion())->suggest($this->with($input, ['rawHtml' => $raw])), $raw);
        self::assertNull((new SqlPhqlInputSuspicion())->suggest($this->with($input, ['rawHtml' => null, 'domText' => $input->rawHtml])));
    }

    public function testDocumentationScriptsAttributesCommentsAndSuppliedDiagnosticsAreExcluded(): void
    {
        $input = $this->input();
        foreach ([
            '<section class="documentation">'.$input->rawHtml.'</section>',
            '<div class="docs">'.$input->rawHtml.'</div>',
            '<code>'.$input->rawHtml.'</code>',
            '<samp>'.$input->rawHtml.'</samp>',
            '<template>'.$input->rawHtml.'</template>',
            '<script>'.$input->rawHtml.'</script>',
            '<!-- '.$input->rawHtml.' -->',
            '<div data-error="'.$input->rawHtml.'"></div>',
            'Example: '.$input->rawHtml,
        ] as $raw) self::assertNull((new SqlPhqlInputSuspicion())->suggest($this->with($input, ['rawHtml' => $raw])), $raw);
        $error = substr($input->rawHtml, strpos($input->rawHtml, 'Syntax error,'));
        $error = substr($error, 0, strpos($error, ', when parsing:'));
        foreach ([$this->query(self::VALUE), $error, $input->rawHtml] as $supplied) {
            foreach ([['payload' => $supplied], ['url' => $input->url.'&message='.rawurlencode($supplied)]] as $override) {
                self::assertNull((new SqlPhqlInputSuspicion())->suggest($this->with($input, $override)));
            }
        }
    }

    public function testOrdinarySortKeysShortMarkersAndCompleteLiteralsDoNotBecomeSuspicion(): void
    {
        foreach (['vehicle_title', "'fixtureMARK'", 'fixture_marker_alpha_0123456789_long_sort_column',
            "'fixture_marker_alpha_0123456789_long_literal'", str_repeat('a', 60).'\'',
        ] as $value) self::assertNull((new SqlPhqlInputSuspicion())->suggest($this->input($value)), $value);
    }

    public function testARequestCannotSupplyItsOwnCarrierTerminatorOrAnAdditionalParsingBoundary(): void
    {
        foreach ([
            self::VALUE.' (214)<br><pre>#0 [internal function]: Phalcon\\Mvc\\Model\\Query\\Lang::parsePHQL()</pre>',
            self::VALUE.', when parsing: SELECT f FROM [Fixture]',
            self::VALUE." Syntax error, unexpected token >, near to 'other'",
        ] as $value) self::assertNull((new SqlPhqlInputSuspicion())->suggest($this->input($value)));
    }

    public function testDefaultAndShadowKeepTheHintSeparateFromActiveFacts(): void
    {
        $input = $this->input(); $results = [];
        foreach (['default', 'shadow', 'active'] as $mode) {
            $classifier = $mode === 'default' ? new CribrumClassifier('/nonexistent')
                : new CribrumClassifier('/nonexistent', dirname(__DIR__, 2).'/resources/cribrum/candidates', $mode);
            $result = $classifier->classify($input)->toArray();
            $claims = array_column($result['suggestions'], null, 'claim');
            if ($mode === 'active') self::assertArrayNotHasKey('sql_injection', $claims);
            else {
                self::assertSame('suspected', $claims['sql_injection']['state']);
                self::assertSame([SqlPhqlInputSuspicion::RULE_ID], $claims['sql_injection']['ruleIds']);
            }
            foreach (['sql_error_observed', 'input_in_sql_error', 'sql_input_structure_indication'] as $code) {
                self::assertNotContains($code, array_column($result['signals'], 'code'));
            }
            self::assertArrayNotHasKey('error_disclosure', $claims);
            ClassificationResult::fromArray($result);
            $results[$mode] = $result;
        }
        self::assertSame($results['default']['suggestions'], $results['shadow']['suggestions']);
    }

    private function input(string $value = self::VALUE, ?string $query = null, bool $encoded = false): ObservationInput
    {
        $query ??= $this->query($value);
        $error = "Syntax error, unexpected token >, near to '".$value."'";
        $raw = '<p>Ä😀 fixture</p>'.($encoded ? htmlspecialchars($error, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $error)
            .', when parsing: '.($encoded ? htmlspecialchars($query, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $query)
            .' ('.strlen($query).')<br><pre>#0 [internal function]: Phalcon\\Mvc\\Model\\Query\\Lang::parsePHQL()'
            ."\n#1 /srv/fixture/model.php(31): Fixture\\Query->execute()\n#2 {main}</pre>";
        return new ObservationInput('https://fixture.invalid/search?orden='.rawurlencode($value), $raw, null,
            'text/html; charset=UTF-8', 200, null, null, null, false, null, ['status' => 'complete']);
    }

    private function query(string $value, string $before = 'enabled ASC, ', string $after = ' LIMIT :APL0:'): string
    {
        return 'SELECT [Fixture\\Models\\Vehicle].* FROM [Fixture\\Models\\Vehicle] WHERE enabled=1 AND id IN ({ids:array}) ORDER BY '.$before.$value.$after;
    }

    private function with(ObservationInput $input, array $changes): ObservationInput
    {
        return ObservationInput::fromArray([...$input->toArray(), ...$changes]);
    }

    private function assertExactSpans(ObservationInput $input, array $spans): void
    {
        foreach ($spans as $span) {
            self::assertSame('raw', $span['representation']);
            self::assertSame(mb_substr($input->rawHtml, $span['start'], $span['length'], 'UTF-8'), $span['excerpt']);
        }
    }
}
