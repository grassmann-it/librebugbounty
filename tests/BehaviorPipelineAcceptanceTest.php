<?php

namespace App\Tests;

use App\Cribrum\Detector\ContextTriageDetector;
use App\Cribrum\Detector\SqlTriageDetector;
use App\Cribrum\ObservationInput;
use App\Cribrum\ReportPolicy;
use App\Dto\FindingReadFilter;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Repository\FindingReadRepository;
use App\Service\CribrumClaimService;
use App\Service\CribrumObservationStore;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use App\Service\IntakeProfileResolver;
use App\Service\LegacyCohortService;
use App\Service\ReportProjectionService;
use App\Service\WorkerProofResolver;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/** Offline integration across the actual PHP producers, SQLite view/query and Python reader. */
final class BehaviorPipelineAcceptanceTest extends DatabaseTestCase
{
    private ReportProjectionService $projection;
    private CribrumObservationStore $observations;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->entityManager->getConnection();
        if (!in_array('report_source_contract', $db->createSchemaManager()->listTableNames(), true)) {
            require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
            $migration = new \DoctrineMigrations\Version20261022000000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
        $this->observations = new CribrumObservationStore($this->entityManager, self::getContainer()->get(EvidenceStorageInterface::class));
        $this->projection = new ReportProjectionService($db, new ReportPolicy(), new IntakeProfileResolver(), new LegacyCohortService($db), new WorkerProofResolver($db), $this->observations);
    }

    public function testRealSqlDetectorProducesMixedSummaryWithSameComponentsInPythonAndUi(): void
    {
        $finding = $this->finding('combined');
        $this->proof($finding);
        $snapshot = $this->observe($finding, "<pre>Microsoft JET Database Engine error: Syntax error (missing operator) in query expression 'id = OPENBUG'.</pre>");
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('xss_with_sqli_suspected', $snapshot['lane']);
        self::assertSame('mixed', $snapshot['caseSummary']['kind']);
        self::assertContains('sqli_suspected', array_column($snapshot['components'], 'code'));
        $this->assertConsumers($finding, $snapshot, 'mixed', 'xss_containing');
        self::assertSame(0, (new FindingReadRepository($this->entityManager->getConnection()))->count(new FindingReadFilter(reportKind: 'pure_xss')));
        $python = $this->python($finding, 'compose');
        self::assertStringContainsString('Verdacht auf SQL-Injection', $python['paragraphs']);
        self::assertNotEmpty($python['leaks']);
        self::assertSame([], $python['safe']);
        self::assertSame($finding->getPocUrl(), $python['poc']);
    }

    public function testCompleteCoverageAllowsPureXssButUnknownCoverageDoesNotBlockMail(): void
    {
        $finding = $this->finding('pure');
        $this->proof($finding);
        $snapshot = $this->observe($finding, '<p>No error here.</p>');
        self::assertSame('pure_xss', $snapshot['caseSummary']['kind']);
        $this->assertConsumers($finding, $snapshot, 'pure_xss', 'pure_xss');
        $unknown = $this->finding('unknown');
        $this->proof($unknown);
        $unknownSnapshot = $this->projection->refresh($unknown);
        self::assertSame('eligible', $unknownSnapshot['gate']);
        self::assertSame('unknown', $unknownSnapshot['caseSummary']['purity']);
        $this->assertConsumers($unknown, $unknownSnapshot, 'unknown', 'xss_containing');
    }

    public function testRealDisclosureAndShadowEvidenceRemainDistinct(): void
    {
        $finding = $this->finding('disclosure');
        $snapshot = $this->observe($finding, '<p>Fatal error: broken in /var/www/private/site.php on line 42</p>');
        self::assertSame('information_disclosure', $snapshot['lane']);
        self::assertSame('pure_information_disclosure', $snapshot['caseSummary']['kind']);
        $this->assertConsumers($finding, $snapshot, 'only_information_disclosure', 'disclosure_only');
        $shadow = $this->finding('shadow');
        $this->proof($shadow);
        $shadowSnapshot = $this->observe($shadow, "<pre>Syntax error (missing operator) in query expression 'id = OPENBUG'.</pre>", 'shadow');
        self::assertSame('xss', $shadowSnapshot['lane']);
        self::assertNotContains('input_in_sql_error', $shadowSnapshot['tags']);
        self::assertNotContains('sqli_suspected', array_column($shadowSnapshot['components'], 'code'));
    }

