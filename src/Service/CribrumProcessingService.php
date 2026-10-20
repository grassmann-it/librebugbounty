<?php

namespace App\Service;

use App\Cribrum\ClassificationResult;
use App\Cribrum\CribrumClassifier;
use App\Cribrum\ObservationInput;
use App\Cribrum\ObservationTransport;
use App\Entity\Finding;
use App\Entity\HttpObservation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Stores an observation and applies deterministic suggestions without failing the browser result. */
final class CribrumProcessingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CribrumClassifier $classifier,
        private readonly CribrumObservationStore $observations,
        private readonly CribrumClaimService $claims,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function process(
        Finding $finding,
        ?array $observation,
        ?string $screenshotJobId = null,
        ?string $retestRunId = null,
        ?int $pocRevision = null,
        ?string $contextUrl = null,
        ?string $payload = null,
        ?string $expectedEvidence = null,
        bool $dialogSeen = false,
        ?string $dialogText = null,
    ): ?HttpObservation {
        $observation = ObservationTransport::normalize($observation);
        if ($observation === null) {
            $this->logger?->warning('Invalid Cribrum observation was dropped at the PHP boundary.', ['finding' => $finding->getId()]);
            return null;
        }

        try {
            $entity = $this->observations->store($finding, $observation, $screenshotJobId, $retestRunId, $pocRevision ?? $finding->getPocRevision(), $contextUrl ?? $finding->getPocUrl(), [
                'url' => $contextUrl ?? $finding->getPocUrl(),
                'payload' => $payload ?? $finding->getPayload(),
                'expectedEvidence' => $expectedEvidence ?? $finding->getExpectedEvidence(),
                'dialogSeen' => $dialogSeen,
                'dialogText' => $dialogText === null ? null : mb_strcut($dialogText, 0, 8192, 'UTF-8'),
            ]);
        } catch (\Throwable $error) {
            $this->logger?->error('Cribrum observation could not be stored.', ['exception' => $error, 'finding' => $finding->getId()]);
            return null;
        }

        $capturedUrl = $contextUrl ?? $finding->getPocUrl();
        $capturedPayload = $payload ?? $finding->getPayload();
        $capturedEvidence = $expectedEvidence ?? $finding->getExpectedEvidence();
        $revision = $pocRevision ?? $finding->getPocRevision();
        $current = $this->entityManager->find(Finding::class, $finding->getId());
        if (!$current instanceof Finding || $current->getPocUrl() !== $capturedUrl || $current->getPocRevision() !== $revision) {
            try {
                $this->claims->attachClassification($entity, [
                    'classifierVersion' => 'cribrum-stale',
                    'suggestions' => [],
                    'signals' => [],
                    'packs' => [],
                    'diagnostics' => ['poc_changed_before_classification'],
                ]);
            } catch (\Throwable $error) {
                $this->logger?->error('Stale Cribrum observation could not be marked.', ['exception' => $error, 'finding' => $finding->getId()]);
            }
            return $entity;
        }

        try {
            $input = new ObservationInput(
                $capturedUrl,
                $observation['rawHtml'],
                $observation['domText'],
                $observation['contentType'],
                $observation['httpStatus'],
                $observation['finalUrl'],
                $capturedPayload,
                $capturedEvidence,
                $dialogSeen,
                $dialogText,
            );
            $result = $this->classifier->classify($input);
            $failed = false;
        } catch (\Throwable $error) {
            $this->logger?->error('Cribrum classification failed.', ['exception' => $error, 'finding' => $finding->getId()]);
            $result = new ClassificationResult([], [], [], ['classifier: '.$error->getMessage()], 'cribrum-error');
            $failed = true;
        }

        if ($entity->getClassification() !== null) {
            return $entity;
        }
        try {
            $this->entityManager->getConnection()->transactional(function () use ($entity, $finding, $result, $failed): void {
                $this->claims->attachClassification($entity, $result->toArray());
                if (!$failed) {
                    $this->claims->applySuggestions($finding, $entity, array_map(
                        static fn ($suggestion): array => $suggestion->toArray(),
                        $result->suggestions,
                    ));
                }
            });
        } catch (\Throwable $error) {
            $this->logger?->error('Cribrum suggestions could not be applied.', ['exception' => $error, 'finding' => $finding->getId()]);
        }

        return $entity;
    }
}
