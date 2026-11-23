<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Finding;
use App\Entity\HttpObservation;
use App\Service\CribrumClaimService;
use App\Service\CribrumObservationStore;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use App\Service\SourceReviewService;
use App\Service\WorkerProofResolver;
use App\Tests\Support\ConcurrentWorkerKernel;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;

final class SourceReviewServiceTest extends DatabaseTestCase
{
    private CribrumObservationStore $observations;
    private EvidenceStorageInterface $storage;
    private SourceReviewService $reviews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $this->observations = new CribrumObservationStore($this->entityManager, $this->storage);
        $this->reviews = new SourceReviewService($this->entityManager, $this->observations);
    }

    public function testReservationsAndReadingReceiptsNeverMutateTheSecurityDecisionOrSource(): void
    {
        [$finding, $observation] = $this->fixture('separate-verdict');
        $finding->setManualAssessment('confirmed', null, new \DateTimeImmutable('2026-10-26T10:00:00Z'));
        $this->entityManager->flush();
        (new CribrumClaimService($this->entityManager))->apply($finding, 'sql_injection', 'rejected', false, 'manual');
        $before = $this->securitySnapshot($finding);
        $artifactBefore = $this->storage->read($observation->getArtifactPath());

        self::assertSame('open', $this->reviews->readCurrentForFinding($finding)['status']);
        $claims = $this->reviews->claim('Codex worker A', 1, $finding->getId());
        self::assertCount(1, $claims);
        self::assertSame($observation->getId(), $claims[0]['observationId']);
        self::assertSame($observation->getArtifactSha256(), $claims[0]['artifactSha256']);
        self::assertSame(SourceReviewService::POLICY_VERSION, $claims[0]['policyVersion']);
        $competing = new SourceReviewService($this->entityManager, $this->observations);
        self::assertSame([], $competing->claim('Codex worker B', 1, $finding->getId()));
        $pending = $competing->readCurrentForFinding($finding);
        self::assertSame('pending', $pending['status']);
        self::assertSame('Codex worker A', $pending['reviewer']);

        $this->reviews->record($claims[0]['leaseToken'], 'potential_gap', 'Native diagnostic needs a classifier check.', 'cribrum-fixture');
        $current = $this->reviews->readCurrentForFinding($finding->getId());
        self::assertSame('current', $current['status']);
        self::assertSame('Codex worker A', $current['reviewer']);
        self::assertSame('potential_gap', $current['outcome']);
        self::assertSame('Native diagnostic needs a classifier check.', $current['summary']);
        self::assertSame('cribrum-fixture', $current['classifierVersion']);
        self::assertSame($observation->getId(), $current['observationId']);
        self::assertStringEndsWith('+00:00', $current['reviewedAt']);
        self::assertSame([], $this->reviews->claim('Codex worker B', 1, $finding->getId()));
        self::assertSame($before, $this->securitySnapshot($finding));
        self::assertSame($artifactBefore, $this->storage->read($observation->getArtifactPath()));
    }

    public function testExpiredReservationCanBeReclaimedButItsOldTokenCannotComplete(): void
    {
        [$finding] = $this->fixture('expired');
        $first = $this->reviews->claim('Codex old worker', 1, $finding->getId())[0];
        $this->entityManager->getConnection()->executeStatement("UPDATE source_review_lease SET expires_at='2000-01-01 00:00:00' WHERE lease_token=?", [$first['leaseToken']]);
        $second = $this->reviews->claim('Codex replacement worker', 1, $finding->getId())[0];
        self::assertNotSame($first['leaseToken'], $second['leaseToken']);
        $this->assertRejected(fn () => $this->reviews->record($first['leaseToken'], 'no_new_hint', 'Late result.', 'cribrum-fixture'));
        self::assertSame(0, $this->rowCount('source_review'));
        $this->reviews->record($second['leaseToken'], 'context_required', 'The retained source needs more context.', 'cribrum-fixture');
        self::assertSame('Codex replacement worker', $this->reviews->readCurrentForFinding($finding)['reviewer']);
        self::assertSame(1, $this->rowCount('source_review'));
    }

    public function testCompletionRetriesAreIdempotentAndCannotRewriteACompletedReview(): void
    {
        [$finding] = $this->fixture('idempotent');
        $token = $this->reviews->claim('Codex worker', 1, $finding->getId())[0]['leaseToken'];
        $first = $this->reviews->record($token, 'no_new_hint', 'No additional source hint.', 'cribrum-fixture');
        self::assertSame($first, $this->reviews->record($token, 'no_new_hint', 'No additional source hint.', 'cribrum-fixture'));
        foreach ([['potential_gap', 'No additional source hint.', 'cribrum-fixture'], ['no_new_hint', 'Changed conclusion.', 'cribrum-fixture'], ['no_new_hint', 'No additional source hint.', 'cribrum-other']] as $changed) {
            $this->assertRejected(fn () => $this->reviews->record($token, ...$changed));
        }
        self::assertSame(1, $this->rowCount('source_review'));
        self::assertSame('No additional source hint.', $this->reviews->readCurrentForFinding($finding)['summary']);
    }

    public function testChangedRequestContextInvalidatesAnOutstandingReservation(): void
    {
        [$finding] = $this->fixture('request-changed');
        $token = $this->reviews->claim('Codex worker', 1, $finding->getId())[0]['leaseToken'];
        $finding->setPayload('A different current input');
        $this->entityManager->flush();
        $this->assertRejected(fn () => $this->reviews->record($token, 'no_new_hint', 'Old request reviewed.', 'cribrum-fixture'));
        self::assertSame(0, $this->rowCount('source_review'));
        self::assertSame('unavailable', $this->reviews->readCurrentForFinding($finding)['status']);
        self::assertSame([], $this->reviews->claim('Codex next worker', 1, $finding->getId()));
    }

    public function testNewRetainedSourceMakesThePreviousReviewStaleAndOldLeaseCannotComplete(): void
    {
        [$finding, $original] = $this->fixture('new-source');
        $token = $this->reviews->claim('Codex first worker', 1, $finding->getId())[0]['leaseToken'];
        $this->reviews->record($token, 'no_new_hint', 'First source read.', 'cribrum-fixture');
        $next = $this->capture($finding, '<p>Second retained response.</p>');
        $this->makeNewest($next, $original);
        $state = $this->reviews->readCurrentForFinding($finding);
        self::assertSame('stale', $state['status']);
        self::assertSame($next->getArtifactSha256(), $state['artifactSha256']);
        self::assertSame('First source read.', $state['previousReview']['summary']);
        $nextToken = $this->reviews->claim('Codex second worker', 1, $finding->getId())[0]['leaseToken'];
        $third = $this->capture($finding, '<p>Third retained response.</p>');
        $this->makeNewest($third, $next);
        $this->assertRejected(fn () => $this->reviews->record($nextToken, 'no_new_hint', 'Second source read.', 'cribrum-fixture'));
        self::assertSame(1, $this->rowCount('source_review'));
    }

    public function testReclassificationOfTheSameArtifactDoesNotNeedAnotherSourceReview(): void
    {
        [$finding, $original] = $this->fixture('same-artifact');
        $token = $this->reviews->claim('Codex worker', 1, $finding->getId())[0]['leaseToken'];
        $revision = new HttpObservation($finding, $original->getPocRevision(), $original->getHttpStatus(), $original->getFinalUrl(), $original->getContentType(), $original->getRawBytes(), $original->getRawTruncated(), $original->getDomBytes(), $original->getDomTruncated(), [...$original->getMetadata(), 'reclassifiedFrom' => $original->getId()]);
        $revision->setArtifact($original->getArtifactPath(), $original->getArtifactSha256());
        $revision->setClassification(['classifierVersion' => 'cribrum-newer', 'suggestions' => []]);
        $this->entityManager->persist($revision);
        $this->entityManager->flush();
        $this->makeNewest($revision, $original);
        $this->reviews->record($token, 'no_new_hint', 'Original bytes read.', 'cribrum-fixture');
        self::assertSame('current', $this->reviews->readCurrentForFinding($finding)['status']);
        self::assertSame($original->getId(), $this->entityManager->getConnection()->fetchOne('SELECT observation_id FROM source_review WHERE finding_id=?', [$finding->getId()]), 'The receipt names the source actually leased, even when the same bytes were reclassified in flight.');
        self::assertSame([], $this->reviews->claim('Codex second worker', 1, $finding->getId()));
        self::assertSame(1, $this->rowCount('source_review'));
    }

    public function testIdenticalBodyInADifferentResponseContextNeedsAnotherReview(): void
    {
        [$finding, $original] = $this->fixture('response-changed');
        $first = $this->reviews->claim('Codex worker', 1, $finding->getId())[0];
        $this->reviews->record($first['leaseToken'], 'no_new_hint', 'HTTP 200 source read.', 'cribrum-fixture');
        $changed = new HttpObservation($finding, $original->getPocRevision(), 500, $original->getFinalUrl(), $original->getContentType(), $original->getRawBytes(), $original->getRawTruncated(), $original->getDomBytes(), $original->getDomTruncated(), $original->getMetadata());
        $changed->setArtifact($original->getArtifactPath(), $original->getArtifactSha256());
        $this->entityManager->persist($changed);
        $this->entityManager->flush();
        $this->makeNewest($changed, $original);
        self::assertSame('stale', $this->reviews->readCurrentForFinding($finding)['status']);
        $next = $this->reviews->claim('Codex next worker', 1, $finding->getId())[0];
        self::assertSame($first['artifactSha256'], $next['artifactSha256']);
        self::assertNotSame($first['contextSha256'], $next['contextSha256']);
    }

    public function testMissingCorruptOrUnboundSourcesNeverBecomeReviewed(): void
    {
        [$missing, $missingSource] = $this->fixture('missing');
        $this->storage->deleteFile($missingSource->getArtifactPath());
        [$corrupt, $corruptSource] = $this->fixture('corrupt');
        $relative = substr($corruptSource->getArtifactPath(), strlen('storage/artifacts/'));
        file_put_contents(APP_TEST_ROOT.'/artifacts/'.$relative, 'corrupt isolated fixture bytes');
        $unbound = self::getContainer()->get(FindingService::class)->createFinding('https://source-review.test/unbound');
        $this->observations->store($unbound, $this->transport('<p>Unpinned source.</p>', $unbound));
        $noRepresentations = self::getContainer()->get(FindingService::class)->createFinding('https://source-review.test/no-representations');
        $encoded = gzencode(json_encode(['schema' => 1, 'manifest' => ['rawSha256' => null, 'domSha256' => null], 'rawHtml' => null, 'domText' => null], JSON_THROW_ON_ERROR));
        $artifact = $this->storage->storeContents($noRepresentations, $encoded, 'observation.json.gz');
        $nullSource = new HttpObservation($noRepresentations, $noRepresentations->getPocRevision(), 200, $noRepresentations->getPocUrl(), 'text/html', 0, false, 0, false, ['requestUrl' => $noRepresentations->getPocUrl(), 'requestContextSha256' => WorkerProofResolver::requestContextSha256($noRepresentations)]);
        $nullSource->setArtifact($artifact->relativePath, $artifact->sha256);
        $this->entityManager->persist($nullSource);
        $this->entityManager->flush();
        foreach ([$missing, $corrupt, $unbound, $noRepresentations] as $finding) {
            self::assertSame('unavailable', $this->reviews->readCurrentForFinding($finding)['status']);
            self::assertSame([], $this->reviews->claim('Codex worker', 1, $finding->getId()));
        }
        self::assertSame(0, $this->rowCount('source_review'));
        self::assertSame(0, $this->rowCount('source_review_lease'));
        $empty = self::getContainer()->get(FindingService::class)->createFinding('https://source-review.test/empty-response');
        $this->capture($empty, '');
        self::assertCount(1, $this->reviews->claim('Codex empty-source worker', 1, $empty->getId()), 'Retained empty strings are an authenticated empty response, unlike absent representations.');
    }

    public function testMissingMigrationIsReadOnlyAndClearlyUnavailable(): void
    {
        [$finding] = $this->fixture('migration-needed');
        $this->entityManager->getConnection()->executeStatement('DROP TABLE source_review_lease');
        $this->entityManager->getConnection()->executeStatement('DROP TABLE source_review');
        self::assertSame(['status' => 'unavailable', 'reason' => 'migration_required', 'findingId' => $finding->getId(), 'policyVersion' => SourceReviewService::POLICY_VERSION], $this->reviews->readCurrentForFinding($finding));
        $this->assertRejected(fn () => $this->reviews->claim('Codex worker', 1, $finding->getId()));
    }

    public function testMigrationMatchesOrmAndCompletedRowsRejectDirectUpdates(): void
    {
        [$finding] = $this->fixture('migration');
        $before = $this->securitySnapshot($finding);
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DROP TABLE source_review_lease');
        $connection->executeStatement('DROP TABLE source_review');
        require_once dirname(__DIR__).'/migrations/Version20261026010000.php';
        $migration = new \DoctrineMigrations\Version20261026010000($connection, new NullLogger());
        $migration->up(new Schema());
        $connection->transactional(function () use ($migration, $connection): void {
            foreach ($migration->getSql() as $sql) $connection->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        });
        // interactive_browser_lease is a DBAL-only table without ORM metadata,
        // so the schema diff always wants to drop it once another test left it
        // behind. It is unrelated to this migration and removed before comparing.
        $connection->executeStatement('DROP TABLE IF EXISTS interactive_browser_lease');
        $diff = (new SchemaTool($this->entityManager))->getUpdateSchemaSql($this->entityManager->getMetadataFactory()->getAllMetadata());
        self::assertSame([], $diff, implode("\n", $diff));
        $token = $this->reviews->claim('Codex worker', 1, $finding->getId())[0]['leaseToken'];
        $this->reviews->record($token, 'no_new_hint', 'Immutable completed source review.', 'cribrum-fixture');
        try {
            $connection->executeStatement("UPDATE source_review SET summary='rewritten' WHERE finding_id=?", [$finding->getId()]);
            self::fail('Completed source-review rows must reject direct updates.');
        } catch (DbalException $error) {
            self::assertStringContainsString('immutable source review', $error->getMessage());
        }
        self::assertSame('Immutable completed source review.', $this->reviews->readCurrentForFinding($finding)['summary']);
        self::assertSame($before, $this->securitySnapshot($finding));
    }

    public function testTwoProcessesCannotLeaseTheSameSource(): void
    {
        if (!function_exists('proc_open')) self::markTestSkipped('proc_open is required for the lease race.');
        [$finding] = $this->fixture('parallel-claim');
        $fixture = APP_TEST_ROOT.'/source-review-race';
        mkdir($fixture, 0700, true);
        $environment = getenv();
        self::assertIsArray($environment);
        $environment = array_replace($environment, ['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'APP_SECRET' => (string) $_SERVER['APP_SECRET'], 'DATABASE_URL' => (string) $_SERVER['DATABASE_URL'], 'EVIDENCE_STORAGE_DIR' => (string) $_SERVER['EVIDENCE_STORAGE_DIR'], 'INTAKE_CONCURRENT_CACHE_DIR' => $fixture.'/cache', 'INTAKE_CONCURRENT_LOG_DIR' => $fixture.'/logs']);
        foreach (['INTAKE_CONCURRENT_CACHE_DIR', 'INTAKE_CONCURRENT_LOG_DIR'] as $key) putenv($key.'='.$environment[$key]);
        $kernel = new ConcurrentWorkerKernel('test', false);
        $kernel->boot();
        $kernel->shutdown();
        $this->entityManager->getConnection()->executeStatement("CREATE TRIGGER pause_source_review_lease BEFORE INSERT ON source_review_lease BEGIN SELECT fixture_pause(); END");
        $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$kernel = new App\Tests\Support\ConcurrentWorkerKernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$connection = $container->get(Doctrine\DBAL\Connection::class);
$connection->getNativeConnection()->sqliteCreateFunction('fixture_pause', static function (): int { usleep(300000); return 0; });
file_put_contents($argv[3], 'ready');
$deadline = microtime(true)+10;
while (!is_file($argv[2])) { if (microtime(true)>$deadline) throw new RuntimeException('Timed out awaiting source-review race start.'); usleep(1000); }
$em = $container->get(Doctrine\ORM\EntityManagerInterface::class);
$reviews = new App\Service\SourceReviewService($em, $container->get(App\Service\CribrumObservationStore::class));
echo json_encode($reviews->claim($argv[5],1,$argv[4]), JSON_THROW_ON_ERROR);
$kernel->shutdown();
PHP;
        $workers = [];
        $start = $fixture.'/start';
        try {
            for ($index = 0; $index < 2; ++$index) {
                $ready = $fixture.'/ready-'.$index;
                $pipes = [];
                $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__), $start, $ready, $finding->getId(), 'Codex race '.$index], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = compact('process', 'pipes', 'ready');
            }
            $deadline = microtime(true) + 15;
            while (!is_file($workers[0]['ready']) || !is_file($workers[1]['ready'])) {
                if (microtime(true) > $deadline) self::fail('Parallel source-review workers did not become ready.');
                usleep(1000);
            }
            touch($start);
            $counts = [];
            foreach ($workers as &$worker) {
                $stdout = stream_get_contents($worker['pipes'][1]);
                $stderr = stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]); fclose($worker['pipes'][2]);
                $exit = proc_close($worker['process']);
                $worker['process'] = null;
                self::assertSame(0, $exit, trim($stdout."\n".$stderr));
                $counts[] = count(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
            }
            unset($worker);
            sort($counts);
            self::assertSame([0, 1], $counts);
            self::assertSame(1, $this->rowCount('source_review_lease'));
            self::assertSame(0, $this->rowCount('source_review'));
        } finally {
            foreach ($workers as $worker) if (is_resource($worker['process'])) { proc_terminate($worker['process']); proc_close($worker['process']); }
        }
    }

    private function fixture(string $name): array
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://source-review.test/'.$name);
        return [$finding, $this->capture($finding, '<p>Retained source '.$name.'.</p>')];
    }

    private function capture(Finding $finding, string $body): HttpObservation
    {
        return $this->observations->store($finding, $this->transport($body, $finding), classificationContext: ['url' => $finding->getPocUrl(), 'payload' => $finding->getPayload(), 'expectedEvidence' => $finding->getExpectedEvidence()], requestContextSha256: WorkerProofResolver::requestContextSha256($finding));
    }

    private function transport(string $body, Finding $finding): array
    {
        $dom = strip_tags($body);
        return ['schema' => 1, 'contentType' => 'text/html', 'httpStatus' => 200, 'finalUrl' => $finding->getPocUrl(), 'redirectChain' => [], 'headers' => [], 'rawHtml' => $body, 'rawHtmlBytes' => strlen($body), 'rawHtmlTruncated' => false, 'domText' => $dom, 'domTextBytes' => strlen($dom), 'domTextTruncated' => false];
    }

    private function makeNewest(HttpObservation $newer, HttpObservation $older): void
    {
        $this->entityManager->getConnection()->executeStatement("UPDATE http_observation SET created_at=datetime((SELECT created_at FROM http_observation WHERE id=?),'+1 second') WHERE id=?", [$older->getId(), $newer->getId()]);
        $this->entityManager->refresh($newer);
    }

    private function securitySnapshot(Finding $finding): array
    {
        $connection = $this->entityManager->getConnection();
        $snapshot = [];
        foreach (['finding', 'finding_claim', 'finding_claim_revision', 'http_observation', 'screenshot_job', 'evidence', 'retest_run', 'finding_report_revision'] as $table) {
            if (!$connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", [$table])) continue;
            $column = $table === 'finding' ? 'id' : 'finding_id';
            $snapshot[$table] = $connection->fetchAllAssociative('SELECT * FROM '.$table.' WHERE '.$column.'=?', [$finding->getId()]);
        }
        return $snapshot;
    }

    private function rowCount(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function assertRejected(callable $operation): void
    {
        try { $operation(); self::fail('A stale or conflicting source-review operation must be rejected.'); }
        catch (\UnexpectedValueException) { self::assertTrue(true); }
    }
}
