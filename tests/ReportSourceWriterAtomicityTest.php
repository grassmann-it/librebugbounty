<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Exception\InteractiveCaptureInvalidatedException;
use App\Service\CribrumClaimService;
use App\Service\FindingPocService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real writer services against migration triggers, never the application database. */
final class ReportSourceWriterAtomicityTest extends TestCase
{
    private Connection $db;
    private EntityManager $em;
    private Finding $finding;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__).'/src/Entity'], true);
        $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
        $this->em = new EntityManager($this->db, $config);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
        $migration = new \DoctrineMigrations\Version20261022000000($this->db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) $this->db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        $domain = (new Domain())->setHostname('atomic.fixture.test');
        $this->finding = (new Finding())->setDomain($domain)->setType('xss')->setTitle('Atomic fixture')->setUrl('https://atomic.fixture.test/?q=%2527')->setMethod('GET');
        $this->em->persist($domain); $this->em->persist($this->finding); $this->em->flush();
        $this->db->executeStatement('INSERT OR IGNORE INTO finding_report_context(finding_id) VALUES (?)', [$this->finding->getId()]);
    }

    protected function tearDown(): void { $this->em->close(); $this->db->close(); }

    public function testClaimWriteInvalidatesBeforeOuterCommitAndRollbackRestoresWholeBasis(): void
    {
        $bytes = $this->snapshot(); $basis = $this->basis();
        $this->db->beginTransaction();
        try {
            (new CribrumClaimService($this->em))->apply($this->finding, 'sql_injection', 'suspected', false, 'classifier');
            self::assertSame($basis + 1, $this->basis(), 'Exactly one trigger bump; no service-level duplicate invalidation.');
            self::assertSame(0, $this->current());
            self::assertSame($bytes, $this->snapshotBytes());
        } finally { $this->db->rollBack(); $this->em->clear(); }
        self::assertSame($basis, $this->basis()); self::assertSame(1, $this->current());
        self::assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM finding_claim'));
        self::assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM finding_claim_revision'));
    }

    public function testClaimSelectionNoopAndRevisionFenceHavePreciseInvalidation(): void
    {
        $claims = new CribrumClaimService($this->em);
        $claims->apply($this->finding, 'sql_injection', 'suspected', false, 'classifier');
        $bytes = $this->snapshot(); $basis = $this->basis();
        $claims->apply($this->finding, 'sql_injection', 'suspected', false, 'classifier', [], 'same semantic state', null, null, 1);
        self::assertSame($basis, $this->basis()); self::assertSame(1, $this->current());
        $claims->apply($this->finding, 'sql_injection', 'suspected', true, 'manual', [], 'select component', null, null, 1);
        self::assertSame($basis + 1, $this->basis()); self::assertSame(0, $this->current());
        self::assertSame($bytes, $this->snapshotBytes());
        try { $claims->apply($this->finding, 'sql_injection', 'rejected', false, 'manual', [], null, null, null, 1); self::fail('Stale revision accepted.'); }
        catch (\UnexpectedValueException) { self::assertSame($basis + 1, $this->basis()); }
    }

    public function testSameStateManualOwnershipIsARevisionAndProtectsAgainstClassifier(): void
    {
        $claims = new CribrumClaimService($this->em);
        $claims->apply($this->finding, 'sql_injection', 'suspected', false, 'classifier');
        $this->snapshot(); $basis = $this->basis();
        $claim = $claims->apply($this->finding, 'sql_injection', 'suspected', false, 'manual');
        self::assertSame('manual', $claim->source); self::assertSame(2, $claim->revision);
        self::assertSame($basis + 1, $this->basis());
        $ignored = $claims->apply($this->finding, 'sql_injection', 'stale', false, 'classifier');
        self::assertSame('suspected', $ignored->state); self::assertSame(2, $ignored->revision);
        self::assertSame($basis + 1, $this->basis());
    }

    public function testMigrationSourceDoesNotEnrollAndAutomaticStalePreservesSeparateLegacyBasis(): void
    {
        $claims = new CribrumClaimService($this->em);
        $claims->apply($this->finding, 'xss', 'observed', false, 'migration');
        self::assertNull($this->db->fetchOne('SELECT legacy_basis_json FROM finding_report_context WHERE finding_id=?', [$this->finding->getId()]));
        $legacy = '{"cohortId":"fixed-test-cohort","baselinePocRevision":0}';
        $this->db->update('finding_report_context', ['legacy_basis_json' => $legacy, 'legacy_basis_sha256' => hash('sha256', $legacy)], ['finding_id' => $this->finding->getId()]);
        $this->snapshot(); $basis = $this->basis();
        $claims->apply($this->finding, 'xss', 'stale', false, 'classifier', [], 'Automatic no-hit');
        self::assertSame($basis + 1, $this->basis()); self::assertSame(0, $this->current());
        self::assertSame($legacy, $this->db->fetchOne('SELECT legacy_basis_json FROM finding_report_context WHERE finding_id=?', [$this->finding->getId()]));
    }

    public function testPocCasInvalidatesBeforeHistoryFlushAndRollsBackWithOwner(): void
    {
        [$evidence, $job] = $this->screenshot(); $bytes = $this->snapshot(); $basis = $this->basis();
        $writer = $this->writer(); $this->db->beginTransaction();
        try {
            (new FindingPocService($this->db))->apply($this->finding, 'https://atomic.fixture.test/selected?q=%2F', 0, 'manual', $evidence, $job, $writer);
            self::assertSame($basis + 1, $this->basis()); self::assertSame(0, $this->current());
            self::assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM finding_poc_revision'));
            self::assertSame($bytes, $this->snapshotBytes());
            $writer->flush(); self::assertSame($basis + 1, $this->basis());
        } finally { $this->db->rollBack(); $writer->close(); }
        self::assertSame($basis, $this->basis()); self::assertSame(1, $this->current());
        self::assertSame(0, (int)$this->db->fetchOne('SELECT poc_revision FROM finding WHERE id=?', [$this->finding->getId()]));
    }

    public function testPocNoopAndStaleCasDoNotInvalidate(): void
    {
        [$evidence, $job] = $this->screenshot(); $this->snapshot(); $basis = $this->basis();
        $writer = $this->writer();
        $this->db->transactional(function () use ($writer, $evidence, $job): void {
            self::assertNull((new FindingPocService($this->db))->apply($this->finding, $this->finding->getPocUrl(), 0, 'manual', $evidence, $job, $writer));
        });
        self::assertSame($basis, $this->basis()); self::assertSame(1, $this->current());
        try {
            $this->db->transactional(fn() => (new FindingPocService($this->db))->apply($this->finding, 'https://atomic.fixture.test/new', 4, 'manual', $evidence, $job, $writer));
            self::fail('Stale PoC revision accepted.');
        } catch (InteractiveCaptureInvalidatedException) { self::assertSame($basis, $this->basis()); self::assertSame(1, $this->current()); }
        finally { $writer->close(); }
    }

    public function testHistoryFailureRollsBackPocAndInvalidation(): void
    {
        [$evidence, $job] = $this->screenshot(); $this->snapshot(); $basis = $this->basis();
        $this->db->executeStatement("CREATE TRIGGER reject_atomic_history BEFORE INSERT ON finding_poc_revision BEGIN SELECT RAISE(ABORT, 'forced atomic history failure'); END");
        $writer = $this->writer();
        try {
            $this->db->transactional(function () use ($writer, $evidence, $job): void {
                (new FindingPocService($this->db))->apply($this->finding, 'https://atomic.fixture.test/new', 0, 'manual', $evidence, $job, $writer);
                $writer->flush();
            }); self::fail('Expected history write failure.');
        } catch (\Doctrine\DBAL\Exception $error) { self::assertStringContainsString('forced atomic history failure', $error->getMessage()); }
        finally { $writer->close(); }
        self::assertSame($basis, $this->basis()); self::assertSame(1, $this->current());
        self::assertSame(0, (int)$this->db->fetchOne('SELECT poc_revision FROM finding WHERE id=?', [$this->finding->getId()]));
    }

    public function testRequestAndNotificationChangesInvalidateInsideTransaction(): void
    {
        foreach ([['method', 'POST'], ['request_params', '{"query":"changed"}'], ['notified_owner_at', '2026-10-23 12:00:00']] as [$column, $value]) {
            $this->snapshot(); $basis = $this->basis();
            $this->db->beginTransaction();
            try {
                $this->db->executeStatement('UPDATE finding SET '.$column.'=? WHERE id=?', [$value, $this->finding->getId()]);
                self::assertSame($basis + 1, $this->basis(), $column);
                self::assertSame(0, $this->current(), $column);
            } finally { $this->db->rollBack(); }
            self::assertSame($basis, $this->basis());
            self::assertSame(1, $this->current());
        }
    }

    private function writer(): EntityManager { return new EntityManager($this->db, $this->em->getConfiguration()); }
    private function basis(): int { return (int)$this->db->fetchOne('SELECT basis_revision FROM finding_report_context WHERE finding_id=?', [$this->finding->getId()]); }
    private function current(): int { return (int)$this->db->fetchOne('SELECT is_current FROM finding_report_current WHERE finding_id=?', [$this->finding->getId()]); }
    private function snapshotBytes(): string { return $this->db->fetchOne('SELECT snapshot_json FROM finding_report_revision WHERE finding_id=? ORDER BY revision DESC LIMIT 1', [$this->finding->getId()]); }
    private function screenshot(): array
    {
        $evidence = (new Evidence())->setFinding($this->finding)->setKind('screenshot')->setFilePath('atomic.png');
        $job = (new ScreenshotJob())->setFinding($this->finding)->setUrl($this->finding->getUrl())->setScreenshotPath('atomic.png');
        $this->em->persist($evidence); $this->em->persist($job); $this->em->flush(); return [$evidence, $job];
    }
    private function snapshot(): string
    {
        $source = $this->db->fetchAssociative('SELECT * FROM report_source_contract WHERE id=1');
        $bytes = '{"gate":"eligible", "preserveBytes":true}';
        $revision = 1 + (int)$this->db->fetchOne('SELECT COALESCE(MAX(revision),0) FROM finding_report_revision WHERE finding_id=?', [$this->finding->getId()]);
        $this->db->insert('finding_report_revision', ['finding_id' => $this->finding->getId(), 'revision' => $revision, 'basis_revision' => $this->basis(), 'schema_version' => $source['schema_version'], 'policy_version' => $source['policy_version'], 'source_instance_id' => $source['source_instance_id'], 'lane' => 'xss', 'gate' => 'eligible', 'snapshot_json' => $bytes, 'snapshot_sha256' => hash('sha256', $bytes), 'created_at' => '2026-10-22T12:00:00Z', 'case_kind' => 'pure_xss', 'purity' => 'pure', 'tags_json' => '[]', 'case_summary_json' => '{}', 'coverage_json' => '{}']);
        self::assertSame(1, $this->current()); return $bytes;
    }
}