    public function testCurrentProjectionInvalidationAndLiveWorkflowAreEnforcedByBothConsumers(): void
    {
        $finding = $this->finding('fenced');
        $this->proof($finding);
        $snapshot = $this->observe($finding, '<p>No error.</p>');
        $db = $this->entityManager->getConnection();
        $this->projection->invalidate($finding, 'acceptance_source_change');
        self::assertSame('hold', $this->projection->current($finding)['gate']);
        self::assertSame(0, (new FindingReadRepository($db))->count(new FindingReadFilter(reportKind:'pure_xss')));
        self::assertArrayHasKey('error', $this->python($finding));
        $this->projection->refresh($finding);
        $db->executeStatement("UPDATE finding SET status='fixed' WHERE id=?", [$finding->getId()]);
        $this->entityManager->refresh($finding);
        $this->projection->refresh($finding);
        self::assertStringContainsString('workflow_blocked', $this->python($finding)['error']);
        $db->executeStatement("UPDATE finding SET status='new' WHERE id=?", [$finding->getId()]);
        $db->executeStatement("UPDATE report_source_contract SET source_instance_id='changed-source' WHERE id=1");
        self::assertArrayHasKey('error', $this->python($finding));
        self::assertSame(0, (new FindingReadRepository($db))->count(new FindingReadFilter(reportKind:'pure_xss')));
    }

    public function testNativeProofEventsAndMarkerChangesCannotReuseAnOldExecutionFact(): void
    {
        $finding=$this->finding('marker');
        $this->proof($finding);
        $snapshot=$this->projection->refresh($finding);
        self::assertSame('eligible',$snapshot['gate']);
        $finding->setExpectedEvidence('CHANGED');
        $this->entityManager->flush();
        $changed=$this->projection->refresh($finding);
        self::assertNotContains('xss_confirmed',array_column($changed['components'],'code'));
        $boundary=$this->finding('events');
        $run=(new RetestRun())->setFinding($boundary)->setMode('browser')->setResult('still_vulnerable')->setFinishedAt(new \DateTimeImmutable())->setRawResult(['proofContext'=>WorkerProofResolver::captureContext($boundary),'dialogEvents'=>[['message'=>'OPEN'],['message'=>'BUG']]]);
        $this->entityManager->persist($run); $this->entityManager->flush();
        $unmatched=$this->projection->refresh($boundary);
        self::assertNotContains('xss_confirmed',array_column($unmatched['components'],'code'));
    }

    public function testFirstProvenWafBlockHoldsUntilExplicitCurrentAcknowledgement(): void
    {
        $finding=$this->finding('waf'); $this->proof($finding);
        self::assertSame('eligible',$this->observe($finding,'<p>Normal response</p>')['gate']);
        $blocked=$this->observe($finding,'<p>Sorry, you have been blocked by Cloudflare</p>');
        self::assertSame('hold',$blocked['gate']);
        self::assertContains('new_waf_block_unreviewed',$blocked['reasonCodes']);
        self::assertContains('xss_confirmed',array_column($blocked['components'],'code'));
        self::assertArrayHasKey('error',$this->python($finding));
        $fingerprint=$blocked['wafBlockFingerprints'][0];
        $ack=$this->projection->acknowledgeWafBlock($finding,$fingerprint,'acceptance-reviewer');
        self::assertSame('eligible',$ack['gate']);
        self::assertArrayNotHasKey('error',$this->python($finding));
    }

