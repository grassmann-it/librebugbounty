<?php

namespace App\Service;

use App\Cribrum\CribrumClassifier;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use App\Entity\Finding;
use App\Entity\HttpObservation;
use Doctrine\ORM\EntityManagerInterface;

/** Local reclassification preserves original results and all manual decisions. */
final class CribrumReclassifyService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CribrumClassifier $classifier,
        private readonly CribrumObservationStore $observations,
        private readonly CribrumClaimService $claims,
        private readonly ?ReportProjectionService $reports = null,
    ) {
    }

    /** @return array{processed: int, suggestions: int, skipped: int, errors: list<string>} */
    public function reclassify(?string $findingId = null, bool $dryRun = false, ?\DateTimeImmutable $since = null): array
    {
        $connection = $this->entityManager->getConnection();
        if (!CribrumClaimService::available($connection)) {
            throw new \LogicException('Cribrum tables are not available in this installation.');
        }
        if ($findingId !== null && $this->entityManager->find(Finding::class, $findingId) === null) {
            throw new \InvalidArgumentException('Finding not found.');
        }
        $summary = ['processed' => 0, 'suggestions' => 0, 'skipped' => 0, 'errors' => []];
        $scope = '';
        $parameters = [];
        if ($since !== null) {
            $scope .= 'f.created_at >= ? AND ';
            $parameters[] = $since->format('Y-m-d H:i:s');
        }
        if ($findingId !== null) {
            $scope .= 'o.finding_id = ? AND ';
            $parameters[] = $findingId;
        }
        // Snapshot the newest capture per case. Replaying historical captures would
        // oscillate current claims on every run and could revive obsolete evidence.
        $ids = $connection->fetchFirstColumn(
            'SELECT o.id FROM http_observation o JOIN finding f ON f.id = o.finding_id WHERE o.poc_revision = f.poc_revision AND '.$scope
            .'NOT EXISTS (SELECT 1 FROM http_observation newer WHERE newer.finding_id = o.finding_id AND newer.poc_revision = o.poc_revision '
            .'AND (newer.created_at > o.created_at OR (newer.created_at = o.created_at AND newer.id > o.id))) ORDER BY o.id',
            $parameters,
        );
        $summary['skipped'] = (int) $connection->fetchOne(
            'SELECT COUNT(DISTINCT o.finding_id) FROM http_observation o JOIN finding f ON f.id = o.finding_id WHERE '.$scope
            .'NOT EXISTS (SELECT 1 FROM http_observation active WHERE active.finding_id = f.id AND active.poc_revision = f.poc_revision)',
            $parameters,
        );
        foreach ($ids as $index => $id) {
            try {
                $observation = $this->entityManager->find(HttpObservation::class, $id);
                $finding = $observation->getFinding();
                $this->entityManager->refresh($finding);
                if ($observation->getArtifactPath() === null || $observation->getPocRevision() !== $finding->getPocRevision()) {
                    ++$summary['skipped'];
                    continue;
                }
                $contents = $this->observations->load($observation);
                $context = $observation->getMetadata()['classificationContext'] ?? [
                    // Historical unknowns stay unknown: replay never fills today's payload or marker.
                    'url' => $observation->getMetadata()['requestUrl'] ?? $observation->getFinalUrl() ?? '',
                    'payload' => null, 'expectedEvidence' => null,
                ];
                $input = ObservationInput::fromArray([
                    ...$context, ...$contents,
                    'contentType' => $observation->getContentType(),
                    'httpStatus' => $observation->getHttpStatus(),
                    'finalUrl' => $observation->getFinalUrl(),
                    'coverage' => ['status' => !$observation->getRawTruncated() && !isset($observation->getMetadata()['error']) ? 'complete' : 'partial', 'truncated' => $observation->getRawTruncated()],
                ]);
                $result = $this->classifier->classify($input)->toArray();
                // Early captures did not retain dialog context. Preserve their
                // proven native-dialog signal rather than silently downgrading it.
                if (!isset($context['dialogSeen'])) {
                    foreach ($observation->getClassification()['suggestions'] ?? [] as $suggestion) {
                        if (($suggestion['ruleIds'] ?? []) === ['builtin/dialog-marker']) {
                            $result['suggestions'] = array_values(array_filter($result['suggestions'], static fn (array $s): bool => $s['claim'] !== 'xss'));
                            $result['suggestions'][] = $suggestion;
                            usort($result['suggestions'], static fn (array $a, array $b): int => $a['claim'] <=> $b['claim']);
                        }
                    }
                }
                if (!$this->observations->matchesCurrentRequest($finding,$observation)) {
                    // Keep a replay inspectable without announcing an active interpretation of an unknown request.
                    foreach ($result['signals'] as &$signal) {
                        if (($signal['kind'] ?? null)==='observation_evidence') $signal=EvidenceSignal::make($signal['code'],$input,$signal['spans'],$signal['attributes'],$signal['relations'],$signal['producer'],'shadow');
                    }
                    unset($signal);
                    if (isset($result['coverage'])) {
                        $result['coverage']['mode']='shadow'; $result['coverage']['status']='unknown';
                        if (isset($result['coverage']['evaluation']['runs'])) {
                            foreach ($result['coverage']['evaluation']['runs'] as &$run) $run['mode']='shadow';
                            unset($run);
                        }
                    }
                    $result['diagnostics'][]='capture_context_changed_or_unpinned_history_only';
                }
                ++$summary['processed'];
                $summary['suggestions'] += count($result['suggestions']);
                if ($dryRun || $result === $observation->getClassification()) continue;
                $connection->transactional(function () use ($finding, $observation, $result, $connection): void {
                    $connection->executeStatement('UPDATE finding SET id = id WHERE id = ?', [$finding->getId()]);
                    $this->entityManager->refresh($finding);
                    $latest = $connection->fetchOne('SELECT id FROM http_observation WHERE finding_id = ? AND poc_revision = ? ORDER BY created_at DESC, id DESC LIMIT 1', [$finding->getId(), $finding->getPocRevision()]);
                    if ($finding->getPocRevision() !== $observation->getPocRevision() || $latest !== $observation->getId()) {
                        throw new \UnexpectedValueException('PoC changed during reclassification.');
                    }
                    // A new immutable result references the same hash-verified artifact;
                    // historical report references and original classifications stay valid.
                    $revision = new HttpObservation(
                        $finding, $observation->getPocRevision(), $observation->getHttpStatus(),
                        $observation->getFinalUrl(), $observation->getContentType(),
                        $observation->getRawBytes(), $observation->getRawTruncated(),
                        $observation->getDomBytes(), $observation->getDomTruncated(),
                        [...($observation->getMetadata() ?? []), 'reclassifiedFrom' => $observation->getId(),
                            'observedAt' => $observation->getMetadata()['observedAt'] ?? $observation->getCreatedAt()->format(DATE_ATOM),
                            'captureRef' => $observation->getMetadata()['captureRef'] ?? ($observation->getScreenshotJobId()!==null?['kind'=>'screenshot_job','id'=>$observation->getScreenshotJobId()]:($observation->getRetestRunId()!==null?['kind'=>'retest_run','id'=>$observation->getRetestRunId()]:null)),
                            'captureId' => $observation->getMetadata()['captureId'] ?? $observation->getId()],
                    );
                    $revision->setArtifact($observation->getArtifactPath(), $observation->getArtifactSha256());
                    $this->claims->attachClassification($revision, $result);
                    // Replay can retain an interpretation of stale/unpinned bytes, never current claims.
                    if (!$this->observations->matchesCurrentRequest($finding,$revision)) return;
                    $this->claims->applySuggestions($finding, $revision, $result['suggestions']);
                    if ($this->reports !== null) {
                        $this->reports->refresh($finding, $revision);
                    }
                });
            } catch (\Throwable $error) {
                $summary['errors'][] = $id.': '.$error->getMessage();
            } finally {
                if (($index + 1) % 50 === 0) $this->entityManager->clear();
            }
        }

        return $summary;
    }
}
