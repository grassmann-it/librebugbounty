<?php
namespace App\Tests;

use App\Command\ReportRefreshCommand;
use App\Cribrum\Detector\SqlTriageDetector;
use App\Cribrum\ObservationInput;
use App\Service\{FindingService,CribrumObservationStore,ReportProjectionService};
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

final class ReportRefreshCommandTest extends DatabaseTestCase
{
    private function command(): CommandTester
    {
        $db=$this->entityManager->getConnection();
        if (!in_array('report_source_contract',$db->createSchemaManager()->listTableNames(),true)) {
            require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
            $migration=new \DoctrineMigrations\Version20261022000000($db,new NullLogger()); $migration->up(new Schema());
            foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement());
        }
        return new CommandTester(new ReportRefreshCommand($this->entityManager,self::getContainer()->get(ReportProjectionService::class)));
    }

    public function testRequiresExplicitScopeAndRejectsAmbiguousOrUnboundedLimits(): void
    {
        $command=$this->command();
        foreach ([[],['--all'=>true,'--limit'=>'1'],['--limit'=>'0'],['--limit'=>'10001'],['--finding'=>['']]] as $options) self::assertSame(2,$command->execute($options));
    }

    public function testDryRunRollsBackAllProjectionWritesAndLimitIsBounded(): void
    {
        $command=$this->command();
        $findings=self::getContainer()->get(FindingService::class);
        $findings->createFinding('https://refresh.test/one'); $findings->createFinding('https://refresh.test/two');
        $db=$this->entityManager->getConnection();
        $before=$db->fetchAllAssociative('SELECT * FROM finding_report_context ORDER BY finding_id');
        $revisions=$db->fetchOne('SELECT COUNT(*) FROM finding_report_revision');
        self::assertSame(0,$command->execute(['--limit'=>'1','--dry-run'=>true]));
        self::assertSame($before,$db->fetchAllAssociative('SELECT * FROM finding_report_context ORDER BY finding_id'));
        self::assertSame($revisions,$db->fetchOne('SELECT COUNT(*) FROM finding_report_revision'));
        self::assertStringContainsString('1 findings evaluated',$command->getDisplay());
        self::assertStringContainsString('all projection writes rolled back',$command->getDisplay());
    }

    public function testExplicitFindingWithoutObservationRefreshesAndMissingIdFails(): void
    {
        $command=$this->command();
        $finding=self::getContainer()->get(FindingService::class)->createFinding('https://refresh.test/no-observation');
        self::assertSame(0,$command->execute(['--finding'=>[$finding->getId()]]));
        self::assertStringContainsString('independent proof/context',$command->getDisplay());
        self::assertNotNull(self::getContainer()->get(ReportProjectionService::class)->current($finding));
        self::assertSame(1,$command->execute(['--finding'=>['nonexistent-id']]));
        self::assertStringContainsString('Finding not found',$command->getDisplay());
    }

    public function testUsesLatestRetainedActiveObservationInsteadOfNewerShadow(): void
    {
        $command=$this->command();
        $finding=self::getContainer()->get(FindingService::class)->createFinding('https://refresh.test/active?q=demo');
        $store=self::getContainer()->get(CribrumObservationStore::class);
        $raw='<pre>Microsoft JET Database Engine error: Syntax error (missing operator) in query expression id = demo.</pre>';
        $input=new ObservationInput($finding->getPocUrl(),$raw,null,'text/html',500,$finding->getPocUrl(),null,null,false,null);
        $signals=(new SqlTriageDetector())->detect($input,'active');
        self::assertNotEmpty($signals);
        $capture=['schema'=>1,'redirectChain'=>[],'headers'=>[],'rawHtmlBytes'=>strlen($raw),'rawHtmlTruncated'=>false,'domTextBytes'=>null,'domTextTruncated'=>false,'rawHtml'=>$raw,'domText'=>null,'httpStatus'=>500,'finalUrl'=>$finding->getPocUrl(),'contentType'=>'text/html'];
        $active=$store->store($finding,$capture, requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($finding));
        $active->setClassification(['signals'=>$signals,'classifierVersion'=>'command-test']); $this->entityManager->flush();
        $shadow=$store->store($finding,$capture, requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($finding));
        $shadow->setClassification(['signals'=>(new SqlTriageDetector())->detect($input,'shadow'),'classifierVersion'=>'command-test']); $this->entityManager->flush();
        $db=$this->entityManager->getConnection(); $jobs=$db->fetchOne('SELECT COUNT(*) FROM screenshot_job'); $runs=$db->fetchOne('SELECT COUNT(*) FROM retest_run');
        self::assertSame(0,$command->execute(['--finding'=>[$finding->getId()]]));
        self::assertStringContainsString('retained active observation '.$active->getId(),$command->getDisplay());
        self::assertStringNotContainsString($shadow->getId(),$command->getDisplay());
        $snapshot=self::getContainer()->get(ReportProjectionService::class)->current($finding);
        self::assertContains('sql_error_observed',$snapshot['tags']);
        self::assertSame($jobs,$db->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
        self::assertSame($runs,$db->fetchOne('SELECT COUNT(*) FROM retest_run'));
    }
}
