<?php

namespace App\Service;

use App\Cribrum\RulePack;
use App\Cribrum\RulePackRepository;
use Doctrine\DBAL\Connection;

/** Read-only Cribrum operations snapshot for status and settings screens. */
final class CribrumStatusService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $repository = new RulePackRepository($this->privateDirectory());
        $loaded = $repository->load();
        $available = (bool) $this->connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'http_observation'");
        $observations = null;
        if ($available) {
            $row = $this->connection->fetchAssociative(
                "SELECT COUNT(*) AS total, "
                ."COALESCE(SUM(CASE WHEN artifact_path IS NOT NULL THEN 1 ELSE 0 END), 0) AS with_artifact, "
                ."COALESCE(SUM(CASE WHEN json_extract(classification, '$.classifierVersion') IN ('cribrum-error', 'cribrum-stale') THEN 1 ELSE 0 END), 0) AS attention, "
                ."MAX(classified_at) AS last_classified_at "
                .'FROM http_observation',
            );
            $observations = [
                'total' => (int) $row['total'],
                'withArtifact' => (int) $row['with_artifact'],
                'attention' => (int) $row['attention'],
                'lastClassifiedAt' => is_string($row['last_classified_at']) ? new \DateTimeImmutable($row['last_classified_at']) : null,
            ];
        }

        return [
            'available' => $available,
            'packs' => array_map(static fn (RulePack $pack): array => [
                'id' => $pack->id,
                'version' => $pack->version,
                'rules' => count($pack->rules),
            ], $loaded['packs']),
            'packDiagnostics' => $loaded['diagnostics'],
            'observations' => $observations,
            'limits' => [
                'compressedBytes' => CribrumObservationStore::MAX_COMPRESSED_BYTES,
                'retainedRawBytes' => CribrumObservationStore::RETAINED_RAW_BYTES,
                'retainedDomBytes' => CribrumObservationStore::RETAINED_DOM_BYTES,
            ],
            'privateDirectory' => $this->privateDirectory(),
        ];
    }

    private function privateDirectory(): string
    {
        return dirname(__DIR__, 2).'/var/cribrum/packs';
    }
}
