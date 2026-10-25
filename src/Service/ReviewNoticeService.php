<?php

namespace App\Service;

use App\Dto\ReviewNoticeView;
use Doctrine\DBAL\Connection;

/** The one policy for queue/detail/list hints, based solely on stored records. */
final class ReviewNoticeService
{
    public function __construct(private readonly Connection $connection, private readonly ReviewSchema $schema) {}

    public function available(): bool { return $this->schema->available(); }

    /** @param ?list<string> $ids @return array<string, ReviewNoticeView> Includes resolved manual cases with an empty observation list. */
    public function forFindings(?array $ids = null): array
    {
        if (!$this->available() || $ids === []) {
            return [];
        }
        $where = "f.manual_assessment IN ('confirmed', 'fixed') AND f.status NOT IN ('duplicate', 'discarded') AND ".FindingWorkPolicy::pursuitActiveSql($this->connection).' AND '.ReviewDispositionService::activeSql($this->connection);
        $parameters = [];
        if ($ids !== null) {
            $where .= ' AND f.id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
            $parameters = $ids;
        }
        $findings = $this->connection->fetchAllAssociative(
            'SELECT f.id, '.(in_array('poc_revision', array_column($this->connection->fetchAllAssociative('PRAGMA table_info(finding)'), 'name'), true) ? 'f.url, f.poc_url, f.poc_revision, ' : '').'f.manual_assessment, f.assessed_at, a.id AS decision_id, a.assessment AS decision_assessment, a.assessed_at AS decision_at, a.known_observation_ids, a.known_observation_states '
            .'FROM finding f LEFT JOIN finding_assessment a ON a.id = (SELECT latest.id FROM finding_assessment latest WHERE latest.finding_id = f.id AND '.AssessmentHistoryProjection::activeSql($this->connection, 'latest').' ORDER BY latest.assessed_at DESC, latest.id DESC LIMIT 1) WHERE '.$where,
            $parameters,
        );
        if ($findings === []) { return $this->withSemanticNotices($this->withWafNotices([], $ids), $ids); }
        $findingIds = array_column($findings, 'id');
        $placeholders = implode(',', array_fill(0, count($findingIds), '?'));
        $runsByFinding = [];
        // Group the sort by the indexed finding_id so SQLite does not sort all
        // large observation bodies together. Each finding keeps its existing
        // newest-first time/rowid order and raw SELECT * fingerprint format.
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM retest_run WHERE finding_id IN ('.$placeholders.') ORDER BY finding_id, COALESCE(finished_at, started_at) DESC, rowid DESC', $findingIds) as $run) {
            $runsByFinding[$run['finding_id']][] = $run;
        }
        $acksByFinding = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM finding_review_acknowledgement WHERE finding_id IN ('.$placeholders.') ORDER BY reviewed_at DESC, id DESC', $findingIds) as $ack) {
            $acksByFinding[$ack['finding_id']][$ack['decision_key']] ??= $ack;
        }
        $result = [];
        foreach ($findings as $finding) {
            $matchingHistory = $finding['decision_id'] !== null && $finding['decision_assessment'] === $finding['manual_assessment'] && $finding['decision_at'] === $finding['assessed_at'];
            $decisionKey = $matchingHistory ? 'history:'.$finding['decision_id'] : 'legacy:'.hash('sha256', json_encode([$finding['manual_assessment'], $finding['assessed_at']]));
            $ack = $acksByFinding[$finding['id']][$decisionKey] ?? null;
            $states = $ack !== null ? json_decode($ack['known_observation_states'], true, 512, JSON_THROW_ON_ERROR)
                : ($matchingHistory && $finding['known_observation_states'] !== null ? json_decode($finding['known_observation_states'], true, 512, JSON_THROW_ON_ERROR) : null);
            $knownIds = $matchingHistory ? json_decode($finding['known_observation_ids'], true, 512, JSON_THROW_ON_ERROR) : null;
            $triggers = [];
            foreach ($runsByFinding[$finding['id']] ?? [] as $run) {
                if (!PocObservation::current($run, $finding)) continue;
                $reason = $this->reason($finding['manual_assessment'], $run['result']);
                if ($reason === null) { continue; }
                if ($states !== null) {
                    $unseen = !isset($states[$run['id']]) || $states[$run['id']] !== self::runFingerprint($run);
                } elseif ($knownIds !== null) {
                    // Old histories still have a reliable ID arrival boundary.
                    // Later edits can be recognized where a later timestamp was
                    // retained; no missing past result is fabricated for ties.
                    $unseen = !in_array($run['id'], $knownIds, true)
                        || ($finding['assessed_at'] !== null && max($run['finished_at'] ?? $run['started_at'], $run['updated_at']) > $finding['assessed_at']);
                } else {
                    // No history: equal-second provenance is ambiguous, so keep
                    // potentially new qualifying observations visible.
                    $unseen = $finding['assessed_at'] === null || max($run['finished_at'] ?? $run['started_at'], $run['updated_at']) >= $finding['assessed_at'];
                }
                if ($unseen) { $triggers[] = $run + ['reason' => $reason]; }
            }
            $result[$finding['id']] = new ReviewNoticeView($finding['id'], $finding['manual_assessment'], $decisionKey, $states !== null || $knownIds !== null, $ack === null ? null : new \DateTimeImmutable($ack['reviewed_at']), $triggers);
        }
        return $this->withSemanticNotices($this->withWafNotices($result, $ids), $ids);
    }

    /** E7 also applies to accepted cohort cases without an individual judgment. */
    private function withWafNotices(array $result, ?array $ids): array
    {
        if ($this->connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'finding_report_current'") === false) return $result;
        $where = "f.status NOT IN ('duplicate', 'discarded') AND ".FindingWorkPolicy::pursuitActiveSql($this->connection).' AND '.ReviewDispositionService::activeSql($this->connection);
        $parameters = [];
        if ($ids !== null) {
            $where .= ' AND f.id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
            $parameters = $ids;
        }
        $rows = $this->connection->fetchAllAssociative('SELECT f.id, f.manual_assessment, r.snapshot_json FROM finding f JOIN finding_report_current r ON r.finding_id = f.id WHERE '.$where, $parameters);
        foreach ($rows as $row) {
            $snapshot = json_decode($row['snapshot_json'], true);
            if (!is_array($snapshot) || !in_array('new_waf_block_unreviewed', $snapshot['reasonCodes'] ?? [], true)) continue;
            $pending = $snapshot['wafReview']['pending'] ?? [];
            if ($pending === [] && is_string($snapshot['wafBlockFingerprint'] ?? null)) {
                $pending = [$snapshot['wafBlockFingerprint'] => ['fingerprint' => $snapshot['wafBlockFingerprint']]];
            }
            $triggers = [];
            foreach ($pending as $fingerprint => $entry) {
                if (!is_string($fingerprint) || !is_array($entry)) continue;
                $triggers[] = [
                    'id' => $entry['observationId'] ?? 'waf:'.$fingerprint,
                    'reason' => 'waf_block', 'fingerprint' => $fingerprint,
                    'result' => 'waf_block', 'mode' => 'classification',
                    'started_at' => $entry['observedAt'] ?? $snapshot['decidedAt'] ?? null,
                    'finished_at' => $entry['observedAt'] ?? null,
                    'interpretationOnly' => $entry['interpretationOnly'] ?? false,
                    'evidenceRefs' => $entry['evidenceRefs'] ?? [],
                ];
            }
            if ($triggers === []) continue;
            $prior = $result[$row['id']] ?? null;
            $result[$row['id']] = new ReviewNoticeView($row['id'], $row['manual_assessment'] ?? 'legacy_basis', $prior?->decisionKey ?? 'waf:'.$row['id'], $prior?->baselineKnown ?? true, $prior?->lastAcknowledgedAt, [...$triggers, ...($prior?->observations ?? [])]);
        }
        return $result;
    }

    private function withSemanticNotices(array $result, ?array $ids): array
    {
        if ($this->connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'finding_report_revision'") === false) return $result;
        $parameters = [];
        $where = "f.status NOT IN ('duplicate','discarded') AND ".FindingWorkPolicy::pursuitActiveSql($this->connection).' AND '.ReviewDispositionService::activeSql($this->connection);
        if ($ids !== null) { $where .= ' AND f.id IN ('.implode(',', array_fill(0,count($ids),'?')).')'; $parameters=$ids; }
        $rows=$this->connection->fetchAllAssociative('SELECT f.id,f.manual_assessment,f.assessed_at,r.snapshot_json FROM finding f JOIN finding_report_current r ON r.finding_id=f.id WHERE '.$where,$parameters);
        if ($rows === []) return $result;
        $findingIds=array_column($rows,'id'); $slots=implode(',',array_fill(0,count($findingIds),'?'));
        $acksByFinding=[]; $assessmentsByFinding=[]; $revisionsByFinding=[];
        foreach ($this->connection->fetchAllAssociative('SELECT finding_id,known_observation_states,reviewed_at FROM finding_review_acknowledgement WHERE finding_id IN ('.$slots.') ORDER BY reviewed_at DESC,rowid DESC',$findingIds) as $ack) $acksByFinding[$ack['finding_id']][]=$ack;
        foreach ($this->connection->fetchAllAssociative('SELECT finding_id,known_observation_states FROM finding_assessment a WHERE finding_id IN ('.$slots.') AND '.AssessmentHistoryProjection::activeSql($this->connection,'a').' ORDER BY assessed_at DESC,rowid DESC',$findingIds) as $assessment) $assessmentsByFinding[$assessment['finding_id']] ??= $assessment;
        foreach ($this->connection->fetchAllAssociative('SELECT finding_id,revision,snapshot_json FROM finding_report_revision WHERE finding_id IN ('.$slots.') ORDER BY revision ASC',$findingIds) as $revision) $revisionsByFinding[$revision['finding_id']][]=$revision;
        $transition=new RecheckTransitionService(new \App\Cribrum\ReportPolicy());
        foreach ($rows as $row) {
            $current=json_decode($row['snapshot_json'],true);
            if (!is_array($current)) continue;
            $meaning=$transition->reportMeaning($current);
            $baseline=null; $ackAt=null;
            // State written by a real review is authoritative, including an empty/no-hit meaning.
            foreach ($acksByFinding[$row['id']] ?? [] as $ack) {
                if ($row['assessed_at'] !== null && new \DateTimeImmutable($ack['reviewed_at']) < new \DateTimeImmutable($row['assessed_at'])) continue;
                $states=json_decode($ack['known_observation_states'],true);
                if (isset($states['__report_semantic'])) { $baseline=json_decode($states['__report_semantic'],true); $ackAt=new \DateTimeImmutable($ack['reviewed_at']); break; }
            }
            if ($baseline===null && $row['manual_assessment']!==null) {
                $statesRaw=$assessmentsByFinding[$row['id']]['known_observation_states'] ?? null;
                $states=$statesRaw ? json_decode($statesRaw,true) : [];
                if (isset($states['__report_semantic'])) $baseline=json_decode($states['__report_semantic'],true);
            }
            // Compatibility: a prior accepted revision is a baseline, never the first current result.
            $revisions=$revisionsByFinding[$row['id']] ?? [];
            if ($row['manual_assessment']!==null) $revisions=array_reverse($revisions);
            if ($baseline===null) foreach ($revisions as $revision) {
                if ((int)$revision['revision'] >= ($current['decisionRevision'] ?? 0)) continue;
                $old=json_decode($revision['snapshot_json'],true);
                if (!is_array($old)) continue;
                $accepted=($old['legacyBasis']['verified'] ?? false)===true;
                $beforeJudgment=$row['manual_assessment']!==null && $row['assessed_at']!==null && isset($old['decidedAt']) && new \DateTimeImmutable($old['decidedAt']) <= new \DateTimeImmutable($row['assessed_at']);
                if (($row['manual_assessment']===null && $accepted) || $beforeJudgment) { $baseline=$transition->reportMeaning($old); break; }
            }
            if ($baseline===null || $transition->semanticFingerprint($baseline)===$transition->semanticFingerprint($meaning)) continue;
            $fingerprint=$transition->semanticFingerprint($meaning);
            $trigger=['id'=>'semantic:'.$fingerprint,'reason'=>'classification_changed','result'=>'classification_changed','mode'=>'classification','fingerprint'=>$fingerprint,'started_at'=>$current['decidedAt'] ?? null,'finished_at'=>null,'before'=>$baseline,'after'=>$meaning,'interpretationOnly'=>$current['interpretationOnly'] ?? isset($current['observation']['metadata']['reclassifiedFrom']),'evidenceRefs'=>[]];
            $prior=$result[$row['id']] ?? null;
            $result[$row['id']]=new ReviewNoticeView($row['id'],$row['manual_assessment'] ?? 'accepted_basis',$prior?->decisionKey ?? 'semantic:'.$row['id'],true,$ackAt ?? $prior?->lastAcknowledgedAt,[...($prior?->observations ?? []),$trigger]);
        }
        return $result;
    }

    public function get(string $id): ?ReviewNoticeView { return $this->forFindings([$id])[$id] ?? null; }

    public function states(string $id): array
    {
        $states = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM retest_run WHERE finding_id = ? ORDER BY rowid ASC', [$id]) as $run) {
            $states[$run['id']] = self::runFingerprint($run);
        }
        if ($this->connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'finding_report_current'") !== false) {
            $raw = $this->connection->fetchOne('SELECT snapshot_json FROM finding_report_current WHERE finding_id = ?', [$id]);
            if ($raw) {
                $snapshot = json_decode($raw, true);
                if (is_array($snapshot)) $states['__report_semantic'] = json_encode((new RecheckTransitionService(new \App\Cribrum\ReportPolicy()))->reportMeaning($snapshot), JSON_THROW_ON_ERROR);
            }
        }
        return $states;
    }

    public static function runFingerprint(array $run): string { return hash('sha256', json_encode($run, JSON_THROW_ON_ERROR)); }

    private function reason(string $assessment, string $result): ?string
    {
        if (in_array($result, ['inconclusive', 'error'], true)) { return $result; }
        return ($assessment === 'confirmed' && $result === 'fixed') || ($assessment === 'fixed' && $result === 'still_vulnerable') ? 'contradiction' : null;
    }
}
