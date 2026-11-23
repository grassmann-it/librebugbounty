<?php

namespace App\Tests\Cribrum;

use App\Cribrum\CribrumClassifier;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use App\Cribrum\ReportPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Canonical helper policy examples, not a family holdout or a coverage claim. */
final class SqlSuspicionPolicyMatrixTest extends TestCase
{
    private const VALUE = '-->\'"></title></noscript></script><img src=0 onerror=alert(\'fixtureMARK\')>';
    private const RELEVANT_FACTS = [
        'error_response', 'input_in_sql_error', 'internal_information_exposed',
        'sql_context_required', 'sql_error_observed', 'sql_input_structure_indication',
    ];

    #[DataProvider('policyMatrix')]
    public function testExplicitClaimsFactsAndReportGates(array $fixture, string $mode, array $expected): void
    {
        $input = ObservationInput::fromArray([
            ...$fixture,
            'contentType' => 'text/html', 'httpStatus' => 200,
            'expectedEvidence' => 'fixtureMARK', 'dialogSeen' => true, 'dialogText' => 'fixtureMARK',
            'coverage' => ['status' => 'complete', 'truncated' => false],
        ]);
        $classifier = $mode === 'default' ? new CribrumClassifier('/nonexistent')
            : new CribrumClassifier('/nonexistent', dirname(__DIR__, 2).'/resources/cribrum/candidates', $mode);
        $result = $classifier->classify($input)->toArray();

        // These expectations are authored from the evidence in each fixture;
        // neither default output nor shadow parity serves as the test oracle.
        $claims = array_column($result['suggestions'], null, 'claim');
        self::assertSame($expected['claims'], array_column($result['suggestions'], 'state', 'claim'));
        if ($expected['sqlRule'] !== null) {
            self::assertSame([$expected['sqlRule']], $claims['sql_injection']['ruleIds']);
        }
        self::assertSame(['builtin/dialog-marker'], $claims['xss']['ruleIds']);

        $evidence = array_values(array_filter($result['signals'], static fn (array $signal): bool => ($signal['kind'] ?? null) === 'observation_evidence'));
        self::assertSame($expected['evidence'], $this->relevantCodes($evidence));
        foreach ($evidence as $signal) {
            EvidenceSignal::validateAgainstInput($signal, $input);
            self::assertSame($mode, $signal['mode']);
            self::assertNotSame('sql_input_structure_indication', $signal['code']);
            self::assertNotContains('input_at_sql_structure_error', array_column($signal['relations'], 'type'));
            if ($signal['code'] === 'sql_error_observed') self::assertSame($expected['sqlBlockComplete'], $signal['attributes']['blockComplete']);
        }

        $context = $this->reportContext($input, $result);
        self::assertSame($expected['facts'], $this->relevantCodes($context['facts']));
        $withoutProof = (new ReportPolicy())->evaluate($context);
        $this->assertReport($withoutProof, $expected['withoutProof']);

        $context['workerProof'] = $this->nativeWorkerProof($context);
        $withProof = (new ReportPolicy())->evaluate($context);
        $this->assertReport($withProof, $expected['withProof']);
        self::assertSame('confirmed', array_column($withProof['components'], 'certainty', 'code')['xss_confirmed']);

        // A suspected core claim cannot satisfy an explicit SQLi report request.
        $context['selection'] = [['component' => 'sqli_suspected', 'mode' => 'include']];
        $requestedSql = (new ReportPolicy())->evaluate($context);
        $this->assertReport($requestedSql, ['hold', $expected['withProof'][1], $expected['withProof'][2]]);
        self::assertContains('sql_basis_incomplete', $requestedSql['reasonCodes']);
    }

