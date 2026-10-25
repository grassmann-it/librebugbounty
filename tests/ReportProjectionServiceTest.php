<?php
namespace App\Tests;
use App\Cribrum\ReportPolicy;
use App\Service\{FindingService,ReportProjectionService,IntakeProfileResolver,LegacyCohortService,WorkerProofResolver};
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261022000000;
use Psr\Log\NullLogger;
final class ReportProjectionServiceTest extends DatabaseTestCase
{
    private function projection(): ReportProjectionService
    {
        $db=$this->entityManager->getConnection();
        $db->executeStatement('DROP VIEW IF EXISTS finding_report_current');
        foreach (['finding_report_revision','finding_behavior_fact','finding_report_context','report_source_contract'] as $table) $db->executeStatement('DROP TABLE IF EXISTS '.$table);
        foreach ($db->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type='trigger' AND name LIKE 'report_basis_%'") as $trigger) $db->executeStatement('DROP TRIGGER '.$trigger);
        // DBAL projection schema deliberately lives outside ORM SchemaTool ownership.
        require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
        $migration=new Version20261022000000($db,new NullLogger()); $migration->up(new Schema());
        foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement());
        return new ReportProjectionService($db,new ReportPolicy(),new IntakeProfileResolver(),new LegacyCohortService($db),new WorkerProofResolver($db));
    }
    public function testHashIdempotenceSourceIdentityAndAtomicDirectSqlInvalidation(): void
    {
        $projection=$this->projection(); $finding=self::getContainer()->get(FindingService::class)->createFinding('https://projection.test/?q=%22+ä');
        $a=$projection->refresh($finding); $b=$projection->refresh($finding);
        self::assertSame($a,$b); self::assertSame(1,$a['decisionRevision']);
        $db=$this->entityManager->getConnection(); $row=$db->fetchAssociative('SELECT * FROM finding_report_current WHERE finding_id=?',[$finding->getId()]);
        self::assertSame(hash('sha256',$row['snapshot_json']),$row['snapshot_sha256']);
        self::assertSame(hash('sha256',$finding->getPocUrl()),$a['poc']['sha256']);
        self::assertNull($a['legacyBasis']); // migration claim is never E2 enrollment
        $db->executeStatement('UPDATE finding SET payload=? WHERE id=?',['changed',$finding->getId()]);
        self::assertSame(0,(int)$db->fetchOne('SELECT is_current FROM finding_report_current WHERE finding_id=?',[$finding->getId()]));
        self::assertSame('hold',$projection->current($finding)['gate']);
        try { $projection->refresh($finding); self::fail('Stale ORM source was accepted.'); }
        catch (\UnexpectedValueException $error) { self::assertStringContainsString('reload before report refresh: payload',$error->getMessage()); }
        self::assertSame('hold',$projection->current($finding)['gate']);
        $this->entityManager->refresh($finding);
        $projection->refresh($finding);
        $db->executeStatement("UPDATE report_source_contract SET policy_version='future'");
        self::assertSame('hold',$db->fetchOne('SELECT gate FROM finding_report_current WHERE finding_id=?',[$finding->getId()]));
    }
    public function testRevisionRowsRejectMutation(): void
    {
        $projection=$this->projection(); $finding=self::getContainer()->get(FindingService::class)->createFinding('https://projection.test/immutable'); $projection->refresh($finding);
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->entityManager->getConnection()->executeStatement("UPDATE finding_report_revision SET gate='eligible'");
    }
}
