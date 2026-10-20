<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Read-only recovery state; historical artifacts remain the audit's responsibility. */
final class CurrentPocImageService
{
    public function __construct(private readonly Connection $connection, private readonly StoredImageInspector $images) {}

    /** @return array<string, array<string, mixed>> Missing image problems, keyed by finding ID. */
    public function missing(): array
    {
        $findings = $this->connection->fetchAllAssociative('SELECT id, url, poc_url, poc_revision, created_at FROM finding');
        $evidence = $jobs = $runs = [];
        foreach ($this->connection->fetchAllAssociative("SELECT id, finding_id, file_path, created_at FROM evidence WHERE kind = 'screenshot' ORDER BY created_at DESC, rowid DESC") as $row) $evidence[$row['finding_id']][] = $row;
        foreach ($this->connection->fetchAllAssociative('SELECT finding_id, url, screenshot_path, capture_metadata, requested_at FROM screenshot_job') as $row) $jobs[$row['finding_id']][] = $row;
        foreach ($this->connection->fetchAllAssociative('SELECT finding_id, screenshot_path, raw_result, started_at FROM retest_run WHERE screenshot_path IS NOT NULL') as $row) $runs[$row['finding_id']][] = $row;
        $missing = [];
        foreach ($findings as $finding) {
            $id = $finding['id'];
            $candidates = $boundPaths = [];
            foreach ($jobs[$id] ?? [] as $job) {
                $raw = json_decode($job['capture_metadata'] ?? '{}', true);
                $raw = is_array($raw) ? $raw : [];
                if ($job['screenshot_path']) $boundPaths[$job['screenshot_path']] = true;
                if (!($raw['stalePoc'] ?? false) && !($raw['staleFindingContext'] ?? false)
                    && (int) ($raw['queuedPocRevision'] ?? 0) === (int) $finding['poc_revision']
                    && ($raw['pocUrl'] ?? $job['url']) === ($finding['poc_url'] ?? $finding['url'])) {
                    $candidates[] = $job['screenshot_path'];
                }
            }
            foreach ($runs[$id] ?? [] as $run) {
                $boundPaths[$run['screenshot_path']] = true;
                if (PocObservation::current($run, $finding)) $candidates[] = $run['screenshot_path'];
            }
            // Unbound legacy evidence is attributable only to the original PoC.
            foreach ($evidence[$id] ?? [] as $image) {
                if ((int) $finding['poc_revision'] === 0 && ($finding['poc_url'] ?? $finding['url']) === $finding['url'] && !isset($boundPaths[$image['file_path'] ?? ''])) $candidates[] = $image['file_path'];
            }
            $readable = false;
            foreach (array_unique($candidates) as $path) {
                if ($this->images->problem($path) === null) { $readable = true; break; }
            }
            if ($readable || (!isset($evidence[$id]) && !isset($jobs[$id]) && !isset($runs[$id]))) continue;
            $example = $evidence[$id][0] ?? null;
            $missing[$id] = [
                'message' => null, 'at' => $example['created_at'] ?? $finding['created_at'],
                'reason' => 'missing', 'evidenceId' => null, 'count' => 0,
            ];
            foreach ($evidence[$id] ?? [] as $image) {
                $problem = $this->images->problem($image['file_path']);
                if ($problem !== null) {
                    if ($missing[$id]['count'] === 0) $missing[$id] = array_replace($missing[$id], ['reason' => $problem, 'evidenceId' => $image['id'], 'at' => $image['created_at']]);
                    ++$missing[$id]['count'];
                }
            }
        }
        return $missing;
    }
}
