<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Finding;
use App\Entity\HttpObservation;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Local work coordination and reading receipts; never a security verdict. */
final class SourceReviewService
{
    public const POLICY_VERSION = 'source-review-v1';
    public const OUTCOMES = ['no_new_hint', 'potential_gap', 'corrected_gap', 'context_required'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CribrumObservationStore $observations,
    ) {}

    public function available(): bool
    {
        $connection = $this->entityManager->getConnection();
        return (bool) $connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='source_review'")
            && (bool) $connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type='table' AND name='source_review_lease'");
    }

    /** @return list<array<string,mixed>> Private local references, never target requests. */
    public function claim(string $reviewer, int $limit = 1, ?string $findingId = null, int $leaseSeconds = 900): array
    {
        $reviewer = trim($reviewer);
        if (!$this->label($reviewer) || $limit < 1 || $limit > 20 || $leaseSeconds < 30 || $leaseSeconds > 3600
            || ($findingId !== null && !Uuid::isValid($findingId))) throw new \InvalidArgumentException('Ungültige Angaben zur KI-Quellensichtung.');
        $this->requireAvailable();
        $connection = $this->entityManager->getConnection();
        return $connection->transactional(function () use ($connection, $reviewer, $limit, $findingId, $leaseSeconds): array {
            $this->reserveWriter($connection);
            $parameters = $findingId === null ? [] : [$findingId];
            $ids = $connection->fetchFirstColumn(
                "SELECT f.id FROM finding f WHERE f.status NOT IN ('discarded','duplicate') AND (f.manual_assessment IS NULL OR f.manual_assessment<>'discarded') "
                .($findingId === null ? '' : 'AND f.id=? ')
                .'AND EXISTS (SELECT 1 FROM http_observation o WHERE o.finding_id=f.id AND o.poc_revision=f.poc_revision AND o.artifact_path IS NOT NULL) ORDER BY f.created_at DESC,f.id DESC',
                $parameters,
            );
            $now = $this->now();
            $claims = [];
            foreach ($ids as $id) {
                $source = $this->currentSource($id, false);
                if ($source === null || $this->review($source) !== null) continue;
                $lease = $this->lease($source);
                if ($lease !== null && $lease['expires_at'] > $now) continue;
                // Hash authentication happens only for a source about to be claimed.
                // Completed or already leased sources do not need another body read.
                if (!$this->authenticate($source['observation'])) continue;
                $token = bin2hex(random_bytes(32));
                $expires = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->modify('+'.$leaseSeconds.' seconds')->format('Y-m-d H:i:s');
                $row = $this->identityRow($source) + ['id' => Uuid::v7()->toRfc4122(), 'reviewer' => $reviewer, 'lease_token' => $token, 'created_at' => $now, 'expires_at' => $expires];
                if ($lease === null) {
                    $connection->insert('source_review_lease', $row);
                } else {
                    unset($row['id']);
                    $connection->update('source_review_lease', $row, ['id' => $lease['id']]);
                }
                $observation = $source['observation'];
                $claims[] = $this->sourceArray($source) + [
                    'leaseToken' => $token, 'leaseExpiresAt' => $this->iso($expires), 'reviewer' => $reviewer,
                    'artifactPath' => $observation->getArtifactPath(),
                    'classificationContext' => $observation->getMetadata()['classificationContext'] ?? null,
                    'requestContextSha256' => $source['requestContextSha256'],
                    'httpStatus' => $observation->getHttpStatus(), 'contentType' => $observation->getContentType(),
                    'finalUrl' => $observation->getFinalUrl(), 'rawTruncated' => $observation->getRawTruncated(),
                    'domTruncated' => $observation->getDomTruncated(),
                ];
                if (count($claims) >= $limit) break;
            }
            return $claims;
        });
    }

