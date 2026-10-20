<?php

namespace App\Service;

use App\Entity\Finding;
use App\Repository\FindingRepository;
use Doctrine\DBAL\Connection;

/** Explicit queue requests only; never captures or changes assessments/history. */
final class MissingImageRecoveryService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FindingProblemService $problems,
        private readonly FindingRepository $findings,
        private readonly ScreenshotEnqueuerInterface $queue,
    ) {}

    /** @return array{queued: int, alreadyActive: int, skipped: int} */
    public function recover(array $query, ?string $id = null, ?string $url = null, ?int $revision = null): array
    {
        $result = ['queued' => 0, 'alreadyActive' => 0, 'skipped' => 0];
        // Same filters as the overview, across all result pages. Never trust posted IDs.
        foreach ($this->problems->get($query, false)['rows'] as $row) {
            if (!isset($row['problems']['missing']) || ($id !== null && $id !== $row['id'])) continue;
            $outcome = (function () use ($row, $url, $revision): string {
                $current = $this->connection->fetchAssociative('SELECT COALESCE(poc_url, url) AS url, poc_revision, status, manual_assessment FROM finding WHERE id = ?', [$row['id']]);
                if (!$current || $current['url'] !== ($url ?? $row['url'])
                    || (int) $current['poc_revision'] !== ($revision ?? (int) $row['poc_revision'])
                    || in_array($current['status'], ['discarded', 'duplicate'], true) || $current['manual_assessment'] === 'discarded'
                    || ReviewDispositionService::isDeferred($this->connection, $row['id'])) return 'skipped';
                $finding = $this->findings->find($row['id']);
                if (!$finding instanceof Finding || !(new FindingWorkPolicy($this->connection))->checksAllowed($finding)) return 'skipped';
                // The queue rechecks policy and PoC under its own writer lock;
                // its workers also fence deferral changes before accepting results.
                try {
                    $queued = $this->queue->enqueue($finding);
                } catch (\LogicException) {
                    return 'skipped';
                }
                return $queued->created ? 'queued' : 'alreadyActive';
            })();
            ++$result[$outcome];
        }
        return $result;
    }
}
