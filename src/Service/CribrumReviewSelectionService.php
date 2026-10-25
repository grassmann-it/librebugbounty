<?php

namespace App\Service;

use App\Cribrum\ClaimSuggestion;
use App\Cribrum\EvidenceSignal;
use App\Entity\Finding;
use App\Entity\FindingClaim;
use App\Entity\HttpObservation;
use Doctrine\ORM\EntityManagerInterface;
use App\Value\CribrumBadgeCatalog;
use Symfony\Component\Uid\Uuid;

/** Server-owned, observation-bound options for the versioned Review form. */
final class CribrumReviewSelectionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CribrumClaimService $claims,
        private readonly CribrumObservationStore $observations,
        private readonly CribrumRuleReviewService $ruleReview,
    ) {}

    /** @return array{rows:list<array<string,mixed>>,observationId:?string} */
    public function view(Finding $finding): array
    {
        $connection = $this->entityManager->getConnection();
        if (!CribrumClaimService::available($connection)) return ['rows' => [], 'observationId' => null];
        $rows = [];
        foreach ($this->claims->currentClaims($finding) as $claim) {
            $open = in_array($claim->state, ['observed', 'suspected', 'confirmed'], true);
            $rows[$claim->claim] = [
                'claim' => $claim->claim, 'kind' => 'claim', 'displayCode' => $claim->claim,
                'state' => $claim->state, 'reportSelected' => $claim->reportSelected,
                'revision' => $claim->revision, 'source' => $claim->source,
                'severity' => $claim->severity, 'observationId' => $claim->observationId,
                'proposalObservationId' => null,
                'suggestedState' => null, 'score' => null, 'reasons' => [], 'ruleIds' => $this->historicalRuleIds($finding->getId(), $claim->claim),
                'selectionSeeded' => $open && $claim->source === 'manual' && $claim->reportSelected,
                'selectionSource' => $claim->source === 'manual' ? 'current' : 'none',
                'selectionState' => $claim->state,
                'selectionSelectable' => $open && $claim->source === 'manual',
                'ruleProvenance' => [],
            ];
        }
        $observation = $this->currentObservation($finding);
        $classification = $observation?->getClassification() ?? [];
        if ($observation !== null && $this->supported($classification)) {
            foreach (is_array($classification['suggestions'] ?? null) ? $classification['suggestions'] : [] as $raw) {
                try { $suggestion = ClaimSuggestion::fromArray($raw); } catch (\InvalidArgumentException) { continue; }
                if (!in_array($suggestion->state, ['observed', 'suspected'], true)) continue;
                $key = $suggestion->claim;
                $row = $rows[$key] ?? [
                    'claim' => $key, 'kind' => 'claim', 'displayCode' => $key,
                    'state' => null, 'reportSelected' => false, 'revision' => 0,
                    'source' => null, 'severity' => null, 'observationId' => null,
                    'proposalObservationId' => null,
                    'suggestedState' => null, 'score' => null, 'reasons' => [], 'ruleIds' => [],
                    'selectionSeeded' => false, 'selectionSource' => 'none',
                    'selectionState' => $suggestion->state, 'selectionSelectable' => true, 'ruleProvenance' => [],
                ];
                $row['suggestedState'] = $suggestion->state;
                $row['score'] = max($suggestion->score, $row['score'] ?? 0);
                $row['reasons'] = array_values(array_unique([...$row['reasons'], ...$suggestion->reasons]));
                $row['ruleIds'] = array_values(array_unique([...$row['ruleIds'], ...$suggestion->ruleIds]));
                foreach ($suggestion->ruleIds as $ruleId) {
                    $row['ruleProvenance'][$ruleId] = ['packVersion' => $this->packVersion($classification, $ruleId), 'evidenceIds' => []];
                }
                $row['proposalObservationId'] = $observation->getId();
                if ($row['source'] === null || $row['source'] === 'classifier') $row['observationId'] = $observation->getId();
                $legacyOnlyXss = $key === 'xss' && $row['ruleIds'] !== []
                    && array_diff($row['ruleIds'], ['builtin/reflection']) === [];
                if ($row['source'] !== 'manual' && !$legacyOnlyXss && !in_array($row['state'], ['rejected', 'resolved', 'stale'], true)) {
                    $row['selectionSeeded'] = true;
                    $row['selectionSource'] = 'suggestion';
                }
                if (!in_array($row['state'], ['rejected', 'resolved', 'stale'], true)) $row['selectionSelectable'] = true;
                $rows[$key] = $row;
            }
            $signalGroups = [];
            foreach (is_array($classification['signals'] ?? null) ? $classification['signals'] : [] as $signal) {
                if (!is_array($signal) || ($signal['mode'] ?? null) !== 'active') continue;
                try { EvidenceSignal::validate($signal); } catch (\InvalidArgumentException) { continue; }
                $code = $signal['code'];
                $catalog = CribrumBadgeCatalog::entry($code);
                if (!$catalog['known'] || $catalog['group'] === 'context') continue;
                $signalGroups[$code][] = $signal;
            }
            $basis = $this->reportContext($finding->getId());
            foreach ($signalGroups as $code => $signals) {
                $canonical = CribrumBadgeCatalog::canonicalCode($code);
                if ($code !== 'sql_error_observed' && isset($rows[$canonical])) {
                    // One visual option per canonical claim; preserve all supporting evidence.
                    $rows[$canonical]['proposalObservationId'] = $observation->getId();
                    foreach ($signals as $signal) {
                        $ruleId = $signal['producer'].'/'.$signal['code'];
                        if (!in_array($ruleId, $rows[$canonical]['ruleIds'], true)) $rows[$canonical]['ruleIds'][] = $ruleId;
                        $rows[$canonical]['ruleProvenance'][$ruleId]['packVersion'] = $signal['producerVersion'];
                        $rows[$canonical]['ruleProvenance'][$ruleId]['evidenceIds'][] = $signal['evidenceId'];
                    }
                    continue;
                }
                $component = $code === 'sql_error_observed' ? 'sql_error_observation' : null;
                $key = $component === null ? 'observation:'.$code : 'component:'.$component;
                $stored = $this->storedSelection($finding->getId(), $key, $observation->getId());
                $mode = $component === null || $basis === null ? null : $this->componentMode($basis, $component);
                $seed = $stored === null ? $mode !== 'exclude' : (bool) $stored['selected'];
                $provenance = [];
                foreach ($signals as $signal) {
                    $ruleId = $signal['producer'].'/'.$signal['code'];
                    $provenance[$ruleId]['packVersion'] = $signal['producerVersion'];
                    $provenance[$ruleId]['evidenceIds'][] = $signal['evidenceId'];
                }
                $state = in_array($code, ['sql_input_structure_indication', 'lfi_suspected', 'rfi_suspected'], true) ? 'suspected' : 'observed';
                $rows[$key] = [
                    'claim' => $key, 'kind' => $component === null ? 'observation' : 'component', 'displayCode' => $code,
                    'state' => $state, 'reportSelected' => $stored === null ? $mode === 'include' : (bool) $stored['selected'],
                    'revision' => $stored === null ? 0 : (int) $stored['revision'],
                    'source' => 'classifier', 'severity' => null, 'observationId' => $observation->getId(),
                    'proposalObservationId' => $observation->getId(),
                    'suggestedState' => $state, 'score' => null, 'reasons' => [], 'ruleIds' => array_keys($provenance),
                    'selectionSeeded' => $seed, 'selectionSource' => 'suggestion', 'selectionState' => $state,
                    'selectionSelectable' => true, 'ruleProvenance' => $provenance,
                ];
            }
        }
        if ($this->verifiedXssComponent($finding->getId()) && (!isset($rows['xss']) || $rows['xss']['source'] !== 'manual')) {
            unset($rows['xss']);
            $rows['component:xss_confirmed'] = [
                'claim' => 'component:xss_confirmed', 'kind' => 'proof', 'displayCode' => 'xss',
                'state' => 'confirmed', 'reportSelected' => true, 'revision' => 0,
                'source' => 'worker_proof', 'severity' => null, 'observationId' => null,
                'proposalObservationId' => null, 'suggestedState' => null, 'score' => null,
                'reasons' => [], 'ruleIds' => [], 'selectionSeeded' => true,
                'selectionSource' => 'current', 'selectionState' => 'confirmed',
                'selectionSelectable' => false, 'ruleProvenance' => [],
            ];
        }
        ksort($rows);
        return ['rows' => array_values($rows), 'observationId' => $observation?->getId()];
    }

    /** Validate the complete offered set before any assessment mutation. */
    public function prepare(Finding $finding, string $version, string $observationId, array $submitted): array
    {
        if ($version !== '1') throw new \InvalidArgumentException('Ungültige Version der Schwachstellen-Auswahl.');
        $connection = $this->entityManager->getConnection();
        if (!$this->ruleReview->available()
            || $connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='cribrum_review_selection'") === false
            || $connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='cribrum_review_selection_event'") === false) {
            throw new \UnexpectedValueException('Die Schwachstellen-Auswahl ist nach der Datenbankaktualisierung verfügbar.');
        }
        $view = $this->view($finding);
        if ($observationId !== ($view['observationId'] ?? '')) {
            // null represents the intentionally empty observation binding.
            if (!($observationId === '' && $view['observationId'] === null)) throw new \UnexpectedValueException('Die Klassifikation hat sich geändert. Bitte die Auswahl neu prüfen.');
        }
        $offered = array_column($view['rows'], null, 'claim');
        if (array_diff_key($offered, $submitted) !== [] || array_diff_key($submitted, $offered) !== []) {
            throw new \UnexpectedValueException('Die Schwachstellen-Auswahl hat sich geändert. Bitte neu laden.');
        }
        $decisions = [];
        foreach ($offered as $name => $row) {
            $entry = $submitted[$name];
            if (!is_array($entry) || !isset($entry['revision']) || !is_string($entry['revision'])
                || preg_match('/^(0|[1-9][0-9]*)$/D', $entry['revision']) !== 1
                || (int) $entry['revision'] !== $row['revision']
                || array_diff(array_keys($entry), ['revision', 'selected']) !== []
                || (isset($entry['selected']) && $entry['selected'] !== '1')) {
                throw new \UnexpectedValueException('Eine Schwachstellen-Revision hat sich geändert. Bitte neu laden.');
            }
            $selected = isset($entry['selected']);
            if ($selected && !$row['selectionSelectable']) {
                throw new \InvalidArgumentException('Ein geschlossener Schwachstellenhinweis kann nicht für den Bericht ausgewählt werden.');
            }
            if ($selected && $row['kind'] === 'claim' && $row['source'] !== 'manual' && $row['suggestedState'] === null) {
                throw new \InvalidArgumentException('Eine veraltete Klassifikation kann nicht ausgewählt werden.');
            }
            $decisions[$name] = ['row' => $row, 'selected' => $selected];
        }
        return ['observation' => $view['observationId'], 'decisions' => $decisions];
    }

    /** Caller holds ReviewQueueService's writer transaction and context fence. */
    public function apply(Finding $finding, array $plan): void
    {
        $observation = $plan['observation'] === null ? null : $this->entityManager->find(HttpObservation::class, $plan['observation']);
        $classification = $observation?->getClassification() ?? [];
        foreach ($plan['decisions'] as $name => $decision) {
            $row = $decision['row']; $selected = $decision['selected'];
            if ($row['kind'] === 'proof') continue;
            if ($row['kind'] !== 'claim') {
                $this->setFactReview($finding, $row, !$selected);
                $this->recordSelection($finding->getId(), $name, $observation?->getId(), $selected, $row);
            } else {
                $current = $this->entityManager->find(FindingClaim::class, ['finding' => $finding, 'claim' => $name]);
                if ($current !== null) $this->entityManager->refresh($current);
                if ($selected) {
                    if ($current?->source === 'manual' && $current->reportSelected) continue;
                    $state = $current?->state ?? $row['suggestedState'];
                    if (!in_array($state, ['observed', 'suspected', 'confirmed'], true)) throw new \UnexpectedValueException('Schwachstellenstatus hat sich geändert.');
                    $this->claims->apply($finding, $name, $state, true, 'manual', $row['ruleIds'], 'Explicit Review selection', $row['observationId'], $current?->severity, $row['revision']);
                } elseif ($row['selectionSeeded']) {
                    $state = $current?->state ?? $row['suggestedState'];
                    if (!in_array($state, ['observed', 'suspected', 'confirmed'], true)) throw new \UnexpectedValueException('Schwachstellenstatus hat sich geändert.');
                    $this->claims->apply($finding, $name, 'rejected', false, 'manual', $row['ruleIds'], 'Does not apply to this case: explicit Review deselection', $row['observationId'], $current?->severity, $row['revision']);
                    $this->setFactReview($finding, $row, true);
                }
            }
            if (!$selected && $row['selectionSeeded'] && $observation !== null && $row['proposalObservationId'] === $observation->getId()) {
                foreach ($row['ruleProvenance'] as $ruleId => $ruleProvenance) {
                    $this->ruleReview->raise($finding->getId(), $name, $ruleId, $observation->getId(), (string) $classification['classifierVersion'], $ruleProvenance['packVersion'], $ruleProvenance['evidenceIds'] ?? []);
                }
            }
        }
    }

    private function currentObservation(Finding $finding): ?HttpObservation
    {
        $connection = $this->entityManager->getConnection();
        $ids = $connection->fetchFirstColumn('SELECT id FROM http_observation WHERE finding_id=? AND poc_revision=? AND classification IS NOT NULL ORDER BY created_at DESC,rowid DESC', [$finding->getId(), $finding->getPocRevision()]);
        foreach ($ids as $id) {
            $observation = $this->entityManager->find(HttpObservation::class, $id);
            if ($observation !== null && $this->observations->matchesCurrentRequest($finding, $observation)) return $observation;
        }
        return null;
    }

    private function verifiedXssComponent(string $findingId): bool
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='view' AND name='finding_report_current'") === false) return false;
        $json = $connection->fetchOne('SELECT snapshot_json FROM finding_report_current WHERE finding_id=? AND is_current=1', [$findingId]);
        if (!is_string($json)) return false;
        $snapshot = json_decode($json, true);
        foreach ($snapshot['components'] ?? [] as $component) {
            if (($component['code'] ?? null) === 'xss_confirmed' && ($component['certainty'] ?? null) === 'confirmed') return true;
        }
        return false;
    }

    private function supported(array $classification): bool
    {
        return preg_match('/^cribrum-[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $classification['classifierVersion'] ?? '') === 1
            && is_array($classification['suggestions'] ?? null);
    }

    private function reportContext(string $findingId): ?array
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='finding_report_context'") === false) return null;
        return $connection->fetchAssociative('SELECT basis_revision,selection_json FROM finding_report_context WHERE finding_id=?', [$findingId]) ?: null;
    }

    private function componentMode(array $context, string $component): ?string
    {
        foreach (json_decode($context['selection_json'] ?? '[]', true) ?: [] as $entry) {
            if (($entry['component'] ?? null) === $component) return $entry['mode'] ?? null;
        }
        return null;
    }

    private function storedSelection(string $findingId, string $key, string $observationId): ?array
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='cribrum_review_selection'") === false) return null;
        return $connection->fetchAssociative('SELECT selected,revision FROM cribrum_review_selection WHERE finding_id=? AND option_key=? AND observation_id=?', [$findingId, $key, $observationId]) ?: null;
    }

    private function recordSelection(string $findingId, string $key, ?string $observationId, bool $selected, array $row): void
    {
        if ($observationId === null) throw new \UnexpectedValueException('Die Beobachtung ist nicht verfügbar.');
        $connection = $this->entityManager->getConnection();
        if ($connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='cribrum_review_selection_event'") === false) throw new \UnexpectedValueException('Die Schwachstellen-Auswahl ist nach der Datenbankaktualisierung verfügbar.');
        $existing = $connection->fetchAssociative('SELECT id,selected,revision FROM cribrum_review_selection WHERE finding_id=? AND option_key=? AND observation_id=?', [$findingId, $key, $observationId]);
        if ($existing !== false && (bool) $existing['selected'] === $selected) return;
        if ($existing === false && !$selected && !$row['selectionSeeded']) return;
        $id = $existing['id'] ?? Uuid::v7()->toRfc4122();
        $revision = $existing === false ? 1 : (int) $existing['revision'] + 1;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $provenance = ['observationId' => $observationId, 'classifierRules' => $row['ruleProvenance'], 'state' => $row['selectionState']];
        $json = json_encode($provenance, JSON_THROW_ON_ERROR);
        if ($existing === false) $connection->insert('cribrum_review_selection', ['id' => $id, 'finding_id' => $findingId, 'option_key' => $key, 'observation_id' => $observationId, 'selected' => (int) $selected, 'revision' => $revision, 'provenance' => $json, 'created_at' => $now, 'updated_at' => $now]);
        else $connection->update('cribrum_review_selection', ['selected' => (int) $selected, 'revision' => $revision, 'provenance' => $json, 'updated_at' => $now], ['id' => $id]);
        $connection->insert('cribrum_review_selection_event', ['id' => Uuid::v7()->toRfc4122(), 'selection_id' => $id, 'revision' => $revision, 'selected' => (int) $selected, 'provenance' => $json, 'created_at' => $now]);
    }

    private function setComponent(string $findingId, string $component, string $mode): void
    {
        $context = $this->reportContext($findingId);
        if ($context === null) throw new \UnexpectedValueException('Die Berichtsauswahl ist nach der Datenbankaktualisierung verfügbar.');
        $selection = json_decode($context['selection_json'] ?? '[]', true) ?: [];
        $selection = array_values(array_filter($selection, static fn (mixed $entry): bool => !is_array($entry) || ($entry['component'] ?? null) !== $component));
        $selection[] = ['component' => $component, 'mode' => $mode];
        $json = json_encode($selection, JSON_THROW_ON_ERROR);
        if ($json !== $context['selection_json']) $this->entityManager->getConnection()->executeStatement('UPDATE finding_report_context SET selection_json=? WHERE finding_id=?', [$json, $findingId]);
    }

    /** Rejects only authenticated facts from the displayed capture; report evidence remains immutable. */
    private function setFactReview(Finding $finding, array $row, bool $rejected): void
    {
        $codes = match ($row['kind']) {
            'component', 'observation' => [$row['displayCode']],
            'claim' => match ($row['claim']) {
                'sql_injection' => ['sql_input_structure_indication'],
                'information_disclosure' => ['internal_information_exposed'],
                'local_file_inclusion' => ['lfi_suspected'],
                'remote_file_inclusion' => ['rfi_suspected'],
                default => [],
            },
            default => [],
        };
        if ($codes === [] || !is_string($row['proposalObservationId'] ?? null)) return;
        $context = $this->reportContext($finding->getId());
        if ($context === null) throw new \UnexpectedValueException('Die Berichtsauswahl ist nach der Datenbankaktualisierung verfügbar.');
        $selection = json_decode($context['selection_json'] ?? '[]', true) ?: [];
        $selection = array_values(array_filter($selection, static fn (mixed $entry): bool => !is_array($entry)
            || ($entry['kind'] ?? null) !== 'fact_review'
            || ($entry['observationId'] ?? null) !== $row['proposalObservationId']
            || !in_array($entry['code'] ?? null, $codes, true)));
        if ($rejected) {
            foreach ($codes as $code) {
                $refs = $this->factRefs($finding, $row['proposalObservationId'], $code);
                if ($refs === []) {
                    if ($row['kind'] !== 'claim') throw new \UnexpectedValueException('Der zugehörige Beobachtungsbeleg ist nicht verfügbar. Bitte neu prüfen.');
                    continue;
                }
                $selection[] = [
                    'kind' => 'fact_review', 'decision' => 'not_applicable', 'code' => $code,
                    'observationId' => $row['proposalObservationId'], 'pocRevision' => $finding->getPocRevision(),
                    'requestContextSha256' => WorkerProofResolver::requestContextSha256($finding),
                    'factRefs' => $refs, 'actor' => 'manual', 'reason' => 'Explicit Review deselection',
                    'reviewedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ];
            }
        }
        $json = json_encode($selection, JSON_THROW_ON_ERROR);
        if ($json !== $context['selection_json']) $this->entityManager->getConnection()->executeStatement('UPDATE finding_report_context SET selection_json=? WHERE finding_id=?', [$json, $finding->getId()]);
    }

    /** @return list<array{id:string,contentSha256:string}> */
    private function factRefs(Finding $finding, string $observationId, string $code): array
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='finding_behavior_fact'") === false) return [];
        $rows = $connection->fetchAllAssociative("SELECT fact_id,fact_json,fact_sha256 FROM finding_behavior_fact WHERE finding_id=? AND poc_revision=? AND code=? AND mode='active'", [$finding->getId(), $finding->getPocRevision(), $code]);
        $refs = [];
        foreach ($rows as $stored) {
            if (!hash_equals($stored['fact_sha256'], hash('sha256', $stored['fact_json']))) throw new \UnexpectedValueException('Der Beleg hat sich geändert. Bitte neu prüfen.');
            $fact = json_decode($stored['fact_json'], true, 512, JSON_THROW_ON_ERROR);
            if (($fact['sourceRef']['id'] ?? null) !== $observationId || ($fact['code'] ?? null) !== $code
                || ($fact['requestContextSha256'] ?? null) !== WorkerProofResolver::requestContextSha256($finding)
                || ($fact['pocSha256'] ?? null) !== hash('sha256', $finding->getPocUrl())) continue;
            $refs[] = ['id' => $stored['fact_id'], 'contentSha256' => $stored['fact_sha256']];
        }
        return $refs;
    }

    /** @return list<string> */
    private function historicalRuleIds(string $findingId, string $claim): array
    {
        $rows = $this->entityManager->getConnection()->fetchFirstColumn('SELECT rule_ids FROM finding_claim_revision WHERE finding_id=? AND claim=? AND rule_ids IS NOT NULL ORDER BY revision DESC LIMIT 1', [$findingId, $claim]);
        $decoded = isset($rows[0]) ? json_decode($rows[0], true) : null;
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function packVersion(array $classification, string $ruleId): string
    {
        $pack = explode('/', $ruleId, 2)[0];
        if (str_contains($ruleId, '/') && is_string($classification['packs'][$pack] ?? null)) return $classification['packs'][$pack];
        foreach ($classification['packs'] ?? [] as $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $pack && is_string($entry['version'] ?? null)) return $entry['version'];
        }
        return (string) ($classification['classifierVersion'] ?? 'unknown');
    }
}
