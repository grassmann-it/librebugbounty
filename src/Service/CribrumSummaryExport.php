<?php

declare(strict_types=1);
namespace App\Service;

use App\Dto\FindingReadView;

/** Bounded structured interpretation; never reads bodies, artifacts or private decision reasons. */
final class CribrumSummaryExport
{
    public static function fromReadView(FindingReadView $finding, array $claims, bool $includeHistory = false): array
    {
        $summary = CribrumFindingSummary::fromReadView($finding);
        if (!$includeHistory) unset($summary['history'], $summary['shadow']);
        foreach (['badges', 'history', 'shadow'] as $bucket) {
            foreach ($summary[$bucket] ?? [] as $index => $badge) {
                unset($badge['filterUrl'], $badge['detailAnchor']);
                $summary[$bucket][$index] = $badge;
            }
        }
        $summary['claims'] = $claims;
        $summary['includesHistory'] = $includeHistory;

        return $summary;
    }

    /** Preserve reference identity inside a portable archive. */
    public static function localizeReferences(array $value, array $references): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) $item = self::localizeReferences($item, $references);
            elseif (is_string($item) && isset($references[$item])) $item = $references[$item];
        }
        unset($item);
        return $value;
    }
}