    public function testFrozenCohortManifestIntegrityDoesNotEnrollNewMigrationClaims(): void
    {
        if (!is_file(dirname(__DIR__).'/.architecture-sparring/behavior-tags-legacy-cohort-v1.json')) self::markTestSkipped('Private authorized cohort manifest is not distributed.');
        $manifest=json_decode(file_get_contents(dirname(__DIR__).'/.architecture-sparring/behavior-tags-legacy-cohort-v1.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertCount(6968,LegacyCohortService::validateManifest($manifest));
        $finding=$this->finding('migration');
        (new CribrumClaimService($this->entityManager))->apply($finding,'xss','observed',true,'migration');
        $snapshot=$this->projection->refresh($finding);
        self::assertNull($snapshot['legacyBasis']);
        self::assertNotContains('xss_confirmed',array_column($snapshot['components'],'code'));
    }

    public function testStaleCoverageAndPartialFamilyEvaluationNeverPassPureFilters(): void
    {
        foreach (['partial','stale'] as $variant) {
            $finding=$this->finding('coverage-'.$variant); $this->proof($finding);
            $snapshot=$this->observe($finding,'<p>No error.</p>',coverageVariant:$variant);
            self::assertSame('eligible',$snapshot['gate']);
            self::assertNotSame('pure',$snapshot['caseSummary']['purity']);
            self::assertArrayHasKey('error',$this->python($finding,'read','pure_xss'));
        }
        self::assertSame(0,(new FindingReadRepository($this->entityManager->getConnection()))->count(new FindingReadFilter(reportKind:'pure_xss')));
    }

    public function testEffectivePocBytesSurviveProjectionAndPythonWithoutNormalization(): void
    {
        $finding=$this->finding('original');
        $exact='https://original.fixture.invalid/new?q=TWO%2Bthree&q=FOUR#KeepCase';
        $finding->setPocUrl($exact,$finding->getPocRevision()+1); $this->entityManager->flush();
        $this->proof($finding);
        $snapshot=$this->observe($finding,'<p>No error.</p>');
        self::assertNotSame($finding->getUrl(),$snapshot['poc']['effectiveUrl']);
        self::assertSame($exact,$snapshot['poc']['effectiveUrl']);
        self::assertSame(hash('sha256',$exact),$snapshot['poc']['sha256']);
        self::assertSame($exact,$this->python($finding)['poc']);
    }

    private function finding(string $host): Finding
    {
        return self::getContainer()->get(FindingService::class)->createFinding('https://'.$host.'.fixture.invalid/check?q=OPENBUG', type:'unclassified', expectedEvidence:'OPENBUG');
    }

    private function proof(Finding $finding): void
    {
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('still_vulnerable')->setFinishedAt(new \DateTimeImmutable('-1 minute'))->setRawResult(['proofContext'=>WorkerProofResolver::captureContext($finding), 'dialogEvents'=>[['message'=>'observed OPENBUG', 'type'=>'alert']]]);
        $this->entityManager->persist($run); $this->entityManager->flush();
    }

    private function observe(Finding $finding, string $raw, string $mode='active', string $coverageVariant='complete'): array
    {
        $input = new ObservationInput($finding->getPocUrl(),$raw,null,'text/html',500,$finding->getPocUrl(),null,'OPENBUG',false,null);
        $signals = [...(new SqlTriageDetector())->detect($input,$mode), ...(new ContextTriageDetector())->detect($input,$mode)];
        $observation = $this->observations->store($finding,['schema'=>1,'contentType'=>'text/html','httpStatus'=>500,'finalUrl'=>$finding->getPocUrl(),'rawHtml'=>$raw,'rawHtmlBytes'=>strlen($raw),'rawHtmlTruncated'=>false,'domText'=>null,'domTextBytes'=>null,'domTextTruncated'=>false,'redirectChain'=>[],'headers'=>[]], requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($finding));
        $coverage=['profile'=>'behavior-triage-v1','version'=>1,'status'=>'complete','evaluatedFamilies'=>ReportPolicy::COVERAGE_FAMILIES,'pocRevision'=>$finding->getPocRevision(),'pocSha256'=>hash('sha256',$finding->getPocUrl()),'artifactSha256'=>hash('sha256',$raw),'resultVersion'=>'acceptance-v1','inputUrlSha256'=>hash('sha256',$finding->getPocUrl()),'mode'=>'active'];
        if ($coverageVariant==='partial') $coverage['evaluatedFamilies']=['sql'];
        if ($coverageVariant==='stale') $coverage['artifactSha256']=str_repeat('a',64);
        (new CribrumClaimService($this->entityManager))->attachClassification($observation,['classifierVersion'=>'acceptance-v1','signals'=>$signals,'suggestions'=>[],'coverage'=>$coverage]);
        return $this->projection->refresh($finding,$observation);
    }

    private function assertConsumers(Finding $finding,array $snapshot,string $uiKind,string $scope): void
    {
        $views=(new FindingReadRepository($this->entityManager->getConnection()))->findPage(new FindingReadFilter(reportKind:$uiKind));
        $view=array_values(array_filter($views,static fn($v)=>$v->id===$finding->getId()));
        self::assertCount(1,$view);
        $python=$this->python($finding,'read',$scope);
        self::assertArrayNotHasKey('error',$python,json_encode($python));
        self::assertSame($snapshot['caseSummary'],$python['caseSummary']);
        self::assertSame($snapshot['components'],$python['components']);
    }

    private function python(Finding $finding,string $action='read',string $scope='all_supported'): array
    {
        $root=getenv('RGEN_BEHAVIOR_PATH') ?: dirname(__DIR__,2).'/R_GEN-behavior-integration';
        if (!is_file($root.'/src/report_gen/handoff.py')) self::markTestSkipped('Optional report-gen checkout absent; set RGEN_BEHAVIOR_PATH for cross-language acceptance.');
        $process=new Process(['python3',__DIR__.'/Support/behavior_pipeline_consumer.py',APP_TEST_ROOT.'/database.sqlite',$finding->getId(),$action,$scope],$root,['PYTHONPATH'=>$root.'/src']);
        $process->mustRun();
        return json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR);
    }
}
