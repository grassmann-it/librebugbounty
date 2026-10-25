<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Finding;
use Doctrine\DBAL\Connection;

/** Reads independent browser sources; a body or image file is not required. */
final class WorkerProofResolver
{
    public const MATCH_POLICY = 'native_marker_cs_substring_v1';

    public function __construct(private readonly Connection $connection) {}

    /** Capture this before browser work; never reconstruct the marker from the current finding. */
    public static function captureContext(Finding $finding): array
    {
        return ['findingId' => $finding->getId(), 'pocUrl' => $finding->getPocUrl(),
            'pocRevision' => $finding->getPocRevision(), 'pocSha256' => hash('sha256', $finding->getPocUrl()),
            'expectedMarker' => $finding->getExpectedEvidence(),
            'requestContextSha256' => self::requestContextSha256($finding)];
    }

    /** Exact request inputs; preserve array order, nulls and original UTF-8 bytes. */
    public static function requestContextSha256(Finding $finding): string
    {
        return self::requestContextHashFromValues($finding->getMethod(), $finding->getRequestParams(), $finding->getPayload(), $finding->getExpectedEvidence());
    }

    public static function requestContextHashFromValues(string $method, ?array $requestParams, ?string $payload, ?string $expectedEvidence): string
    {
        return hash('sha256', json_encode([$method, $requestParams, $payload, $expectedEvidence], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function resolve(Finding $finding, string $sourceInstanceId): ?array
    {
        $candidates = $this->candidates($finding, $sourceInstanceId);
        foreach ($candidates as $proof) if ($proof['verified']) return $proof;
        if (in_array('finding_report_context', $this->connection->createSchemaManager()->listTableNames(), true)) {
            $raw = $this->connection->fetchOne('SELECT acknowledgements_json FROM finding_report_context WHERE finding_id=?', [$finding->getId()]);
            $reviews = $raw ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
            foreach ($candidates as $proof) foreach ($reviews as $review) {
                if (($review['kind'] ?? '') !== 'worker_proof' || ($review['proofId'] ?? null) !== $proof['proofId']
                    || ($review['contentSha256'] ?? null) !== $proof['contentSha256'] || ($proof['attribution'] ?? '') !== 'matched'
                    || !in_array($proof['dialogOrigin'], ['native', 'hook'], true) || $proof['dialogText'] === ''
                    || empty($review['actor']) || empty($review['reason']) || ($review['explicit'] ?? false) !== true) continue;
                $proof['matchPolicy'] = 'reviewed_worker_proof_v1';
                $proof['review'] = $review; $proof['verified'] = true;
                $proof['contentSha256'] = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return $proof;
            }
        }
        return $candidates[0] ?? null;
    }

    /** Actual individual worker events, never payload or concatenated message text. */
    public function candidates(Finding $finding, string $sourceInstanceId): array
    {
        $candidates = [];
        $tables = $this->connection->createSchemaManager()->listTableNames();
        foreach ([['screenshot_job', 'capture_metadata', 'captured_at', 'screenshot_job'], ['retest_run', 'raw_result', 'finished_at', 'retest_run']] as [$table, $column, $time, $kind]) {
            if (!in_array($table, $tables, true)) { continue; }
            $rows = $this->connection->fetchAllAssociative("SELECT * FROM $table WHERE finding_id = ? AND $time IS NOT NULL ORDER BY $time DESC, id DESC", [$finding->getId()]);
            foreach ($rows as $row) {
                $raw = json_decode((string) ($row[$column] ?? ''), true);
                if (!is_array($raw)) { continue; }
                $events = [];
                foreach (['dialogEvents' => 'native', 'hookedDialogEvents' => 'hook'] as $key => $origin) {
                    foreach (is_array($raw[$key] ?? null) ? $raw[$key] : [] as $index => $event) {
                        if (is_array($event) && is_string($event['message'] ?? null)) {
                            $events[] = [$event['message'], $origin, $key.':'.$index];
                        }
                    }
                }
                // A separately stored dialog with explicit provenance is also supported.
                if ($events === [] && is_string($raw['dialogText'] ?? null)) {
                    $events[] = [$raw['dialogText'], in_array($raw['dialogOrigin'] ?? null, ['native', 'hook'], true) ? $raw['dialogOrigin'] : 'unknown', 'dialogText'];
                }
                foreach ($events as [$text, $origin, $eventId]) {
                    $proof = $this->fromEvent($finding, $sourceInstanceId, $kind, (string) $row['id'], (string) $row[$time], $raw, $text, $origin, $eventId);
                    $candidates[] = $proof;
                }
            }
        }
        return $candidates;
    }

    private function fromEvent(Finding $finding, string $source, string $kind, string $id, string $at, array $raw, string $text, string $origin, string $event): array
    {
        $context = is_array($raw['proofContext'] ?? null) ? $raw['proofContext'] : [];
        $marker = $context['expectedMarker'] ?? null;
        $url = $context['pocUrl'] ?? $raw['pocUrl'] ?? $raw['requestedUrl'] ?? null;
        $revision = $context['pocRevision'] ?? $raw['pocRevision'] ?? $raw['queuedPocRevision'] ?? null;
        $hash = is_string($url) ? hash('sha256', $url) : null;
        $bound = ($context['findingId'] ?? null) === $finding->getId()
            && $url === $finding->getPocUrl() && $revision === $finding->getPocRevision()
            && ($context['pocSha256'] ?? null) === $hash
            && $marker === $finding->getExpectedEvidence()
            && ($context['requestContextSha256'] ?? null) === self::requestContextSha256($finding)
            && !($raw['stalePoc'] ?? false) && !($raw['staleFindingContext'] ?? false);
        $matched = is_string($marker) && self::markerMatches($marker, $text);
        $proof = ['proofId' => hash('sha256', $kind.':'.$id.':'.$event), 'sourceKind' => $kind,
            'sourceId' => $id, 'sourceInstanceId' => $source, 'findingId' => $finding->getId(),
            'pocRevision' => $revision, 'pocSha256' => $hash, 'capturedAt' => $at,
            'requestContextSha256' => $context['requestContextSha256'] ?? null,
            'expectedMarker' => $marker, 'dialogText' => $text, 'dialogOrigin' => $origin,
            'sourcePageUrl' => $url, 'attribution' => $bound ? 'matched' : 'uncertain',
            'matchPolicy' => self::MATCH_POLICY, 'verified' => $bound && $matched && $origin === 'native'];
        $proof['contentSha256'] = hash('sha256', json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $proof;
    }

    public static function markerMatches(string $marker, string $dialog): bool
    {
        $chars = preg_split('//u', $marker, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) && count($chars) >= 3 && count(array_unique($chars)) >= 2 && str_contains($dialog, $marker);
    }
}
