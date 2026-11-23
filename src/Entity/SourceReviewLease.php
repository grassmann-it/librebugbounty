<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Expiring work reservation; it is never evidence that a source was reviewed. */
#[ORM\Entity]
#[ORM\Table(name: 'source_review_lease')]
#[ORM\UniqueConstraint(name: 'uniq_source_review_lease_identity', columns: ['finding_id', 'artifact_sha256', 'context_sha256', 'policy_version'])]
#[ORM\UniqueConstraint(name: 'uniq_source_review_lease_token', columns: ['lease_token'])]
#[ORM\Index(name: 'idx_source_review_lease_expiry', columns: ['expires_at'])]
class SourceReviewLease
{
    #[ORM\Id, ORM\Column(length: 36)] public string $id;
    #[ORM\ManyToOne(targetEntity: Finding::class), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] public Finding $finding;
    #[ORM\Column(length: 36)] public string $observationId;
    #[ORM\Column(length: 64)] public string $artifactSha256;
    #[ORM\Column(length: 64)] public string $contextSha256;
    #[ORM\Column(length: 32)] public string $policyVersion;
    #[ORM\Column] public int $pocRevision;
    #[ORM\Column(length: 64)] public string $requestContextSha256;
    #[ORM\Column(length: 64)] public string $pocUrlSha256;
    #[ORM\Column(length: 128)] public string $reviewer;
    #[ORM\Column(length: 64)] public string $leaseToken;
    #[ORM\Column(type: 'datetime_immutable')] public \DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'datetime_immutable')] public \DateTimeImmutable $expiresAt;
}