    public static function policyMatrix(): iterable
    {
        $native = static fn (string $fragment): string => 'SQLSTATE[42000]: Syntax error or access violation: 1064 '
            ."You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near '".$fragment."' at line 1";
        $nativeUrl = 'https://fixture.invalid/jobs?jobs%5B%5D='.rawurlencode(self::VALUE);
        $query = static fn (array $values): string => 'SELECT f.fixture_id FROM fixture_items f WHERE f.fixture_kind IN('
            .implode(',', $values).') ORDER BY f.fixture_name';
        $pdoQuery = $query([self::VALUE, '2', '3', '1']);
        $pdoBody = '<span class="fixture-error">PDO Error: '."SQLSTATE[HY000]: General error: 20018 Incorrect syntax near '('. [20018] (severity 15) ["
            .$pdoQuery.'] Query: '.$pdoQuery.'<br /></span><pre>#0 /srv/fixture/controller.php(12): Fixture\\Query->execute()</pre>';
        $pdoUrl = 'https://fixture.invalid/search?type%5B%5D='.rawurlencode(self::VALUE).'&type%5B%5D=2&type%5B%5D=3&type%5B%5D=1';
        $phql = 'SELECT [Fixture\\Models\\Vehicle].* FROM [Fixture\\Models\\Vehicle] WHERE enabled=1 AND id IN ({ids:array}) ORDER BY enabled ASC, '.self::VALUE.' LIMIT :APL0:';
        $phqlBody = "Syntax error, unexpected token >, near to '".self::VALUE."', when parsing: ".$phql.' ('.strlen($phql).')<br><pre>#0 [internal function]: Phalcon\\Mvc\\Model\\Query\\Lang::parsePHQL()'
            ."\n#1 /srv/fixture/model.php(31): Demo\\Query->execute()\n#2 {main}</pre>";
        $alertBody = '<html><body><script>alert('.json_encode(
            "You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near '".self::VALUE."' at line 7",
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ).');history.go(-1);</script>';
        $accessBody = "<html><body><p>Microsoft Access Database Engine error '80040e14'</p><p>Errore di sintassi nella stringa nell'espressione della query '"
            .htmlspecialchars('(record_id='.self::VALUE.') AND hidden=false', ENT_QUOTES | ENT_HTML5, 'UTF-8')
            ."'.</p><p>/record.asp, line 42</p>";

        $blockQuery = 'SELECT V.* FROM fixture_valores V'."\n".' LEFT JOIN fixture_seo S ON V.seo_id = S.id'."\n".' WHERE 1 AND S.url_es = \''.self::VALUE.'\'';
        $blockBody = '<!DOCTYPE html><html lang="en"><body><div class="md:flex"><div class="max-w-sm"><div class="font-black">Debug output</div>'
            .'<p class="leading-normal">'."\n".$blockQuery."\n".'</p>'."\n\n".'</div></div></body></html>';

        $fixtures = [
            'native full value' => [
                ['url' => $nativeUrl, 'rawHtml' => '<div class="error">'.$native(self::VALUE.'))').'</div>'],
                'builtin/mysql-syntax-input-suffix', ['error_disclosure' => 'observed', 'xss' => 'observed'],
                ['sql_error_observed'], true,
            ],
            'native suffix in unfinished carrier' => [
                ['url' => $nativeUrl, 'rawHtml' => '<html><body><div class="error"><p>Application failed: '.$native(substr(self::VALUE, 2).'))')],
                'builtin/mysql-syntax-input-suffix', ['error_disclosure' => 'observed', 'xss' => 'observed'],
                ['sql_error_observed'], false,
            ],
            'PDO ordered append array' => [
                ['url' => $pdoUrl, 'rawHtml' => $pdoBody],
                'builtin/sql-query-array-input', ['error_disclosure' => 'observed', 'information_disclosure' => 'observed', 'xss' => 'observed'],
                ['internal_information_exposed', 'sql_error_observed'], true,
            ],
            'PHQL parser ORDER BY' => [
                ['url' => 'https://fixture.invalid/vehicles?orden='.rawurlencode(self::VALUE), 'rawHtml' => $phqlBody],
                'builtin/phql-order-input', ['information_disclosure' => 'observed', 'xss' => 'observed'],
                ['internal_information_exposed'], null,
            ],
            'native diagnostic without matching request value' => [
                ['url' => 'https://fixture.invalid/jobs?jobs%5B%5D=unrelated_value', 'rawHtml' => '<div class="error">'.$native(substr(self::VALUE, 2).'))').'</div>'],
                null, ['error_disclosure' => 'observed', 'xss' => 'observed'],
                ['sql_error_observed'], true,
            ],
            'PDO query without matching ordered request companions' => [
                ['url' => str_replace('=2&type%5B%5D=3', '=3&type%5B%5D=2', $pdoUrl), 'rawHtml' => $pdoBody],
                null, ['error_disclosure' => 'observed', 'information_disclosure' => 'observed', 'xss' => 'observed'],
                ['internal_information_exposed', 'sql_error_observed'], true,
            ],
            'PHQL parser without matching request value' => [
                ['url' => 'https://fixture.invalid/vehicles?orden=unrelated_value', 'rawHtml' => $phqlBody],
                null, ['information_disclosure' => 'observed', 'xss' => 'observed'],
                ['internal_information_exposed'], null,
            ],
            'PHP database result TypeError without native SQL diagnosis' => [
                ['url' => 'https://fixture.invalid/search?q='.rawurlencode(self::VALUE),
                    'rawHtml' => '<html><body><div><input value="'.self::VALUE.'"><b>Fatal error</b>: Uncaught TypeError: mysqli_fetch_array(): Argument #1 ($result) must be of type mysqli_result, bool given in /srv/fixture/search.php:42'
                        ."\nStack trace:\n#0 /srv/fixture/search.php(42): mysqli_fetch_array()\n#1 {main}\n"
                        .'  thrown in <b>/srv/fixture/search.php</b> on line <b>42</b><br>'],
                null, ['error_disclosure' => 'observed', 'information_disclosure' => 'observed', 'xss' => 'observed'],
                ['error_response', 'internal_information_exposed'], null,
            ],
            'PHP database result warning without native SQL diagnosis' => [
                ['url' => 'https://fixture.invalid/search?q='.rawurlencode(rawurlencode(self::VALUE)),
                    'rawHtml' => '<html><body><p>'.self::VALUE.'</p><b>Warning</b>: mysqli_num_rows() expects parameter 1 to be mysqli_result, bool given in <b>D:\\fixture\\search.php</b> on line <b>59</b>'],
                null, ['error_disclosure' => 'observed', 'information_disclosure' => 'observed', 'xss' => 'observed'],
                ['error_response', 'internal_information_exposed'], null,
            ],
            'serialized native diagnostic with unique request operand' => [
                ['url' => 'https://fixture.invalid/search?q='.rawurlencode(self::VALUE), 'rawHtml' => $alertBody],
                'builtin/sql-serialized-native-diagnostic', ['xss' => 'observed'],
                [], null, ['error_disclosure' => 'observed'],
            ],
            'serialized native diagnostic without input binding' => [
                ['url' => 'https://fixture.invalid/search?q=unrelated_value', 'rawHtml' => $alertBody],
                null, ['xss' => 'observed'], [], null, ['error_disclosure' => 'observed'],
            ],
            'localized native Access expression with unique operand' => [
                ['url' => 'https://fixture.invalid/record.asp?record_id='.rawurlencode(self::VALUE), 'rawHtml' => $accessBody],
                'builtin/access-expression-input', ['xss' => 'observed'],
                [], null, ['error_disclosure' => 'observed'],
            ],
            'localized native Access expression without input binding' => [
                ['url' => 'https://fixture.invalid/record.asp?record_id=unrelated_value', 'rawHtml' => $accessBody],
                null, ['xss' => 'observed'], [], null, ['error_disclosure' => 'observed'],
            ],
            'disclosed statement with unescaped path value' => [
                ['url' => 'https://fixture.invalid/'.rawurlencode(self::VALUE), 'rawHtml' => $blockBody],
                'builtin/sql-disclosed-query-input', ['information_disclosure' => 'observed', 'xss' => 'observed'],
                [], null,
            ],
            'native query disclosure with request reflection only outside query' => [
                ['url' => 'https://fixture.invalid/search?pag='.rawurlencode(self::VALUE),
                    'rawHtml' => '<br/>SQLSTATE[2201X]: &lt;&lt;Unknown error&gt;&gt;: 7 ERROR: OFFSET must not be negative, query was: '
                        .'SELECT f.fixture_id, f.fixture_name FROM fixture_items f WHERE f.enabled=true ORDER BY f.fixture_id LIMIT 15 OFFSET -15<br>'
                        .'<pre>Array ( [pag] =&gt; '.htmlspecialchars(self::VALUE, ENT_QUOTES | ENT_HTML5, 'UTF-8').' )</pre>'],
                null, ['error_disclosure' => 'observed', 'information_disclosure' => 'observed', 'xss' => 'observed'],
                ['internal_information_exposed', 'sql_error_observed'], true,
            ],
        ];

        foreach ($fixtures as $name => $case) {
            [$fixture, $sqlRule, $otherClaims, $candidateEvidence, $sqlBlockComplete] = $case;
            $withSqlClaim = $otherClaims + ($case[5] ?? []);
            if ($sqlRule !== null) $withSqlClaim['sql_injection'] = 'suspected';
            ksort($withSqlClaim);
            $hasDisclosure = in_array('internal_information_exposed', $candidateEvidence, true);
            $hasSqlError = in_array('sql_error_observed', $candidateEvidence, true) && $sqlBlockComplete;
            $activeWithoutProof = $hasDisclosure
                ? ['eligible', 'information_disclosure', $hasSqlError ? ['information_disclosure', 'sql_error_observation'] : ['information_disclosure']]
                : ['no_mail', 'none', []];
            $activeWithProof = $hasSqlError
                ? ['eligible', 'xss_with_sql_error', $hasDisclosure ? ['information_disclosure', 'sql_error_observation', 'xss_confirmed'] : ['sql_error_observation', 'xss_confirmed']]
                : ($hasDisclosure ? ['eligible', 'xss_with_disclosure', ['information_disclosure', 'xss_confirmed']] : ['eligible', 'xss', ['xss_confirmed']]);
            $expected = [
                'default' => ['claims' => $withSqlClaim, 'sqlRule' => $sqlRule, 'evidence' => [], 'facts' => [],
                    'withoutProof' => ['no_mail', 'none', []], 'withProof' => ['eligible', 'xss', ['xss_confirmed']]],
                'shadow' => ['claims' => $withSqlClaim, 'sqlRule' => $sqlRule, 'evidence' => $candidateEvidence, 'facts' => [],
                    'withoutProof' => ['no_mail', 'none', []], 'withProof' => ['eligible', 'xss', ['xss_confirmed']]],
                'active' => ['claims' => $otherClaims, 'sqlRule' => null, 'evidence' => $candidateEvidence, 'facts' => $candidateEvidence,
                    'withoutProof' => $activeWithoutProof, 'withProof' => $activeWithProof],
            ];
            foreach ($expected as $mode => $expectation) yield $name.' / '.$mode => [$fixture, $mode, [...$expectation, 'sqlBlockComplete' => $sqlBlockComplete]];
        }
    }

