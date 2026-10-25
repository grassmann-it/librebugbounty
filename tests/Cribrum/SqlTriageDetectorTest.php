<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\SqlTriageDetector;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlTriageDetectorTest extends TestCase
{
    public function testJsonTailStartsOnUtf8BoundaryAndKeepsBudgetCoverage(): void
    {
        foreach (['A', 'é', '한', '🙂'] as $character) {
            $prefix = str_repeat('x', 590).$character;
            $raw = $prefix.str_repeat('z', 32768 - 1).'<p>SQLSTATE[HY000]: General error</p>';
            $input = ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $raw]);
            $evaluation = (new SqlTriageDetector())->evaluate($input);
            self::assertNotSame('partial', $evaluation->status, $character);
            self::assertNotContains('regex_error', array_column($evaluation->familyOutcomes[0]['stages'][0]['issues'] ?? [], 'code'));
            self::assertContains('sql_error_observed', array_column($evaluation->signals, 'code'));
        }
    }

    public function testAggregateQueryImmediatelyBeforeDiagnosticIsTypedDisclosure(): void
    {
        $query = "SELECT COUNT(entries.id) AS count FROM entries WHERE entries.code='broken'";
        $body = '<div class="message">' . "\n" . $query . "\n\nYou have an error in your SQL syntax at line 1</div>";
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?code=broken', 'rawHtml' => $body]);
        $signals = (new SqlTriageDetector())->evaluateQueryDisclosures($input)->signals;
        self::assertCount(1, $signals);
        self::assertSame('internal_information_exposed', $signals[0]['code']);
        self::assertSame($query, $signals[0]['spans'][0]['excerpt']);
        EvidenceSignal::validateAgainstInput($signals[0], $input);
        $withBreaks = '<div>'."\n".$query."\n<br /><br />\nYou have an error in your SQL syntax at line 1".'</div>';
        self::assertCount(1, (new SqlTriageDetector())->evaluateQueryDisclosures(ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $withBreaks]))->signals);
        $unrelated = '<p>'.$query.'</p><script>const sample="You have an error in your SQL syntax";</script>';
        self::assertSame([], (new SqlTriageDetector())->evaluateQueryDisclosures(ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $unrelated]))->signals);
    }

    public function testHiddenInputSearchReportsTruncation(): void
    {
        $body = '<form>'.str_repeat('<input type="hidden" name="other" value="ordinary">', 129)
            .'<input type="hidden" name="sQuery" value="SELECT id FROM records WHERE active=1"></form>';
        $result = (new SqlTriageDetector())->evaluateQueryDisclosures(ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $body]));
        self::assertSame('limited', $result->status);
        self::assertSame([], $result->signals);
        $issues = array_merge(...array_column($result->familyOutcomes[0]['stages'], 'issues'));
        self::assertContains('match_budget', array_column($issues, 'code'));
    }
    #[DataProvider('cases')]
    public function testBoundedSqlEvidence(string $query, string $body, array $expected): void
    {
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?'.$query, 'rawHtml' => $body, 'httpStatus' => 200]);
        $signals = (new SqlTriageDetector())->detect($input);
        $codes = array_values(array_unique(array_column($signals, 'code')));
        self::assertSame($expected, $codes);
        foreach ($signals as $signal) {
            EvidenceSignal::validateAgainstInput($signal, $input);
            self::assertTrue($signal['shadow']);
            self::assertNotContains('xss', array_values($signal['attributes']));
        }
    }

    public static function cases(): iterable
    {
        $diagnostic = '[Microsoft][ODBC Microsoft Access Driver] Syntax error in query expression ';
        $all = ['sql_error_observed', 'input_in_sql_error', 'sql_input_structure_indication'];
        yield 'Access encoded unquoted operand' => ['id=%2527%253E-mark', $diagnostic."'ID = %27%3E-mark'.", $all];
        yield 'Access entity comparison and Unicode offsets' => ['q=%3Cmarker%3E', 'ä<p>'.$diagnostic."'item = &lt;marker&gt;'.</p>", $all];
        yield 'Access quote operand' => ['q=%27broken', $diagnostic."'language <> ''broken'.", $all];
        yield 'missing operator' => ['q=%27broken', "<pre>Microsoft JET Database Engine\nSyntax error (missing operator) in query expression 'language <> ''broken'.</pre>", $all];
        yield 'Access balanced parenthesized left column' => ['q=marker', $diagnostic."'(((items.id)=marker))'.", $all];
        yield 'Access four balanced left wrappers' => ['q=marker', $diagnostic."'((((items.id))))=marker'.", $all];
        yield 'Access left wrapper depth is bounded' => ['q=marker', $diagnostic."'(((((items.id)))))=marker'.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'Access parenthesized CSTR quote operand' => ['q=%27broken', $diagnostic."'((CSTR(id))=''broken)'.", $all];
        yield 'Access parenthesized concatenated operand' => ['q=%27broken', $diagnostic."'(given_name & ' ' & family_name) LIKE '%'broken%'.", $all];
        yield 'Access malformed closing wrapper stays context' => ['q=marker', $diagnostic."'(items.id))=marker'.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'Access wrapped arithmetic unsupported' => ['q=marker', $diagnostic."'(items.id + 1)=marker'.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'JET statement tail phrase is diagnostic only' => ['q=marker', '<p>Microsoft JET Database Engine Characters found after end of SQL statement. marker</p>', ['sql_error_observed', 'input_in_sql_error']];
        $iis = '<li>Error Type:<br>Microsoft OLE DB Provider for ODBC Drivers (0x80040E14)<br>'.$diagnostic."'(id=marker)'.<br><b>/fixture.asp, line 7</b><br></li>";
        yield 'IIS Error Type expression excludes footer' => ['q=marker', '<table><tr><td>'.$iis.'<li>Page: marker</li></td></tr></table>', $all];
        yield 'IIS full input only in footer stays context' => ['q=marker', str_replace('(id=marker)', '(id=mark)', $iis).'<li>Page: marker</li>', ['sql_error_observed']];
        yield 'IIS truncated expression stays context' => ['q=marker', str_replace("(id=marker)'.", '(id=marker)', $iis), ['sql_error_observed', 'input_in_sql_error']];
        yield 'IIS another query in footer cannot qualify fragment' => ['q=marker', str_replace("'(id=marker)'.", "'(id=mark)'.", $iis).'<li>Query: SELECT * FROM items WHERE id=marker</li>', ['sql_error_observed']];
        yield 'no repeated URL decoding' => ['q=%25253Cmarker%25253E', $diagnostic."'ID = <marker>'.", ['sql_error_observed']];
        yield 'unknown transform' => ['q=lowercase', $diagnostic."'ID = LOWERCASE'.", ['sql_error_observed']];
        yield 'global echo outside block' => ['q=marker', $diagnostic."'ID = broken'.<div>marker</div>", ['sql_error_observed']];
        yield 'collation parameter binding' => ['q=marker', '<pre>SqlException: Cannot resolve the collation conflict. Query: SELECT * FROM items WHERE alias = ? Params: marker</pre>', ['sql_error_observed', 'input_in_sql_error']];
        yield 'type conversion' => ['q=marker', "Conversion failed when converting the varchar value 'marker' to data type int.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'postgres bound parameter' => ['q=marker', 'ERROR: invalid input syntax for type integer: "marker" unnamed portal parameter $1', ['sql_error_observed', 'input_in_sql_error']];
        yield 'postgres missing table' => ['q=marker', 'ERROR: relation "marker" does not exist', ['sql_error_observed', 'input_in_sql_error']];
        yield 'SQL Server missing object' => ['q=marker', "Invalid object name 'marker'.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'numeric fragment not an exact input' => ['q=1234', "Incorrect syntax near '123456'.", ['sql_error_observed']];
        yield 'word fragment not an exact input' => ['q=marker', "Incorrect syntax near 'xmarkerx'.", ['sql_error_observed']];
        yield 'mysql data length failure' => ['q=marker', "Data too long for column 'uri' at row 1", ['sql_error_observed']];
        yield 'mysql collation' => ['q=marker', "Illegal mix of collations (latin1_general_ci,IMPLICIT) and (utf8mb3_general_ci,COERCIBLE) for operation '='", ['sql_error_observed']];
        yield 'vendor marker alone is not diagnostic' => ['q=marker', '[Microsoft][ODBC Microsoft Access Driver]', []];
        yield 'classic ASP implicitly closed paragraph' => ['id=%2527%253E-mark', '<p><font>'.$diagnostic."'ID = %27%3E-mark'.</font><p><font>/fixture.asp, line 7</font>", $all];
        yield 'unclosed ordinary HTML diagnostic block' => ['q=marker', '<p>'.$diagnostic."'ID = marker'.", ['sql_error_observed']];
        yield 'length failure' => ['q=marker', 'ERROR: value too long for type character varying(4): marker', ['sql_error_observed', 'input_in_sql_error']];
        yield 'short syntax fragment' => ['q=marker', "Incorrect syntax near 'mark'.", ['sql_error_observed']];
        yield 'mysql syntax fragment only' => ['q=marker', "You have an error in your SQL syntax near 'marker' at line 1", ['sql_error_observed', 'input_in_sql_error']];
        yield 'postgres syntax full query' => ['q=%27broken', "<pre>ERROR: syntax error at or near \"'broken\"\nSTATEMENT: SELECT * FROM items WHERE name = ''broken</pre>", $all];
        yield 'duplicate parameter ambiguous' => ['q=marker&q=other', $diagnostic."'ID = marker'.", ['sql_error_observed']];
        yield 'array parameter ambiguous' => ['q%5B%5D=marker', $diagnostic."'ID = marker'.", ['sql_error_observed']];
        yield 'ordinary quoted data insufficient' => ['q=marker', $diagnostic."'name = 'marker' AND broken'.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'incomplete diagnostic container' => ['q=marker', '<pre>'.$diagnostic."'ID = marker'.", ['sql_error_observed']];
        yield 'documentation code example' => ['q=marker', '<h1>Documentation</h1><code>'.$diagnostic."'ID = marker'.</code>", []];
        yield 'German Access exact unquoted operand' => ['id=marker', "<p>Syntaxfehler in Abfrageausdruck 'ID = marker'.</p>", $all];
        yield 'German Access documentation' => ['id=marker', "<code>Syntaxfehler in Abfrageausdruck 'ID = marker'.</code>", []];
        yield 'JET Cstr quote break' => ['id=%27broken', "<p>Syntax error (missing operator) in query expression 'Cstr(id)=''broken' AND online = true'.</p>", $all];
        yield 'JET LIKE wildcard quote break' => ['q=%27broken', $diagnostic."'name LIKE '%'broken%' OR active = true'.", $all];
        yield 'JET LIKE underscore wildcard quote break' => ['q=%27broken', $diagnostic."'name LIKE '_'broken%'.", $all];
        yield 'JET concatenated LIKE wildcard quote break' => ['q=%27broken', $diagnostic."'given_name & ' ' & family_name LIKE '%'broken%'.", $all];
        yield 'JET concatenated LIKE doubled quote separator' => ['q=%27broken', $diagnostic."'given_name & 'it''s' & family_name LIKE '%'broken%'.", $all];
        yield 'JET concatenated LIKE repeated full input' => ['q=%27broken', $diagnostic."'person.Active=True And (Given_Name & ' ' & Family_Name Like '%'broken%' OR Birth_Given_Name & ' ' & Birth_Family_Name Like '%'broken%' OR Godparents Like '%'broken%') Order by InStr(Given_Name, ''broken')'.", $all];
        yield 'JET concatenated LIKE quoted input is data' => ['q=marker', $diagnostic."'given_name & ' ' & family_name LIKE '%marker%' AND broken'.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'JET concatenated LIKE safe doubled quote input' => ['q=O%27%27Brien', $diagnostic."'given_name & ' ' & family_name LIKE '%O''Brien%' AND broken'.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'JET LIKE safe doubled quote input' => ['q=O%27%27Brien', $diagnostic."'name LIKE '%O''Brien%' AND broken'.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'JET LIKE ordinary quoted data' => ['q=marker', $diagnostic."'name LIKE '%marker%' AND broken'.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'querystring equals inside literal is data' => ['q=marker', $diagnostic."'path = 'index.asp?kind=marker' AND broken'.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'querystring equals after escaped quote remains data' => ['q=marker', $diagnostic."'path = 'it''s?kind=marker' AND broken'.", ['sql_error_observed', 'input_in_sql_error']];
        yield 'literal quote pairs do not change later operand' => ['q=marker', $diagnostic."'path = 'it''s' AND id = marker'.", $all];
        $postgres = "<pre>ERROR: syntax error at or near &quot;missing&quot;\nSTATEMENT: ";
        foreach ([
            'double quoted data or identifier' => 'SELECT * FROM items WHERE note = "id=marker"',
            'backtick identifier' => 'SELECT * FROM items WHERE `id=marker` = 1',
            'bracket identifier' => 'SELECT * FROM items WHERE [id=marker] = 1',
            'block comment' => 'SELECT * FROM items /* id=marker */',
            'line comment' => 'SELECT * FROM items -- id=marker',
            'hash comment' => 'SELECT * FROM items # id=marker',
            'doubled double quote' => 'SELECT * FROM items WHERE "it""s id=marker" = 1',
            'doubled bracket' => 'SELECT * FROM items WHERE [it]]s id=marker] = 1',
            'doubled backtick' => 'SELECT * FROM items WHERE `it``s id=marker` = 1',
            'dollar quoting unknown' => 'SELECT * FROM items WHERE note = $tag$id=marker$tag$',
        ] as $label => $sql) {
            yield 'lexical negative '.$label => ['q=marker', $postgres.$sql.'</pre>', ['sql_error_observed', 'input_in_sql_error']];
        }
        yield 'closed comment permits later neutral operand' => ['q=marker', $postgres.'SELECT * FROM items /* note = missing */ WHERE id = marker</pre>', $all];
        yield 'closed double identifier permits later neutral operand' => ['q=marker', $postgres.'SELECT * FROM "items" WHERE id = marker</pre>', $all];
        yield 'closed bracket identifier permits later neutral operand' => ['q=marker', $postgres.'SELECT * FROM [items] WHERE id = marker</pre>', $all];
        yield 'closed backtick identifier permits later neutral operand' => ['q=marker', $postgres.'SELECT * FROM `items` WHERE id = marker</pre>', $all];
        yield 'uncertain backslash escape stays context' => ['q=marker', $postgres."SELECT * FROM items WHERE note = 'it\\'s' AND id = marker</pre>", ['sql_error_observed', 'input_in_sql_error']];
        yield 'backslash quote inside input is ambiguous escaped data' => ['q=O%5C%27Brien', "<pre>You have an error in your SQL syntax\nQuery: SELECT * FROM items WHERE name = 'O\\'Brien' AND broken</pre>", ['sql_error_observed', 'input_in_sql_error']];
        yield 'LIKE quote input with later backslash remains ambiguous' => ['q=%27broken%5Ctail', $diagnostic."'name LIKE '%'broken\\tail%' AND broken'.", ['sql_error_observed', 'input_in_sql_error']];
        $mysqlMessage = "You have an error in your SQL syntax; check MySQL near 'marker AND active = 1' at line 2";
        $mysqlQuery = "SELECT item_id<br>FROM items<br>WHERE id = marker AND active = 1";
        yield 'Lucee adjacent message and multiline SQL' => ['q=marker', self::lucee($mysqlMessage, $mysqlQuery), $all];
        yield 'Lucee near starts after closing input quote but SQL is exact' => ['q=%27broken', self::lucee("You have an error in your SQL syntax; MySQL near 'broken' at line 3", "SELECT * FROM items WHERE id = ''broken"), $all];
        yield 'Lucee table in code example' => ['q=marker', '<code>'.self::lucee($mysqlMessage, $mysqlQuery).'</code>', []];
        yield 'Lucee table in samp example' => ['q=marker', '<samp>'.self::lucee($mysqlMessage, $mysqlQuery).'</samp>', []];
        yield 'Lucee real error after local code example' => ['q=marker', '<code>'.self::lucee($mysqlMessage, $mysqlQuery).'</code>'.self::lucee($mysqlMessage, $mysqlQuery), $all];
        yield 'Lucee near fragment lacks complete input' => ['q=marker', self::lucee(str_replace("near 'marker", "near 'mark", $mysqlMessage), $mysqlQuery), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Lucee near word fragment is not input' => ['q=marker', self::lucee(str_replace("near 'marker", "near 'xmarkerx", $mysqlMessage), $mysqlQuery), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Lucee complete input only in query quoted data' => ['q=marker', self::lucee($mysqlMessage, "SELECT * FROM items WHERE name = 'marker'"), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Lucee placeholders remain diagnostic' => ['q=marker', self::lucee($mysqlMessage, 'SELECT * FROM items WHERE id = ? /* marker */'), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Lucee collation is nonstructure' => ['q=marker', self::lucee('Illegal mix of collations for marker', $mysqlQuery), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Lucee incomplete SQL cell stays message only' => ['q=marker', str_replace('</td></tr></table>', '', self::lucee($mysqlMessage, $mysqlQuery)), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Lucee unrelated table cannot supply SQL' => ['q=marker', self::lucee($mysqlMessage, 'SELECT 1').self::lucee('Ordinary output', $mysqlQuery), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Lucee message without SQL and global query' => ['q=marker', '<table id="-lucee-err"><tr><td>Lucee Error (database)</td></tr><tr><td>Message</td><td>'.$mysqlMessage.'</td></tr></table><p>Query: '.$mysqlQuery.'</p>', ['sql_error_observed', 'input_in_sql_error']];
        yield 'generic adjacent table cells are not error object' => ['q=marker', str_replace('id="-lucee-err"', 'id="results"', self::lucee($mysqlMessage, $mysqlQuery)), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Lucee no query relation across intervening row' => ['q=marker', str_replace('<td>SQL</td>', '<td>Other</td><td>marker</td></tr><tr><td>SQL</td>', self::lucee($mysqlMessage, $mysqlQuery)), ['sql_error_observed', 'input_in_sql_error']];
        yield 'German SQLServer near fragment needs context' => ['id=%25252527%2525253Emarker', '<td>[SQLServer]Falsche Syntax in der N&auml;he von &#x27;252527&#x27;.</td>', ['sql_error_observed']];
        yield 'German SQLServer diagnostic example' => ['id=marker', '<code>Falsche Syntax in der Nähe von marker.</code>', []];
        yield 'MySQL incorrect datetime is nonstructure' => ['q=marker', "<pre>Incorrect DATETIME value: 'marker' Query: SELECT * FROM items WHERE stamp = marker</pre>", ['sql_error_observed', 'input_in_sql_error']];
        yield 'datetime unrelated value' => ['q=marker', '<p>System.FormatException: String was not recognized as a valid DateTime: test</p><div>marker</div>', []];
        yield 'database name documentation alone' => ['q=marker', '<p>Microsoft SQL Server documentation and MySQL examples</p>', []];
        yield 'Yii executed SQL grouped operand' => ['category=%2527probe', self::yii('SELECT COUNT(*) FROM items WHERE (visible=1) AND (category_id=%27probe)'), $all];
        yield 'Yii once decoded entity operand' => ['category=%3Cprobe%3E', self::yii('SELECT * FROM items WHERE category_id=&lt;probe&gt;'), $all];
        yield 'Yii complete value only in diagnostic' => ['category=marker', self::yii('SELECT * FROM items WHERE category_id=other', 'near marker: syntax error'), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Yii partial value in SQL' => ['category=marker', self::yii('SELECT * FROM items WHERE category_id=mark'), ['sql_error_observed']];
        yield 'Yii transformed value in SQL' => ['category=marker', self::yii('SELECT * FROM items WHERE category_id=MARKER'), ['sql_error_observed']];
        yield 'Yii no repeated URL decoding' => ['category=%253Cprobe%253E', self::yii('SELECT * FROM items WHERE category_id=&lt;probe&gt;'), ['sql_error_observed']];
        yield 'Yii no repeated entity decoding' => ['category=%3Cprobe%3E', self::yii('SELECT * FROM items WHERE category_id=&amp;lt;probe&amp;gt;'), ['sql_error_observed']];
        yield 'Yii quoted data insufficient' => ['category=marker', self::yii("SELECT * FROM items WHERE category_id='marker'"), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Yii placeholder with echoed value' => ['category=marker', self::yii('SELECT * FROM items WHERE category_id=:category /* marker */'), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Yii duplicate parameters' => ['category=marker&category=other', self::yii('SELECT * FROM items WHERE category_id=marker'), ['sql_error_observed']];
        yield 'Yii separate paragraph cannot supply query' => ['category=marker', '<p>SQLSTATE[HY000]: General error: near marker: syntax error.</p><p>The SQL statement executed was: SELECT * FROM items WHERE category_id=marker</p>', ['sql_error_observed', 'input_in_sql_error']];
        yield 'Yii separated error objects' => ['category=marker', self::yii('SELECT 1', 'near marker: syntax error').'<p>The SQL statement executed was: SELECT * FROM items WHERE category_id=marker</p>', ['sql_error_observed', 'input_in_sql_error']];
        yield 'Yii repeated object markers in same block' => ['category=marker', self::yii('SELECT 1. SQLSTATE[HY000]: General error: near marker: syntax error. The SQL statement executed was: SELECT * FROM items WHERE category_id=marker'), ['sql_error_observed', 'input_in_sql_error']];
        yield 'Yii incomplete error paragraph' => ['category=marker', substr(self::yii('SELECT * FROM items WHERE category_id=marker'), 0, -4), ['sql_error_observed']];
        yield 'Yii documentation example' => ['category=marker', '<code>'.self::yii('SELECT * FROM items WHERE category_id=marker').'</code>', []];
        yield 'German varchar to int diagnostic' => ['id=marker', '<p>Fehler beim Konvertieren des varchar-Werts &quot;marker&quot; in den int-Datentyp.</p>', ['sql_error_observed', 'input_in_sql_error']];
        yield 'German varchar conversion with complete SQL stays diagnostic' => ['id=%3Cprobe%3E', '<pre>Fehler beim Konvertieren des varchar-Werts &quot;&lt;probe&gt;&quot; in den int-Datentyp. Query: SELECT * FROM items WHERE id = &lt;probe&gt;</pre>', ['sql_error_observed', 'input_in_sql_error']];
        yield 'German varchar converted fragment' => ['id=marker', '<p>Fehler beim Konvertieren des varchar-Werts &quot;mark&quot; in den int-Datentyp.</p>', ['sql_error_observed']];
        yield 'German conversion documentation' => ['id=marker', '<code>Fehler beim Konvertieren des varchar-Werts &quot;marker&quot; in den int-Datentyp.</code>', []];
    }

    private static function yii(string $query, string $message = 'near &quot;%&quot;: syntax error'): string
    {
        return '<p class="message">CDbCommand failed to execute the SQL statement: SQLSTATE[HY000]: General error: 1 '
            .$message.'. The SQL statement executed was: '.$query.'</p>';
    }

    private static function lucee(string $message, string $query): string
    {
        return '<table id="-lucee-err"><tr><td>Lucee 6.0 Error (database)</td></tr>'
            .'<tr><td>Message</td><td>'.$message.'</td></tr>'
            .'<tr><td>SQL</td><td>'.$query.'</td></tr></table>';
    }

    #[DataProvider('linkAndOperandCases')]
    public function testLinkParametersNeedTheirOwnSqlOperand(array $params, string $expression, array $mapped, array $structural): void
    {
        $body = 'ä😀<p>Syntax error (missing operator) in query expression &#039;'
            .htmlspecialchars($expression, ENT_QUOTES | ENT_HTML5, 'UTF-8').'&#039;.</p>';
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?'.http_build_query($params), 'rawHtml' => $body]);
        $signals = (new SqlTriageDetector())->detect($input);
        self::assertSame('sql_error_observed', $signals[0]['code']);
        $parametersFor = static fn (string $code): array => array_values(array_map(
            static fn (array $signal): string => $signal['attributes']['parameter'],
            array_filter($signals, static fn (array $signal): bool => $signal['code'] === $code),
        ));
        self::assertSame($mapped, $parametersFor('input_in_sql_error'));
        self::assertSame($structural, $parametersFor('sql_input_structure_indication'));
        foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
        foreach (array_filter($signals, static fn (array $signal): bool => $signal['code'] === 'sql_input_structure_indication') as $signal) {
            self::assertSame('suspected_only', $signal['attributes']['assessment']);
            self::assertSame('input_at_sql_structure_error', $signal['relations'][1]['type']);
            self::assertSame(htmlspecialchars($params[$signal['attributes']['parameter']], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $signal['spans'][2]['excerpt']);
        }
    }

    public static function linkAndOperandCases(): iterable
    {
        $payload = "'><probe alt=\"\">-marker";
        $damaged = "'><probe alt=[]>-marker";
        foreach (['kind' => 'Panel7', 'view' => 'Übersicht'] as $name => $value) {
            yield $name.' before damaged link input' => [
                ['route' => $payload, $name => $value],
                "Enabled = 1 AND Target = 'page.asp&".$name.'='.$value.'&route='.$damaged."'", [$name], [],
            ];
            yield $name.' after damaged link input' => [
                ['route' => $payload, $name => $value],
                "Enabled = 1 AND Target = '".$damaged.'&'.$name.'='.$value."&cat=140'", [$name], [],
            ];
            yield $name.' in intact link' => [
                [$name => $value], "Enabled = 1 AND Target = 'page.asp&".$name.'='.$value."&cat=140'", [$name], [],
            ];
            yield $name.' as real full unquoted operand' => [
                [$name => $payload], 'Enabled = 1 AND category_id = '.$payload, [$name], [$name],
            ];
            yield $name.' ordinary value as real full operand' => [
                [$name => $value], 'Enabled = 1 AND category_id = '.$value, [$name], [$name],
            ];
        }
        yield 'URL and ampersand in genuine full operand' => [
            ['route' => 'https://fixture.invalid/page.asp&kind=Panel7&cat=140'],
            'Enabled = 1 AND Target = https://fixture.invalid/page.asp&kind=Panel7&cat=140', ['route'], ['route'],
        ];
        yield 'link-like SQL left expression remains ambiguous' => [
            ['id' => 'marker'], "Link = ''broken&something=(id=marker'", ['id'], [],
        ];
        yield 'concatenation-looking damaged link tail is not an operand' => [
            ['kind' => 'Panel7'], "Enabled = 1 AND Target = ''broken&given_name & ' ' & family_name=Panel7&cat=140'", ['kind'], [],
        ];
        yield 'link parameter before concatenation-looking damaged tail is not an operand' => [
            ['kind' => 'Panel7'], "Enabled = 1 AND Target = 'page.asp&kind=Panel7&given_name & ' ' & family_name=''broken'", ['kind'], [],
        ];
        yield 'comparison-shaped text inside a literal stays data' => [
            ['id' => "'broken"], "Link = 'page AND id='broken'", ['id'], [],
        ];
        yield 'grouped SQL operand' => [
            ['id' => 'marker'], 'Enabled = 1 AND (category_id = marker)', ['id'], ['id'],
        ];
    }

    public function testLuceeQueryEvidenceKeepsRawSpansAndSuspectedAssessment(): void
    {
        $body = 'ä'.self::lucee("You have an error in your SQL syntax; MySQL near '&lt;probe&gt;' at line 2", 'SELECT id<br>FROM items<br>WHERE id = &lt;probe&gt;');
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?q=%3Cprobe%3E', 'rawHtml' => $body]);
        $signals = (new SqlTriageDetector())->detect($input);
        $structural = $signals[2];
        EvidenceSignal::validateAgainstInput($structural, $input);
        self::assertSame('sql_input_structure_indication', $structural['code']);
        self::assertSame('suspected_only', $structural['attributes']['assessment']);
        self::assertSame('&lt;probe&gt;', $structural['spans'][2]['excerpt']);
        self::assertSame('SELECT id<br>FROM items<br>WHERE id = &lt;probe&gt;', $structural['spans'][3]['excerpt']);
        self::assertSame('input_at_sql_structure_error', $structural['relations'][1]['type']);
    }

    #[DataProvider('vendorCases')]
    public function testVendorRequiresDatabaseEvidence(string $body, string $vendor, string $diagnosticClass): void
    {
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?q=marker', 'rawHtml' => $body]);
        $signals = (new SqlTriageDetector())->detect($input);
        self::assertNotEmpty($signals);
        foreach ($signals as $signal) {
            self::assertSame($vendor, $signal['attributes']['vendor']);
            self::assertSame($diagnosticClass, $signal['attributes']['diagnosticClass']);
        }
    }

    public static function vendorCases(): iterable
    {
        yield 'Yii and generic error do not identify vendor' => [self::yii('SELECT * FROM items WHERE id=marker'), 'unknown', 'syntax'];
        yield 'generic SQLSTATE without vendor' => ['<p>SQLSTATE[42000]: General error: marker</p>', 'unknown', 'database_error'];
        yield 'generic interface failure is not ACE evidence' => ['<p>SQLSTATE[HY000]: General error: interface failure for marker</p>', 'unknown', 'database_error'];
        yield 'unqualified SqlException is not SQLServer proof' => ['<p>SqlException: Database failure: marker</p>', 'unknown', 'database_error'];
        yield 'unqualified syntax near has no default vendor' => ["<p>Incorrect syntax near 'marker'.</p>", 'unknown', 'syntax'];
        yield 'explicit PostgreSQL' => ['<p>PostgreSQL ERROR: syntax error at or near marker</p>', 'postgresql', 'syntax'];
        yield 'specific PostgreSQL type diagnostic' => ['ERROR: invalid input syntax for type integer: "marker"', 'postgresql', 'nonstructure'];
        yield 'explicit SQLServer marker' => ['<p>[SQLServer]Falsche Syntax in der Nähe von marker.</p>', 'sql_server', 'syntax'];
        yield 'explicit SQLServer OLE DB provider' => ["<p>Microsoft OLE DB Provider for SQL Server: Incorrect syntax near 'marker'.</p>", 'sql_server', 'syntax'];
        yield 'explicit SQLServer ODBC provider' => ['<p>[Microsoft][ODBC SQL Server Driver][SQL Server]Falsche Syntax in der N&#228;he von marker.</p>', 'sql_server', 'syntax'];
        yield 'ODBC provider in quoted input is no vendor evidence' => ["<p>Incorrect syntax near '[ODBC SQL Server Driver]'.</p>", 'unknown', 'syntax'];
        yield 'qualified dotnet SQLServer exception' => ['<p>System.Data.SqlException: Database failure: marker</p>', 'sql_server', 'database_error'];
        yield 'English SQLServer conversion' => ["Conversion failed when converting the varchar value 'marker' to data type int.", 'sql_server', 'nonstructure'];
        yield 'German SQLServer conversion' => ['<p>Fehler beim Konvertieren des varchar-Werts &quot;marker&quot; in den int-Datentyp.</p>', 'sql_server', 'nonstructure'];
        yield 'Access expression diagnostic' => ["Syntax error in query expression 'id=marker'.", 'access_jet', 'syntax'];
        yield 'MySQL syntax diagnostic' => ["You have an error in your SQL syntax near 'marker' at line 1", 'mysql', 'syntax'];
        yield 'explicit Access provider' => ['<p>[Microsoft][ODBC Microsoft Access Driver] SQLSTATE[HY000]: General error: marker</p>', 'access_jet', 'database_error'];
        yield 'explicit JET engine' => ['<p>Microsoft JET Database Engine SQLSTATE[HY000]: General error: marker</p>', 'access_jet', 'database_error'];
        yield 'JET statement tail with same local provider' => ['<p>Microsoft JET Database Engine Characters found after end of SQL statement.</p>', 'access_jet', 'database_error'];
        yield 'JET statement tail alone has unknown vendor' => ['<p>Characters found after end of SQL statement.</p>', 'unknown', 'database_error'];
        yield 'JET statement tail classic ASP provider pair' => ["<font><p>Microsoft JET Database Engine</font> <font>error '80040e14'</font><p><font>Characters found after end of SQL statement.</font><p><font>/fixture.asp, line 7</font>", 'access_jet', 'database_error'];
        yield 'unrelated JET provider cannot identify phrase' => ['<p>Microsoft JET Database Engine</p><p>Characters found after end of SQL statement.</p>', 'unknown', 'database_error'];
        yield 'explicit ACE provider' => ['<p>Microsoft.ACE.OLEDB.12.0 SQLSTATE[HY000]: General error: marker</p>', 'access_jet', 'database_error'];
        yield 'explicit MySQL driver' => ['<p>[MySQL ODBC Driver] SQLSTATE[HY000]: General error: marker</p>', 'mysql', 'database_error'];
        yield 'explicit PostgreSQL driver' => ['<p>[PostgreSQL JDBC Driver] SQLSTATE[HY000]: General error: marker</p>', 'postgresql', 'database_error'];
        yield 'explicit PostgreSQL exception' => ['<p>PG::DatabaseError: SQLSTATE[HY000]: General error: marker</p>', 'postgresql', 'database_error'];
        yield 'specific MySQL diagnostic after SQLSTATE wrapper' => ['<p>SQLSTATE[HY000]: General error: 1267 Illegal mix of collations for marker</p>', 'mysql', 'nonstructure'];
        yield 'free vendor words in generic diagnostic' => ['<p>SQLSTATE[HY000]: General error: access JET ACE MySQL PostgreSQL pgsql marker</p>', 'unknown', 'database_error'];
        yield 'Access provider phrase in quoted input' => ["<p>Incorrect syntax near 'Microsoft JET Database Engine'.</p>", 'unknown', 'syntax'];
        yield 'SQLServer provider marker in quoted input' => ["<p>Incorrect syntax near '[SQLServer]'.</p>", 'unknown', 'syntax'];
        yield 'SQLServer OLE DB provider in quoted input' => ["<p>Incorrect syntax near 'Microsoft OLE DB Provider for SQL Server'.</p>", 'unknown', 'syntax'];
        yield 'qualified SQLServer exception in quoted input' => ["<p>Incorrect syntax near 'System.Data.SqlException'.</p>", 'unknown', 'syntax'];
        yield 'MySQL diagnostic phrase in quoted input' => ["<p>Incorrect syntax near 'You have an error in your SQL syntax'.</p>", 'unknown', 'syntax'];
        yield 'Access diagnostic phrase in quoted input' => ["<p>Incorrect syntax near 'Syntax error in query expression'.</p>", 'unknown', 'syntax'];
    }

    #[DataProvider('vendorQueryCases')]
    public function testQueryNamesCannotSupplyVendorEvidence(string $body): void
    {
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?category=%2527probe', 'rawHtml' => $body]);
        $signals = (new SqlTriageDetector())->detect($input);
        self::assertSame(['sql_error_observed', 'input_in_sql_error', 'sql_input_structure_indication'], array_column($signals, 'code'));
        foreach ($signals as $signal) {
            self::assertSame('unknown', $signal['attributes']['vendor']);
            EvidenceSignal::validateAgainstInput($signal, $input);
        }
    }

    public static function vendorQueryCases(): iterable
    {
        foreach (['items', 'access', 'JET', 'ACE', 'mysql', 'postgresql', 'pgsql', '[SQLServer]', '"Microsoft SQL Server"', '"Microsoft OLE DB Provider for SQL Server"'] as $table) {
            $query = 'SELECT * FROM '.$table.' WHERE category_id=%27probe';
            yield 'Yii table '.$table => [self::yii($query)];
            yield 'inline Query table '.$table => ['<pre>SQLSTATE[42000]: syntax error. Query: '.$query.'</pre>'];
            yield 'multiline STATEMENT table '.$table => ["<pre>SQLSTATE[42000]: syntax error.\nSTATEMENT: ".$query.'</pre>'];
            yield 'Lucee SQL cell table '.$table => [self::lucee("SQLSTATE[42000]: syntax error near '%27probe' at line 2", $query)];
        }
    }

    public function testYiiEvidenceKeepsRawEntitySpansAndFullInput(): void
    {
        $body = 'ä😀'.self::yii('SELECT * FROM items WHERE category_id=&lt;probe&gt;');
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?category=%3Cprobe%3E', 'rawHtml' => $body]);
        $signals = (new SqlTriageDetector())->detect($input);
        self::assertSame(['sql_error_observed', 'input_in_sql_error', 'sql_input_structure_indication'], array_column($signals, 'code'));
        $signal = $signals[2];
        EvidenceSignal::validateAgainstInput($signal, $input);
        self::assertSame('unknown', $signal['attributes']['vendor']);
        self::assertSame('category', $signal['attributes']['parameter']);
        self::assertSame('suspected_only', $signal['attributes']['assessment']);
        self::assertSame('&lt;probe&gt;', $signal['spans'][2]['excerpt']);
        self::assertSame('SELECT * FROM items WHERE category_id=&lt;probe&gt;', $signal['spans'][3]['excerpt']);
        self::assertSame(['url_query_decode_once', 'html_entities_once_for_comparison'], $signal['relations'][1]['transformations']);
        self::assertSame('input_at_sql_structure_error', $signal['relations'][1]['type']);
    }

    public function testSignalSeparatesRawEntitySpanAndNamedComparison(): void
    {
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?q=%3Cmarker%3E', 'rawHtml' => "[Microsoft][ODBC Microsoft Access Driver] Syntax error in query expression 'ID = &lt;marker&gt;'."]);
        $signals = (new SqlTriageDetector())->detect($input, 'active');
        $relation = $signals[1];
        self::assertSame('&lt;marker&gt;', $relation['spans'][2]['excerpt']);
        self::assertSame(['url_query_decode_once', 'html_entities_once_for_comparison'], $relation['relations'][0]['transformations']);
        self::assertFalse($relation['shadow']);
        self::assertSame($signals, (new SqlTriageDetector())->detect($input, 'active'));
    }

    #[DataProvider('jsonMessageCases')]
    public function testJsonErrorMessageIsCompleteAndDecodedOnce(string $body, string $value, bool $mapped, ?string $rawInput): void
    {
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?q='.rawurlencode($value), 'rawHtml' => $body]);
        $signals = (new SqlTriageDetector())->detect($input);
        self::assertSame($mapped ? ['sql_error_observed', 'input_in_sql_error'] : ['sql_error_observed'], array_column($signals, 'code'));
        foreach ($signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
        if ($mapped) {
            self::assertTrue($signals[1]['attributes']['blockComplete']);
            self::assertSame(['url_query_decode_once', 'json_string_decode_once'], $signals[1]['relations'][0]['transformations']);
            self::assertSame($rawInput, $signals[1]['spans'][2]['excerpt']);
            self::assertStringNotContainsString('"file"', $signals[1]['spans'][0]['excerpt']);
            self::assertStringNotContainsString('"file"', $signals[1]['spans'][1]['excerpt']);
        }
    }

    public static function jsonMessageCases(): iterable
    {
        $message = 'You have an error in your SQL syntax near \'%s\' at line 1';
        $json = static fn (string $value): string => json_encode(['data' => ['error' => ['message' => sprintf($message, $value), 'file' => '/is/private/fixture.php']]], JSON_THROW_ON_ERROR);
        yield 'complete JSON tail within HTML' => ["ä😀<script>/* app */</script>\n".$json('marker'), 'marker', true, 'marker'];
        yield 'Unicode and surrogate pair raw spans' => [$json('ä😀probe'), 'ä😀probe', true, '\u00e4\ud83d\ude00probe'];
        yield 'escaped quote and slash raw spans' => [$json('"/probe'), '"/probe', true, '\"\/probe'];
        yield 'backslash is decoded once' => [$json('\\u006darker'), 'marker', false, null];
        yield 'entities are not decoded again inside JSON' => [$json('&lt;probe&gt;'), '<probe>', false, null];
        yield 'full input only in sibling field' => [json_encode(['data' => ['error' => ['message' => sprintf($message, 'mark'), 'file' => 'marker']]], JSON_THROW_ON_ERROR), 'marker', false, null];
        yield 'full input only in title' => ['<h1>marker</h1>'.$json('mark'), 'marker', false, null];
        yield 'complete root error object' => [json_encode(['error' => ['message' => sprintf($message, 'marker')]], JSON_THROW_ON_ERROR), 'marker', true, 'marker'];
        yield 'JSON cannot supply query from sibling' => [json_encode(['data' => ['error' => ['message' => sprintf($message, 'marker'), 'query' => 'SELECT * FROM items WHERE id=marker']]], JSON_THROW_ON_ERROR), 'marker', true, 'marker'];
        yield 'unclosed JSON object does not establish completeness' => [substr($json('marker'), 0, -1), 'marker', false, null];
        yield 'unclosed JSON string does not establish completeness' => ['{"error":{"message":"'.sprintf($message, 'marker'), 'marker', false, null];
    }

    public function testSqlSignatureInInactiveHtmlOrGenericJsonIsNotAnObservedError(): void
    {
        $signature = 'SQLSTATE[42000]: Syntax error or access violation';
        foreach ([
            '<script>const messages=["'.$signature.'"];</script>',
            '<section class="documentation"><p>'.$signature.'</p></section>',
            json_encode(['labels' => ['sample' => $signature]], JSON_THROW_ON_ERROR),
            json_encode(['message' => $signature], JSON_THROW_ON_ERROR),
            json_encode(['labels' => ['sample' => $signature], 'error' => ['message' => 'ordinary']], JSON_THROW_ON_ERROR),
        ] as $body) {
            self::assertSame([], (new SqlTriageDetector())->detect(ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $body])), $body);
        }
        $real = json_encode(['labels' => ['sample' => $signature], 'error' => ['message' => $signature]], JSON_THROW_ON_ERROR);
        self::assertSame(['sql_error_observed'], array_column((new SqlTriageDetector())->detect(ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $real])), 'code'));
    }

    public function testExplicitManualAndSearchExamplesDoNotBecomeSqlErrors(): void
    {
        $detector = new SqlTriageDetector();
        foreach ([
            '<p>Manual: You have an error in your SQL syntax when quoting is wrong.</p>',
            '<p>Search: PostgreSQL ERROR: syntax error at or near</p>',
            '<article>You have an error in your SQL syntax when quoting is wrong.</article>',
            '<article>You have an error in your SQL syntax if a quote is left open.</article>',
            '<article>You have an error in your SQL syntax can occur when expressions are malformed.</article>',
            '<article>You have an error in your SQL syntax means the statement could not be parsed.</article>',
        ] as $body) {
            self::assertSame([], $detector->detect(ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $body])), $body);
        }
        foreach ([
            '<p>You have an error in your SQL syntax; check the manual that corresponds to your server version</p>',
            '<p>You have an error in your SQL syntax near unexpected token at line 1</p>',
            '<h2>Manual</h2><p>You have an error in your SQL syntax near token at line 1</p>',
        ] as $body) {
            self::assertSame(['sql_error_observed'], array_column($detector->detect(ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $body])), 'code'), $body);
        }
    }
    public function testQueryDisclosureNeedsNoInputAndKeepsIndependentRawSpans(): void
    {
        $query = "SELECT title\nFROM fixture_entries\nWHERE MATCH (title) AGAINST ('needleVALUE*' IN BOOLEAN MODE)";
        $body = "前🙂<h1>[Emergency] Uncaught Fixture\\DatabaseException: Couldn't run query:\n\n".$query."\n\nCan't find FULLTEXT index matching the column list</h1>"
            .'<pre>Stack trace: fixtureRun(needleVALUE)</pre>';
        foreach (['https://fixture.test/', 'https://fixture.test/?Search=needleVALUE'] as $url) {
            $input = ObservationInput::fromArray(['url' => $url, 'rawHtml' => $body]);
            $evaluation = (new SqlTriageDetector())->evaluateQueryDisclosures($input);
            self::assertSame('completed', $evaluation->status);
            self::assertCount(1, $evaluation->signals);
            $signal = $evaluation->signals[0];
            self::assertSame('query', $signal['attributes']['detailKind']);
            self::assertSame('no_input_binding_claimed', $signal['attributes']['inputAssessment']);
            self::assertSame("Can't find FULLTEXT index matching the column list", $signal['spans'][0]['excerpt']);
            self::assertSame($query, $signal['spans'][1]['excerpt']);
            EvidenceSignal::validateAgainstInput($signal, $input);
            $sql = (new SqlTriageDetector())->detect($input);
            self::assertSame($url === 'https://fixture.test/' ? ['sql_error_observed'] : ['sql_error_observed', 'input_in_sql_error'], array_column($sql, 'code'));
            self::assertSame('nonstructure', $sql[0]['attributes']['diagnosticClass']);
        }
    }

    public function testPlaceholderQueryAndLaterCallCannotSupplyAnInputRelation(): void
    {
        $body = '<strong>An exception occurred while executing \'SELECT title FROM fixture_pages WHERE alias=? AND language_id=?\' with params [&quot;\\uff07fixture&quot;, 0]:'
            ."\n\nIllegal mix of collations for operation '='</strong>"
            .'<pre>Call9 getPageIdFromAlias(＇fixture)</pre>';
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?id='.rawurlencode('＇fixture'), 'rawHtml' => $body]);
        $detector = new SqlTriageDetector();
        $signals = $detector->evaluateQueryDisclosures($input)->signals;
        self::assertCount(1, $signals);
        self::assertSame('SELECT title FROM fixture_pages WHERE alias=? AND language_id=?', $signals[0]['spans'][1]['excerpt']);
        self::assertSame(['sql_error_observed'], array_column($detector->detect($input), 'code'));
        EvidenceSignal::validateAgainstInput($signals[0], $input);
    }

    #[DataProvider('queryDisclosureCountercases')]
    public function testQueryDisclosureExcludesUnboundAndStaticStatements(string $body): void
    {
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?q=needleVALUE', 'rawHtml' => $body]);
        self::assertSame([], (new SqlTriageDetector())->evaluateQueryDisclosures($input)->signals);
    }

    public static function queryDisclosureCountercases(): iterable
    {
        $query = 'SELECT title FROM fixture_entries WHERE id=needleVALUE';
        yield 'trace only' => ['<h1>DatabaseException: failed</h1><pre>Stack trace: runQuery('.$query.')</pre>'];
        yield 'later query cannot join another object' => ['<p>SQLSTATE[HY000]: General error</p><p>Query: '.$query.'</p>'];
        yield 'SQL after explicit trace' => ['<pre>SQLSTATE[HY000]: General error' . "\nStack trace:\nQuery: ".$query.'</pre>'];
        yield 'documentation' => ['<code><p>SQLSTATE[HY000]: General error. Query: '.$query.'</p></code>'];
        yield 'comment' => ['<!-- <p>SQLSTATE[HY000]: General error. Query: '.$query.'</p> -->'];
        yield 'dictionary' => ['<script>const strings={"SQLSTATE[HY000]: General error. Query: '.$query.'":"label"}</script>'];
        yield 'source formatter' => ['<pre>function renderFailure() { return "SQLSTATE[HY000]: General error. Query: '.$query.'"; }</pre>'];
        yield 'query value example is data' => ['<pre>const text = "SQLSTATE[HY000]: General error. Query: '.$query.'";</pre>'];
        yield 'partial statement' => ['<p>SQLSTATE[HY000]: General error. Query: SELECT title</p>'];
        $wrapper = "<strong>An exception occurred while executing 'SELECT title FROM fixture_pages WHERE alias=?' with params []:\nIllegal mix of collations for operation '='</strong>";
        yield 'fake tag inside quoted attribute' => ['<div data-x="'.$wrapper.'">ordinary</div>'];
        yield 'unquoted documentation ancestor' => ['<article class=documentation>'.$wrapper.'</article>'];
        yield 'source formatter ancestor' => ['<pre class=language-php>'.$wrapper.'</pre>'];
        yield 'documentation ancestor' => ['<article class="documentation">'.$wrapper.'</article>'];
        yield 'template ancestor' => ['<template>'.$wrapper.'</template>'];
        yield 'unclosed header' => ['<h1>Uncaught Fixture\\DatabaseException: Couldn\'t run query: '.$query."\nCan't find FULLTEXT index matching the column list"];
    }

    public function testQueryDisclosureRetainsLegacyInlineDomAndReportsBudgets(): void
    {
        foreach (['rawHtml', 'domText'] as $key) {
            $input = ObservationInput::fromArray(['url' => 'https://fixture.test/', $key => 'SQL error Query: SELECT title FROM fixture_entries WHERE id=7']);
            $evaluation = (new SqlTriageDetector())->evaluateQueryDisclosures($input);
            self::assertCount(1, $evaluation->signals);
            self::assertSame($key === 'rawHtml' ? 'raw' : 'dom_text', $evaluation->signals[0]['spans'][1]['representation']);
            EvidenceSignal::validateAgainstInput($evaluation->signals[0], $input);
        }
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => str_repeat('<p>normal</p>', 129)]);
        self::assertSame('limited', (new SqlTriageDetector())->evaluateQueryDisclosures($input)->status);
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => '<pre>SQLSTATE[HY000]: error. Query: SELECT title FROM fixture_entries WHERE id='.str_repeat('x', 2100).'</pre>']);
        self::assertSame('limited', (new SqlTriageDetector())->evaluateQueryDisclosures($input)->status);
    }

    public function testTraceArgumentIsNotInputInThePrecedingSqlDiagnostic(): void
    {
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?q=needleVALUE', 'rawHtml' => "<pre>SQLSTATE[HY000]: General error\nStack trace:\n#0 fixtureRun(needleVALUE)</pre>"]);
        self::assertSame(['sql_error_observed'], array_column((new SqlTriageDetector())->detect($input), 'code'));
    }

    public function testFulltextDiagnosticMapsOnlyQueryLiteralAndNotHeaderOrTrace(): void
    {
        $body = "<h1>[Emergency] Uncaught SyntheticNamespace\\DatabaseException: Couldn't run query:\nSELECT title FROM fixture_entries WHERE MATCH (title) AGAINST ('needleVALUE*' IN BOOLEAN MODE)\nCan't find FULLTEXT index matching the column list</h1>";
        $detector = new SqlTriageDetector();
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?q=SyntheticNamespace', 'rawHtml' => $body]);
        self::assertSame(['sql_error_observed'], array_column($detector->detect($input), 'code'));
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?q=needleVALUE', 'rawHtml' => $body]);
        $signals = $detector->detect($input);
        self::assertSame('whole_value_in_query_literal', $signals[1]['attributes']['inputBinding']);
        self::assertSame('input_in_query_literal', $signals[1]['relations'][1]['type']);
        EvidenceSignal::validateAgainstInput($signals[1], $input);
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => '<pre>SQLSTATE[HY000]: error. Query: SELECT title FROM fixture_entries WHERE name=\'example\'</pre>']);
        self::assertCount(1, $detector->evaluateQueryDisclosures($input)->signals);
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => '<h1>SQLSTATE[HY000]: error. Query: SELECT title FROM fixture_entries WHERE id='.str_repeat('x', 9000).'</h1>']);
        self::assertSame('limited', $detector->evaluateQueryDisclosures($input)->status);
    }

    public function testFulltextWholeValueCrossingQuoteIsOnlyExpressionCorrespondence(): void
    {
        $value = "needleVALUE' OR 1=1";
        $body = "<h1>Uncaught Fixture\\DatabaseException: Couldn't run query:\nSELECT title FROM fixture_entries WHERE MATCH (title) AGAINST ('".$value."*' IN BOOLEAN MODE)\nCan't find FULLTEXT index matching the column list</h1>";
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?q='.rawurlencode($value), 'rawHtml' => $body]);
        $signals = (new SqlTriageDetector())->detect($input);
        self::assertSame(['sql_error_observed', 'input_in_sql_error'], array_column($signals, 'code'));
        self::assertSame('whole_value_in_query_expression', $signals[1]['attributes']['inputBinding']);
        self::assertSame('input_in_query_expression', $signals[1]['relations'][1]['type']);
    }

    public function testSharedQueryTextGateRejectsAttributeAndStaticSyntax(): void
    {
        $detector = new SqlTriageDetector();
        foreach ([
            '<a title="SELECT value FROM entries WHERE id=7">open</a>',
            '<div data-x="<strong>SELECT value FROM entries WHERE id=7</strong>">ordinary</div>',
            '<div data-x="<strong>SELECT value FROM entries WHERE id=7</strong>',
            '<!-- SELECT value FROM entries WHERE id=7 -->',
            '<template>SELECT value FROM entries WHERE id=7</template>',
            '<article class="documentation">SELECT value FROM entries WHERE id=7</article>',
            '<script>const text="SELECT value FROM entries WHERE id=7"</script>',
        ] as $body) self::assertFalse($detector->isActiveQueryText($body, strpos($body, 'SELECT')));
        foreach (['SELECT value FROM entries WHERE id=7', '<pre>SELECT value FROM entries WHERE id=7</pre>', '<script>const x="<fake \'unterminated";</script><p>SELECT value FROM entries WHERE id=7</p>'] as $body) {
            self::assertTrue($detector->isActiveQueryText($body, strpos($body, 'SELECT')));
        }
    }

    public function testQueryOnlyHtmlComparisonDoesNotConsumeUnusedJsonTailBudget(): void
    {
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => '<html><!--'.str_repeat('x', 40000).'--><pre>SQL error Query: SELECT title FROM fixture_entries WHERE id=7</pre></html>']);
        $evaluation = (new SqlTriageDetector())->evaluateQueryDisclosures($input);
        self::assertSame('completed', $evaluation->status);
        self::assertCount(1, $evaluation->signals);
        self::assertNotContains('json_comparison', array_column($evaluation->familyOutcomes[0]['stages'], 'stage'));
    }

    public function testAttributeLookingDataDoesNotBecomeDocumentationContext(): void
    {
        $wrapper = "<strong>An exception occurred while executing 'SELECT title FROM fixture_pages WHERE alias=?' with params []:\nIllegal mix of collations for operation '='</strong>";
        foreach (['<article data-note="class=\'documentation\'">', '<article data-note="unrelated class=\'documentation\' text">', '<article class=ordinary class=documentation>'] as $open) {
            $body = $open.$wrapper.'</article>';
            $input = ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $body]);
            self::assertCount(1, (new SqlTriageDetector())->evaluateQueryDisclosures($input)->signals);
        }
    }

    public function testNewFulltextSqlObservationAlsoRequiresActiveDiagnosticText(): void
    {
        $wrapper = "<h1>Uncaught Fixture\\DatabaseException: Couldn't run query:\nSELECT title FROM fixture_entries WHERE title='needleVALUE'\nCan't find FULLTEXT index matching the column list</h1>";
        foreach (['<div data-value="'.$wrapper.'">ordinary</div>', '<article class=documentation>'.$wrapper.'</article>', '<template>'.$wrapper.'</template>'] as $body) {
            $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?q=needleVALUE', 'rawHtml' => $body]);
            self::assertSame([], (new SqlTriageDetector())->detect($input));
        }
    }

}
