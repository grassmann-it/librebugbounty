<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'finding_claim')]
#[ORM\Index(name: 'idx_finding_claim_finding', columns: ['finding_id'])]
#[ORM\Index(name: 'idx_finding_claim_state', columns: ['claim', 'state'])]
#[ORM\UniqueConstraint(name: 'pk_finding_claim', columns: ['finding_id', 'claim'])]
#[ORM\HasLifecycleCallbacks]
class FindingClaim
{
    public const CLAIMS = [
        'xss',
        'sql_injection',
        'local_file_inclusion',
        'remote_file_inclusion',
        'information_disclosure',
        'error_disclosure',
        'stack_trace',
    ];

    public const STATES = ['observed', 'suspected', 'confirmed', 'rejected', 'stale', 'resolved'];

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(name: 'finding_id', nullable: false, onDelete: 'CASCADE')]
    public Finding $finding;

    #[ORM\Id]
    #[ORM\Column(length: 48)]
    public string $claim;

    #[ORM\Column(length: 16)]
    public string $state;

    #[ORM\Column]
    public bool $reportSelected = false;

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $severity = null;

    #[ORM\Column(length: 16)]
    public string $source;

    #[ORM\Column]
    public int $revision = 1;

    #[ORM\Column(length: 36, nullable: true)]
    public ?string $observationId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function initializeTimestamps(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt ??= $now;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
