<?php
namespace App\Tests;

use App\Service\ReviewNoticeService;
use App\Service\ReviewSchema;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class WafReviewNoticeTest extends TestCase
{
    public function testCohortWithoutManualVerdictGetsExistingNoticeProjectionReadOnly(): void
    {
        $connection=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
        try {
            $connection->executeStatement('CREATE TABLE finding (id TEXT, manual_assessment TEXT, assessed_at TEXT, status TEXT)');
            $connection->executeStatement('CREATE TABLE finding_assessment (id TEXT, finding_id TEXT, assessment TEXT, assessed_at TEXT, known_observation_ids TEXT, known_observation_states TEXT)');
            $connection->executeStatement('CREATE TABLE finding_review_acknowledgement (id TEXT)');
            $connection->executeStatement('CREATE TABLE finding_report_current (finding_id TEXT, snapshot_json TEXT)');
            $connection->insert('finding',['id'=>'cohort-member','manual_assessment'=>null,'assessed_at'=>null,'status'=>'new']);
            $snapshot=['reasonCodes'=>['new_waf_block_unreviewed'],'wafReview'=>['pending'=>['semantic-block'=>['fingerprint'=>'semantic-block','observationId'=>'capture','observedAt'=>'2026-10-22T01:00:00Z','interpretationOnly'=>true,'evidenceRefs'=>[['kind'=>'behavior_fact','id'=>'block-proof']]]]]];
            $connection->insert('finding_report_current',['finding_id'=>'cohort-member','snapshot_json'=>json_encode($snapshot)]);
            $service=new ReviewNoticeService($connection,new ReviewSchema($connection));
            $before=$connection->fetchAllAssociative('SELECT * FROM finding');
            $notice=$service->get('cohort-member');
            self::assertNotNull($notice);
            self::assertSame('legacy_basis',$notice->assessment);
            self::assertSame('waf_block',$notice->observations[0]['reason']);
            self::assertTrue($notice->observations[0]['interpretationOnly']);
            self::assertSame('block-proof',$notice->observations[0]['evidenceRefs'][0]['id']);
            self::assertSame($before,$connection->fetchAllAssociative('SELECT * FROM finding'));
            $connection->executeStatement('UPDATE finding_report_current SET snapshot_json=?',[json_encode(['reasonCodes'=>[],'wafReview'=>['pending'=>[]]])]);
            self::assertNull($service->get('cohort-member'));
        } finally { $connection->close(); }
    }
}
