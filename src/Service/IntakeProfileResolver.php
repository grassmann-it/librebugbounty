<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Finding;

/** Intake is intent and provenance, never an execution credential. */
final class IntakeProfileResolver
{
    public function resolve(Finding $finding, ?array $storedProfile = null): array
    {
        $profile = [
            'schemaVersion' => 1, 'sourceKind' => 'manual', 'sourceRef' => null,
            'declaredClaims' => [], 'payloadKind' => 'unknown', 'payloadSource' => null,
            'markerSource' => $finding->getExpectedEvidence() === null ? null : 'finding.expected_evidence:legacy_adapter',
            'legacyCohortId' => null,
        ];
        if ($storedProfile === null || ($storedProfile['schemaVersion'] ?? null) !== 1) {
            return $profile;
        }
        if (in_array($storedProfile['sourceKind'] ?? null, ['manual', 'browser_intake', 'checker_import', 'file_import', 'legacy_snapshot'], true)) {
            $profile['sourceKind'] = $storedProfile['sourceKind'];
        }
        foreach (['sourceRef', 'payloadSource', 'markerSource', 'legacyCohortId'] as $key) {
            $profile[$key] = is_string($storedProfile[$key] ?? null) ? $storedProfile[$key] : null;
        }
        $allowed = array_keys(\App\Value\ClaimLabels::CLAIMS);
        $declared = is_array($storedProfile['declaredClaims'] ?? null) ? $storedProfile['declaredClaims'] : [];
        $declared = array_map(static fn ($claim) => $claim === 'reflected_xss' ? 'xss' : $claim, $declared);
        $profile['declaredClaims'] = array_values(array_unique(array_intersect($allowed, $declared)));
        if (in_array($storedProfile['payloadKind'] ?? null, ['xss', 'sql', 'other', 'unknown'], true)) {
            $profile['payloadKind'] = $storedProfile['payloadKind'];
        }
        return $profile;
    }
}
