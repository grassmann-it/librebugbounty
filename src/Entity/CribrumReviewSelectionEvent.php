<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cribrum_review_selection_event')]
class CribrumReviewSelectionEvent
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(length: 36)]
    public string $selectionId;

    #[ORM\Column]
    public int $revision;

    #[ORM\Column]
    public bool $selected;

    #[ORM\Column(type: 'json')]
    public array $provenance = [];

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;
}
