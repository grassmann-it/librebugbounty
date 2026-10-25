<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cribrum_rule_review_event')]
class CribrumRuleReviewEvent
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(length: 36)]
    public string $hintId;

    #[ORM\Column(length: 16)]
    public string $action;

    #[ORM\Column]
    public int $revision;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $note = null;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;
}
