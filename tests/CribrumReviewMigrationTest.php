<?php

namespace App\Tests;

use App\Service\CribrumRuleReviewService;
use App\Service\FindingService;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class CribrumReviewMigrationTest extends DatabaseTestCase
{
    public function testAdditiveSchemaKeepsCaseAndEnforcesFeedbackHistory(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://review-migration.example.test/a');
        $db = $this->entityManager->getConnection();
        $before = $db->fetchAssociative('SELECT * FROM finding WHERE id=?', [$finding->getId()]);
        // DatabaseTestCase creates ORM tables for isolated tests. Recreate the
        // release boundary so this exercises the actual migration SQL.
        foreach (['cribrum_review_selection_event', 'cribrum_review_selection', 'cribrum_rule_review_event', 'cribrum_rule_review_hint'] as $table) {
            $db->executeStatement('DROP TABLE '.$table);
        }
        require_once dirname(__DIR__).'/migrations/Version20261025000000.php';
        $migration = new \DoctrineMigrations\Version20261025000000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        self::assertSame($before, $db->fetchAssociative('SELECT * FROM finding WHERE id=?', [$finding->getId()]));

        $reviews = new CribrumRuleReviewService($db);
        $observation = Uuid::v7()->toRfc4122();
        $proof = str_repeat('a', 64);
        $reviews->raise($finding->getId(), 'observation:sql_error_observed', 'sql-triage-v1/sql_error_observed', $observation, 'cribrum-1.3.1', '1', [$proof]);
        $reviews->raise($finding->getId(), 'observation:sql_error_observed', 'sql-triage-v1/sql_error_observed', $observation, 'cribrum-1.3.1', '1', [$proof]);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM cribrum_rule_review_hint'));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM cribrum_rule_review_event'));
        $hint = $reviews->list()['rows'][0];
        self::assertSame([$proof], json_decode($hint['evidence_ids'], true, 512, JSON_THROW_ON_ERROR));
        $reviews->resolve($hint['id'], 1, 'Checked against this case');
        self::assertSame('reviewed', $reviews->get($hint['id'])['status']);
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM cribrum_rule_review_event'));
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $db->executeStatement("UPDATE cribrum_rule_review_event SET action='raised'");
    }

    public function testMigrationForeignKeyAndSelectionEventImmutability(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://review-migration.example.test/b');
        $db = $this->entityManager->getConnection();
        foreach (['cribrum_review_selection_event', 'cribrum_review_selection', 'cribrum_rule_review_event', 'cribrum_rule_review_hint'] as $table) $db->executeStatement('DROP TABLE '.$table);
        require_once dirname(__DIR__).'/migrations/Version20261025000000.php';
        $migration = new \DoctrineMigrations\Version20261025000000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        $db->executeStatement('PRAGMA foreign_keys=ON');
        $selectionId = Uuid::v7()->toRfc4122();
        $eventId = Uuid::v7()->toRfc4122();
        $now = '2026-10-25 00:00:00';
        $db->insert('cribrum_review_selection', ['id' => $selectionId, 'finding_id' => $finding->getId(), 'option_key' => 'observation:sql_error_observed', 'observation_id' => Uuid::v7()->toRfc4122(), 'selected' => 0, 'revision' => 1, 'provenance' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $db->insert('cribrum_review_selection_event', ['id' => $eventId, 'selection_id' => $selectionId, 'revision' => 1, 'selected' => 0, 'provenance' => '{}', 'created_at' => $now]);
        try {
            $db->executeStatement('UPDATE cribrum_review_selection_event SET selected=1 WHERE id=?', [$eventId]);
            self::fail('Review selection event was mutable.');
        } catch (\Doctrine\DBAL\Exception) {}
        $db->executeStatement('DELETE FROM finding WHERE id=?', [$finding->getId()]);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM cribrum_review_selection'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM cribrum_review_selection_event'));
    }
}
