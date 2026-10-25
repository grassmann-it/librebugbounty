<?php
namespace App\Tests;

use App\Service\CribrumFindingSummary;
use App\Value\CribrumBadgeCatalog;
use PHPUnit\Framework\TestCase;

final class CribrumBadgeSummaryTest extends TestCase
{
    public function testCaseRejectedObservationRemainsHistoryWithOriginalEvidence(): void
    {
        $snapshot = ['components' => [['code' => 'xss_confirmed', 'certainty' => 'confirmed']], 'reviewedFactExclusions' => [[
            'decision' => 'not_applicable', 'code' => 'sql_error_observed', 'observationId' => 'capture-original',
            'reviewedAt' => '2026-10-25T12:00:00Z', 'evidenceRefs' => [['kind' => 'behavior_fact', 'id' => 'original-sql-fact']],
        ]]];
        $result = CribrumFindingSummary::derive($snapshot, [], true);
        self::assertSame(['xss'], array_column($result['badges'], 'code'));
        self::assertSame('sql_error_observed', $result['history'][0]['code']);
        self::assertSame('rejected', $result['history'][0]['state']);
        self::assertSame('manual:fact_review', $result['history'][0]['origin']);
        self::assertSame(['original-sql-fact', 'capture-original'], array_column($result['history'][0]['evidenceRefs'], 'id'));
        self::assertSame(0, $result['additionalCount']);
    }
    public function testExplicitEmptyClaimsNeverResurrectSnapshotClaimsAndOldAutomaticSourceIsHistory(): void
    {
        $snapshot=['claims'=>[['claim'=>'sql_injection','state'=>'suspected','source'=>'classifier']]];
        self::assertSame([],CribrumFindingSummary::derive($snapshot,[])['badges']);
        self::assertSame([],CribrumFindingSummary::derive($snapshot,null,false)['badges']);
        $claim=['claim'=>'sql_injection','state'=>'suspected','source'=>'classifier','updatedAt'=>'2026-10-24','observation'=>['id'=>'capture','capturedAt'=>'2020-01-01'],'observationCurrent'=>false];
        $result=CribrumFindingSummary::derive($snapshot,[$claim],false);
        self::assertSame([],$result['badges']); self::assertSame('2020-01-01',$result['history'][0]['observedAt']);
        self::assertSame('2026-10-24',$result['history'][0]['recognizedAt']);
        self::assertSame('capture',$result['history'][0]['evidenceRefs'][0]['id']);
    }
    public function testWafCompatibilityAndNewRequiresRelevantNotice(): void
    {
        $snapshot=['wafBlockReview'=>['pending'=>['hash'=>['observedAt'=>'2020-01-01']]]];
        $result=CribrumFindingSummary::fromReadView(['reportProjection'=>$snapshot,'noticeReasons'=>['classification_changed']]);
        self::assertSame('waf_block',$result['badges'][0]['code']); self::assertTrue($result['new']);
        self::assertFalse(CribrumFindingSummary::fromReadView(['reportProjection'=>$snapshot,'noticeReasons'=>['execution_proof_missing']])['new']);
        $unknown=CribrumFindingSummary::derive([],[],true,'de',[['factId'=>'unknown','mode'=>'active','code'=>'future_code']]);
        self::assertSame('future_code',$unknown['history'][0]['code']); self::assertSame(0,$unknown['additionalCount']);
    }
    public function testSemanticAliasesDeduplicateAndDoNotPromoteSqlError(): void
    {
        $snapshot=['components'=>[['code'=>'xss_confirmed','certainty'=>'confirmed','evidenceRefs'=>[['id'=>'proof']]],['code'=>'sql_error_observation','certainty'=>'observed','evidenceRefs'=>[['id'=>'error']]]], 'tags'=>['xss_execution_confirmed','sql_error_observed','sql_error_observed','product_context'],'caseSummary'=>['hints'=>['sql_error_observed']]];
        $result=CribrumFindingSummary::derive($snapshot);
        self::assertSame(1,$result['additionalCount']);
        self::assertNotContains('sql_injection',array_column($result['badges'],'code'));
        self::assertSame('confirmed',$result['badges'][0]['state']);
        self::assertCount(1,$result['badges'][1]['evidenceRefs']);
        self::assertNull($result['changedAt']);
    }
    public function testMissingProjectionRetainsClaimWithoutQualifyingMailComponent(): void
    {
        $result=CribrumFindingSummary::derive(null,[['claim'=>'sql_injection','state'=>'suspected','source'=>'classifier','report_selected'=>false]]);
        self::assertSame('missing',$result['projectionStatus']);
        self::assertSame('unknown',$result['badges'][0]['freshness']);
        self::assertFalse($result['badges'][0]['selected']);
        self::assertSame(1,$result['additionalCount']);
    }
    public function testStaleAndShadowNeverCountCurrentAndUnknownStaysNeutral(): void
    {
        $snapshot=['tags'=>['sql_input_structure_indication','vendor_unregistered'],'factIds'=>['old']];
        $result=CribrumFindingSummary::derive($snapshot,[],false,'en',[['factId'=>'shadow','mode'=>'shadow','code'=>'rfi_suspected','capturedAt'=>'2020-01-01']]);
        self::assertSame(0,$result['additionalCount']); self::assertSame('stale',$result['projectionStatus']);
        self::assertCount(2,$result['history']); self::assertCount(1,$result['shadow']);
        self::assertSame('unknown',$result['history'][1]['state']); self::assertFalse($result['history'][1]['additional']);
        self::assertStringContainsString('Unclassified',$result['history'][1]['label']);
    }
    public function testManualConflictAndCaptureRecognitionRemainDistinct(): void
    {
        $snapshot=['decidedAt'=>'2026-10-24T12:00:00Z','observation'=>['observedAt'=>'2020-01-01T12:00:00Z'],'components'=>[['code'=>'sqli_suspected','certainty'=>'suspected','evidenceRefs'=>[['id'=>'a']]]],'tags'=>['sql_input_structure_indication']];
        $result=CribrumFindingSummary::derive($snapshot,[['claim'=>'sql_injection','state'=>'rejected','source'=>'manual']]);
        self::assertTrue($result['badges'][0]['conflict']); self::assertTrue($result['history'][0]['conflict']);
        self::assertSame('2020-01-01T12:00:00Z',$result['badges'][0]['observedAt']);
        self::assertSame('2026-10-24T12:00:00Z',$result['badges'][0]['recognizedAt']);
        self::assertSame('SQL injection',CribrumBadgeCatalog::entry('sqli_suspected','en')['label']);
    }
}
