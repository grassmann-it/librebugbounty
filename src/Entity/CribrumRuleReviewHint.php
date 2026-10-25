<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cribrum_rule_review_hint')]
#[ORM\UniqueConstraint(name: 'uniq_cribrum_rule_review_hint', columns: ['finding_id', 'claim', 'rule_id', 'observation_id', 'classifier_version', 'pack_version'])]
class CribrumRuleReviewHint
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(length: 36)]
    public string $findingId;

    #[ORM\Column(length: 48)]
    public string $claim;

    #[ORM\Column(length: 128)]
    public string $ruleId;

    #[ORM\Column(length: 36)]
    public string $observationId;

    #[ORM\Column(length: 128)]
    public string $classifierVersion;

    #[ORM\Column(length: 128)]
    public string $packVersion;

    #[ORM\Column(type: 'json')]
    public array $evidenceIds = [];

    #[ORM\Column(length: 16)]
    public string $status = 'pending';

    #[ORM\Column]
    public int $revision = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $note = null;
}
