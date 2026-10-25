<?php

namespace App\Tests;

use App\Entity\RetestRun;
use App\Service\{FindingService,ReportProjectionService,WorkerProofResolver};
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;

final class ReportWorkerProofReviewTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
        $db=$this->entityManager->getConnection();
        $migration=new \DoctrineMigrations\Version20261022000000($db,new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $q) $db->executeStatement($q->getStatement());
    }

    public function testDeclaredXssNeedsActualWorkerEvidenceAndExplicitReviewIsSourceBound(): void
    {
        $finding=self::getContainer()->get(FindingService::class)->createIntakeFinding('https://review-proof.fixture.invalid/?q=payload',expectedEvidence:'MARKER',declaredType:'reflected_xss')->finding;
        $reports=self::getContainer()->get(ReportProjectionService::class);
        self::assertSame('hold',$reports->current($finding)['gate']);
        self::assertSame(['xss'],$reports->current($finding)['intake']['declaredClaims']);
        self::assertSame(0,(int)$this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM finding_claim WHERE finding_id=?',[$finding->getId()]));
        $run=(new RetestRun())->setFinding($finding)->setMode('browser')->setResult('still_vulnerable')->setFinishedAt(new \DateTimeImmutable())->setRawResult(['proofContext'=>WorkerProofResolver::captureContext($finding),'hookedDialogEvents'=>[['message'=>'different worker dialog']]]);
        $this->entityManager->persist($run); $this->entityManager->flush();
        $held=$reports->refresh($finding);
        self::assertSame('hold',$held['gate']);
        $candidate=$reports->workerProofCandidates($finding)[0];
        $accepted=$reports->reviewWorkerProof($finding,$candidate['proofId'],$held['basisRevision'],'fixture-reviewer','The captured hook event belongs to this exact PoC.');
        self::assertSame('eligible',$accepted['gate']);
        self::assertContains('xss_confirmed',array_column($accepted['components'],'code'));
        self::assertSame($accepted,$reports->refresh($finding));
        $finding->setExpectedEvidence('DIFFERENT'); $this->entityManager->flush();
        self::assertSame('hold',$reports->refresh($finding)['gate']);
        self::assertSame([],$reports->workerProofCandidates($finding));
    }

    public function testReviewCannotInventAWorkerEventOrUseAnOldBasis(): void
    {
        $finding=self::getContainer()->get(FindingService::class)->createFinding('https://no-event.fixture.invalid/',type:'reflected_xss');
        $reports=self::getContainer()->get(ReportProjectionService::class);
        $snapshot=$reports->refresh($finding);
        try { $reports->reviewWorkerProof($finding,'invented',$snapshot['basisRevision'],'reviewer','Payload looked convincing.'); self::fail('Payload cannot become proof.'); }
        catch (\InvalidArgumentException) { self::assertSame([],$reports->workerProofCandidates($finding)); }
        $this->expectException(\UnexpectedValueException::class);
        $reports->reviewWorkerProof($finding,'invented',0,'reviewer','Old context.');
    }
}