    private function relevantCodes(array $facts): array
    {
        $codes = array_values(array_unique(array_intersect(self::RELEVANT_FACTS, array_column($facts, 'code'))));
        sort($codes);
        return $codes;
    }

    /** Same active-only boundary as ReportProjectionService, without persistence. */
    private function reportContext(ObservationInput $input, array $result): array
    {
        $context = [
            'findingId' => 'policy-fixture', 'sourceInstanceId' => 'policy-source', 'basisRevision' => 1, 'decisionRevision' => 1,
            'poc' => ['intakeUrl' => $input->url, 'effectiveUrl' => $input->url, 'revision' => 1, 'sha256' => hash('sha256', $input->url)],
            'requestContextSha256' => hash('sha256', 'sanitized policy request context'), 'expectedMarker' => 'fixtureMARK',
            'claims' => array_map(static fn (array $claim): array => [...$claim, 'source' => 'classifier'], $result['suggestions']),
            'facts' => [], 'selection' => [], 'reportSelection' => ['mode' => 'auto'], 'intake' => ['payloadKind' => 'unknown'],
        ];
        foreach ($result['signals'] as $signal) {
            if (($signal['kind'] ?? null) !== 'observation_evidence' || ($signal['mode'] ?? null) !== 'active') continue;
            EvidenceSignal::validateAgainstInput($signal, $input);
            $fact = [
                'kind' => 'behavior', 'schemaVersion' => 1, 'factId' => $signal['evidenceId'],
                'findingId' => $context['findingId'], 'sourceInstanceId' => $context['sourceInstanceId'],
                'pocRevision' => $context['poc']['revision'], 'pocSha256' => $context['poc']['sha256'],
                'requestContextSha256' => $context['requestContextSha256'],
                'sourceRef' => ['kind' => 'http_observation', 'id' => 'policy-observation', 'artifactSha256' => hash('sha256', $input->rawHtml)],
                'capturedAt' => '2026-10-27T10:00:00Z', 'producer' => $signal['producer'].':'.$signal['producerVersion'],
                'mode' => $signal['mode'], 'code' => $signal['code'], 'attributes' => $signal['attributes'],
                'spans' => $signal['spans'], 'relations' => $signal['relations'],
            ];
            $fact['contentSha256'] = hash('sha256', json_encode($fact, JSON_THROW_ON_ERROR));
            $context['facts'][] = $fact;
        }
        return $context;
    }

