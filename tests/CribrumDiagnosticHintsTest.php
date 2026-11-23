<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\CribrumClaimExport;
use App\Service\CribrumDiagnosticHints as Hints;
use App\Service\CribrumFindingQuery as Query;
use App\Service\CribrumFindingSummary as Summary;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CribrumDiagnosticHintsTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE finding(id TEXT, poc_revision INTEGER, poc_url TEXT, url TEXT)',
            'CREATE TABLE finding_claim(finding_id TEXT, claim TEXT, state TEXT, report_selected INTEGER, severity TEXT, source TEXT, revision INTEGER, updated_at TEXT, observation_id TEXT)',
            'CREATE TABLE finding_claim_revision(finding_id TEXT, claim TEXT, revision INTEGER, state TEXT, source TEXT, observation_id TEXT, rule_ids TEXT)',
            'CREATE TABLE http_observation(id TEXT, finding_id TEXT, poc_revision INTEGER, metadata TEXT, artifact_sha256 TEXT, http_status INTEGER, content_type TEXT, created_at TEXT)',
            'CREATE TABLE finding_report_current(finding_id TEXT, is_current INTEGER, snapshot_json TEXT)',
        ] as $sql) $this->db->executeStatement($sql);
        self::assertTrue(Hints::available($this->db));
    }

    protected function tearDown(): void { $this->db->close(); }

    public function testRecognizedNativeErrorsStayObservedAndDistinctFromSqliAndGenericRuntime(): void
    {
        foreach ([
            'builtin/sql_observation' => 'sql_error_observed',
            'builtin/sql-serialized-native-diagnostic' => 'sql_error_observed',
            'builtin/access-expression-diagnostic' => 'sql_error_observed',
            'builtin/database-result-runtime' => 'database_runtime_error',
            'builtin/runtime-error' => null,
        ] as $rule => $expected) {
            $id = substr(hash('sha256', $rule), 0, 12);
            $snapshot = $this->fixture($id, [$rule]);
            foreach (['finding_claim', 'finding_claim_revision'] as $table) $this->db->update($table, ['source' => 'classifier'], ['finding_id' => $id]);
            $claims = CribrumClaimExport::forFindings($this->db, [$id])[$id];
            self::assertSame($expected === null ? [] : [$expected], $claims[0]['diagnosticHints']);
            $summary = Summary::derive($snapshot, $claims, true);
            $badges = array_column($summary['badges'], null, 'code');
            self::assertArrayHasKey('xss', $badges);
            self::assertArrayHasKey('error_disclosure', $badges);
            self::assertArrayNotHasKey('sql_injection', $badges);
            if ($expected !== null) {
                self::assertSame('observed', $badges[$expected]['state']);
                self::assertFalse($badges[$expected]['reportSelected']);
                self::assertFalse($badges[$expected]['qualifiedComponent']);
                self::assertSame([['kind' => 'http_observation', 'id' => 'obs-'.$id]], $badges[$expected]['evidenceRefs']);
            }
            if ($expected === 'database_runtime_error') self::assertArrayNotHasKey('sql_error_observed', $badges);
            self::assertSame($expected === null ? [] : [$expected], $this->diagnosticRows($id));
            self::assertSame($summary['additionalCount'], $this->additionalCount($id));
            self::assertStringNotContainsString('PRIVATE', json_encode($claims, JSON_THROW_ON_ERROR));
        }
    }

    #[DataProvider('ruleDocuments')]
    public function testPhpAndSqlRecognizeOnlyAnArrayOfWhitelistedStringValues(?string $json, array $expected): void
    {
        $snapshot = $this->fixture('json');
        $this->db->update('finding_claim_revision', ['rule_ids' => $json], ['finding_id' => 'json']);
        self::assertSame($expected, Hints::codes('error_disclosure', 'confirmed', $json));
        $claims = CribrumClaimExport::forFindings($this->db, ['json'])['json'];
        self::assertSame($expected, $claims[0]['diagnosticHints']);
        self::assertSame($expected, $this->diagnosticRows('json'));
        self::assertSame(Summary::derive($snapshot, $claims, true)['additionalCount'], $this->additionalCount('json'));
        self::assertSame([], Hints::codes('sql_injection', 'confirmed', $json));
        self::assertSame([], Hints::codes('error_disclosure', 'suspected', $json));
    }

    public static function ruleDocuments(): iterable
    {
        yield 'null' => [null, []];
        yield 'malformed' => ['["builtin/sql_observation"', []];
        yield 'object' => ['{"rule":"builtin/sql_observation"}', []];
        yield 'numeric-key-object' => ['{"0":"builtin/sql_observation"}', []];
        yield 'string' => ['"builtin/sql_observation"', []];
        yield 'number' => ['7', []];
        yield 'mixed-list' => ['[["x"],"builtin/sql_observation",17,null,true]', ['sql_error_observed']];
        yield 'duplicates' => ['["builtin/sql_observation","builtin/sql_observation"]', ['sql_error_observed']];
        yield 'lookalike' => ['["mysql_fetch_array","SQLSTATE","builtin/sql_observation-extra"]', []];
        yield 'two-kinds' => ['["builtin/sql_observation","builtin/database-result-runtime"]', ['sql_error_observed', 'database_runtime_error']];
    }

    #[DataProvider('revisionMismatches')]
    public function testNoRuleCauseIsBorrowedFromAnUnrelatedOrPastDecision(string $column, mixed $value): void
    {
        $this->fixture('exact');
        $this->db->update('finding_claim_revision', [$column => $value], ['finding_id' => 'exact']);
        $claims = CribrumClaimExport::forFindings($this->db, ['exact'])['exact'];
        self::assertSame([], $claims[0]['diagnosticHints']);
        self::assertSame([], $this->diagnosticRows('exact', true));
    }

    public static function revisionMismatches(): iterable
    {
        yield 'past-revision' => ['revision', 0];
        yield 'other-state' => ['state', 'observed'];
        yield 'earlier-classifier-owner' => ['source', 'classifier'];
        yield 'other-observation' => ['observation_id', 'old-observation'];
        yield 'no-observation' => ['observation_id', null];
        yield 'other-claim' => ['claim', 'information_disclosure'];
        yield 'other-finding' => ['finding_id', 'other'];
    }

    #[DataProvider('staleSources')]
    public function testManualIntentSurvivesButItsDiagnosticCauseMovesToHistory(string $change): void
    {
        $snapshot = $this->fixture('stale');
        $observation = ['id' => 'obs-stale'];
        $metadata = ['requestUrl' => 'https://stale.test/', 'requestContextSha256' => str_repeat('a', 64)];
        switch ($change) {
            case 'revision': $this->db->update('finding', ['poc_revision' => 2], ['id' => 'stale']); break;
            case 'url': $this->db->update('finding', ['poc_url' => 'https://stale.test/changed'], ['id' => 'stale']); break;
            case 'pin': $metadata['requestContextSha256'] = str_repeat('b', 64); break;
            case 'uppercase-pin': $metadata['requestContextSha256'] = str_repeat('A', 64); break;
            case 'short-pin': $metadata['requestContextSha256'] = 'a'; break;
            case 'unpinned': unset($metadata['requestContextSha256']); break;
            case 'missing-url': unset($metadata['requestUrl']); break;
            case 'source-hash': $this->db->update('http_observation', ['artifact_sha256' => str_repeat('d', 64)], $observation); break;
            case 'no-artifact': $this->db->update('http_observation', ['artifact_sha256' => null], $observation); break;
            case 'foreign-observation': $this->db->update('http_observation', ['finding_id' => 'other'], $observation); break;
            case 'stale-projection': $this->db->update('finding_report_current', ['is_current' => 0], ['finding_id' => 'stale']); break;
            case 'missing-projection': $this->db->delete('finding_report_current', ['finding_id' => 'stale']); break;
            case 'missing-coverage':
                unset($snapshot['coverage']);
                $this->db->update('finding_report_current', ['snapshot_json' => json_encode($snapshot)], ['finding_id' => 'stale']);
                break;
            default: throw new \LogicException('Unknown fixture change.');
        }
        $this->db->update('http_observation', ['metadata' => json_encode($metadata)], $observation);
        $claims = CribrumClaimExport::forFindings($this->db, ['stale'])['stale'];
        self::assertTrue($claims[0]['claimCurrent'], 'Manual intent stays live independently of its source.');
        self::assertFalse($claims[0]['diagnosticCurrent']);
        self::assertSame(['sql_error_observed'], $claims[0]['diagnosticHints']);
        $projection = $change === 'missing-projection' ? null : $snapshot;
        $summary = Summary::derive($projection, $claims, $change !== 'stale-projection');
        self::assertContains('error_disclosure', array_column($summary['badges'], 'code'));
        self::assertNotContains('sql_error_observed', array_column($summary['badges'], 'code'));
        self::assertContains('sql_error_observed', array_column($summary['history'], 'code'));
        self::assertSame([], $this->diagnosticRows('stale'));
        self::assertSame(['sql_error_observed'], $this->diagnosticRows('stale', true));
        self::assertSame($summary['additionalCount'], $this->additionalCount('stale'));
    }

    public static function staleSources(): iterable
    {
        foreach (['revision', 'url', 'pin', 'uppercase-pin', 'short-pin', 'unpinned', 'missing-url', 'source-hash', 'no-artifact', 'foreign-observation', 'stale-projection', 'missing-projection', 'missing-coverage'] as $change) yield $change => [$change];
    }

    public function testTerminalClaimsStayHistoricalAndReportTagsDeduplicate(): void
    {
        foreach (['rejected', 'resolved', 'stale'] as $state) {
            $snapshot = $this->fixture($state);
            foreach (['finding_claim', 'finding_claim_revision'] as $table) $this->db->update($table, ['state' => $state], ['finding_id' => $state]);
            $claims = CribrumClaimExport::forFindings($this->db, [$state])[$state];
            self::assertFalse($claims[0]['diagnosticCurrent']);
            $summary = Summary::derive($snapshot, $claims, true);
            self::assertSame([], $this->diagnosticRows($state));
            self::assertSame(['sql_error_observed'], $this->diagnosticRows($state, true));
            self::assertSame($state, array_column($summary['history'], null, 'code')['sql_error_observed']['state']);
        }
        $snapshot = $this->fixture('dedup');
        $snapshot['tags'][] = 'sql_error_observation';
        $snapshot['caseSummary']['hints'] = ['sql_error_observed'];
        $this->db->update('finding_report_current', ['snapshot_json' => json_encode($snapshot)], ['finding_id' => 'dedup']);
        $claims = CribrumClaimExport::forFindings($this->db, ['dedup'])['dedup'];
        $summary = Summary::derive($snapshot, $claims, true);
        self::assertCount(1, array_filter($summary['badges'], static fn (array $badge): bool => $badge['code'] === 'sql_error_observed'));
        self::assertSame($summary['additionalCount'], $this->additionalCount('dedup'));
        $claims[0]['mode'] = 'shadow';
        $shadow = Summary::derive([], $claims, true);
        self::assertNotContains('sql_error_observed', array_column($shadow['badges'], 'code'));
        self::assertContains('sql_error_observed', array_column($shadow['shadow'], 'code'));
    }

    public function testMinimalLegacyMetadataSchemaDisablesOnlyTheNewCauseLabels(): void
    {
        $this->db->executeStatement('DROP TABLE finding_claim_revision');
        $this->db->executeStatement('CREATE TABLE finding_claim_revision(finding_id TEXT, claim TEXT, revision INTEGER)');
        self::assertFalse(Hints::available($this->db));
        $this->fixture('legacy', [], false);
        $claims = CribrumClaimExport::forFindings($this->db, ['legacy'])['legacy'];
        self::assertSame([], $claims[0]['diagnosticHints']);
        self::assertFalse($claims[0]['diagnosticCurrent']);
        self::assertTrue($claims[0]['claimCurrent']);
    }

    private function fixture(string $id, array $rules = ['builtin/sql_observation'], bool $revision = true): array
    {
        $url = 'https://'.$id.'.test/';
        $this->db->insert('finding', ['id' => $id, 'poc_revision' => 1, 'poc_url' => null, 'url' => $url]);
        $this->db->insert('finding_claim', ['finding_id' => $id, 'claim' => 'error_disclosure', 'state' => 'confirmed', 'source' => 'manual', 'revision' => 1, 'observation_id' => 'obs-'.$id, 'report_selected' => 1, 'updated_at' => '2026-10-27 10:00:00']);
        if ($revision) $this->db->insert('finding_claim_revision', ['finding_id' => $id, 'claim' => 'error_disclosure', 'state' => 'confirmed', 'source' => 'manual', 'revision' => 1, 'observation_id' => 'obs-'.$id, 'rule_ids' => json_encode($rules)]);
        $this->db->insert('http_observation', ['id' => 'obs-'.$id, 'finding_id' => $id, 'poc_revision' => 1, 'metadata' => json_encode(['requestUrl' => $url, 'requestContextSha256' => str_repeat('a', 64), 'private' => 'PRIVATE_METADATA']), 'artifact_sha256' => str_repeat('c', 64), 'http_status' => 500, 'content_type' => 'text/html', 'created_at' => '2026-10-27 09:00:00']);
        $snapshot = ['tags' => ['xss_confirmed'], 'components' => [], 'coverage' => ['requestContextSha256' => str_repeat('a', 64), 'sourceRef' => ['artifactSha256' => str_repeat('c', 64)]]];
        $this->db->insert('finding_report_current', ['finding_id' => $id, 'is_current' => 1, 'snapshot_json' => json_encode($snapshot)]);
        return $snapshot;
    }

    private function diagnosticRows(string $id, bool $history = false): array
    {
        $json = $this->db->fetchOne("SELECT (SELECT json_group_array(cb.code) FROM (".Query::badgeRows('f', true, $history, false, false, true, true).") cb WHERE cb.origin='claim_diagnostic') FROM finding f WHERE f.id=?", [$id]);
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function additionalCount(string $id): int
    {
        return (int) $this->db->fetchOne('SELECT '.Query::additionalCount('f', true, true, true).' FROM finding f WHERE f.id=?', [$id]);
    }
}
