<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cribrum_review_selection')]
#[ORM\UniqueConstraint(name: 'uniq_cribrum_review_selection', columns: ['finding_id', 'option_key', 'observation_id'])]
class CribrumReviewSelection
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    public string $id;

    #[ORM\Column(length: 36)]
    public string $findingId;

    #[ORM\Column(length: 128)]
    public string $optionKey;

    #[ORM\Column(length: 36)]
    public string $observationId;

    #[ORM\Column]
    public bool $selected;

    #[ORM\Column]
    public int $revision = 1;

    #[ORM\Column(type: 'json')]
    public array $provenance = [];

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;
}
