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
        $observationsAvailable=(bool)$connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='http_observation'");
        $reportsAvailable=(bool)$connection->fetchOne("SELECT 1 FROM sqlite_master WHERE name='finding_report_current'");
        $bound=CribrumFindingQuery::observationCurrentSql('fc','f',$observationsAvailable);
        $current=CribrumFindingQuery::claimCurrentSql('fc','f',$reportsAvailable,$observationsAvailable);
        $observationColumns=$observationsAvailable ? "o.id AS observation_id, o.http_status, o.content_type, o.poc_revision, COALESCE(json_extract(CASE WHEN json_valid(o.metadata) THEN o.metadata ELSE '{}' END,'$.observedAt'),o.created_at) AS captured_at " : 'NULL AS observation_id,NULL AS http_status,NULL AS content_type,NULL AS poc_revision,NULL AS captured_at ';
        $observationJoin=$observationsAvailable ? ' LEFT JOIN http_observation o ON o.id = fc.observation_id AND o.finding_id = fc.finding_id ' : ' ';

        foreach ($connection->fetchAllAssociative(
            'SELECT fc.finding_id, fc.claim, fc.state, fc.report_selected, fc.severity, fc.source, fc.revision, fc.updated_at, '
            .$bound.' AS observation_current, '.$current.' AS claim_current, '.$observationColumns
            .'FROM finding_claim fc JOIN finding f ON f.id=fc.finding_id '.$observationJoin
            .'WHERE fc.finding_id IN (:ids) ORDER BY fc.finding_id, fc.claim',
            ['ids' => $findingIds], ['ids' => ArrayParameterType::STRING],
        ) as $row) {
            $result[$row['finding_id']][] = [
                'claim' => $row['claim'], 'state' => $row['state'],
                'reportSelected' => (bool) $row['report_selected'],
                'severity' => $row['severity'], 'source' => $row['source'],
                'revision' => (int) $row['revision'],
                'observationCurrent'=>$row['observation_current']===null?null:(bool)$row['observation_current'],
                'claimCurrent'=>(bool)$row['claim_current'],
                'capturedAt'=>$row['captured_at'],
                'updatedAt' => (new \DateTimeImmutable($row['updated_at']))->format(DATE_ATOM),
                'observation' => $row['observation_id'] === null ? null : [
                    'id' => $row['observation_id'],
                    'capturedAt'=>$row['captured_at'],
                    'httpStatus' => $row['http_status'] === null ? null : (int) $row['http_status'],
                    'contentType' => $row['content_type'], 'pocRevision' => (int) $row['poc_revision'],
                ],
            ];
        }

        return $result;
    }
}
