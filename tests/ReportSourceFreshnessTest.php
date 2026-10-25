<?php
namespace App\Tests;

use App\Cribrum\CribrumClassifier;
use App\Entity\{Finding,HttpObservation,RetestRun};
use App\Service\{FindingService,CribrumProcessingService,CribrumObservationStore,CribrumClaimService,ReportProjectionService,WorkerProofResolver,CribrumReclassifyService,EvidenceStorageInterface};
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;

/** Source deletion and same-PoC request changes must never manufacture fresh evidence. */
final class ReportSourceFreshnessTest extends DatabaseTestCase
{
    private CribrumObservationStore $store;
    private ReportProjectionService $reports;
    protected function setUp(): void
    {
        parent::setUp(); $db=$this->entityManager->getConnection();
        if (!in_array('report_source_contract',$db->createSchemaManager()->listTableNames(),true)) {
            require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
            $migration=new \DoctrineMigrations\Version20261022000000($db,new NullLogger()); $migration->up(new Schema());
            foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement());
        }
        $this->store=self::getContainer()->get(CribrumObservationStore::class);
        $this->reports=self::getContainer()->get(ReportProjectionService::class);
    }
    public function testDeletingZeroFactCoverageSourceCannotRestorePureXssOnBodylessRefresh(): void
    {
        $finding=$this->finding('zero-fact'); $this->proof($finding);
        $capture=$this->process($finding,'<p>Ordinary response.</p>');
        $before=$this->reports->current($finding);
        self::assertSame('pure_xss',$before['caseSummary']['kind']);
        self::assertSame($capture->getId(),$before['coverage']['sourceRef']['id']);
        self::assertSame(['execution_observed'],array_column($this->reports->evidence($finding),'code'));
        $this->entityManager->remove($capture); $this->entityManager->flush();
        $after=$this->reports->refresh($finding);
        self::assertSame('eligible',$after['gate']);
        self::assertSame('unknown',$after['coverage']['status']);
        self::assertNotSame('pure_xss',$after['caseSummary']['kind']);
    }
    public function testDeletedCoverageArtifactCannotRestorePurityEither(): void
    {
        $finding=$this->finding('deleted-artifact'); $this->proof($finding);
        $capture=$this->process($finding,'<p>Ordinary response.</p>');
        self::getContainer()->get(EvidenceStorageInterface::class)->deleteFile($capture->getArtifactPath());
        $after=$this->reports->refresh($finding);
        self::assertSame('eligible',$after['gate']); self::assertSame('unknown',$after['coverage']['status']);
        self::assertNotSame('pure_xss',$after['caseSummary']['kind']);
    }
    public function testUnpinnedSameContextHistoryDoesNotDisplaceValidIndependentBasis(): void
    {
        $finding=$this->finding('unknown-history'); $this->proof($finding);
        $this->process($finding,'<p>Ordinary response.</p>');
        $before=$this->reports->current($finding);
        $processor=new CribrumProcessingService($this->entityManager,$this->classifier(),$this->store,new CribrumClaimService($this->entityManager),new NullLogger(),$this->reports);
        $unknown=$processor->process($finding,$this->transport($finding,'<p>Unknown unpinned diagnostic capture.</p>'));
        self::assertSame('cribrum-stale',$unknown->getClassification()['classifierVersion']);
        self::assertSame($before,$this->reports->current($finding));
        $service=new CribrumReclassifyService($this->entityManager,$this->classifier(),$this->store,new CribrumClaimService($this->entityManager),$this->reports);
        self::assertSame([],$service->reclassify($finding->getId())['errors']);
        self::assertSame($before,$this->reports->current($finding));
    }

    public function testChangedPayloadWithNewProofCannotReuseSelectedOldSqlFacts(): void
    {
        $finding=$this->finding('payload-change'); $this->proof($finding);
        $capture=$this->process($finding,"<pre>Microsoft JET Database Engine error: Syntax error (missing operator) in query expression 'id = OPENBUG'.</pre>");
        self::assertContains('sqli_suspected',array_column($this->reports->current($finding)['components'],'code'));
        $finding->setPayload('different payload'); $this->entityManager->flush(); $this->proof($finding);
        try { $this->reports->refresh($finding); self::fail('Selected stale facts must fail closed.'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('request context changed',$error->getMessage()); }
        self::assertSame('hold',$this->reports->current($finding)['gate']);
        $after=$this->reports->refresh($finding,$capture);
        self::assertSame('eligible',$after['gate']);
        self::assertSame(['xss_confirmed'],array_column($after['components'],'code'));
        self::assertNotContains('sql_error_observed',$after['tags']);
        self::assertSame('unknown',$after['coverage']['status']);
        self::assertNotSame('pure_xss',$after['caseSummary']['kind']);
    }
    public function testStalePinnedProcessAndUnpinnedReplayNeverApplyCurrentClaims(): void
    {
        $finding=$this->finding('old-request'); $pin=WorkerProofResolver::requestContextSha256($finding);
        $finding->setMethod('POST')->setRequestParams(['q'=>'changed']); $this->entityManager->flush();
        $capture=$this->process($finding,"<p>Fatal error: broken in /var/www/private/site.php on line 42</p>",$pin);
        self::assertSame('cribrum-stale',$capture->getClassification()['classifierVersion']);
        self::assertSame(0,(int)$this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM finding_claim WHERE finding_id=?',[$finding->getId()]));
        // A historical record with no pin remains inspectable but cannot adopt today's context on replay.
        $unknown=$this->store->store($finding,$this->transport($finding,'<p>Fatal error: broken in /var/www/private/other.php on line 42</p>'));
        $service=new CribrumReclassifyService($this->entityManager,$this->classifier(),$this->store,new CribrumClaimService($this->entityManager),$this->reports);
        $result=$service->reclassify($finding->getId()); self::assertSame([],$result['errors']);
        self::assertSame(0,(int)$this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM finding_claim WHERE finding_id=?',[$finding->getId()]));
        $latest=$this->entityManager->getConnection()->fetchOne('SELECT metadata FROM http_observation WHERE finding_id=? ORDER BY created_at DESC,id DESC LIMIT 1',[$finding->getId()]);
        self::assertNull(json_decode($latest,true)['requestContextSha256']);
        self::assertSame($unknown->getId(),json_decode($latest,true)['reclassifiedFrom']);
    }
    private function finding(string $name): Finding { return self::getContainer()->get(FindingService::class)->createFinding('https://'.$name.'.fixture.invalid/?q=OPENBUG',type:'unclassified',expectedEvidence:'OPENBUG'); }
    private function proof(Finding $finding): void
    {
        $run=(new RetestRun())->setFinding($finding)->setMode('browser')->setResult('still_vulnerable')->setFinishedAt(new \DateTimeImmutable())->setRawResult(['proofContext'=>WorkerProofResolver::captureContext($finding),'dialogEvents'=>[['message'=>'OPENBUG native alert']]]);
        $this->entityManager->persist($run); $this->entityManager->flush();
    }
    private function classifier(): CribrumClassifier { return new CribrumClassifier('/nonexistent-private-packs',dirname(__DIR__).'/resources/cribrum/candidates','active'); }
    private function process(Finding $finding,string $raw,?string $pin=null): HttpObservation
    {
        $processor=new CribrumProcessingService($this->entityManager,$this->classifier(),$this->store,new CribrumClaimService($this->entityManager),new NullLogger(),$this->reports);
        return $processor->process($finding,$this->transport($finding,$raw),requestContextSha256:$pin ?? WorkerProofResolver::requestContextSha256($finding));
    }
    private function transport(Finding $finding,string $raw): array { return ['schema'=>1,'contentType'=>'text/html','httpStatus'=>500,'finalUrl'=>$finding->getPocUrl(),'rawHtml'=>$raw,'rawHtmlBytes'=>strlen($raw),'rawHtmlTruncated'=>false,'domText'=>null,'domTextBytes'=>null,'domTextTruncated'=>false,'redirectChain'=>[],'headers'=>[]]; }
}
