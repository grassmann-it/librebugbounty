<?php

declare(strict_types=1);
namespace App\Service;

use App\Cribrum\EvidenceSignal;
use App\Cribrum\ReportPolicy;
use App\Entity\Finding;
use App\Entity\HttpObservation;
use Doctrine\DBAL\Connection;

/** One byte-preserving, transactionally versioned handoff for UI and report-gen. */
final class ReportProjectionService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ReportPolicy $policy,
        private readonly IntakeProfileResolver $intake,
        private readonly LegacyCohortService $legacy,
        private readonly WorkerProofResolver $proofs,
        private readonly ?CribrumObservationStore $observations = null,
    ) {}

    public function refresh(Finding $finding, ?HttpObservation $observation = null): array
    {
        if (!$this->available()) return [];
        try { return $this->connection->transactional(function () use ($finding, $observation): array {
            $id = $finding->getId();
            $this->ensureContext($id);
            $this->assertFreshFinding($finding);
            $source = $this->connection->fetchAssociative('SELECT * FROM report_source_contract WHERE id=1');
            if (!$source) throw new \RuntimeException('Report source contract missing.');
            $row = $this->connection->fetchAssociative('SELECT * FROM finding_report_context WHERE finding_id=?', [$id]);
            $poc = ['intakeUrl' => $finding->getUrl(), 'effectiveUrl' => $finding->getPocUrl(), 'revision' => $finding->getPocRevision(), 'sha256' => hash('sha256', $finding->getPocUrl())];
            $facts = $this->observationFacts($finding, $observation, $source['source_instance_id'], $poc);
            // A body-less refresh keeps the selected prior facts, rather than interpreting absence as a no-hit.
            if ($observation === null) {
                $old = $this->connection->fetchOne('SELECT snapshot_json FROM finding_report_current WHERE finding_id=?', [$id]);
                $prior = $old ? self::decode($old) : [];
                $retainedIds = $prior['factIds'] ?? [];
                foreach ($prior['reviewedFactExclusions'] ?? [] as $review) {
                    foreach ($review['factRefs'] ?? [] as $ref) if (is_string($ref['id'] ?? null)) $retainedIds[] = $ref['id'];
                }
                foreach (array_unique($retainedIds) as $factId) {
                    $stored = $this->connection->fetchAssociative('SELECT * FROM finding_behavior_fact WHERE fact_id=? AND finding_id=? AND poc_revision=? AND poc_sha256=?', [$factId, $id, $poc['revision'], $poc['sha256']]);
                    if ($stored && $stored['code'] !== 'execution_observed' && hash_equals($stored['fact_sha256'], hash('sha256', $stored['fact_json']))) {
                        $fact = self::decode($stored['fact_json']);
                        if ($this->observations === null) throw new \RuntimeException('Authenticated fact storage is unavailable.');
                        $this->observations->authenticateFact($fact,$finding);
                        $fact['contentSha256'] = $stored['fact_sha256']; $facts[] = $fact;
                    }
                }
            }
            $proof = $this->proofs->resolve($finding, $source['source_instance_id']);
            if ($proof !== null && ($proof['verified'] ?? false) === true) {
                $fact = ['kind' => 'behavior', 'schemaVersion' => 1, 'factId' => hash('sha256', self::json($proof)), 'code' => 'execution_observed', 'findingId' => $id, 'sourceInstanceId' => $source['source_instance_id'], 'pocRevision' => $poc['revision'], 'pocSha256' => $poc['sha256'], 'sourceRef' => ['kind' => $proof['sourceKind'], 'id' => $proof['sourceId']], 'capturedAt' => $proof['capturedAt'], 'producer' => 'worker-proof-v1', 'mode' => 'active', 'spans' => [], 'attributes' => ['workerProof' => $proof], 'relatedFactIds' => []];
                $fact = $this->storeFact($fact); $facts[$fact['factId']] = $fact;
                $proof['proofId'] = $fact['factId']; $proof['contentSha256'] = $fact['contentSha256'];
            }
            $facts = array_values(array_column($facts, null, 'factId'));
            usort($facts, static fn(array $a,array $b): int => strcmp($a['factId'],$b['factId']));
            $claims = array_map(static fn(array $c): array => ['claim' => $c['claim'], 'state' => $c['state'], 'source' => $c['source'], 'revision' => (int)$c['revision'], 'reportSelected' => (bool)$c['report_selected'], 'observationId' => $c['observation_id']], $this->connection->fetchAllAssociative('SELECT * FROM finding_claim WHERE finding_id=? ORDER BY claim', [$id]));
            $context = ['schemaVersion'=>(int)$source['schema_version'],'policyVersion'=>$source['policy_version'],'findingId' => $id, 'sourceInstanceId' => $source['source_instance_id'], 'poc' => $poc, 'intake' => $this->intake->resolve($finding, self::decode($row['intake_json'])), 'legacyBasis' => $this->legacy->resolve($finding,$source['source_instance_id']), 'claims' => $claims, 'facts' => $facts, 'workerProof' => $proof ?? [], 'coverage' => $observation?->getClassification()['coverage'] ?? self::decode($row['coverage_json']), 'selection' => self::decode($row['selection_json']), 'reportSelection' => self::decode($row['report_selection_json']), 'acknowledgements' => self::decode($row['acknowledgements_json']), 'workflow' => ['status' => $finding->getStatus(), 'manualAssessment' => $finding->getManualAssessment(), 'discarded' => $finding->isDiscarded(), 'wafReview' => self::decode($row['waf_review_json'])]];
            [$coverage,$rawHash,$version]=$this->authenticatedCoverage($finding,$observation,self::decode($row['coverage_json']),$source['source_instance_id']);
            $context['coverage']=$coverage; $context['artifactSha256']=$rawHash; $context['resultVersion']=$version;
            $context['expectedMarker']=$finding->getExpectedEvidence();
            $context['requestContextSha256']=WorkerProofResolver::requestContextSha256($finding);
            $context['interpretationOnly'] = $observation !== null && isset($observation->getMetadata()['reclassifiedFrom']);
            $context['observation'] = $observation === null ? [] : ['id'=>$observation->getId(),'metadata'=>$observation->getMetadata(),'observedAt'=>$observation->getMetadata()['observedAt'] ?? $observation->getCreatedAt()->format(DATE_ATOM)];
            $wafReview=(new RecheckTransitionService($this->policy))->reconcile(self::decode($row['waf_review_json']),$context);
            $context['workflow']['wafReview']=$wafReview;
            // Payload and expected marker changes invalidate even when the public PoC revision is unchanged.
            $basisContext=$context; unset($basisContext['observation']);
            $inputHash = hash('sha256', self::json([$basisContext, $finding->getPayload(), $finding->getExpectedEvidence()]));
            $basis = (int)$row['basis_revision'];
            if ($row['input_sha256'] !== null && !hash_equals($row['input_sha256'], $inputHash)) ++$basis;
            $this->connection->update('finding_report_context', ['basis_revision' => $basis, 'input_sha256' => $inputHash, 'intake_json' => self::json($context['intake']), 'coverage_json' => self::json($context['coverage']), 'waf_review_json' => self::json($wafReview)], ['finding_id' => $id]);
            $current = $this->connection->fetchAssociative('SELECT * FROM finding_report_current WHERE finding_id=?', [$id]);
            if ($current && (int)$current['is_current'] === 1 && (int)$current['basis_revision'] === $basis) return $this->validatedSnapshot($current);
            $revision = (int)($current['revision'] ?? 0) + 1;
            $context['basisRevision'] = $basis; $context['decisionRevision'] = $revision;
            $snapshot = $this->policy->evaluate($context);
            $snapshot['wafReview']=$wafReview;
            $snapshot['wafBlockFingerprints']=array_keys($wafReview['pending']);
            $snapshot['decidedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
            $json = self::json($snapshot);
            if ((int)$this->connection->fetchOne('SELECT basis_revision FROM finding_report_context WHERE finding_id=?',[$id]) !== $basis) throw new \RuntimeException('Concurrent report basis change; retry projection.');
            $summary = $snapshot['caseSummary'];
            $this->connection->insert('finding_report_revision', ['finding_id'=>$id,'revision'=>$revision,'basis_revision'=>$basis,'schema_version'=>$snapshot['schemaVersion'],'policy_version'=>$snapshot['policyVersion'],'source_instance_id'=>$source['source_instance_id'],'lane'=>$snapshot['lane'],'gate'=>$snapshot['gate'],'snapshot_json'=>$json,'snapshot_sha256'=>hash('sha256',$json),'created_at'=>$snapshot['decidedAt'],'case_kind'=>$summary['kind'],'purity'=>$summary['purity'],'tags_json'=>self::json($snapshot['tags']),'case_summary_json'=>self::json($summary),'coverage_json'=>self::json($snapshot['coverage'])]);
            return $snapshot;
        }); } catch (\Throwable $error) {
            $this->invalidate($finding,'projection_failed');
            throw $error;
        }
    }

    public function setIntakeProfile(Finding $finding, array $profile): array
    {
        if (!$this->available()) return [];
        $this->connection->transactional(function () use ($finding,$profile): void {
            $this->ensureContext($finding->getId());
            $json=self::json($this->intake->resolve($finding,$profile));
            $this->connection->executeStatement('UPDATE finding_report_context SET intake_json=?,basis_revision=basis_revision+1 WHERE finding_id=? AND intake_json<>?',[$json,$finding->getId(),$json]);
        });
        return $this->refresh($finding);
    }

    public function available(): bool
    {
        return (bool)$this->connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='finding_report_context'");
    }

    /** Authenticate and persist current observation facts for a source-bound Review decision. */
    public function materializeObservationFacts(Finding $finding, HttpObservation $observation): array
    {
        $this->assertFreshFinding($finding);
        if ($this->observations === null || $this->observations->coverageSource($finding, $this->observations->coverageReference($observation)) === null) {
            throw new \UnexpectedValueException('Current authenticated observation is unavailable for Review.');
        }
        $source = $this->connection->fetchAssociative('SELECT * FROM report_source_contract WHERE id=1');
        if (!$source) throw new \RuntimeException('Report source contract missing.');
        $poc = ['intakeUrl' => $finding->getUrl(), 'effectiveUrl' => $finding->getPocUrl(),
            'revision' => $finding->getPocRevision(), 'sha256' => hash('sha256', $finding->getPocUrl())];
        return $this->observationFacts($finding, $observation, $source['source_instance_id'], $poc);
    }

    /** Immutable interpretation history; capture times stay in the referenced facts. */
    public function history(Finding $finding, int $limit = 10): array
    {
        if (!$this->available()) return [];
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM finding_report_revision WHERE finding_id=? ORDER BY revision DESC LIMIT '.max(1,min(100,$limit)), [$finding->getId()]);
        return array_map(fn(array $row): array => $this->validatedSnapshot($row), $rows);
    }

    public function workerProofCandidates(Finding $finding): array
    {
        if (!$this->available()) return [];
        $source = (string) $this->connection->fetchOne('SELECT source_instance_id FROM report_source_contract WHERE id=1');
        return array_values(array_filter($this->proofs->candidates($finding, $source), static fn(array $p): bool => !$p['verified'] && $p['attribution'] === 'matched' && in_array($p['dialogOrigin'], ['native', 'hook'], true) && $p['dialogText'] !== ''));
    }

    /** Explicit one-time attribution of an existing worker event, fenced to current basis. */
    public function reviewWorkerProof(Finding $finding, string $proofId, int $expectedBasis, string $actor, string $reason): array
    {
        if (trim($actor) === '' || trim($reason) === '') throw new \InvalidArgumentException('Actor and review reason required.');
        return $this->connection->transactional(function () use ($finding,$proofId,$expectedBasis,$actor,$reason): array {
            $this->connection->executeStatement('UPDATE finding_report_context SET basis_revision=basis_revision WHERE finding_id=?', [$finding->getId()]);
            $row = $this->connection->fetchAssociative('SELECT * FROM finding_report_context WHERE finding_id=?', [$finding->getId()]);
            if (!$row || (int)$row['basis_revision'] !== $expectedBasis) throw new \UnexpectedValueException('Report basis changed; reload the evidence.');
            $matches = array_values(array_filter($this->workerProofCandidates($finding), static fn(array $p): bool => $p['proofId'] === $proofId));
            if (count($matches) !== 1) throw new \InvalidArgumentException('No attributable worker event for this decision.');
            $proof = $matches[0];
            $acks = self::decode($row['acknowledgements_json']);
            $acks[] = ['kind'=>'worker_proof','proofId'=>$proofId,'contentSha256'=>$proof['contentSha256'],'actor'=>$actor,'reason'=>$reason,'explicit'=>true,
                'sourceRef'=>['kind'=>$proof['sourceKind'],'id'=>$proof['sourceId']],'acknowledgedAt'=>(new \DateTimeImmutable())->format(DATE_ATOM)];
            $this->connection->update('finding_report_context', ['acknowledgements_json'=>self::json($acks),'basis_revision'=>$expectedBasis+1], ['finding_id'=>$finding->getId()]);
            return $this->refresh($finding);
        });
    }

    /** Null means no stored projection; false preserves a historical, stale decision. */
    public function isCurrent(Finding|string $finding): ?bool
    {
        if (!$this->available()) return null;
        $value = $this->connection->fetchOne('SELECT is_current FROM finding_report_current WHERE finding_id=?', [$finding instanceof Finding ? $finding->getId() : $finding]);
        return $value === false ? null : (bool) $value;
    }

    public function current(Finding|string $finding): ?array
    {
        if (!$this->available()) return null;
        $row = $this->connection->fetchAssociative('SELECT * FROM finding_report_current WHERE finding_id=?', [$finding instanceof Finding ? $finding->getId() : $finding]);
        if (!$row) return null;
        $snapshot = $this->validatedSnapshot($row);
        if (!(bool)$row['is_current']) { $snapshot['gate']='hold'; $source=$this->connection->fetchAssociative('SELECT * FROM report_source_contract WHERE id=1'); $reason=$row['source_instance_id']!==($source['source_instance_id'] ?? null)?'source_mismatch':($row['policy_version']!==($source['policy_version'] ?? null)?'policy_changed':'basis_changed'); $snapshot['reasonCodes']=array_values(array_unique([...($snapshot['reasonCodes'] ?? []),$reason])); $snapshot['reviewRequired']=true; $snapshot['caseSummary']['kind']='unknown'; $snapshot['caseSummary']['purity']='unknown'; $snapshot['coverage']['status']='unknown'; }
        return $snapshot;
    }

    /** Selected, hash-checked bounded evidence for local detail review. */
    public function evidence(Finding|string $finding): array
    {
        $snapshot=$this->current($finding);
        if ($snapshot===null) return [];
        $evidence=[]; $refs=[];
        foreach ($snapshot['factIds'] ?? [] as $id) if (is_string($id)) $refs[$id] = null;
        foreach ($snapshot['reviewedFactExclusions'] ?? [] as $review) {
            foreach ($review['factRefs'] ?? [] as $ref) {
                if (!is_string($ref['id'] ?? null) || !is_string($ref['contentSha256'] ?? null)) continue;
                $refs[$ref['id']] = $ref['contentSha256'];
            }
        }
        foreach ($refs as $id => $expectedHash) {
            $row=$this->connection->fetchAssociative('SELECT * FROM finding_behavior_fact WHERE fact_id=? AND finding_id=?',[$id,$snapshot['findingId']]);
            if (!$row || !hash_equals($row['fact_sha256'],hash('sha256',$row['fact_json']))) throw new \RuntimeException('Report evidence hash mismatch.');
            if ($expectedHash !== null && !hash_equals($expectedHash, $row['fact_sha256'])) throw new \RuntimeException('Reviewed report evidence hash mismatch.');
            $fact=self::decode($row['fact_json']); $fact['contentSha256']=$row['fact_sha256']; $evidence[]=$fact;
        }
        return $evidence;
    }

    public function invalidate(Finding|string $finding, string $reason): void
    {
        if (!$this->available()) return;
        $id = $finding instanceof Finding ? $finding->getId() : $finding;
        $this->connection->transactional(function () use ($id,$reason): void { $this->ensureContext($id); $this->connection->executeStatement('UPDATE finding_report_context SET basis_revision=basis_revision+1,invalidation_reason=? WHERE finding_id=?',[$reason,$id]); });
    }

    public function acknowledgeWafBlock(Finding $finding, string $fingerprint, string $actor): array
    {
        if (trim($actor)==='' || trim($fingerprint)==='') throw new \InvalidArgumentException('Explicit actor and fingerprint required.');
        $this->connection->transactional(function () use ($finding,$fingerprint,$actor): void {
            $snapshot=$this->current($finding);
            $fingerprints=$snapshot['wafBlockFingerprints'] ?? [$snapshot['wafBlockFingerprint'] ?? null];
            if (!$snapshot || !in_array('new_waf_block_unreviewed',$snapshot['reasonCodes'],true) || !in_array($fingerprint,$fingerprints,true)) throw new \InvalidArgumentException('WAF acknowledgement context is stale.');
            $row=$this->connection->fetchAssociative('SELECT * FROM finding_report_context WHERE finding_id=?',[$finding->getId()]);
            $acks=self::decode($row['acknowledgements_json']); $acks[]=['fingerprint'=>$fingerprint,'actor'=>$actor,'acknowledgedAt'=>(new \DateTimeImmutable())->format(DATE_ATOM)];
            $this->connection->update('finding_report_context',['acknowledgements_json'=>self::json($acks),'basis_revision'=>(int)$row['basis_revision']+1],['finding_id'=>$finding->getId()]);
        });
        return $this->refresh($finding);
    }

    /** Never publish a fresh decision from an ORM instance older than its locked source row. */
    private function assertFreshFinding(Finding $finding): void
    {
        $row=$this->connection->fetchAssociative('SELECT url,poc_url,poc_revision,method,request_params,payload,expected_evidence,type,status,manual_assessment,discard_reason,review_state FROM finding WHERE id=?',[$finding->getId()]);
        if (!$row) throw new \UnexpectedValueException('Finding source is unavailable.');
        $expected=['url'=>$finding->getUrl(),'poc_url'=>$finding->getPocUrl(),'poc_revision'=>$finding->getPocRevision(),
            'method'=>$finding->getMethod(),'request_params'=>$finding->getRequestParams(),'payload'=>$finding->getPayload(),
            'expected_evidence'=>$finding->getExpectedEvidence(),'type'=>$finding->getType(),'status'=>$finding->getStatus(),
            'manual_assessment'=>$finding->getManualAssessment(),'discard_reason'=>$finding->getDiscardReason(),'review_state'=>$finding->getReviewState()];
        $row['poc_url']=$row['poc_url'] ?? $row['url'];
        $row['poc_revision']=(int)$row['poc_revision'];
        $row['request_params']=$row['request_params']===null?null:json_decode($row['request_params'],true,512,JSON_THROW_ON_ERROR);
        foreach ($expected as $key=>$value) if ($row[$key]!==$value) throw new \UnexpectedValueException('Finding source changed; reload before report refresh: '.$key);
    }

    private function ensureContext(string $id): void { $this->connection->executeStatement('INSERT OR IGNORE INTO finding_report_context(finding_id) VALUES (?)',[$id]); }
    private function validatedSnapshot(array $row): array
    {
        if (!hash_equals($row['snapshot_sha256'],hash('sha256',$row['snapshot_json']))) throw new \RuntimeException('Report snapshot hash mismatch.');
        $snapshot=self::decode($row['snapshot_json']);
        foreach (['finding_id'=>'findingId','revision'=>'decisionRevision','basis_revision'=>'basisRevision','schema_version'=>'schemaVersion','policy_version'=>'policyVersion','source_instance_id'=>'sourceInstanceId','lane'=>'lane'] as $column=>$field) {
            if ((string)($snapshot[$field] ?? '')!==(string)$row[$column]) throw new \RuntimeException('Report snapshot metadata mismatch: '.$field);
        }
        return $snapshot;
    }
    private function observationFacts(Finding $finding, ?HttpObservation $observation, string $source, array $poc): array
    {
        if (!$observation || $this->observations===null) return [];
        $observation=$this->observations->coverageSource($finding,$this->observations->coverageReference($observation));
        if ($observation===null) return [];
        $facts=[]; $signals=$observation->getClassification()['signals'] ?? [];
        foreach ($signals as $signal) {
            if (($signal['kind'] ?? null)!=='observation_evidence' || ($signal['mode'] ?? null)!=='active') continue;
            EvidenceSignal::validate($signal);
            // Revalidate offsets against authenticated stored representations, never just excerpts.
            if ($this->observations===null) throw new \RuntimeException('Active evidence requires authenticated observation storage.');
            $artifact=$this->observations->load($observation);
            foreach ($signal['spans'] as $span) {
                $raw=match($span['representation']) {'raw'=>$artifact['rawHtml'],'dom_text'=>$artifact['domText'], default=>null};
                if ($raw===null || hash('sha256',$raw)!==$span['representationSha256'] || mb_substr($raw,$span['start'],$span['length'],'UTF-8')!==$span['excerpt']) throw new \InvalidArgumentException('Active evidence source span mismatch.');
            }
            $fact=['kind'=>'behavior','schemaVersion'=>1,'factId'=>hash('sha256',self::json([$source,$finding->getId(),$poc,$observation->getId(),$signal['evidenceId']])),'sourceInstanceId'=>$source,'findingId'=>$finding->getId(),'pocRevision'=>$poc['revision'],'pocSha256'=>$poc['sha256'],'requestContextSha256'=>$observation->getMetadata()['requestContextSha256'],'sourceRef'=>['kind'=>'http_observation','id'=>$observation->getId(),'artifactSha256'=>$observation->getArtifactSha256(),'captureRef'=>$observation->getMetadata()['captureRef'] ?? ($observation->getScreenshotJobId()!==null?['kind'=>'screenshot_job','id'=>$observation->getScreenshotJobId()]:($observation->getRetestRunId()!==null?['kind'=>'retest_run','id'=>$observation->getRetestRunId()]:null))],'capturedAt'=>$observation->getMetadata()['observedAt'] ?? $observation->getCreatedAt()->format(DATE_ATOM),'producer'=>$signal['producer'].':'.$signal['producerVersion'],'mode'=>'active','code'=>$signal['code'],'attributes'=>$signal['attributes'],'spans'=>$signal['spans'],'relations'=>$signal['relations'],'relatedFactIds'=>[]];
            $facts[]=$this->storeFact($fact);
        }
        return $facts;
    }
    /** Coverage is evidence with its own source even when the capture produced zero facts. */
    private function authenticatedCoverage(Finding $finding, ?HttpObservation $observation, array $previous, string $source): array
    {
        $unknown=[...($observation?->getClassification()['coverage'] ?? $previous),'status'=>'unknown'];
        if ($this->observations===null) return [$unknown,null,null];
        try {
            $ref=$observation===null ? ($previous['sourceRef'] ?? []) : $this->observations->coverageReference($observation);
            $retained=$this->observations->coverageSource($finding,$ref);
            if ($retained===null) return [$unknown,null,null];
            $artifact=$this->observations->load($retained);
            $coverage=$retained->getClassification()['coverage'] ?? [];
            $version=$retained->getClassification()['classifierVersion'] ?? null;
            $rawHash=$artifact['rawHtml']===null?null:hash('sha256',$artifact['rawHtml']);
            if (($coverage['artifactSha256'] ?? null)!==$rawHash || ($coverage['inputUrlSha256'] ?? null)!==hash('sha256',$finding->getPocUrl()) || ($coverage['mode'] ?? null)!=='active') $coverage['status']='unknown';
            $coverage['sourceRef']=$ref; $coverage['sourceInstanceId']=$source;
            $coverage['pocRevision']=$finding->getPocRevision(); $coverage['pocSha256']=hash('sha256',$finding->getPocUrl());
            $coverage['requestContextSha256']=WorkerProofResolver::requestContextSha256($finding);
            $coverage['resultVersion']=$version;
            return [$coverage,$rawHash,$version];
        } catch (\Throwable) { return [$unknown,null,null]; }
    }

    private function storeFact(array $fact): array
    {
        $json=self::json($fact); $hash=hash('sha256',$json);
        $existing=$this->connection->fetchOne('SELECT fact_json FROM finding_behavior_fact WHERE fact_id=?',[$fact['factId']]);
        if ($existing!==false && $existing!==$json) throw new \RuntimeException('Immutable fact identity collision.');
        if ($existing===false) $this->connection->insert('finding_behavior_fact',['fact_id'=>$fact['factId'],'finding_id'=>$fact['findingId'],'poc_revision'=>$fact['pocRevision'],'poc_sha256'=>$fact['pocSha256'],'code'=>$fact['code'],'mode'=>$fact['mode'],'fact_json'=>$json,'fact_sha256'=>$hash,'created_at'=>$fact['capturedAt']]);
        $fact['contentSha256']=$hash; return $fact;
    }
    private static function json(array $data): string { return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
    private static function decode(string $json): array { return json_decode($json,true,512,JSON_THROW_ON_ERROR); }
}
