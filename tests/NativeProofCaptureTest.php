<?php

namespace App\Tests;

use App\Dto\BrowserRetestRequest;
use App\Dto\BrowserScreenshotResult;
use App\Dto\RetestResultData;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\RecheckPolicy;
use App\Service\ReportProjectionService;
use App\Service\RetestService;
use App\Service\ScreenshotOperationLock;
use App\Service\ScreenshotQueueService;
use App\Service\SettingsService;
use App\Service\ValidationService;
use App\Service\WorkerProofResolver;
use App\Value\RetestResult;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;

final class NativeProofCaptureTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->entityManager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist(['report_source_contract'])) {
            require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
            $migration = new \DoctrineMigrations\Version20261022000000($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    public function testNativeScreenshotProofRefreshesProjectionWithoutHttpObservation(): void
    {
        $finding = $this->finding();
        $context = WorkerProofResolver::captureContext($finding);
        $queue = $this->queue($this->screenshot(['dialogEvents' => [['type' => 'alert', 'message' => 'native OPENBUG proof']], 'proofContext' => ['expectedMarker' => 'forged']]));
        $queue->enqueue($finding);
        $job = $queue->processNext();
        self::assertSame($context, $job->getCaptureMetadata()['proofContext']);
        self::assertSame('native OPENBUG proof', $job->getCaptureMetadata()['dialogEvents'][0]['message']);
        self::assertTrue($this->proof($finding)['verified']);
        self::assertNotNull(self::getContainer()->get(ReportProjectionService::class)->current($finding));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM finding_behavior_fact WHERE finding_id=? AND code='execution_observed'", [$finding->getId()]));
    }

    public function testHookedAndLegacyMergedScreenshotDialogsRemainUnproven(): void
    {
        foreach ([['hookedDialogEvents' => [['type' => 'alert', 'message' => 'OPENBUG']]], []] as $metadata) {
            $finding = $this->finding();
            $queue = $this->queue($this->screenshot($metadata));
            $queue->enqueue($finding);
            $queue->processNext();
            self::assertFalse($this->proof($finding)['verified']);
        }
    }

    public function testScreenshotRequestHashUsesAuthoritativeDatabaseValues(): void
    {
        $finding = $this->finding();
        $params = ['body' => 'é/+'];
        $this->entityManager->getConnection()->update('finding', ['method' => 'POST', 'request_params' => json_encode($params, JSON_THROW_ON_ERROR), 'payload' => 'database payload'], ['id' => $finding->getId()]);
        $expectedHash = WorkerProofResolver::requestContextHashFromValues('POST', $params, 'database payload', 'OPENBUG');
        self::assertNotSame($expectedHash, WorkerProofResolver::requestContextSha256($finding));
        $raw='<p>database payload</p>';
        $observation=['schema'=>1,'contentType'=>'text/html','httpStatus'=>200,'finalUrl'=>$finding->getPocUrl(),'rawHtml'=>$raw,'rawHtmlBytes'=>strlen($raw),'rawHtmlTruncated'=>false,'domText'=>null,'domTextBytes'=>null,'domTextTruncated'=>false,'redirectChain'=>[],'headers'=>[]];
        $result=new BrowserScreenshotResult(self::PNG,new \DateTimeImmutable(),200,dialogSeen:true,dialogText:'OPENBUG',metadata:['dialogEvents'=>[['type'=>'alert','message'=>'OPENBUG']]],observation:$observation);
        $queue = $this->queue($result,withCribrum:true);
        $queue->enqueue($finding);
        $job = $queue->processNext();
        self::assertSame($expectedHash, $job->getCaptureMetadata()['proofContext']['requestContextSha256']);
        $stored=$this->entityManager->getConnection()->fetchOne('SELECT metadata FROM http_observation WHERE screenshot_job_id=?',[$job->getId()]);
        self::assertIsString($stored);
        self::assertSame('database payload',json_decode($stored,true)['classificationContext']['payload']);
        self::assertSame($expectedHash,json_decode($stored,true)['requestContextSha256']);
    }

    public function testScreenshotMarkerIsPinnedBeforeDispatchAndNotReconstructed(): void
    {
        $finding = $this->finding();
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->method('capture')->willReturnCallback(function () use ($finding): BrowserScreenshotResult {
            $this->entityManager->getConnection()->update('finding', ['expected_evidence' => 'NEW-MARKER'], ['id' => $finding->getId()]);
            return $this->screenshot(['dialogEvents' => [['type' => 'alert', 'message' => 'OPENBUG NEW-MARKER']]]);
        });
        $queue = $this->queue(client: $client);
        $queue->enqueue($finding);
        $job = $queue->processNext();
        self::assertSame('OPENBUG', $job->getCaptureMetadata()['proofContext']['expectedMarker']);
        self::assertSame('NEW-MARKER', $finding->getExpectedEvidence());
        self::assertFalse($this->proof($finding)['verified']);
    }

    public function testRetestPinsContextAndRefreshesProjectionWithoutObservation(): void
    {
        $finding = $this->finding();
        $context = WorkerProofResolver::captureContext($finding);
        $service = $this->retester($this->retestResult());
        $run = $service->retest($finding, noStatusUpdate: true);
        self::assertSame($context, $run->getRawResult()['proofContext']);
        self::assertTrue($this->proof($finding)['verified']);
        self::assertNotNull(self::getContainer()->get(ReportProjectionService::class)->current($finding));
    }

    public function testUnboundRecordedResultCannotSupplyItsOwnProofContext(): void
    {
        $finding = $this->finding();
        $result = $this->retestResult(['proofContext' => WorkerProofResolver::captureContext($finding)]);
        $run = $this->retester($result)->recordBrowserResult($finding, $result, noStatusUpdate: true);
        self::assertNull($run->getRawResult()['proofContext']);
        self::assertFalse($this->proof($finding)['verified']);
    }

    public function testRetestMarkerChangeWhileWorkerRunsKeepsOriginalContext(): void
    {
        $finding = $this->finding();
        $client = $this->createMock(BrowserRetestClientInterface::class);
        $client->method('retest')->willReturnCallback(function (BrowserRetestRequest $request) use ($finding): RetestResultData {
            self::assertSame('OPENBUG', $request->expectedEvidence);
            $this->entityManager->getConnection()->update('finding', ['expected_evidence' => 'NEW-MARKER'], ['id' => $finding->getId()]);
            return $this->retestResult();
        });
        $run = $this->retester(client: $client)->retest($finding, noStatusUpdate: true);
        self::assertSame('OPENBUG', $run->getRawResult()['proofContext']['expectedMarker']);
        self::assertTrue($run->getRawResult()['staleFindingContext']);
        self::assertFalse($this->proof($finding)['verified']);
    }

    private function screenshot(array $metadata): BrowserScreenshotResult
    {
        return new BrowserScreenshotResult(self::PNG, new \DateTimeImmutable('2026-10-22T12:00:00Z'), 200, dialogSeen: true, dialogType: 'alert', dialogText: 'OPENBUG', metadata: $metadata);
    }

    private function queue(?BrowserScreenshotResult $result = null, ?BrowserScreenshotClientInterface $client = null, bool $withCribrum = false): ScreenshotQueueService
    {
        if ($client === null) {
            $client = $this->createMock(BrowserScreenshotClientInterface::class);
            $client->method('capture')->willReturn($result);
        }
        return new ScreenshotQueueService(self::getContainer()->get(ManagerRegistry::class), $client,
            self::getContainer()->get(EvidenceStorageInterface::class), self::getContainer()->get(SettingsService::class),
            new ScreenshotOperationLock(), cribrum:$withCribrum ? self::getContainer()->get(\App\Service\CribrumProcessingService::class) : null, reportProjection: self::getContainer()->get(ReportProjectionService::class));
    }

    private function retestResult(array $extra = []): RetestResultData
    {
        return new RetestResultData(RetestResult::STILL_VULNERABLE, 200, dialogText: 'OPENBUG', raw: array_merge(['dialogEvents' => [['type' => 'alert', 'message' => 'OPENBUG']]], $extra));
    }

    private function retester(?RetestResultData $result = null, ?BrowserRetestClientInterface $client = null): RetestService
    {
        if ($client === null) {
            $client = $this->createMock(BrowserRetestClientInterface::class);
            $client->method('retest')->willReturn($result);
        }
        return new RetestService($this->entityManager, $this->entityManager->getRepository(\App\Entity\RetestRun::class), $client,
            self::getContainer()->get(ValidationService::class), self::getContainer()->get(EvidenceStorageInterface::class), new RecheckPolicy(),
            reportProjection: self::getContainer()->get(ReportProjectionService::class));
    }

    private function finding(): Finding
    {
        $domain = (new Domain())->setHostname(bin2hex(random_bytes(4)).'.fixture.test')->setScheme('https');
        $finding = (new Finding())->setDomain($domain)->setTitle('Native proof fixture')->setType('reflected_xss')->setUrl('https://fixture.test/proof')->setExpectedEvidence('OPENBUG');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        return $finding;
    }

    private function proof(Finding $finding): ?array
    {
        return (new WorkerProofResolver($this->entityManager->getConnection()))->resolve($finding, 'fixture-source');
    }
}
