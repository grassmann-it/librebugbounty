<?php
namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingDetailService;
use App\Service\FindingService;
use App\Service\ReviewNoticeService;
use App\Service\ReviewQueueService;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SemanticReviewTransitionTest extends DatabaseTestCase
{
    public function testClaimFreeChangeEntersQueueAndExplicitAckKeepsVerdictEmptyAndDeduplicates(): void
    {
        $db=$this->entityManager->getConnection();
        $db->executeStatement('CREATE TABLE finding_report_revision (finding_id TEXT,revision INTEGER,snapshot_json TEXT)');
        $db->executeStatement('CREATE VIEW finding_report_current AS SELECT * FROM finding_report_revision r WHERE revision=(SELECT MAX(revision) FROM finding_report_revision WHERE finding_id=r.finding_id)');
        $domain=(new Domain())->setHostname('semantic.test')->setScheme('https');
        $finding=(new Finding())->setDomain($domain)->setUrl('https://semantic.test/?x=1')->setTitle('Semantic fixture')->setType('other')->setSeverity('medium')->setMethod('GET')->setStatus('new');
        $this->entityManager->persist($domain); $this->entityManager->persist($finding); $this->entityManager->flush();
        $baseline=['decisionRevision'=>1,'legacyBasis'=>['verified'=>true],'gate'=>'eligible','tags'=>['internal_information_exposed'],'components'=>[],'caseSummary'=>['hints'=>[]],'reasonCodes'=>[]];
        $db->insert('finding_report_revision',['finding_id'=>$finding->getId(),'revision'=>1,'snapshot_json'=>json_encode($baseline)]);
        $notices=self::getContainer()->get(ReviewNoticeService::class);
        self::assertNull($notices->get($finding->getId()));
        $current=array_replace($baseline,['decisionRevision'=>2,'tags'=>['coverage_limited','lfi_suspected'],'gate'=>'no_mail','caseSummary'=>['hints'=>['local_include']],'packVersion'=>'2','decidedAt'=>'2026-10-23T10:00:00Z']);
        $db->insert('finding_report_revision',['finding_id'=>$finding->getId(),'revision'=>2,'snapshot_json'=>json_encode($current)]);
        $notice=$notices->get($finding->getId());
        self::assertSame('classification_changed',$notice->observations[0]['reason']);
        self::assertSame(['internal_information_exposed'],$notice->observations[0]['before']['tags']);
        $csrf=$this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('fixture','signed'));
        $csrf->method('isTokenValid')->willReturn(true);
        $queue=new ReviewQueueService($this->entityManager,self::getContainer()->get(EvidenceStorageInterface::class),self::getContainer()->get(FindingDetailService::class),self::getContainer()->get(FindingService::class),$csrf,$notices);
        $view=$queue->get(['kind'=>'changed','images'=>'all']);
        self::assertSame(1,$view->counts['changed']);
        $oldToken=$queue->contextToken($finding->getId(),$queue->fingerprint($finding->getId()));
        $queue->assess($finding->getId(),'acknowledge_change',null,null,null,$oldToken);
        self::assertNull($notices->get($finding->getId()));
        self::assertNull($db->fetchOne('SELECT manual_assessment FROM finding WHERE id=?',[$finding->getId()]));
        $current['decisionRevision']=3; $current['packVersion']='99'; $current['decidedAt']='2026-10-24T10:00:00Z';
        $db->insert('finding_report_revision',['finding_id'=>$finding->getId(),'revision'=>3,'snapshot_json'=>json_encode($current)]);
        self::assertNull($notices->get($finding->getId()));
        $current['decisionRevision']=4; $current['caseSummary']['hints'][]='sql_input_structure_indication';
        $db->insert('finding_report_revision',['finding_id'=>$finding->getId(),'revision'=>4,'snapshot_json'=>json_encode($current)]);
        self::assertNotNull($notices->get($finding->getId()));
        try { $queue->assess($finding->getId(),'acknowledge_change',null,null,null,$oldToken); self::fail('Stale signed context accepted'); }
        catch (\UnexpectedValueException) { self::assertNotNull($notices->get($finding->getId())); }
        $ordinary=(new Finding())->setDomain($domain)->setUrl('https://semantic.test/ordinary')->setTitle('Unreviewed')->setType('other')->setSeverity('medium')->setMethod('GET')->setStatus('new');
        $this->entityManager->persist($ordinary); $this->entityManager->flush();
        unset($baseline['legacyBasis']);
        $db->insert('finding_report_revision',['finding_id'=>$ordinary->getId(),'revision'=>1,'snapshot_json'=>json_encode($baseline)]);
        unset($current['legacyBasis']);
        $db->insert('finding_report_revision',['finding_id'=>$ordinary->getId(),'revision'=>4,'snapshot_json'=>json_encode($current)]);
        self::assertNull($notices->get($ordinary->getId()), 'An ordinary unreviewed case needs no second review notice');
        self::assertSame('no_mail', json_decode($db->fetchOne('SELECT snapshot_json FROM finding_report_current WHERE finding_id=?',[$finding->getId()]),true)['gate']);

    }
}