    private function nativeWorkerProof(array $context): array
    {
        return [
            'proofId' => 'policy-proof', 'sourceKind' => 'screenshot_job', 'sourceId' => 'policy-job',
            'capturedAt' => '2026-10-27T10:00:00Z', 'sourcePageUrl' => $context['poc']['effectiveUrl'],
            'findingId' => $context['findingId'], 'sourceInstanceId' => $context['sourceInstanceId'],
            'pocRevision' => $context['poc']['revision'], 'pocSha256' => $context['poc']['sha256'],
            'requestContextSha256' => $context['requestContextSha256'],
            'expectedMarker' => 'fixtureMARK', 'dialogText' => 'fixtureMARK', 'dialogOrigin' => 'native',
            'attribution' => 'matched', 'matchPolicy' => 'native_marker_cs_substring_v1',
            'contentSha256' => hash('sha256', 'sanitized native worker proof'),
        ];
    }

    private function assertReport(array $report, array $expected): void
    {
        [$gate, $lane, $components] = $expected;
        self::assertSame($gate, $report['gate']);
        self::assertSame($lane, $report['lane']);
        self::assertSame($components, array_column($report['components'], 'code'));
        self::assertNotContains('sqli_suspected', array_column($report['components'], 'code'));
        self::assertFalse($report['urgencyAllowed']);
        self::assertNotContains('invalid_or_stale_fact', $report['caseSummary']['hints']);
        self::assertNotContains('unknown_fact', $report['caseSummary']['hints']);
        self::assertNotContains('sql_input_structure_indication', $report['tags']);
    }
}
