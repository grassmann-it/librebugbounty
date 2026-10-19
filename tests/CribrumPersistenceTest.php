<?php

namespace App\Tests;

use App\Entity\FindingClaim;
use App\Dto\BrowserScreenshotResult;
use App\Dto\RetestResultData;
use App\Entity\HttpObservation;
use App\Service\CribrumClaimService;
use App\Service\CribrumObservationStore;
use App\Service\EvidenceStorageInterface;
use App\Command\ArtifactAuditCommand;
use Symfony\Component\Console\Tester\CommandTester;
use App\Repository\RetestRunRepository;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\FindingService;
use App\Service\RetestService;
use App\Service\ScreenshotQueueService;
use App\Service\ValidationService;
use Doctrine\Persistence\ManagerRegistry;
use App\Dto\BrowserRetestRequest;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;

final class CribrumPersistenceTest extends DatabaseTestCase
{
    private FindingService $findings;
    private CribrumClaimService $claims;
    private CribrumObservationStore $observations;
    private EvidenceStorageInterface $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->findings = self::getContainer()->get(FindingService::class);
        $this->storage = self::getContainer()->get(EvidenceStorageInterface::class);
        $this->claims = new CribrumClaimService($this->entityManager);
        $this->observations = new CribrumObservationStore($this->entityManager, $this->storage);
    }

    private static function transportObservation(): array
    {
        return [
            'schema' => 1,
            'contentType' => 'text/html',
            'httpStatus' => 500,
            'finalUrl' => 'https://cribrum-transport.example.test/a',
            'redirectChain' => [],
            'headers' => ['server' => 'fixture'],
            'rawHtml' => '<p>error</p>',
            'rawHtmlBytes' => 12,
            'rawHtmlTruncated' => false,
            'domText' => 'error',
            'domTextBytes' => 5,
            'domTextTruncated' => false,
        ];
    }

    public function testMigrationBackfillsReflectedXssAndMatchesMapping(): void
    {
        $finding = $this->findings->createFinding('https://cribrum-migration.example.test/a');
        $connection = $this->entityManager->getConnection();
        $findingBefore = $connection->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$finding->getId()]);
        foreach (['finding_claim_revision', 'finding_claim', 'http_observation'] as $table) {
            $connection->executeStatement('DROP TABLE '.$table);
        }

        require_once dirname(__DIR__).'/migrations/Version20261019000000.php';
        $migration = new \DoctrineMigrations\Version20261019000000($connection, new NullLogger());
        $migration->up(new Schema());
        $connection->transactional(function () use ($migration, $connection): void {
            foreach ($migration->getSql() as $sql) {
                $connection->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
            }
        });

        self::assertSame($findingBefore, $connection->fetchAssociative('SELECT * FROM finding WHERE id = ?', [$finding->getId()]));
        $claim = $connection->fetchAssociative('SELECT * FROM finding_claim WHERE finding_id = ?', [$finding->getId()]);
        self::assertSame('xss', $claim['claim']);
        self::assertSame('observed', $claim['state']);
        self::assertSame('migration', $claim['source']);
        self::assertSame(1, (int) $claim['revision']);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM finding_claim_revision'));
        $diff = (new SchemaTool($this->entityManager))->getUpdateSchemaSql($this->entityManager->getMetadataFactory()->getAllMetadata());
        self::assertSame([], $diff, implode("\n", $diff));
    }

    public function testClaimRevisionsFenceNoopAndManualDecisionWin(): void
    {
        $finding = $this->findings->createFinding('https://cribrum-claims.example.test/a');
        $claim = $this->claims->apply($finding, 'sql_injection', 'suspected', false, 'classifier', ['db/error-generic'], 'Database error observed', null, null, 0);
        self::assertSame(1, $claim->revision);
        $same = $this->claims->apply($finding, 'sql_injection', 'suspected', false, 'classifier', ['db/error-generic'], 'same state', null, null, 1);
        self::assertSame(1, $same->revision);
        $manual = $this->claims->apply($finding, 'sql_injection', 'rejected', false, 'manual', null, 'Not exploitable', null, null, 1);
        self::assertSame(2, $manual->revision);
        $ignored = $this->claims->apply($finding, 'sql_injection', 'confirmed', true, 'classifier', ['db/error'], 'later rule', null, null, 2);
        self::assertSame(2, $ignored->revision);
        self::assertSame('rejected', $ignored->state);
        $this->expectException(\UnexpectedValueException::class);
        $this->claims->apply($finding, 'sql_injection', 'suspected', false, 'manual', null, null, null, null, 1);
    }

    public function testStoresLoadsAndDeletesObservationsWithClaims(): void
    {
        $finding = $this->findings->createFinding('https://cribrum-observation.example.test/a');
        $observation = $this->observations->store($finding, [
            'schema' => 1,
            'contentType' => 'text/html; charset=utf-8',
            'httpStatus' => 500,
            'finalUrl' => 'https://cribrum-observation.example.test/final',
            'redirectChain' => ['https://cribrum-observation.example.test/start'],
            'headers' => ['content-type' => 'text/html', 'server' => 'fixture'],
            'rawHtml' => '<html>SQL syntax error &lt;marker&gt;</html>',
            'rawHtmlBytes' => 44,
            'rawHtmlTruncated' => false,
            'domText' => 'SQL syntax error <marker>',
            'domTextBytes' => 25,
            'domTextTruncated' => false,
        ]);
        self::assertSame(500, $observation->getHttpStatus());
        self::assertSame(['rawHtml' => '<html>SQL syntax error &lt;marker&gt;</html>', 'domText' => 'SQL syntax error <marker>'], $this->observations->load($observation));
        $this->claims->applySuggestions($finding, $observation, [
            ['claim' => 'sql_injection', 'state' => 'suspected', 'score' => 90, 'ruleIds' => ['db/generic'], 'reasons' => ['DB error']],
            ['claim' => 'error_disclosure', 'state' => 'observed', 'score' => 90, 'ruleIds' => ['error/generic'], 'reasons' => ['Error page']],
        ]);
        $claims = $this->claims->currentClaims($finding);
        self::assertSame(['error_disclosure', 'sql_injection', 'xss'], array_map(static fn (FindingClaim $claim): string => $claim->claim, $claims));
        self::assertSame('suspected', $claims[1]->state);
        self::assertSame($observation->getId(), $claims[1]->observationId);
        $this->claims->attachClassification($observation, ['classifierVersion' => 'test', 'suggestions' => []]);
        $this->entityManager->refresh($observation);
        self::assertSame('test', $observation->getClassification()['classifierVersion']);

        $artifactPath = $observation->getArtifactPath();
        self::assertNotNull($artifactPath);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM http_observation');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM finding_claim_revision');
        $this->entityManager->getConnection()->executeStatement('DELETE FROM finding_claim');
        $this->entityManager->remove($finding);
        $this->entityManager->flush();
        $this->storage->deleteForFinding($finding);
        self::assertFalse($this->storage->exists($artifactPath));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM finding_claim'));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM finding_claim_revision'));
    }

    public function testProcessorStoresClassificationAndKeepsClaimLedgerIdempotent(): void
    {
        $finding = $this->findings->createFinding('https://cribrum-processor.example.test/a');
        $processor = new \App\Service\CribrumProcessingService(
            $this->entityManager,
            new \App\Cribrum\CribrumClassifier('/nonexistent-private-cribrum-packs'),
            new CribrumObservationStore($this->entityManager, $this->storage),
            new CribrumClaimService($this->entityManager),
        );
        $observation = [
            'schema' => 1,
            'contentType' => 'text/html',
            'httpStatus' => 500,
            'finalUrl' => 'https://cribrum-processor.example.test/a',
            'redirectChain' => [],
            'headers' => ['server' => 'fixture'],
            'rawHtml' => '<p>You have an error in your SQL syntax &lt;OPENBUGBOUNTY&gt;</p>',
            'rawHtmlBytes' => 65,
            'rawHtmlTruncated' => false,
            'domText' => 'You have an error in your SQL syntax <OPENBUGBOUNTY>',
            'domTextBytes' => 52,
            'domTextTruncated' => false,
        ];
        $entity = $processor->process($finding, $observation, dialogSeen: false, dialogText: null);
        self::assertNotNull($entity);
        self::assertNotNull($entity->getArtifactPath());
        self::assertSame('cribrum-1.0.0', $entity->getClassification()['classifierVersion']);
        $claims = $this->claims->currentClaims($finding);
        self::assertSame(['error_disclosure', 'information_disclosure', 'sql_injection', 'xss'], array_map(static fn ($claim): string => $claim->claim, $claims));
        self::assertNotNull($processor->process($finding, $observation));
        self::assertCount(4, $this->claims->currentClaims($finding));
        self::assertNull($processor->process($finding, ['schema' => 2]));
    }

    public function testScreenshotAndRetestPathsStoreObservationsAndApplySuggestions(): void
    {
        $finding = $this->findings->createFinding('https://cribrum-paths.example.test/a');
        $finding->setExpectedEvidence('<OPENBUGBOUNTY>');
        $this->entityManager->flush();
        $observation = [
            'schema' => 1,
            'contentType' => 'text/html',
            'httpStatus' => 500,
            'finalUrl' => 'https://cribrum-paths.example.test/a',
            'redirectChain' => [],
            'headers' => ['server' => 'fixture'],
            'rawHtml' => '<p>You have an error in your SQL syntax &lt;OPENBUGBOUNTY&gt;</p>',
            'rawHtmlBytes' => 65,
            'rawHtmlTruncated' => false,
            'domText' => 'You have an error in your SQL syntax <OPENBUGBOUNTY>',
            'domTextBytes' => 52,
            'domTextTruncated' => false,
        ];
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

        $screenshotClient = $this->createMock(BrowserScreenshotClientInterface::class);
        $screenshotClient->method('capture')->willReturn(new BrowserScreenshotResult(
            screenshotBase64: $png,
            capturedAt: new \DateTimeImmutable('2026-10-19T00:00:00.000Z'),
            httpStatus: 500,
            finalUrl: $finding->getPocUrl(),
            observation: $observation,
        ));
        $processor = new \App\Service\CribrumProcessingService(
            $this->entityManager,
            new \App\Cribrum\CribrumClassifier('/nonexistent-private-cribrum-packs'),
            new CribrumObservationStore($this->entityManager, $this->storage),
            new CribrumClaimService($this->entityManager),
        );
        $queue = new ScreenshotQueueService(
            self::getContainer()->get(ManagerRegistry::class),
            $screenshotClient,
            $this->storage,
            self::getContainer()->get(\App\Service\SettingsService::class),
            self::getContainer()->get(\App\Service\ScreenshotOperationLock::class),
            $processor,
        );
        $job = $queue->processNext();
        self::assertNotNull($job);
        $screenshotObservation = $this->entityManager->getRepository(HttpObservation::class)->findOneBy(['screenshotJobId' => $job->getId()]);
        self::assertNotNull($screenshotObservation);
        self::assertSame($finding->getId(), $screenshotObservation->getFinding()->getId());

        $retestClient = $this->createMock(BrowserRetestClientInterface::class);
        $retestClient->method('retest')->willReturnCallback(static fn (BrowserRetestRequest $request): RetestResultData => new RetestResultData(
            result: 'still_vulnerable',
            httpStatus: 500,
            finalUrl: $request->url,
            observedEvidence: 'OPENBUGBOUNTY',
            raw: ['fixture' => true],
            observation: $observation,
        ));
        $retest = new RetestService(
            $this->entityManager,
            self::getContainer()->get(RetestRunRepository::class),
            $retestClient,
            self::getContainer()->get(ValidationService::class),
            $this->storage,
            new \App\Service\RecheckPolicy(),
            $processor,
        );
        $run = $retest->retest($finding, false, 10000);
        $retestObservation = $this->entityManager->getRepository(HttpObservation::class)->findOneBy(['retestRunId' => $run->getId()]);
        self::assertNotNull($retestObservation);
        $claims = $this->claims->currentClaims($finding);
        self::assertSame(['error_disclosure', 'information_disclosure', 'sql_injection', 'xss'], array_map(static fn ($claim): string => $claim->claim, $claims));
        self::assertSame('stale', $claims[3]->state);
        self::assertStringContainsString('sql_injection', json_encode($retestObservation->getClassification(), JSON_THROW_ON_ERROR));
    }

    public function testObservationEligibilityAndArtifactAudit(): void
    {
        $finding = $this->findings->createFinding('https://cribrum-eligibility.example.test/a');
        $json = [...$this->transportObservation(), 'contentType' => 'application/json', 'httpStatus' => 200, 'rawHtml' => '{"token":"secret"}', 'rawHtmlBytes' => 17, 'domText' => null, 'domTextBytes' => null];
        try {
            $this->observations->store($finding, $json);
            self::fail('A successful JSON response must not be retained.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->storage->listPaths());
        }

        $plain = [...$this->transportObservation(), 'contentType' => 'text/plain', 'httpStatus' => 200, 'rawHtml' => 'root:x:0:0:root:/root:/bin/bash', 'rawHtmlBytes' => 31, 'domText' => null, 'domTextBytes' => null];
        $observation = $this->observations->store($finding, $plain, contextUrl: 'https://cribrum-eligibility.example.test/file?page=../../etc/passwd');
        self::assertNotNull($observation->getArtifactPath());
        $tester = new CommandTester(new ArtifactAuditCommand($this->entityManager->getConnection(), $this->storage));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('no unreferenced files', $tester->getDisplay());
    }

    public function testRejectsOversizedTransportPayload(): void
    {
        $finding = $this->findings->createFinding('https://cribrum-limit.example.test/a');
        $this->expectException(\InvalidArgumentException::class);
        $this->observations->store($finding, [
            'schema' => 1,
            'contentType' => 'text/html',
            'httpStatus' => 200,
            'finalUrl' => 'https://cribrum-limit.example.test/a',
            'redirectChain' => [],
            'headers' => [],
            'rawHtml' => str_repeat('a', 1048577),
            'rawHtmlTruncated' => true,
            'domText' => null,
            'domTextTruncated' => false,
        ]);
    }
}
