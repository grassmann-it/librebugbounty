<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/** Run this fixture under the deployment SQLite too, without a product database. */
final class CribrumSqlitePortabilityTest extends TestCase
{
    public function testAcceptedAndManualBaselineQueriesHavePortableOuterCorrelation(): void
    {
        $path = getenv('CRIBRUM_TEST_NEW_FINDINGS_CONTRACT') ?: dirname(__DIR__).'/docs/cribrum/new-findings-query-v1.json';
        $contract = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $db = new \PDO('sqlite::memory:');
        foreach ([
            'CREATE TABLE finding (id TEXT, status TEXT, manual_assessment TEXT, assessed_at TEXT)',
            'CREATE TABLE finding_report_current (finding_id TEXT, snapshot_json TEXT)',
            'CREATE TABLE finding_report_revision (finding_id TEXT, revision INTEGER, snapshot_json TEXT)',
            'CREATE TABLE finding_review_acknowledgement (finding_id TEXT, known_observation_states TEXT, reviewed_at TEXT)',
            'CREATE TABLE finding_assessment (finding_id TEXT, known_observation_states TEXT, assessed_at TEXT)',
        ] as $sql) $db->exec($sql);
        $insert = static function (string $table, array $values) use ($db): void {
            $db->prepare('INSERT INTO '.$table.' VALUES ('.implode(',', array_fill(0, count($values), '?')).')')->execute($values);
        };
        $first = ['decisionRevision' => 1, 'decidedAt' => '2026-10-01T00:00:00Z', 'legacyBasis' => ['verified' => true], 'components' => [], 'tags' => [], 'caseSummary' => ['hints' => []]];
        $different = $first; $different['tags'] = ['sqli_suspected'];
        foreach (['accepted', 'manual', 'manual-cleared', 'no-baseline'] as $id) {
            $insert('finding', [$id, 'new', str_starts_with($id, 'manual') ? 'confirmed' : null, str_starts_with($id, 'manual') ? '2026-10-02 00:00:00' : null]);
            $current = $different; $current['decisionRevision'] = 3; $current['decidedAt'] = '2026-10-03T00:00:00Z';
            $insert('finding_report_current', [$id, json_encode($current)]);
            if ($id !== 'no-baseline') {
                $insert('finding_report_revision', [$id, 1, json_encode($first)]);
                // Manual reads newest pre-judgment; accepted reads oldest verified.
                $insert('finding_report_revision', [$id, 2, json_encode($id === 'manual-cleared' ? $different : $first)]);
            }
        }
        $semantic = str_replace('{{assessment_active}}', '1=1', $contract['semantic']);
        $query = 'SELECT f.id FROM finding f WHERE '.$semantic.' ORDER BY f.id';
        self::assertSame(['accepted', 'manual'], $db->query($query)->fetchAll(\PDO::FETCH_COLUMN));
        $meaning = ['components' => [], 'tags' => ['sqli_suspected'], 'hints' => []];
        $insert('finding_review_acknowledgement', ['accepted', json_encode(['__report_semantic' => json_encode($meaning)]), '2026-10-04 00:00:00']);
        self::assertSame(['manual'], $db->query($query)->fetchAll(\PDO::FETCH_COLUMN));
    }
}
