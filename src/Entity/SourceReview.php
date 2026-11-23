<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A source-reading receipt, separate from security claims and human assessments. */
#[ORM\Entity]
#[ORM\Table(name: 'source_review')]
#[ORM\UniqueConstraint(name: 'uniq_source_review_identity', columns: ['finding_id', 'artifact_sha256', 'context_sha256', 'policy_version'])]
#[ORM\UniqueConstraint(name: 'uniq_source_review_token', columns: ['lease_token'])]
#[ORM\Index(name: 'idx_source_review_finding', columns: ['finding_id', 'reviewed_at'])]
class SourceReview
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
    #[ORM\Column(length: 32)] public string $outcome;
    #[ORM\Column(type: 'text')] public string $summary;
    #[ORM\Column(length: 128)] public string $classifierVersion;
    #[ORM\Column(length: 64)] public string $leaseToken;
    #[ORM\Column(type: 'datetime_immutable')] public \DateTimeImmutable $reviewedAt;
}
