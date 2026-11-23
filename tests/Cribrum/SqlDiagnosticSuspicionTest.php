<?php

namespace App\Tests\Cribrum;

use App\Cribrum\ClassificationResult;
use App\Cribrum\CribrumClassifier;
use App\Cribrum\Detector\SqlTriageDetector;
use App\Cribrum\ObservationInput;
use App\Cribrum\SqlDiagnosticSuspicion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlDiagnosticSuspicionTest extends TestCase
{
    private const VALUE = '-->\'"></title></noscript></script><img src=0 onerror=alert(\'fixtureMARK\')>';

    public function testSingleArrayLeafWithNativeDialogRetainsXssAndAddsOnlySuspectedSqlInjection(): void
    {
        $input = $this->input('jobs[]', self::VALUE, substr(self::VALUE, 1).'))');
        $default = (new CribrumClassifier('/nonexistent'))->classify($input)->toArray();
        $shadow = (new CribrumClassifier('/nonexistent', dirname(__DIR__, 2).'/resources/cribrum/candidates'))->classify($input)->toArray();
        self::assertSame($default['suggestions'], $shadow['suggestions']);
        self::assertSame('observed', array_column($default['suggestions'], 'state', 'claim')['xss']);
        $claims = array_column($default['suggestions'], null, 'claim');
        self::assertSame('observed', $claims['error_disclosure']['state']);
        self::assertSame('suspected', $claims['sql_injection']['state']);
        self::assertSame([SqlDiagnosticSuspicion::RULE_ID], $claims['sql_injection']['ruleIds']);
        self::assertStringContainsString('partial input binding', implode(' ', $claims['sql_injection']['reasons']));
        self::assertStringContainsString('full SQL query remain unverified', implode(' ', $claims['sql_injection']['reasons']));
        self::assertContains('mysql-syntax-input-suffix', array_column($default['signals'], 'rule'));
        $this->assertExactSpans($input, $claims['sql_injection']['spans']);
        ClassificationResult::fromArray($default);
        $active = (new CribrumClassifier('/nonexistent', dirname(__DIR__, 2).'/resources/cribrum/candidates', 'active'))->classify($input)->toArray();
        self::assertArrayNotHasKey('sql_injection', array_column($active['suggestions'], null, 'claim'));
        self::assertNotContains('sql_input_structure_indication', array_column($active['signals'], 'code'));
    }

    #[DataProvider('positiveBindings')]
    public function testNativeLocationRequiresAnExactDistinctiveSuffix(string $name, int $omitted, string $tail): void
    {
        $input = $this->input($name, self::VALUE, substr(self::VALUE, $omitted).$tail);
        $suggestion = (new SqlDiagnosticSuspicion())->suggest($input);
        self::assertNotNull($suggestion);
        self::assertSame('sql_injection', $suggestion->claim);
        self::assertSame('suspected', $suggestion->state);
        self::assertSame(substr(self::VALUE, $omitted), $suggestion->spans[1]['excerpt']);
        $this->assertExactSpans($input, $suggestion->spans);
    }

    public static function positiveBindings(): iterable
    {
        yield 'scalar exact value' => ['job', 0, ''];
        yield 'single array leaf actual shape' => ['jobs[]', 1, '))'];
        yield 'one explicit array index' => ['jobs[0]', 2, ')'];
        yield 'four leading punctuation characters' => ['job', 4, ')),'];
        yield 'five leading punctuation characters' => ['job', 5, '))'];
        yield 'short LIKE wrapper tail' => ['job', 4, "%'"];
        yield 'clipped keyword prefix' => ['job', 0, ' LI...'];
        yield 'case insensitive clipped SQL keyword' => ['job', 0, "\tli..."];
    }

    #[DataProvider('nativeFormats')]
    public function testCanonicalNativeMysqlFamilyMessageMayOmitItsWrapper(string $vendor, bool $wrapped): void
    {
        $input = $this->input('job', self::VALUE, self::VALUE.' LI...', $vendor, $wrapped);
        $suggestion = (new SqlDiagnosticSuspicion())->suggest($input);
        self::assertNotNull($suggestion);
        self::assertSame($this->nativeMessage(self::VALUE.' LI...', $vendor, $wrapped), $suggestion->spans[0]['excerpt']);
        self::assertStringContainsString('ends in an ellipsis', implode(' ', $suggestion->reasons));
        self::assertStringNotContainsString('LIMIT', implode(' ', $suggestion->reasons));
        $this->assertExactSpans($input, $suggestion->spans);
    }

    public static function nativeFormats(): iterable
    {
        yield 'wrapped MySQL' => ['MySQL', true];
        yield 'wrapperless MySQL' => ['MySQL', false];
        yield 'wrapped MariaDB' => ['MariaDB', true];
        yield 'wrapperless MariaDB' => ['MariaDB', false];
    }

    public function testEightLeadingPunctuationCodepointsAreBoundedIndependentlyOfTheRetentionRatio(): void
    {
        $suffix = "'distinctive_0123456789_abcdefghijklmnopqrstuvwxyz_ABCDEFGHIJKLMNOPQRSTUVWXYZ_0123456789'";
        foreach (['--------', str_repeat('—', 8)] as $prefix) {
            $suggestion = (new SqlDiagnosticSuspicion())->suggest($this->input('job', $prefix.$suffix, $suffix));
            self::assertNotNull($suggestion);
            self::assertSame($suffix, $suggestion->spans[1]['excerpt']);
        }
        self::assertGreaterThanOrEqual(.9, mb_strlen($suffix) / mb_strlen('---------'.$suffix));
        foreach (['---------', '-------A', '------- '] as $prefix) {
            self::assertNull((new SqlDiagnosticSuspicion())->suggest($this->input('job', $prefix.$suffix, $suffix)));
        }
    }

    public function testOneEntityDecodeWithinNativeNearRetainsExactRawEvidence(): void
    {
        $suffix = substr(self::VALUE, 4);
        $encoded = htmlspecialchars($suffix, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ([$encoded, str_replace('&apos;', '&#039;', $encoded), str_replace('&apos;', '&#x27;', $encoded)] as $rawSuffix) {
            $input = $this->input('job', self::VALUE, $rawSuffix.'%&#039;', 'MySQL', false);
            $suggestion = (new SqlDiagnosticSuspicion())->suggest($input);
            self::assertNotNull($suggestion);
            self::assertSame($rawSuffix, $suggestion->spans[1]['excerpt']);
            self::assertSame($suffix, html_entity_decode($suggestion->spans[1]['excerpt'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            self::assertStringContainsString('decoded once within the native near fragment', implode(' ', $suggestion->reasons));
            $this->assertExactSpans($input, $suggestion->spans);
        }
    }

    public function testEntityComparisonDoesNotDecodeTwiceDeleteInteriorTextOrBindTwoDifferentParameters(): void
    {
        $suffix = substr(self::VALUE, 4);
        $encoded = htmlspecialchars($suffix, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ([
            htmlspecialchars($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            str_replace('fixtureMARK', 'fixtureXMARK', $encoded),
            str_replace('fixtureMARK', 'fixture<em>MARK</em>', $encoded),
            str_replace('fixtureMARK', 'fixture&nbsp;MARK', $encoded),
        ] as $fragment) self::assertNull((new SqlDiagnosticSuspicion())->suggest($this->input('job', self::VALUE, $fragment)));
        $rawValue = "'distinctive_0123456789_&amp;_abcdefghijklmnopqrstuvwxyz";
        $input = $this->input('first', $rawValue, $rawValue);
        self::assertNotNull((new SqlDiagnosticSuspicion())->suggest($input));
        $input = ObservationInput::fromArray([...$input->toArray(), 'url' => $input->url.'&second='.rawurlencode(html_entity_decode($rawValue, ENT_QUOTES | ENT_HTML5, 'UTF-8'))]);
        self::assertNull((new SqlDiagnosticSuspicion())->suggest($input));
        $unknown = "'distinctive_0123456789_&UnknownEntity;_abcdefghijklmnopqrstuvwxyz";
        self::assertNotNull((new SqlDiagnosticSuspicion())->suggest($this->input('job', $unknown, $unknown)));
    }

    public function testEncodedSecondNativeLocationAndPartialMulticodepointEntityCannotSupplyEvidence(): void
    {
        foreach ([" near 'secondary'", "' at line 2"] as $extra) {
            $value = self::VALUE.$extra;
            self::assertNull((new SqlDiagnosticSuspicion())->suggest($this->input('job', $value, htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        }
        $prefix = "'distinctive_0123456789_abcdefghijklmnopqrstuvwxyz";
        $value = '--'.$prefix.'≂';
        self::assertSame("≂\u{0338}", html_entity_decode('&NotEqualTilde;', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        self::assertNull((new SqlDiagnosticSuspicion())->suggest($this->input('job', $value, $prefix.'&NotEqualTilde;')));
    }

    public function testEncodedClippedFragmentUses80ComparisonBytesAndExactLongerRawSpans(): void
    {
        $encoded = htmlspecialchars(self::VALUE, ENT_QUOTES | ENT_HTML5, 'UTF-8').' LI...';
        self::assertGreaterThan(80, strlen($encoded));
        self::assertSame(80, strlen(html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $input = $this->input('job', self::VALUE, $encoded, 'MariaDB', false);
        $suggestion = (new SqlDiagnosticSuspicion())->suggest($input);
        self::assertNotNull($suggestion);
        self::assertSame(htmlspecialchars(self::VALUE, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $suggestion->spans[1]['excerpt']);
        $this->assertExactSpans($input, $suggestion->spans);
        $unicodeValue = str_replace('fixtureMARK', 'fíxtureMARK', self::VALUE);
        $unicodeFragment = htmlspecialchars($unicodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8').' LI...';
        self::assertSame(81, strlen(html_entity_decode($unicodeFragment, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        self::assertNull((new SqlDiagnosticSuspicion())->suggest($this->input('job', $unicodeValue, $unicodeFragment, 'MariaDB', false)));
    }

    public function testClippedKeywordTailUsesAnExplicitWhitelistAndTheBounded80ByteFragmentShape(): void
    {
        foreach ([' LI...', "\tli...", '  OFFSET...', ' OR...', ' HAVIN...', ' WH...', ' AN...', ' UN...', ' JO...', ' GR...', ' ORD...'] as $tail) {
            $suffix = str_pad("'distinctive_marker_0123456789_abcdefghijklmnopqrstuvwxyz_", 80 - strlen($tail), 'x');
            $input = $this->input('job', '--'.$suffix, $suffix.$tail, 'MariaDB', false);
            self::assertSame(80, strlen($suffix.$tail));
            self::assertNotNull((new SqlDiagnosticSuspicion())->suggest($input), $tail);
        }
        $unicodeValue = str_replace('fixtureMARK', 'fíxtureMARK', self::VALUE);
        self::assertSame(80, mb_strlen($unicodeValue.' LI...', 'UTF-8'));
        self::assertSame(81, strlen($unicodeValue.' LI...'));
        foreach ([
            [substr(self::VALUE, 1), substr(self::VALUE, 1).' LI...'], // 79 bytes.
            [self::VALUE, self::VALUE.'  LI...'], // 81 bytes.
            [$unicodeValue, $unicodeValue.' LI...'], // 80 codepoints, 81 bytes.
        ] as [$value, $fragment]) {
            self::assertNull((new SqlDiagnosticSuspicion())->suggest($this->input('job', $value, $fragment, 'MariaDB', false)));
        }
    }

    #[DataProvider('unqualifiedClippedTails')]
    public function testAn80ByteFragmentDoesNotRelaxTheClippedTailGrammar(string $tail): void
    {
        $suffix = str_pad("'distinctive_marker_0123456789_abcdefghijklmnopqrstuvwxyz_", 80 - strlen($tail), 'x');
        self::assertSame(80, strlen($suffix.$tail));
        self::assertNull((new SqlDiagnosticSuspicion())->suggest($this->input('job', '--'.$suffix, $suffix.$tail, 'MariaDB', false)));
    }

    public static function unqualifiedClippedTails(): iterable
    {
        yield 'unknown prefix' => [' XY...'];
        yield 'arbitrary identifier' => [' LI_CUSTOM...'];
        yield 'one letter prefix' => [' L...'];
        yield 'missing ellipsis' => [' LI'];
        yield 'too few dots' => [' LI..'];
        yield 'too many dots' => [' LI....'];
        yield 'extra text after ellipsis' => [' LI... more'];
        yield 'too much whitespace' => ['   LI...'];
        yield 'newline separator' => ["\nLI..."];
        yield 'missing separator' => ['LI...'];
        yield 'keyword suffix instead of prefix' => [' MIT...'];
        yield 'punctuation before keyword' => [') LI...'];
    }

    public function testCompleteNativeMariaDbLocationInAnUnclosedHtmlCarrierAddsOnlyCoreSuspicion(): void
    {
        // Preserve the observed 76-byte value / 74-byte suffix / 80-byte near
        // fragment shape without copying the retained production marker.
        $value = str_replace('fixtureMARK', 'fixtureMARK12', self::VALUE);
        $suffix = substr($value, 2);
        $native = $this->nativeMessage($suffix.' LI...', 'MariaDB', false);
        self::assertSame(76, strlen($value));
        self::assertSame(74, strlen($suffix));
        self::assertSame(80, strlen($suffix.' LI...'));
        $input = $this->input('novinka', $value, $suffix.' LI...', 'MariaDB', false);
        $input = ObservationInput::fromArray([...$input->toArray(), 'rawHtml' => '<html><body><h1>Ä😀</h1><div id="news"><p>Vyhorelo to'.$native]);
        $strict = (new SqlTriageDetector())->evaluate($input);
        self::assertSame(['sql_error_observed'], array_column($strict->signals, 'code'));
        self::assertFalse($strict->signals[0]['attributes']['blockComplete']);
        $results = [];
        foreach (['default', 'shadow', 'active'] as $mode) {
            $classifier = $mode === 'default' ? new CribrumClassifier('/nonexistent')
                : new CribrumClassifier('/nonexistent', dirname(__DIR__, 2).'/resources/cribrum/candidates', $mode);
            $result = $classifier->classify($input)->toArray();
            $claims = array_column($result['suggestions'], null, 'claim');
            self::assertSame('observed', $claims['xss']['state']);
            self::assertSame('observed', $claims['error_disclosure']['state']);
            self::assertNotContains('input_in_sql_error', array_column($result['signals'], 'code'));
            self::assertNotContains('sql_input_structure_indication', array_column($result['signals'], 'code'));
            if ($mode === 'active') self::assertArrayNotHasKey('sql_injection', $claims);
            else {
                self::assertSame('suspected', $claims['sql_injection']['state']);
                self::assertSame($native, $claims['sql_injection']['spans'][0]['excerpt']);
                self::assertSame($suffix, $claims['sql_injection']['spans'][1]['excerpt']);
                $this->assertExactSpans($input, $claims['sql_injection']['spans']);
            }
            $results[$mode] = $result;
        }
        self::assertSame($results['default']['suggestions'], $results['shadow']['suggestions']);
    }

    #[DataProvider('ambiguousQueries')]
    public function testDuplicateAndConflictingRawKeysAreNeverFlattenedIntoAUniqueBinding(string $query): void
    {
        $input = $this->input('jobs[]', self::VALUE, substr(self::VALUE, 1).'))');
        $data = $input->toArray();
        $data['url'] = 'https://fixture.invalid/jobs?'.$query;
        self::assertNull((new SqlDiagnosticSuspicion())->suggest(ObservationInput::fromArray($data)));
    }

    public static function ambiguousQueries(): iterable
    {
        $value = rawurlencode(self::VALUE);
        yield 'repeated array leaves' => ['jobs[]='.$value.'&jobs[]=other'];
        yield 'repeated explicit index' => ['jobs[0]='.$value.'&jobs[0]=other'];
        yield 'scalar and array conflict' => ['jobs='.$value.'&jobs[]=other'];
        yield 'implicit and explicit index conflict' => ['jobs[]='.$value.'&jobs[0]=other'];
        yield 'same long value in separate parameters' => ['first='.$value.'&second='.$value];
        yield 'encoded duplicate key' => ['jobs%5B%5D='.$value.'&jobs[]=other'];
        yield 'nested array unsupported' => ['jobs[group][0]='.$value];
    }

    #[DataProvider('unqualifiedFragments')]
    public function testShortAlteredOrUnboundFragmentsDoNotCreateSuspicion(string $value, string $fragment): void
    {
        self::assertNull((new SqlDiagnosticSuspicion())->suggest($this->input('jobs[]', $value, $fragment)));
    }

    public static function unqualifiedFragments(): iterable
    {
        yield 'short marker' => ["'fixtureMARK'", "'fixtureMARK'))"];
        yield 'less than ninety percent retained' => ["----'abcdefghijklmnopqrstuvwxyz012345", "'abcdefghijklmnopqrstuvwxyz012345))"];
        yield 'alphabetic omitted prefix' => ['A'.self::VALUE, self::VALUE.'))'];
        yield 'different marker case' => [self::VALUE, str_replace('fixtureMARK', 'fixturemark', substr(self::VALUE, 1)).'))'];
        yield 'embedded rather than leading suffix' => [self::VALUE, 'unrelated '.substr(self::VALUE, 1).'))'];
        yield 'appended identifier' => [self::VALUE, substr(self::VALUE, 1).'other_identifier'];
        yield 'too much SQL punctuation tail' => [self::VALUE, substr(self::VALUE, 1).str_repeat(')', 9)];
        yield 'too much LIKE punctuation tail' => [self::VALUE, substr(self::VALUE, 1).str_repeat('%', 9)];
        yield 'LIKE punctuation followed by identifier' => [self::VALUE, substr(self::VALUE, 1)."%' OR condition"];
        yield 'comment is not LIKE punctuation' => [self::VALUE, substr(self::VALUE, 1)."%'--"];
        yield 'backslash is not LIKE punctuation' => [self::VALUE, substr(self::VALUE, 1)."%\\'"];
        yield 'repeated URL decoding required' => [rawurlencode(self::VALUE), substr(self::VALUE, 1).'))'];
        yield 'no input quote' => ['A_distinctive_request_value_0123456789_long_marker', 'A_distinctive_request_value_0123456789_long_marker))'];
        yield 'repetitive padding is not distinctive' => ["'".str_repeat('a', 80), "'".str_repeat('a', 80).'))'];
        yield 'native near clause injected in value' => [self::VALUE, substr(self::VALUE, 1)." near 'another'"];
    }

    public function testAnySqlStateWrapperMustHaveTheExpectedStateAndCodeAtOneNativeErrorLocation(): void
    {
        $input = $this->input('jobs[]', self::VALUE, substr(self::VALUE, 1).'))');
        foreach ([
            str_replace('SQLSTATE[42000]', 'SQLSTATE[HY000]', $input->rawHtml),
            str_replace('SQLSTATE[42000]', 'localizedSQLSTATE[HY000]', $input->rawHtml),
            str_replace('SQLSTATE[42000]', 'SQLSTATE[42001]', $input->rawHtml),
            str_replace('1064', '1267', $input->rawHtml),
            str_replace('SQLSTATE[42000]:', 'SQLSTATE[42000]', $input->rawHtml),
            str_replace('1064 You', '1064You', $input->rawHtml),
            str_replace('SQLSTATE[42000]:', 'SQLSTATE[HY000]: SQLSTATE[42000]:', $input->rawHtml),
            str_replace('SQLSTATE[42000]:', 'SQLSTATE[42000]: SQLSTATE[42000]:', $input->rawHtml),
            str_replace('MySQL', 'Generic SQL', $input->rawHtml),
            str_replace('at line 1', "at line 1 near 'other' at line 2", $input->rawHtml),
            str_replace("' at line 1", '', $input->rawHtml),
            $input->rawHtml.$input->rawHtml,
        ] as $raw) {
            $data = $input->toArray(); $data['rawHtml'] = $raw;
            self::assertNull((new SqlDiagnosticSuspicion())->suggest(ObservationInput::fromArray($data)));
        }
    }

    public function testConflictingNumericErrorWrappersCannotBeSkippedForAWrapperlessNativeMessage(): void
    {
        foreach (['ERROR 1054 (42S22): ', 'mysqli_sql_exception (1054): ', 'PDOException (1054): ', '1054 - ', 'Syntax error or access violation: 1054 ', 'Warning: mysqli_query(): (42S22/1054): ', 'Warning: mysqli_query(): (HY000/1064): '] as $prefix) {
            $input = $this->input('job', self::VALUE, substr(self::VALUE, 2).'))', 'MariaDB', false);
            $input = ObservationInput::fromArray([...$input->toArray(), 'rawHtml' => '<div>'.$prefix.$this->nativeMessage(substr(self::VALUE, 2).'))', 'MariaDB', false).'</div>']);
            self::assertNull((new SqlDiagnosticSuspicion())->suggest($input), $prefix);
        }
        foreach (['ERROR 1064 (42000): ', 'Warning: mysqli_query(): (42000/1064): '] as $prefix) {
            $input = $this->input('job', self::VALUE, substr(self::VALUE, 2).'))', 'MariaDB', false);
            $input = ObservationInput::fromArray([...$input->toArray(), 'rawHtml' => '<div>'.$prefix.$this->nativeMessage(substr(self::VALUE, 2).'))', 'MariaDB', false).'</div>']);
            self::assertNotNull((new SqlDiagnosticSuspicion())->suggest($input));
        }
    }

    #[DataProvider('nativeFormats')]
    public function testCatalogsExamplesAndNativeWordsInsideSqlLiteralsAreExcluded(string $vendor, bool $wrapped): void
    {
        $input = $this->input('jobs[]', self::VALUE, substr(self::VALUE, 1).'))', $vendor, $wrapped);
        $native = $this->nativeMessage(substr(self::VALUE, 1).'))', $vendor, $wrapped);
        foreach ([
            '<script>const diagnostic='.json_encode($native).';</script>',
            json_encode(['labels' => ['diagnostic' => $native]]),
            '<section class="documentation">'.$input->rawHtml.'</section>',
            '<code>'.$native.'</code>',
            '<template>'.$input->rawHtml.'</template>',
            '<div>DB ERR SELECT * FROM records WHERE value=\''.$native.'\'</div>',
            '<pre>SELECT \''.$native.'\' FROM records</pre>',
        ] as $raw) {
            $data = $input->toArray(); $data['rawHtml'] = $raw;
            self::assertNull((new SqlDiagnosticSuspicion())->suggest(ObservationInput::fromArray($data)));
        }
    }

    public function testJsonNativeErrorWithUnescapedSingleQuotesRemainsOutsideThisHtmlHeuristic(): void
    {
        $value = "--'abcdefghijklmnopqrstuvwxyz0123456789'";
        $input = $this->input('jobs[]', $value, substr($value, 1).'))');
        $data = $input->toArray();
        $data['rawHtml'] = json_encode(['error' => ['message' => $this->nativeMessage(substr($value, 1).'))')]]);
        self::assertNull((new SqlDiagnosticSuspicion())->suggest(ObservationInput::fromArray($data)));
    }

    #[DataProvider('nativeFormats')]
    public function testWhollySuppliedDiagnosticAndSecondBoundValueCannotProvideIndependentEvidence(string $vendor, bool $wrapped): void
    {
        $native = $this->nativeMessage(substr(self::VALUE, 1).'))', $vendor, $wrapped);
        $input = $this->input('jobs[]', self::VALUE, substr(self::VALUE, 1).'))', $vendor, $wrapped);
        foreach ([
            ['url' => $input->url.'&message='.rawurlencode($native)],
            ['payload' => $native],
            ['payload' => '<b>'.htmlspecialchars($native, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</b>'],
        ] as $override) self::assertNull((new SqlDiagnosticSuspicion())->suggest(ObservationInput::fromArray([...$input->toArray(), ...$override])));
    }

    private function input(string $name, string $value, string $fragment, string $vendor = 'MySQL', bool $wrapped = true): ObservationInput
    {
        return ObservationInput::fromArray([
            'url' => 'https://fixture.invalid/jobs?'.rawurlencode($name).'='.rawurlencode($value),
            'rawHtml' => '<html><body><div id="error"><h1>Ä😀 Error</h1><ul><li><strong>Message:</strong>Framework exception - An \'PDOException\' occurs : '.$this->nativeMessage($fragment, $vendor, $wrapped).'</li></ul></div></body></html>',
            'contentType' => 'text/html', 'httpStatus' => 200,
            'expectedEvidence' => 'fixtureMARK', 'dialogSeen' => true, 'dialogText' => 'fixtureMARK',
        ]);
    }

    private function nativeMessage(string $fragment, string $vendor = 'MySQL', bool $wrapped = true): string
    {
        return ($wrapped ? 'SQLSTATE[42000]: Syntax error or access violation: 1064 ' : '').'You have an error in your SQL syntax; check the manual that corresponds to your '.$vendor.' server version for the right syntax to use near \''.$fragment.'\' at line 1';
    }

    private function assertExactSpans(ObservationInput $input, array $spans): void
    {
        foreach ($spans as $span) {
            self::assertSame('raw', $span['representation']);
            self::assertSame($span['excerpt'], mb_substr($input->rawHtml, $span['start'], $span['length'], 'UTF-8'));
        }
    }
}