    /** A caller must actually inspect the claimed source before recording this receipt. */
    public function record(string $leaseToken, string $outcome, string $summary, string $classifierVersion): array
    {
        $summary = trim($summary);
        if (preg_match('/^[a-f0-9]{64}$/D', $leaseToken) !== 1 || !in_array($outcome, self::OUTCOMES, true)
            || $summary === '' || !mb_check_encoding($summary, 'UTF-8') || mb_strlen($summary) > 4000
            || preg_match('~^[A-Za-z0-9_.:/-]{1,128}$~D', $classifierVersion) !== 1) throw new \InvalidArgumentException('Ungültige Angaben zur KI-Quellensichtung.');
        $this->requireAvailable();
        $connection = $this->entityManager->getConnection();
        return $connection->transactional(function () use ($connection, $leaseToken, $outcome, $summary, $classifierVersion): array {
            $this->reserveWriter($connection);
            $completed = $connection->fetchAssociative('SELECT * FROM source_review WHERE lease_token=?', [$leaseToken]);
            if ($completed) {
                if ($completed['outcome'] !== $outcome || $completed['summary'] !== $summary || $completed['classifier_version'] !== $classifierVersion) {
                    throw new \UnexpectedValueException('Die abgeschlossene Quellensichtung ist unveränderlich.');
                }
                return ['status' => 'recorded'] + $this->reviewArray($completed);
            }
            $lease = $connection->fetchAssociative('SELECT * FROM source_review_lease WHERE lease_token=?', [$leaseToken]);
            $now = $this->now();
            if (!$lease || $lease['expires_at'] <= $now) throw new \UnexpectedValueException('Die Reservierung ist abgelaufen oder nicht mehr gültig.');
            $source = $this->currentSource($lease['finding_id'], true);
            if ($source === null || !$this->sameIdentity($source, $lease)) throw new \UnexpectedValueException('Die gespeicherte Quelle oder ihr Anfragekontext hat sich geändert.');
            if ($this->review($source) !== null) throw new \UnexpectedValueException('Diese Quelle wurde bereits gesichtet.');
            $row = $this->identityRow($source) + [
                'id' => Uuid::v7()->toRfc4122(), 'reviewer' => $lease['reviewer'], 'lease_token' => $leaseToken,
                'outcome' => $outcome, 'summary' => $summary, 'classifier_version' => $classifierVersion, 'reviewed_at' => $now,
            ];
            // A local reclassification may reference the same bytes with a new
            // observation ID. Retain the observation the reviewer actually claimed.
            $row['observation_id'] = $lease['observation_id'];
            $connection->insert('source_review', $row);
            $connection->delete('source_review_lease', ['id' => $lease['id']]);
            return ['status' => 'recorded'] + $this->reviewArray($row);
        });
    }

    /** Read only: a missing migration, body or request pin never produces a reviewed badge. */
    public function readCurrentForFinding(Finding|string $finding): array
    {
        $id = $finding instanceof Finding ? $finding->getId() : $finding;
        $base = ['findingId' => $id, 'policyVersion' => self::POLICY_VERSION];
        if (!$this->available()) return ['status' => 'unavailable', 'reason' => 'migration_required'] + $base;
        $source = $this->currentSource($id, true);
        if ($source === null) return ['status' => 'unavailable', 'reason' => 'no_authenticated_current_source'] + $base;
        $base = $this->sourceArray($source);
        if (($review = $this->review($source)) !== null) return ['status' => 'current'] + $this->reviewArray($review) + $base;
        if (($lease = $this->lease($source)) !== null && $lease['expires_at'] > $this->now()) {
            return ['status' => 'pending', 'reviewer' => $lease['reviewer'], 'leaseExpiresAt' => $this->iso($lease['expires_at'])] + $base;
        }
        $previous = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM source_review WHERE finding_id=? ORDER BY reviewed_at DESC,id DESC LIMIT 1', [$id]);
        return ($previous ? ['status' => 'stale', 'previousReview' => $this->reviewArray($previous)] : ['status' => 'open']) + $base;
    }

