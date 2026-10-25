<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Finding;
use Doctrine\DBAL\Connection;

/** E2 enrollment is an explicit operation against one frozen manifest, never a type query. */
final class LegacyCohortService
{
    public const COHORT_ID = 'legacy-xss-2026-10-22';
    public const DIGEST = '3985501a9e3375af4fb492dc92ba8e3472312c0023aabc586343b331137743b7';
    public const COUNT = 6968;

    public function __construct(private readonly Connection $connection) {}

    public static function validateManifest(array $manifest): array
    {
        $members = $manifest['members'] ?? null;
        if (($manifest['schemaVersion'] ?? null) !== 1 || ($manifest['cohortId'] ?? null) !== self::COHORT_ID
            || ($manifest['expectedCount'] ?? null) !== self::COUNT || ($manifest['membersSha256'] ?? null) !== self::DIGEST
            || !is_array($members) || count($members) !== self::COUNT) {
            throw new \InvalidArgumentException('Not the authorized fixed E2 manifest.');
        }
        $seen = [];
        foreach ($members as &$member) {
            if (!is_array($member) || !is_string($member['findingId'] ?? null)
                || isset($seen[$member['findingId']]) || !is_int($member['pocRevision'] ?? null)
                || $member['pocRevision'] < 0 || !is_int($member['claimRevision'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $member['pocSha256'] ?? '')) {
                throw new \InvalidArgumentException('Invalid or duplicate E2 member.');
            }
            $seen[$member['findingId']] = true;
            ksort($member, SORT_STRING);
        }
        unset($member);
        usort($members, static fn (array $a, array $b): int => strcmp($a['findingId'], $b['findingId']));
        if (!hash_equals(self::DIGEST, hash('sha256', self::json($members)))) {
            throw new \InvalidArgumentException('E2 membership digest mismatch.');
        }
        return $members;
    }

    /** Returns differences; missing findings are never replaced or enrolled later automatically. */
    public function importManifest(string $path, string $sourceInstanceId): array
    {
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $members = self::validateManifest($manifest);
        return $this->connection->transactional(function () use ($members, $sourceInstanceId): array {
            $actual = $this->connection->fetchOne('SELECT source_instance_id FROM report_source_contract LIMIT 1');
            if ($sourceInstanceId === '' || $actual !== $sourceInstanceId) {
                throw new \InvalidArgumentException('Explicit target source identity does not match.');
            }
            $this->connection->update('report_source_contract', ['legacy_cohort_id' => self::COHORT_ID, 'legacy_cohort_digest' => self::DIGEST], ['source_instance_id' => $sourceInstanceId]);
            $result = ['cohortId' => self::COHORT_ID, 'cohortDigest' => self::DIGEST, 'imported' => 0, 'missing' => [], 'pocChanged' => []];
            foreach ($members as $member) {
                $row = $this->connection->fetchAssociative('SELECT f.poc_revision, COALESCE(f.poc_url,f.url) AS poc, c.legacy_basis_json FROM finding f LEFT JOIN finding_report_context c ON c.finding_id=f.id WHERE f.id=?', [$member['findingId']]);
                if ($row === false) { $result['missing'][] = $member['findingId']; continue; }
                $basis = ['cohortId' => self::COHORT_ID, 'cohortDigest' => self::DIGEST, 'sourceInstanceId' => $sourceInstanceId,
                    'findingId' => $member['findingId'], 'baselinePocRevision' => $member['pocRevision'],
                    'baselinePocSha256' => $member['pocSha256'], 'member' => $member];
                $json = self::json($basis);
                if ($row['legacy_basis_json'] !== null && $row['legacy_basis_json'] !== $json) {
                    throw new \RuntimeException('Existing immutable E2 baseline differs.');
                }
                $this->connection->executeStatement('INSERT OR IGNORE INTO finding_report_context(finding_id,basis_revision) VALUES (?,1)', [$member['findingId']]);
                $updated = $this->connection->executeStatement('UPDATE finding_report_context SET legacy_basis_json=?, legacy_basis_sha256=?, basis_revision=basis_revision+1 WHERE finding_id=? AND legacy_basis_json IS NULL', [$json, hash('sha256', $json), $member['findingId']]);
                if ($updated === 0 && $row['legacy_basis_json'] === null) { $result['missing'][] = $member['findingId']; continue; }
                $result['imported']++;
                if ((int) $row['poc_revision'] !== $member['pocRevision'] || hash('sha256', $row['poc']) !== $member['pocSha256']) { $result['pocChanged'][] = $member['findingId']; }
            }
            return $result;
        });
    }

    public function resolve(Finding $finding, string $sourceInstanceId): ?array
    {
        if (!in_array('finding_report_context', $this->connection->createSchemaManager()->listTableNames(), true)) { return null; }
        $row = $this->connection->fetchAssociative('SELECT legacy_basis_json,legacy_basis_sha256 FROM finding_report_context WHERE finding_id=?', [$finding->getId()]);
        if ($row === false || !is_string($row['legacy_basis_json'])) { return null; }
        $json = $row['legacy_basis_json'];
        if (!hash_equals(hash('sha256', $json), (string) $row['legacy_basis_sha256'])) { return null; }
        $basis = json_decode($json, true);
        if (!is_array($basis) || ($basis['cohortId'] ?? null) !== self::COHORT_ID || ($basis['cohortDigest'] ?? null) !== self::DIGEST
            || ($basis['sourceInstanceId'] ?? null) !== $sourceInstanceId || ($basis['findingId'] ?? null) !== $finding->getId()
            || ($basis['member']['findingId'] ?? null) !== $finding->getId()
            || ($basis['member']['pocRevision'] ?? null) !== ($basis['baselinePocRevision'] ?? null)
            || ($basis['member']['pocSha256'] ?? null) !== ($basis['baselinePocSha256'] ?? null)) { return null; }
        // Membership remains verified after a PoC edit; the policy separately holds
        // the changed PoC instead of silently losing the historical E2 context.
        $basis['verified'] = true;
        $basis['pocMatches'] = ($basis['baselinePocRevision'] ?? null) === $finding->getPocRevision()
            && ($basis['baselinePocSha256'] ?? null) === hash('sha256', $finding->getPocUrl());
        $basis['contentSha256'] = hash('sha256', $json);
        return $basis;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
