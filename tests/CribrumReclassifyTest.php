<?php

namespace App\Tests;

use App\Cribrum\CribrumClassifier;
use App\Entity\HttpObservation;
use App\Service\CribrumClaimService;
use App\Service\CribrumObservationStore;
use App\Service\CribrumProcessingService;
use App\Service\CribrumReclassifyService;
use App\Service\CribrumStatusService;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use Symfony\Component\Filesystem\Filesystem;

final class CribrumReclassifyTest extends DatabaseTestCase
{
    private string $packs;
    private CribrumObservationStore $store;
    private CribrumClaimService $claims;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packs = sys_get_temp_dir().'/cribrum-reclassify-'.bin2hex(random_bytes(6));
        mkdir($this->packs, 0700);
        $this->store = new CribrumObservationStore($this->entityManager, self::getContainer()->get(EvidenceStorageInterface::class));
        $this->claims = new CribrumClaimService($this->entityManager);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->packs);
        parent::tearDown();
    }

    public function testChangedPackCreatesImmutableRevisionAndRepeatIsNoop(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://fixture.test/reclassify');
        $processor = new CribrumProcessingService($this->entityManager, new CribrumClassifier($this->packs), $this->store, $this->claims);
        $original = $processor->process($finding, $this->transport());
        $originalResult = $original->getClassification();
        $this->pack('v1');
        $summary = $this->service()->reclassify($finding->getId(), true);
        self::assertSame([], $summary['errors']);
        self::assertSame(1, $this->rowCount('http_observation'));
        $this->service()->reclassify($finding->getId());
        $this->entityManager->refresh($original);
        self::assertSame($originalResult, $original->getClassification());
        self::assertSame(2, $this->rowCount('http_observation'));
        $history = $this->rowCount('finding_claim_revision');
        $this->service()->reclassify($finding->getId());
        self::assertSame(2, $this->rowCount('http_observation'));
        self::assertSame($history, $this->rowCount('finding_claim_revision'));
        $this->pack('v2');
        $this->service()->reclassify($finding->getId());
        self::assertSame(3, $this->rowCount('http_observation'));
        self::assertGreaterThan($history, $this->rowCount('finding_claim_revision'));
        $snapshot = (new CribrumStatusService($this->entityManager->getConnection()))->snapshot();
        self::assertSame(3, $snapshot['observations']['total']);
        self::assertSame(3, $snapshot['observations']['withArtifact']);
        self::assertNotNull($snapshot['observations']['lastClassifiedAt']);
    }

    public function testManualClaimsIncludingSameStateRemainUntouched(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://fixture.test/manual');
        $observation = $this->store->store($finding, $this->transport());
        $manual = $this->claims->apply($finding, 'information_disclosure', 'observed', false, 'manual', [], 'Reviewed', $observation->getId());
        $this->claims->apply($finding, 'sql_injection', 'rejected', false, 'manual');
        $this->pack('v1');
        $this->service()->reclassify($finding->getId());
        self::assertSame('manual', $manual->source);
        self::assertSame(1, $manual->revision);
        self::assertSame($observation->getId(), $manual->observationId);
        self::assertSame('rejected', $this->entityManager->getConnection()->fetchOne("SELECT state FROM finding_claim WHERE claim = 'sql_injection'"));
    }

    public function testUnreadableArtifactDoesNotStopOtherCasesAndHistoricalPocIsSkipped(): void
    {
        $findingService = self::getContainer()->get(FindingService::class);
        $broken = $findingService->createFinding('https://fixture.test/broken');
        $observation = $this->store->store($broken, $this->transport());
        self::getContainer()->get(EvidenceStorageInterface::class)->deleteFile($observation->getArtifactPath());
        $good = $findingService->createFinding('https://fixture.test/good');
        $this->store->store($good, $this->transport());
        $old = $findingService->createFinding('https://fixture.test/old');
        $this->store->store($old, $this->transport(), pocRevision: $old->getPocRevision() + 1);
        $summary = $this->service()->reclassify();
        self::assertSame(1, $summary['processed']);
        self::assertSame(1, $summary['skipped']);
        self::assertCount(1, $summary['errors']);
        self::assertStringContainsString($observation->getId(), $summary['errors'][0]);
    }

    public function testOnlyLatestCaptureAffectsClaimsAndDialogContextIsRetained(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://fixture.test/latest');
        $finding->setExpectedEvidence('CRIBRUM');
        $this->entityManager->flush();
        $processor = new CribrumProcessingService($this->entityManager, new CribrumClassifier($this->packs), $this->store, $this->claims);
        $processor->process($finding, $this->transport('You have an error in your SQL syntax CRIBRUM'));
        $processor->process($finding, $this->transport(), dialogSeen: true, dialogText: 'CRIBRUM');
        $this->pack('v1');
        self::assertSame(1, $this->service()->reclassify($finding->getId())['processed']);
        $states = $this->entityManager->getConnection()->fetchAllKeyValue('SELECT claim, state FROM finding_claim');
        self::assertSame('stale', $states['sql_injection']);
        self::assertSame('observed', $states['xss']);
        $history = $this->rowCount('finding_claim_revision');
        $this->service()->reclassify($finding->getId());
        self::assertSame($history, $this->rowCount('finding_claim_revision'));
    }

    public function testStatusWorksWithoutCribrumSchema(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DROP TABLE http_observation');
        $snapshot = (new CribrumStatusService($connection))->snapshot();
        self::assertFalse($snapshot['available']);
        self::assertNull($snapshot['observations']);
        self::assertSame('core', $snapshot['packs'][0]['id']);
    }

    private function service(): CribrumReclassifyService
    {
        return new CribrumReclassifyService($this->entityManager, new CribrumClassifier($this->packs), $this->store, $this->claims);
    }

    private function pack(string $version): void
    {
        file_put_contents($this->packs.'/custom.json', json_encode([
            'schema' => 1, 'id' => 'custom', 'version' => $version,
            'rules' => [['id' => 'marker', 'representation' => 'text', 'any' => ['CRIBRUM'], 'claim' => 'information_disclosure', 'state' => 'observed', 'score' => 90, 'reason' => 'Synthetic marker']],
        ], JSON_THROW_ON_ERROR));
    }

    private function rowCount(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function transport(string $text = 'CRIBRUM'): array
    {
        return ['schema' => 1, 'contentType' => 'text/html', 'httpStatus' => 500,
            'finalUrl' => 'https://fixture.test/response', 'redirectChain' => [], 'headers' => [],
            'rawHtml' => '<p>'.$text.'</p>', 'rawHtmlBytes' => strlen($text) + 7, 'rawHtmlTruncated' => false,
            'domText' => $text, 'domTextBytes' => strlen($text), 'domTextTruncated' => false];
    }
}