    private function currentSource(string $findingId, bool $authenticate): ?array
    {
        if (!Uuid::isValid($findingId)) return null;
        $finding = $this->entityManager->find(Finding::class, $findingId);
        if (!$finding instanceof Finding) return null;
        $this->entityManager->refresh($finding);
        $id = $this->entityManager->getConnection()->fetchOne('SELECT id FROM http_observation WHERE finding_id=? AND poc_revision=? ORDER BY created_at DESC,id DESC LIMIT 1', [$findingId, $finding->getPocRevision()]);
        $observation = $id ? $this->entityManager->find(HttpObservation::class, $id) : null;
        if (!$observation instanceof HttpObservation) return null;
        $this->entityManager->refresh($observation);
        if (!$this->observations->matchesCurrentRequest($finding, $observation) || $observation->getArtifactPath() === null
            || preg_match('/^[a-f0-9]{64}$/D', $observation->getArtifactSha256() ?? '') !== 1) return null;
        if ($authenticate && !$this->authenticate($observation)) return null;
        $requestHash = WorkerProofResolver::requestContextSha256($finding);
        $context = hash('sha256', json_encode([
            $finding->getPocRevision(), $finding->getPocUrl(), $requestHash,
            $observation->getHttpStatus(), $observation->getContentType(), $observation->getFinalUrl(),
            $observation->getRawTruncated(), $observation->getDomTruncated(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return [
            'finding' => $finding, 'observation' => $observation, 'findingId' => $findingId,
            'artifactSha256' => $observation->getArtifactSha256(), 'contextSha256' => $context,
            'requestContextSha256' => $requestHash, 'pocRevision' => $finding->getPocRevision(),
            'pocUrlSha256' => hash('sha256', $finding->getPocUrl()),
        ];
    }

    private function identityRow(array $source): array
    {
        return ['finding_id' => $source['findingId'], 'observation_id' => $source['observation']->getId(),
            'artifact_sha256' => $source['artifactSha256'], 'context_sha256' => $source['contextSha256'],
            'policy_version' => self::POLICY_VERSION, 'poc_revision' => $source['pocRevision'],
            'request_context_sha256' => $source['requestContextSha256'], 'poc_url_sha256' => $source['pocUrlSha256']];
    }

    private function sourceArray(array $source): array
    {
        return ['findingId' => $source['findingId'], 'observationId' => $source['observation']->getId(),
            'artifactSha256' => $source['artifactSha256'], 'contextSha256' => $source['contextSha256'],
            'policyVersion' => self::POLICY_VERSION, 'pocRevision' => $source['pocRevision'],
            'url' => $source['finding']->getPocUrl()];
    }

    private function review(array $source): ?array { return $this->identityLookup('source_review', $source); }
    private function lease(array $source): ?array { return $this->identityLookup('source_review_lease', $source); }

    private function identityLookup(string $table, array $source): ?array
    {
        return $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM '.$table.' WHERE finding_id=? AND artifact_sha256=? AND context_sha256=? AND policy_version=?',
            [$source['findingId'], $source['artifactSha256'], $source['contextSha256'], self::POLICY_VERSION]) ?: null;
    }

    private function sameIdentity(array $source, array $row): bool
    {
        return $source['findingId'] === $row['finding_id'] && $source['artifactSha256'] === $row['artifact_sha256']
            && $source['contextSha256'] === $row['context_sha256'] && $row['policy_version'] === self::POLICY_VERSION;
    }

    private function reviewArray(array $row): array
    {
        return ['reviewId' => $row['id'], 'findingId' => $row['finding_id'], 'observationId' => $row['observation_id'],
            'artifactSha256' => $row['artifact_sha256'], 'contextSha256' => $row['context_sha256'],
            'policyVersion' => $row['policy_version'], 'reviewer' => $row['reviewer'], 'outcome' => $row['outcome'],
            'summary' => $row['summary'], 'classifierVersion' => $row['classifier_version'], 'reviewedAt' => $this->iso($row['reviewed_at'])];
    }

    private function reserveWriter(Connection $connection): void { $connection->executeStatement('UPDATE source_review_lease SET id=id WHERE 1=0'); }
    private function authenticate(HttpObservation $observation): bool
    {
        try {
            $source = $this->observations->load($observation);
            return $source['rawHtml'] !== null || $source['domText'] !== null;
        } catch (\Throwable) { return false; }
    }
    private function requireAvailable(): void { if (!$this->available()) throw new \UnexpectedValueException('Die KI-Quellensichtung benötigt die Datenbankaktualisierung.'); }
    private function now(): string { return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'); }
    private function iso(string $value): string { return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(DATE_ATOM); }
    private function label(string $value): bool { return $value !== '' && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= 128 && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1; }
}
