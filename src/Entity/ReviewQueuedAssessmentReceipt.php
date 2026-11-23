<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Committed with the assessment, so a lost HTTP response cannot duplicate it. */
#[ORM\Entity]
#[ORM\Table(name: 'review_queued_assessment_receipt')]
#[ORM\Index(name: 'idx_review_queued_receipt_created', columns: ['created_at'])]
final class ReviewQueuedAssessmentReceipt
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    public string $id;

    #[ORM\Column(type: 'string', length: 36)]
    public string $findingId;

    #[ORM\Column(type: 'string', length: 36)]
    public string $trailId;

    #[ORM\Column(type: 'string', length: 64)]
    public string $payloadHash;

    #[ORM\Column(type: 'string', length: 64)]
    public string $fingerprint;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;
}
