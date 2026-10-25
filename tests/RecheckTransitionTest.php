<?php
namespace App\Tests;

use App\Cribrum\ReportPolicy;
use App\Service\RecheckTransitionService;
use PHPUnit\Framework\TestCase;

final class RecheckTransitionTest extends TestCase
{
    private function context(): array
    {
        $hash = hash('sha256', 'https://example.test/?x=1');
        return ['findingId'=>'f','sourceInstanceId'=>'s','basisRevision'=>1,'decisionRevision'=>1,
            'poc'=>['effectiveUrl'=>'https://example.test/?x=1','revision'=>1,'sha256'=>$hash],
            'legacyBasis'=>['verified'=>true,'cohortId'=>'fixed-manifest','cohortDigest'=>str_repeat('a',64),'baselinePocRevision'=>1,'baselinePocSha256'=>$hash,'contentSha256'=>str_repeat('b',64)],
            'facts'=>[['kind'=>'behavior','schemaVersion'=>1,'factId'=>'block-1','findingId'=>'f','sourceInstanceId'=>'s','pocRevision'=>1,'pocSha256'=>$hash,'contentSha256'=>str_repeat('c',64),'code'=>'coverage_limited','mode'=>'active','capturedAt'=>'2026-10-22T01:00:00Z','attributes'=>['wafBlock'=>true,'reason'=>'waf_block','vendor'=>'example','blockEvidence'=>'access_denied','relevantRequestActuallyBlocked'=>true]]],
            'observation'=>['id'=>'capture-1','observedAt'=>'2026-10-22T01:00:00Z','metadata'=>[]]];
    }

    public function testFixedCohortBlockSurvivesNoHitAndIsAcknowledgedExactlyOnce(): void
    {
        $policy = new ReportPolicy(); $service = new RecheckTransitionService($policy);
        $context = $this->context();
        $state = $service->reconcile([], $context);
        self::assertCount(1, $state['pending']);
        $fingerprint = array_key_first($state['pending']);
        $context['workflow']['wafReview'] = $state;
        $snapshot = $policy->evaluate($context);
        self::assertSame('xss', $snapshot['lane']);
        self::assertSame('hold', $snapshot['gate']);
        self::assertContains('new_waf_block_unreviewed', $snapshot['reasonCodes']);
        $context['facts'] = [];
        $cleared = $service->reconcile($state, $context);
        self::assertSame($state['pending'], $cleared['pending']);
        $context['workflow']['wafReview'] = $cleared;
        self::assertSame('hold', $policy->evaluate($context)['gate']);
        $context['acknowledgements'] = [['fingerprint'=>'wrong','actor'=>'reviewer','acknowledgedAt'=>'now']];
        self::assertNotEmpty($service->reconcile($cleared, $context)['pending']);
        $context['acknowledgements'] = [['fingerprint'=>$fingerprint,'actor'=>'reviewer','acknowledgedAt'=>'now']];
        $acked = $service->reconcile($cleared, $context);
        self::assertSame([], $acked['pending']);
        $context['facts'] = $this->context()['facts'];
        $context['facts'][0]['factId'] = 'new-run-new-rule';
        $context['facts'][0]['capturedAt'] = '2026-10-23T01:00:00Z';
        $context['facts'][0]['contentSha256'] = str_repeat('d',64);
        self::assertSame([], $service->reconcile($acked, $context)['pending']);
        $context['workflow']['wafReview'] = $acked;
        self::assertSame('eligible', $policy->evaluate($context)['gate']);
    }

    public function testBlockWithoutAcceptedXssDoesNotCreateE7Pending(): void
    {
        $context=$this->context(); unset($context['legacyBasis']);
        self::assertSame([], (new RecheckTransitionService(new ReportPolicy()))->reconcile([], $context)['pending']);
    }

    public function testVendorChallengeShadowAndOtherPocNeverProveRelevantBlock(): void
    {
        $service = new RecheckTransitionService(new ReportPolicy());
        foreach (['vendor','challenge','shadow','other_poc'] as $variant) {
            $context = $this->context();
            if ($variant === 'vendor') $context['facts'][0]['attributes'] = ['vendor'=>'example'];
            if ($variant === 'challenge') { $context['facts'][0]['attributes']['reason']='waf_challenge'; unset($context['facts'][0]['attributes']['relevantRequestActuallyBlocked']); }
            if ($variant === 'shadow') $context['facts'][0]['mode']='shadow';
            if ($variant === 'other_poc') $context['facts'][0]['pocRevision']=2;
            self::assertSame([], $service->reconcile([], $context)['pending'], $variant);
        }
    }

    public function testReinterpretationPreservesVisitIdentityAndPackMetadataIsNotMeaning(): void
    {
        $service = new RecheckTransitionService(new ReportPolicy()); $context=$this->context();
        $context['observation']['metadata']['reclassifiedFrom']='original-capture';
        $state=$service->reconcile([], $context);
        self::assertTrue(reset($state['pending'])['interpretationOnly']);
        self::assertSame($service->semanticFingerprint(['tags'=>['coverage_limited'],'packVersion'=>'1','runId'=>'a']), $service->semanticFingerprint(['tags'=>['coverage_limited'],'packVersion'=>'2','runId'=>'b']));
    }
}
