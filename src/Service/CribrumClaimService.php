<?php

namespace App\Service;

use App\Entity\Finding;
use App\Entity\FindingClaim;
use App\Entity\FindingClaimRevision;
use App\Entity\HttpObservation;
use Doctrine\ORM\EntityManagerInterface;

/** Current Cribrum claims and their immutable decision history. */
final class CribrumClaimService
{
    private const SOURCES = ['manual', 'classifier', 'migration'];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return list<FindingClaim> */
    public static function available(\Doctrine\DBAL\Connection $connection): bool
    {
        return (bool) $connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'finding_claim'");
    }

    public function currentClaims(Finding $finding): array
    {
        $claims = $this->entityManager->getRepository(FindingClaim::class)->findBy(['finding' => $finding], ['claim' => 'ASC']);

        return array_values($claims);
    }

    /** Bounded, database-current metadata shared by detail and review badge views. */
    public function readClaims(Finding $finding): array
    {
        return CribrumClaimExport::forFindings($this->entityManager->getConnection(), [$finding->getId()])[$finding->getId()] ?? [];
    }

    public function apply(
        Finding $finding,
        string $claim,
        string $state,
        bool $reportSelected,
        string $source,
        ?array $ruleIds = [],
        ?string $reason = null,
        ?string $observationId = null,
        ?string $severity = null,
        ?int $expectedRevision = null,
    ): FindingClaim {
        if (!in_array($claim, FindingClaim::CLAIMS, true) || !in_array($state, FindingClaim::STATES, true) || !in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid Cribrum claim.');
        }
        if ($severity !== null && !in_array($severity, \App\Value\FindingSeverity::values(), true)) {
            throw new \InvalidArgumentException('Invalid Cribrum claim severity.');
        }
        $ruleIds ??= [];
        foreach ($ruleIds as $ruleId) {
            if (!is_string($ruleId) || preg_match('/^[A-Za-z0-9_.:\/-]{1,128}$/D', $ruleId) !== 1) {
                throw new \InvalidArgumentException('Invalid Cribrum rule ID.');
            }
        }

        return $this->entityManager->getConnection()->transactional(function () use ($finding, $claim, $state, $reportSelected, $source, $ruleIds, $reason, $observationId, $severity, $expectedRevision): FindingClaim {
            $this->entityManager->getConnection()->executeStatement('UPDATE finding SET id = id WHERE id = ?', [$finding->getId()]);
            $current = $this->entityManager->find(FindingClaim::class, ['finding' => $finding, 'claim' => $claim]);
            if ($current !== null) $this->entityManager->refresh($current);
            $actual = $current?->revision ?? 0;
            if ($expectedRevision !== null && $expectedRevision !== $actual) {
                throw new \UnexpectedValueException('Cribrum claim revision changed.');
            }
            if ($current !== null && $current->source === 'manual' && $source === 'classifier') {
                return $current;
            }
            // A manual adoption of the same state changes decision ownership and
            // must receive its own immutable revision.
            if ($current !== null && $current->state === $state && $current->source === $source && $current->reportSelected === $reportSelected && $current->severity === $severity && $current->observationId === $observationId) {
                return $current;
            }

            if ($current === null) {
                $current = new FindingClaim();
                $current->finding = $finding;
                $current->claim = $claim;
                $current->revision = 1;
                $current->state = $state;
                $current->reportSelected = $reportSelected;
                $current->severity = $severity;
                $current->source = $source;
                $current->observationId = $observationId;
                $this->entityManager->persist($current);
            } else {
                $previous = $current->state;
                ++$current->revision;
                $current->state = $state;
                $current->reportSelected = $reportSelected;
                $current->severity = $severity;
                $current->source = $source;
                $current->observationId = $observationId;
                $revision = new FindingClaimRevision($current, $previous, $ruleIds, $reason);
            }
            if (!isset($revision)) {
                $revision = new FindingClaimRevision($current, null, $ruleIds, $reason);
            }
            $this->entityManager->persist($revision);
            // Migration-installed claim triggers invalidate the report basis in
            // this flush transaction, including callers that use direct SQL.
            $this->entityManager->flush();

            return $current;
        });
    }

    public function attachClassification(HttpObservation $observation, array $result): HttpObservation
    {
        if ($observation->getClassification() !== null) {
            throw new \LogicException('A Cribrum observation classification is immutable.');
        }
        if (($result['schema'] ?? null) !== 1 && !isset($result['classifierVersion'])) {
            throw new \InvalidArgumentException('Invalid Cribrum classification result.');
        }
        json_encode($result, JSON_THROW_ON_ERROR);
        $observation->setClassification($result);
        $this->entityManager->persist($observation);
        $this->entityManager->flush();

        return $observation;
    }

    /** @param list<array<string,mixed>> $suggestions */
    public function applySuggestions(Finding $finding, HttpObservation $observation, array $suggestions): array
    {
        if ($observation->getFinding()->getId() !== $finding->getId()) {
            throw new \InvalidArgumentException('Cribrum observation does not belong to this finding.');
        }
        $validated = [];
        $suggested = [];
        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion)) throw new \InvalidArgumentException('Invalid Cribrum claim suggestion.');
            $dto = \App\Cribrum\ClaimSuggestion::fromArray($suggestion);
            if (!in_array($dto->state, [\App\Cribrum\Taxonomy::OBSERVED, \App\Cribrum\Taxonomy::SUSPECTED], true)) {
                throw new \InvalidArgumentException('Classifier suggestions cannot make a final judgment.');
            }
            $validated[] = $dto;
            $suggested[$dto->claim] = $dto;
        }

        $existing = [];
        foreach ($this->currentClaims($finding) as $current) $existing[$current->claim] = $current;
        $applied = [];
        foreach ($validated as $dto) {
            $current = $existing[$dto->claim] ?? null;
            $applied[] = $this->apply(
                $finding,
                $dto->claim,
                $dto->state,
                false,
                'classifier',
                $dto->ruleIds,
                implode('\n', $dto->reasons),
                $observation->getId(),
                $current?->severity,
                $current?->revision,
            );
        }

        foreach ($existing as $claim => $current) {
            if (isset($suggested[$claim]) || $current->source === 'manual' || $current->state === 'stale') continue;
            $applied[] = $this->apply(
                $finding,
                $claim,
                'stale',
                false,
                'classifier',
                [],
                'The latest observation did not support this claim.',
                $observation->getId(),
                $current->severity,
                $current->revision,
            );
        }

        return $applied;
    }

}
