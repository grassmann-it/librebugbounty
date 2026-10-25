<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Shared SQLite contract also consumed by report-gen; mirrors review notice semantics. */
final class CribrumNewFindingsQuery
{
    public static function predicate(Connection $connection): string
    {
        if (!(new ReviewSchema($connection))->available() || !$connection->fetchOne("SELECT 1 FROM sqlite_master WHERE name='finding_report_current'")) return '0';
        $contract = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/cribrum/new-findings-query-v1.json'), true, 512, JSON_THROW_ON_ERROR);
        $conditions = [$contract['waf']];
        if ($connection->fetchOne("SELECT 1 FROM sqlite_master WHERE name='finding_report_revision'")) $conditions[] = $contract['semantic'];
        return strtr('('.$contract['gates'].' AND ('.implode(' OR ', $conditions).'))', [
            '{{pursuit_active}}' => FindingWorkPolicy::pursuitActiveSql($connection),
            '{{review_active}}' => ReviewDispositionService::activeSql($connection),
            '{{assessment_active}}' => AssessmentHistoryProjection::activeSql($connection),
        ]);
    }
}
