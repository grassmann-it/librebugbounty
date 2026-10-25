<?php

namespace App\Tests\Cribrum;

use App\Cribrum\ReportPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportPolicyTest extends TestCase
{
    private function context(): array
    {
        $url = 'https://fixture.invalid/test?q=MARKer%2BKeepCase#Hash';
        return ['findingId'=>'finding-a','sourceInstanceId'=>'source-a','basisRevision'=>1,'decisionRevision'=>1,
            'poc'=>['intakeUrl'=>'https://fixture.invalid/original','effectiveUrl'=>$url,'revision'=>1,'sha256'=>hash('sha256',$url)],
            'claims'=>[],'facts'=>[],'selection'=>[],'reportSelection'=>['mode'=>'auto'],'intake'=>['payloadKind'=>'xss']];
    }
    private function proof(array $c): array
    {
        return ['proofId'=>'proof-a','sourceKind'=>'screenshot_job','sourceId'=>'job-a','capturedAt'=>'2026-10-21T10:00:00Z',
            'sourcePageUrl'=>$c['poc']['effectiveUrl'],'findingId'=>$c['findingId'],'sourceInstanceId'=>$c['sourceInstanceId'],
            'pocRevision'=>$c['poc']['revision'],'pocSha256'=>$c['poc']['sha256'],'expectedMarker'=>'MARKer','dialogText'=>'hello MARKer!',
            'dialogOrigin'=>'native','attribution'=>'matched','matchPolicy'=>'native_marker_cs_substring_v1','contentSha256'=>hash('sha256','proof')];
    }
    private function legacy(array $c): array
    {
        return ['verified'=>true,'cohortId'=>'fixed-cohort','cohortDigest'=>hash('sha256','manifest'),'baselinePocRevision'=>$c['poc']['revision'],
            'baselinePocSha256'=>$c['poc']['sha256'],'contentSha256'=>hash('sha256','member')];
    }
    private function fact(array $c, string $code, array $attributes=[]): array
    {
        return ['kind'=>'behavior','schemaVersion'=>1,'mode'=>'active','factId'=>$code,'code'=>$code,'findingId'=>$c['findingId'],
            'sourceInstanceId'=>$c['sourceInstanceId'],'pocRevision'=>$c['poc']['revision'],'pocSha256'=>$c['poc']['sha256'],
            'sourceRef'=>['kind'=>'http_observation','id'=>'obs-a'],'producer'=>'test-v1','capturedAt'=>'2026-10-22T10:00:00Z','contentSha256'=>hash('sha256',$code),'attributes'=>$attributes,
            'spans'=>array_map(static fn(string $role, int $i): array => \App\Cribrum\EvidenceSignal::span('diagnostic',0,10)+['role'=>$role,'id'=>'s'.$i], ['diagnostic_block','error','input','query'],[0,1,2,3]),
            'relations'=>[['type'=>'input_at_sql_structure_error','from'=>'s2','to'=>'s3']]];
    }
    private function cover(array $c): array
    {
        return ['profile'=>'behavior-triage-v1','version'=>1,'mode'=>'active','requiredFamilies'=>ReportPolicy::COVERAGE_FAMILIES,
            'evaluatedFamilies'=>ReportPolicy::COVERAGE_FAMILIES,'status'=>'complete','artifactSha256'=>hash('sha256','body'),
            'resultVersion'=>'candidate-v1','pocRevision'=>$c['poc']['revision'],'pocSha256'=>$c['poc']['sha256']];
    }
    public function testLegacyWithoutBodyAndUnknownCoverageRetainsMailBasis(): void
    {
        $c=$this->context(); $c['legacyBasis']=$this->legacy($c); $c['claims']=[['claim'=>'xss','state'=>'stale','source'=>'classifier']];
        $r=(new ReportPolicy)->evaluate($c);
        self::assertSame('eligible',$r['gate']); self::assertSame('xss',$r['lane']); self::assertSame('unknown',$r['caseSummary']['purity']);
        self::assertSame($c['poc'],$r['poc']); self::assertSame('behavior-report-v2',$r['policyVersion']);
    }
    #[DataProvider('invalidProofs')]
    public function testProofGates(array $overrides): void
    {
        $c=$this->context(); $c['explicitXssCandidate']=true; $c['workerProof']=array_replace($this->proof($c),$overrides);
        $r=(new ReportPolicy)->evaluate($c); self::assertSame('hold',$r['gate']); self::assertContains('worker_proof_unattributed',$r['reasonCodes']);
    }
    public static function invalidProofs(): iterable
    {
        yield 'hook' => [['dialogOrigin'=>'hook']]; yield 'mismatch' => [['dialogText'=>'nothing']];
        yield 'old PoC' => [['pocRevision'=>2]]; yield 'uncertain' => [['attribution'=>'uncertain']];
        yield 'short marker' => [['expectedMarker'=>'a','dialogText'=>'a']]; yield 'trivial' => [['expectedMarker'=>'aaaa','dialogText'=>'aaaa']];
        yield 'boundary' => [['expectedMarker'=>'OPENBUG','dialogText'=>'OPEN','nativeDialogEvents'=>['OPEN','BUG']]];
        yield 'no source' => [['sourceId'=>'']]; yield 'unbound source' => [['sourceInstanceId'=>'other']];
    }
    public function testMigrationClaimAndPayloadCannotCreateLegacyProof(): void
    {
        $c=$this->context(); $c['claims']=[['claim'=>'xss','state'=>'observed','source'=>'migration']]; $c['explicitXssCandidate']=true;
        self::assertContains('execution_proof_missing',(new ReportPolicy)->evaluate($c)['reasonCodes']);
    }
    public function testNativeProofWithoutHttpBodyIsEligible(): void
    {
        $c=$this->context(); $c['workerProof']=$this->proof($c);
        $r=(new ReportPolicy)->evaluate($c); self::assertSame('eligible',$r['gate']); self::assertSame('xss',$r['lane']);
    }
    public function testQualifiedSqlIsSuspectedAndFormsOneCombinedComposition(): void
    {
        $c=$this->context(); $c['workerProof']=$this->proof($c);
        $c['facts']=[$this->fact($c,'sql_input_structure_indication',['assessment'=>'suspected_only','blockComplete'=>true]),$this->fact($c,'sql_error_observed'),$this->fact($c,'internal_information_exposed',['detailKind'=>'query'])];
        $r=(new ReportPolicy)->evaluate($c); self::assertSame('xss_with_sqli_suspected',$r['lane']); self::assertSame('eligible',$r['gate']);
        self::assertTrue($r['urgencyAllowed']); self::assertSame('suspected',array_column($r['components'],'certainty','code')['sqli_suspected']);
        self::assertSame('mixed',$r['caseSummary']['purity']);
    }
    public function testSqlErrorAloneIsNotSqliOrStandaloneMail(): void
    {
        $c=$this->context(); $c['facts']=[$this->fact($c,'sql_error_observed'),$this->fact($c,'input_in_sql_error')];
        $r=(new ReportPolicy)->evaluate($c); self::assertSame('no_mail',$r['gate']); self::assertSame([],$r['components']); self::assertFalse($r['urgencyAllowed']);
        self::assertContains('xss_payload_in_sql_error',$r['tags']);
        $c['workerProof']=$this->proof($c); $r=(new ReportPolicy)->evaluate($c); self::assertSame('xss_with_sql_error',$r['lane']); self::assertFalse($r['urgencyAllowed']);
    }
    public function testConcreteDisclosureIndependentButExplicitXssIntentionHolds(): void
    {
        $c=$this->context(); $c['facts']=[$this->fact($c,'internal_information_exposed',['detailKind'=>'internal_path'])];
        self::assertSame('information_disclosure',(new ReportPolicy)->evaluate($c)['lane']);
        $c['explicitXssCandidate']=true; self::assertSame('hold',(new ReportPolicy)->evaluate($c)['gate']);
    }
    public function testGenericErrorsAndShadowNeverCreateDisclosure(): void
    {
        $c=$this->context(); $c['facts']=[$this->fact($c,'error_response')];
        $f=$this->fact($c,'internal_information_exposed',['detailKind'=>'query']); $f['mode']='shadow'; $c['facts'][]=$f;
        self::assertSame('no_mail',(new ReportPolicy)->evaluate($c)['gate']);
    }
    public function testSelectionsRespectE1AndWholeReportExclusionPriority(): void
    {
        $c=$this->context(); $c['workerProof']=$this->proof($c); $c['facts']=[$this->fact($c,'sql_input_structure_indication',['assessment'=>'suspected_only'])];
        $c['selection']=[['component'=>'sqli_suspected','mode'=>'exclude']];
        self::assertContains('combined_component_excluded',(new ReportPolicy)->evaluate($c)['reasonCodes']);
        $c['reportSelection']=['mode'=>'exclude']; self::assertSame('no_mail',(new ReportPolicy)->evaluate($c)['gate']);
    }
    public function testManualRejectionConflictsButAutomaticSelectionFalseDoesNot(): void
    {
        $c=$this->context(); $c['workerProof']=$this->proof($c); $c['claims']=[['claim'=>'xss','state'=>'stale','source'=>'classifier','reportSelected'=>false]];
        self::assertSame('eligible',(new ReportPolicy)->evaluate($c)['gate']);
        $c['claims']=[['claim'=>'xss','state'=>'rejected','source'=>'manual']]; self::assertContains('manual_conflict',(new ReportPolicy)->evaluate($c)['reasonCodes']);
    }
    public function testPureNeedsCurrentFullActiveCoverage(): void
    {
        $c=$this->context(); $c['workerProof']=$this->proof($c); $c['coverage']=$this->cover($c); $c['artifactSha256']=$c['coverage']['artifactSha256']; $c['resultVersion']='candidate-v1';
        self::assertSame('pure_xss',(new ReportPolicy)->evaluate($c)['caseSummary']['kind']);
        foreach (['artifactSha256','resultVersion'] as $key) { $bad=$c; $bad[$key]='old'; self::assertSame('unknown',(new ReportPolicy)->evaluate($bad)['caseSummary']['purity']); }
        $c['coverage']['evaluatedFamilies']=['sql']; self::assertSame('partial',(new ReportPolicy)->evaluate($c)['coverage']['status']);
    }
    public function testHintsAndUnknownFactsNeverPassStrictPureFilter(): void
    {
        $c=$this->context(); $c['workerProof']=$this->proof($c); $c['coverage']=$this->cover($c); $c['artifactSha256']=$c['coverage']['artifactSha256']; $c['resultVersion']='candidate-v1';
        $c['facts']=[$this->fact($c,'file_include_diagnostic')]; self::assertSame('mixed',(new ReportPolicy)->evaluate($c)['caseSummary']['purity']);
        $c['facts']=[$this->fact($c,'future_unknown')]; self::assertSame('unknown',(new ReportPolicy)->evaluate($c)['caseSummary']['purity']);
    }
    public function testIncludeEvidenceRemainsSuspectedWithoutInventingSuccessfulRead(): void
    {
        $c=$this->context(); $c['facts']=[$this->fact($c,'lfi_suspected',['certainty'=>'suspected','inputMapping'=>['parameter'=>'path'],'fileReadProven'=>false,'codeExecutionProven'=>false])];
        $r=(new ReportPolicy)->evaluate($c); self::assertSame('eligible',$r['gate']); self::assertSame('lfi_suspected',$r['lane']); self::assertSame('suspected',$r['components'][0]['certainty']); self::assertFalse($r['urgencyAllowed']);
    }
    public function testE7HoldsLegacyUntilExactExplicitAcknowledgementAndKeepsOpenAcrossSuccess(): void
    {
        $p=new ReportPolicy; $c=$this->context(); $c['legacyBasis']=$this->legacy($c); $c['facts']=[$this->fact($c,'coverage_limited',['reason'=>'waf_block','vendor'=>'cloudflare','wafBlock'=>true,'blockEvidence'=>'explicit_response_body'])];
        $r=$p->evaluate($c); self::assertSame('hold',$r['gate']); self::assertSame('xss',$r['lane']); self::assertContains('new_waf_block_unreviewed',$r['reasonCodes']);
        $fp=$r['wafBlockFingerprint']; $c['workflow']['wafReview']['pending']=[$fp=>[]]; $c['facts']=[];
        self::assertSame('hold',$p->evaluate($c)['gate']);
        $c['acknowledgements']=[['fingerprint'=>'other','actor'=>'tom','acknowledgedAt'=>'now']]; self::assertSame('hold',$p->evaluate($c)['gate']);
        $c['acknowledgements'][0]['fingerprint']=$fp; self::assertSame('eligible',$p->evaluate($c)['gate']);
    }
    public function testVendorOrUnprovenChallengeDoesNotHoldAndFingerprintIgnoresRunIdentity(): void
    {
        $p=new ReportPolicy; $c=$this->context(); $c['legacyBasis']=$this->legacy($c);
        $f=$this->fact($c,'coverage_limited',['reason'=>'waf_challenge','wafBlock'=>true,'blockEvidence'=>'explicit_response_body']); $c['facts']=[$f];
        self::assertSame('eligible',$p->evaluate($c)['gate']);
        $c['facts'][0]['attributes']['reason']='waf_block'; $one=$p->wafBlockFingerprint($c);
        $c['facts'][0]['factId']='different'; $c['facts'][0]['capturedAt']='tomorrow'; $c['facts'][0]['contentSha256']=hash('sha256','other');
        self::assertSame($one,$p->wafBlockFingerprint($c));
    }
    public function testRealDetectorOutputsQualifyThroughFindingAdapter(): void
    {
        $c=$this->context(); $c['workerProof']=$this->proof($c);
        $bodies = [
            [new \App\Cribrum\Detector\SqlTriageDetector, "<pre>Syntax error (missing operator) in query expression 'name = MARKer+KeepCase'.</pre>", 'sqli_suspected'],
            [new \App\Cribrum\Detector\FileTriageDetector, 'Warning: include(MARKer+KeepCase): Failed to open stream: No such file or directory', 'lfi_suspected'],
            [new \App\Cribrum\Detector\ContextTriageDetector, 'Fatal error: Uncaught RuntimeException in /var/www/app/index.php on line 27', 'information_disclosure'],
        ];
        foreach ($bodies as [$detector,$body,$expected]) {
            $input=new \App\Cribrum\ObservationInput($c['poc']['effectiveUrl'],$body,null,'text/html',500,null,null,null,false,null);
            $c['facts']=[];
            foreach ($detector->detect($input,'active') as $signal) {
                $fact=$this->fact($c,$signal['code'],$signal['attributes']);
                $fact['spans']=$signal['spans']; $fact['relations']=$signal['relations'];
                $c['facts'][]=$fact;
            }
            $r=(new ReportPolicy)->evaluate($c);
            self::assertSame('eligible',$r['gate']);
            self::assertContains($expected,array_column($r['components'],'code'));
        }
    }
    public function testRevisionZeroIsValidForFixedCohortAndOpenOtherClaimsExcludePurity(): void
    {
        $c=$this->context(); $c['poc']['revision']=0; $c['legacyBasis']=$this->legacy($c);
        $c['coverage']=$this->cover($c); $c['artifactSha256']=$c['coverage']['artifactSha256']; $c['resultVersion']='candidate-v1';
        self::assertSame('pure_xss',(new ReportPolicy)->evaluate($c)['caseSummary']['kind']);
        $c['claims']=[['claim'=>'sql_injection','state'=>'suspected','source'=>'classifier']];
        $r=(new ReportPolicy)->evaluate($c); self::assertSame('eligible',$r['gate']); self::assertSame('mixed',$r['caseSummary']['purity']);
        self::assertFalse($r['urgencyAllowed']);
    }
    #[DataProvider('reflectionContexts')]
    public function testNativeProofResolvesActualReflectionContextOnly(string $body): void
    {
        $c=$this->context();
        $input=new \App\Cribrum\ObservationInput($c['poc']['effectiveUrl'],$body,null,'text/html',200,null,'MARKer+KeepCase',null,false,null);
        foreach ((new \App\Cribrum\Detector\ReflectionTriageDetector)->detect($input,'active') as $signal) {
            $fact=$this->fact($c,$signal['code'],$signal['attributes']); $fact['spans']=$signal['spans']; $fact['relations']=$signal['relations']; $c['facts'][]=$fact;
        }
        self::assertNotEmpty($c['facts']);
        self::assertContains('ambiguous_input_source',$c['facts'][0]['attributes']['reviewReasons']);
        $c['coverage']=$this->cover($c); $c['artifactSha256']=$c['coverage']['artifactSha256']; $c['resultVersion']='candidate-v1';
        $p=new ReportPolicy;
        $without=$p->evaluate($c);
        self::assertSame('no_mail',$without['gate']); self::assertContains('context_required',$without['caseSummary']['hints']);
        $c['explicitXssCandidate']=true;
        self::assertContains('execution_proof_missing',$p->evaluate($c)['reasonCodes']);
        $c['workerProof']=$this->proof($c);
        $with=$p->evaluate($c); self::assertSame('eligible',$with['gate']); self::assertSame('pure_xss',$with['caseSummary']['kind']);
        self::assertNotContains('context_required',$with['caseSummary']['hints']); self::assertContains('input_reflected',$with['tags']);
        $c['workerProof']['attribution']='uncertain';
        $uncertain=$p->evaluate($c); self::assertSame('hold',$uncertain['gate']); self::assertContains('worker_proof_unattributed',$uncertain['reasonCodes']);
        self::assertContains('context_required',$uncertain['caseSummary']['hints']);
        $c['workerProof']=$this->proof($c); $c['facts'][]=$this->fact($c,'file_include_diagnostic',['contextRequired'=>true]);
        self::assertSame('mixed',$p->evaluate($c)['caseSummary']['purity']);
    }
    public static function reflectionContexts(): iterable
    {
        yield 'script' => ['<script>var value="MARKer+KeepCase";</script>'];
        yield 'attribute' => ['<input value="MARKer+KeepCase">'];
    }
    public function testLegacyOrUnknownReflectionDoubtIsNotAutomaticallyResolved(): void
    {
        $c=$this->context(); $c['legacyBasis']=$this->legacy($c);
        $c['coverage']=$this->cover($c); $c['artifactSha256']=$c['coverage']['artifactSha256']; $c['resultVersion']='candidate-v1';
        $c['facts']=[$this->fact($c,'input_reflected',['family'=>'reflection','contextRequired'=>true,'reviewReasons'=>['attribute_value_requires_browser_context']])];
        self::assertSame('mixed',(new ReportPolicy)->evaluate($c)['caseSummary']['purity']);
        $c['workerProof']=$this->proof($c); $c['facts'][0]['attributes']['reviewReasons'][]='unknown_new_attribution_doubt';
        self::assertNotSame('pure_xss',(new ReportPolicy)->evaluate($c)['caseSummary']['kind']);
    }
    public function testObservationMetadataIsExplicitlyAllowlisted(): void
    {
        $c=$this->context(); $c['observation']=['id'=>'obs-2','observedAt'=>'2026-10-22T10:00:00Z','raw'=>'PRIVATE BODY',
            'metadata'=>['reclassifiedFrom'=>'obs-1','headers'=>['secret'=>'private'],'payload'=>'private']];
        $c['interpretationOnly']=true;
        $r=(new ReportPolicy)->evaluate($c);
        self::assertSame(['id'=>'obs-2','observedAt'=>'2026-10-22T10:00:00Z','metadata'=>['reclassifiedFrom'=>'obs-1']],$r['observation']);
        self::assertTrue($r['interpretationOnly']); self::assertStringNotContainsString('PRIVATE BODY',json_encode($r));
        self::assertFalse((new ReportPolicy)->evaluate($this->context())['interpretationOnly']);
    }
    public function testWafExecutionEquivalenceUsesStableCaptureLineageRatherThanTimestamps(): void
    {
        $c=$this->context(); $c['workerProof']=$this->proof($c);
        $block=$this->fact($c,'coverage_limited',['reason'=>'waf_block','vendor'=>'cloudflare','wafBlock'=>true,'blockEvidence'=>'explicit_response_body']);
        $block['sourceRef']['captureRef']=['kind'=>'screenshot_job','id'=>'job-a'];
        // DB and observation timestamps may represent the same capture differently.
        $block['capturedAt']='2026-10-21T10:00:00+00:00';
        $c['facts']=[$block]; $p=new ReportPolicy;
        self::assertSame('eligible',$p->evaluate($c)['gate']);
        self::assertNull($p->wafBlockFingerprint($c));
        // Matching clocks cannot equate two different captures.
        $c['facts'][0]['capturedAt']=$c['workerProof']['capturedAt'];
        $c['facts'][0]['sourceRef']['captureRef']['id']='job-b';
        self::assertSame('hold',$p->evaluate($c)['gate']);
        self::assertContains('new_waf_block_unreviewed',$p->evaluate($c)['reasonCodes']);
        // Explicit evidence that the relevant request was blocked remains a contradiction.
        $c['facts'][0]['sourceRef']['captureRef']['id']='job-a';
        $c['facts'][0]['attributes']['relevantRequestActuallyBlocked']=true;
        self::assertSame('hold',$p->evaluate($c)['gate']);
    }
    public function testRequestContextStillMustMatchWorkerProofForCaptureException(): void
    {
        $c=$this->context(); $c['workerProof']=$this->proof($c); $c['requestContextSha256']=hash('sha256','current request');
        $c['explicitXssCandidate']=true; $c['workerProof']['requestContextSha256']=hash('sha256','old request');
        self::assertContains('worker_proof_unattributed',(new ReportPolicy)->evaluate($c)['reasonCodes']);
        $c['workerProof']['requestContextSha256']=$c['requestContextSha256'];
        self::assertSame('eligible',(new ReportPolicy)->evaluate($c)['gate']);
    }
    public function testPolicyIsDeterministicAndDoesNotMutateContext(): void
    {
        $c=$this->context(); $c['workerProof']=$this->proof($c); $before=$c; $p=new ReportPolicy;
        self::assertSame($p->evaluate($c),$p->evaluate($c)); self::assertSame($before,$c);
    }
}
