<?php

namespace App\Tests\Cribrum;

use App\Cribrum\ClassificationResult;
use App\Cribrum\CribrumClassifier;
use App\Cribrum\ObservationInput;
use App\Cribrum\SqlQueryInputSuspicion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlQueryInputSuspicionTest extends TestCase
{
    private const VALUE = '-->\'\"></title></noscript></script><img src=0 onerror=alert(\'fixtureMARK\')>';

    public function testOrderedArrayValuesInTheDisclosedQuerySupplyOnlyCoreSuspicion(): void
    {
        $input = $this->input();
        $suggestion = (new SqlQueryInputSuspicion())->suggest($input);
        self::assertNotNull($suggestion);
        self::assertSame('sql_injection', $suggestion->claim);
        self::assertSame('suspected', $suggestion->state);
        self::assertSame(['builtin/sql-query-array-input'], $suggestion->ruleIds);
        self::assertSame(SqlQueryInputSuspicion::RULE_ID, $suggestion->ruleIds[0]);
        self::assertCount(3, $suggestion->spans);
        self::assertContains($this->query([self::VALUE, '2', '3', '1']), array_column($suggestion->spans, 'excerpt'));
        self::assertContains(self::VALUE, array_column($suggestion->spans, 'excerpt'));
        $this->assertExactSpans($input, $suggestion->spans);

        $results = [];
        foreach (['default', 'shadow', 'active'] as $mode) {
            $classifier = $mode === 'default' ? new CribrumClassifier('/nonexistent')
                : new CribrumClassifier('/nonexistent', dirname(__DIR__, 2).'/resources/cribrum/candidates', $mode);
            $result = $classifier->classify($input)->toArray();
            $claims = array_column($result['suggestions'], null, 'claim');
            self::assertSame('observed', $claims['error_disclosure']['state']);
            self::assertSame('observed', $claims['xss']['state']);
            self::assertNotContains('sql_input_structure_indication', array_column($result['signals'], 'code'));
            if ($mode === 'active') self::assertArrayNotHasKey('sql_injection', $claims);
            else {
                self::assertSame('suspected', $claims['sql_injection']['state']);
                self::assertSame([SqlQueryInputSuspicion::RULE_ID], $claims['sql_injection']['ruleIds']);
                $this->assertExactSpans($input, $claims['sql_injection']['spans']);
            }
            ClassificationResult::fromArray($result);
            $results[$mode] = $result;
        }
        self::assertSame($results['default']['suggestions'], $results['shadow']['suggestions']);
    }

    public function testUnicodeBeforeAndInsideTheBoundValueKeepsOriginalRawSpans(): void
    {
        $value = str_replace('fixtureMARK', 'fíxtureMARK😀', self::VALUE);
        $input = $this->input([$value, '2', '3', '1']);
        $suggestion = (new SqlQueryInputSuspicion())->suggest($input);
        self::assertNotNull($suggestion);
        self::assertContains($value, array_column($suggestion->spans, 'excerpt'));
        $this->assertExactSpans($input, $suggestion->spans);
    }

    #[DataProvider('ambiguousRequestQueries')]
    public function testAmbiguousRequestSourcesCannotBeReconstructedByFlattening(string $query): void
    {
        $input = $this->input();
        $input = ObservationInput::fromArray([...$input->toArray(), 'url' => 'https://fixture.invalid/search?'.$query]);
        self::assertNull((new SqlQueryInputSuspicion())->suggest($input));
    }

    public static function ambiguousRequestQueries(): iterable
    {
        $value = rawurlencode(self::VALUE);
        $normal = 'type[]='.$value.'&type[]=2&type[]=3&type[]=1';
        yield 'scalar and append collision' => [$normal.'&type=other'];
        yield 'explicit index and append collision' => [$normal.'&type[0]=other'];
        yield 'nested array source' => ['type[group][]='.$value.'&type[group][]=2&type[group][]=3&type[group][]=1'];
        yield 'same payload in a second root' => [$normal.'&other='.$value];
        yield 'same ordered array in a second root' => [$normal.'&other[]='.$value.'&other[]=2&other[]=3&other[]=1'];
        yield 'duplicate nonnumeric leaf' => [$normal.'&type[]='.$value];
        yield 'duplicate numeric leaf' => [$normal.'&type[]=2'];
        yield 'value needs another URL decoding' => ['type[]='.rawurlencode($value).'&type[]=2&type[]=3&type[]=1'];
        yield 'decoded root collision' => [$normal.'&type%5B0%5D=other'];
    }

    #[DataProvider('unboundArrays')]
    public function testAnExactOrderedLongInputBindingIsRequired(array $requestValues, array $disclosedValues): void
    {
        self::assertNull((new SqlQueryInputSuspicion())->suggest($this->input($disclosedValues, $requestValues)));
    }

    public static function unboundArrays(): iterable
    {
        yield 'numeric leaves reordered' => [[self::VALUE, '2', '3', '1'], [self::VALUE, '3', '2', '1']];
        yield 'numeric leaf replaced' => [[self::VALUE, '2', '3', '1'], [self::VALUE, '2', '3', '9']];
        yield 'leaf missing' => [[self::VALUE, '2', '3', '1'], [self::VALUE, '2', '3']];
        yield 'payload case changed' => [[self::VALUE, '2', '3', '1'], [str_replace('fixtureMARK', 'fixturemark', self::VALUE), '2', '3', '1']];
        yield 'short marker only' => [["'fixtureMARK'", '2', '3', '1'], ["'fixtureMARK'", '2', '3', '1']];
        yield 'no quote in distinctive input' => [['distinctive_fixture_marker_0123456789_long_value', '2', '3', '1'], ['distinctive_fixture_marker_0123456789_long_value', '2', '3', '1']];
        yield 'second nonnumeric leaf' => [[self::VALUE, 'second_value', '3', '1'], [self::VALUE, 'second_value', '3', '1']];
    }

    public function testTheTwoQueryCopiesMustBeCompleteEqualAndBelongToOneNativeError(): void
    {
        $input = $this->input();
        $query = $this->query([self::VALUE, '2', '3', '1']);
        $native = $this->nativeError();
        $variants = [
            str_replace('] Query: '.$query, '] Query: '.str_replace('fixture_active=1', 'fixture_active=9', $query), $input->rawHtml),
            str_replace('] Query: '.$query, ']', $input->rawHtml),
            str_replace('['.$query.'] Query:', 'Query:', $input->rawHtml),
            str_replace($native, str_replace('Incorrect syntax', 'Conversion failed', $native), $input->rawHtml),
            str_replace('SQLSTATE[HY000]', 'SQLSTATE[42000]', $input->rawHtml),
            str_replace('20018', '20019', $input->rawHtml),
            $input->rawHtml.$input->rawHtml,
            '<div>PDO Error: '.$native.'</div><span>['.$query.'] Query: '.$query.'</span>',
        ];
        foreach ($variants as $raw) {
            self::assertNull((new SqlQueryInputSuspicion())->suggest(ObservationInput::fromArray([...$input->toArray(), 'rawHtml' => $raw])));
        }
    }

    public function testQuotedOrCommentedInListLookalikesDoNotEstablishAnOperand(): void
    {
        foreach ([
            fn (string $list): string => "SELECT fixture_name FROM fixture_items WHERE fixture_note='fixture_kind IN(".$list.")'",
            fn (string $list): string => 'SELECT fixture_name FROM fixture_items WHERE fixture_active=1 /* fixture_kind IN('.$list.') */',
            fn (string $list): string => "SELECT fixture_name FROM fixture_items WHERE fixture_active=1 -- fixture_kind IN(".$list.")",
            fn (string $list): string => 'SELECT fixture_name FROM fixture_items WHERE fixture_kind IN(\''.$list.'\')',
        ] as $makeQuery) {
            $query = $makeQuery(implode(',', [self::VALUE, '2', '3', '1']));
            self::assertNull((new SqlQueryInputSuspicion())->suggest($this->input(query: $query)));
        }
    }

    public function testDocumentationScriptsAttributesAndSuppliedQueriesRemainExcluded(): void
    {
        $input = $this->input();
        foreach ([
            '<section class="documentation">'.$input->rawHtml.'</section>',
            '<code>'.$input->rawHtml.'</code>',
            '<template>'.$input->rawHtml.'</template>',
            '<script>const fixture='.json_encode($input->rawHtml).';</script>',
            '<div data-error="'.htmlspecialchars($input->rawHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"></div>',
        ] as $raw) self::assertNull((new SqlQueryInputSuspicion())->suggest(ObservationInput::fromArray([...$input->toArray(), 'rawHtml' => $raw])));
        $query = $this->query([self::VALUE, '2', '3', '1']);
        foreach ([
            ['payload' => $query],
            ['url' => $input->url.'&query='.rawurlencode($query)],
            ['payload' => $input->rawHtml],
        ] as $override) self::assertNull((new SqlQueryInputSuspicion())->suggest(ObservationInput::fromArray([...$input->toArray(), ...$override])));
    }

    public function testRequestSuppliedNativeErrorHeaderCannotProvideIndependentDiagnosticEvidence(): void
    {
        $input = $this->input();
        foreach ([
            ['payload' => $this->nativeError()],
            ['url' => $input->url.'&message='.rawurlencode($this->nativeError())],
        ] as $override) {
            self::assertNull((new SqlQueryInputSuspicion())->suggest(ObservationInput::fromArray([...$input->toArray(), ...$override])));
        }
    }

    public function testAnEntireLeafThatIsACompleteSqlStringLiteralRemainsQueryData(): void
    {
        $value = "'fixture_marker_alpha_0123456789_long_suffix'";
        $input = $this->input([$value, '2', '3', '1']);
        self::assertNull((new SqlQueryInputSuspicion())->suggest($input));
    }

    private function input(array $values = [self::VALUE, '2', '3', '1'], ?array $requestValues = null, ?string $query = null): ObservationInput
    {
        $requestValues ??= $values;
        $query ??= $this->query($values);
        $pairs = array_map(static fn (string $value): string => 'type%5B%5D='.rawurlencode($value), $requestValues);
        return ObservationInput::fromArray([
            'url' => 'https://fixture.invalid/search?active=1&'.implode('&', $pairs),
            'rawHtml' => '<span class="fixture-error">Ä😀<br /><b>Error:</b> 0<br /><b>Message:</b> PDO Error: '.$this->nativeError().' ['.$query.'] Query: '.$query.'<br /><b>File:</b> /fixture/framework.php<br /><b>Line:</b> 12</span>',
            'contentType' => 'text/html', 'httpStatus' => 200,
            'expectedEvidence' => 'fixtureMARK', 'dialogSeen' => true, 'dialogText' => 'fixtureMARK',
            'coverage' => ['status' => 'complete', 'truncated' => false],
        ]);
    }

    private function nativeError(): string
    {
        return "SQLSTATE[HY000]: General error: 20018 Incorrect syntax near '('. [20018] (severity 15)";
    }

    private function query(array $values): string
    {
        return "SELECT DISTINCT f.fixture_name, f.fixture_id\n FROM fixture_items f\n WHERE f.fixture_active=1 AND f.fixture_kind IN(".implode(',', $values).') ORDER BY f.fixture_name';
    }

    private function assertExactSpans(ObservationInput $input, array $spans): void
    {
        foreach ($spans as $span) {
            self::assertSame('raw', $span['representation']);
            self::assertSame($span['excerpt'], mb_substr($input->rawHtml, $span['start'], $span['length'], 'UTF-8'));
        }
    }
}
