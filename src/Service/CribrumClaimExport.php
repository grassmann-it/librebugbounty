<?php

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Bounded claim metadata only. HTTP/DOM contents and private decision reasons are never read. */
final class CribrumClaimExport
{
    /** @param list<string> $findingIds @return array<string, list<array<string, mixed>>> */
    public static function forFindings(Connection $connection, array $findingIds): array
    {
        if ($findingIds === [] || !CribrumClaimService::available($connection)) return [];
        $result = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT fc.finding_id, fc.claim, fc.state, fc.report_selected, fc.severity, fc.source, fc.revision, fc.updated_at, '
            .'o.id AS observation_id, o.http_status, o.content_type, o.poc_revision '
            .'FROM finding_claim fc LEFT JOIN http_observation o ON o.id = fc.observation_id AND o.finding_id = fc.finding_id '
            .'WHERE fc.finding_id IN (:ids) ORDER BY fc.finding_id, fc.claim',
            ['ids' => $findingIds], ['ids' => ArrayParameterType::STRING],
        ) as $row) {
            $result[$row['finding_id']][] = [
                'claim' => $row['claim'], 'state' => $row['state'],
                'reportSelected' => (bool) $row['report_selected'],
                'severity' => $row['severity'], 'source' => $row['source'],
                'revision' => (int) $row['revision'],
                'updatedAt' => (new \DateTimeImmutable($row['updated_at']))->format(DATE_ATOM),
                'observation' => $row['observation_id'] === null ? null : [
                    'id' => $row['observation_id'],
                    'httpStatus' => $row['http_status'] === null ? null : (int) $row['http_status'],
                    'contentType' => $row['content_type'], 'pocRevision' => (int) $row['poc_revision'],
                ],
            ];
        }

        return $result;
    }
}
