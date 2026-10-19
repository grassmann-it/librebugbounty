<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'finding_claim_revision')]
#[ORM\Index(name: 'idx_finding_claim_revision_finding', columns: ['finding_id'])]
#[ORM\UniqueConstraint(name: 'uniq_finding_claim_revision', columns: ['finding_id', 'claim', 'revision'])]
#[ORM\Index(name: 'idx_finding_claim_history', columns: ['finding_id', 'claim', 'created_at'])]
class FindingClaimRevision
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(name: 'finding_id', nullable: false, onDelete: 'CASCADE')]
    private Finding $finding;

    #[ORM\Column(length: 48)]
    private string $claim;

    #[ORM\Column]
    private int $revision;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $previousState = null;

    #[ORM\Column(length: 16)]
    private string $state;

    #[ORM\Column]
    private bool $reportSelected;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $severity = null;

    #[ORM\Column(length: 16)]
    private string $source;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $ruleIds = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $observationId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(FindingClaim $current, ?string $previousState, array $ruleIds = [], ?string $reason = null)
    {
        $this->id = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $this->finding = $current->finding;
        $this->claim = $current->claim;
        $this->revision = $current->revision;
        $this->previousState = $previousState;
        $this->state = $current->state;
        $this->reportSelected = $current->reportSelected;
        $this->severity = $current->severity;
        $this->source = $current->source;
        $this->ruleIds = $ruleIds === [] ? null : array_values($ruleIds);
        $this->reason = $reason;
        $this->observationId = $current->observationId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getFinding(): Finding { return $this->finding; }
    public function getClaim(): string { return $this->claim; }
    public function getRevision(): int { return $this->revision; }
    public function getPreviousState(): ?string { return $this->previousState; }
    public function getState(): string { return $this->state; }
    public function getReportSelected(): bool { return $this->reportSelected; }
    public function getSeverity(): ?string { return $this->severity; }
    public function getSource(): string { return $this->source; }
    public function getRuleIds(): ?array { return $this->ruleIds; }
    public function getReason(): ?string { return $this->reason; }
    public function getObservationId(): ?string { return $this->observationId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
