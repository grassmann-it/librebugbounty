<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'http_observation')]
#[ORM\Index(name: 'idx_http_observation_finding', columns: ['finding_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class HttpObservation
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Finding::class)]
    #[ORM\JoinColumn(name: 'finding_id', nullable: false, onDelete: 'CASCADE')]
    private Finding $finding;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $screenshotJobId = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $retestRunId = null;

    #[ORM\Column]
    private int $pocRevision = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $httpStatus = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $finalUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $artifactPath = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $artifactSha256 = null;

    #[ORM\Column]
    private int $rawBytes = 0;

    #[ORM\Column]
    private bool $rawTruncated = false;

    #[ORM\Column]
    private int $domBytes = 0;

    #[ORM\Column]
    private bool $domTruncated = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $classification = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $classifiedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Finding $finding,
        int $pocRevision,
        ?int $httpStatus,
        ?string $finalUrl,
        ?string $contentType,
        int $rawBytes,
        bool $rawTruncated,
        int $domBytes,
        bool $domTruncated,
        ?array $metadata = null,
    ) {
        $this->id = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $this->finding = $finding;
        $this->pocRevision = $pocRevision;
        $this->httpStatus = $httpStatus;
        $this->finalUrl = $finalUrl;
        $this->contentType = $contentType;
        $this->rawBytes = $rawBytes;
        $this->rawTruncated = $rawTruncated;
        $this->domBytes = $domBytes;
        $this->domTruncated = $domTruncated;
        $this->metadata = $metadata;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getFinding(): Finding { return $this->finding; }
    public function setScreenshotJobId(?string $id): self { $this->screenshotJobId = $id; return $this; }
    public function setRetestRunId(?string $id): self { $this->retestRunId = $id; return $this; }
    public function setArtifact(?string $path, ?string $sha256): self { $this->artifactPath = $path; $this->artifactSha256 = $sha256; return $this; }
    public function setClassification(?array $classification): self { $this->classification = $classification; $this->classifiedAt = $classification === null ? null : new \DateTimeImmutable(); return $this; }
    public function getPocRevision(): int { return $this->pocRevision; }
    public function getHttpStatus(): ?int { return $this->httpStatus; }
    public function getFinalUrl(): ?string { return $this->finalUrl; }
    public function getContentType(): ?string { return $this->contentType; }
    public function getArtifactPath(): ?string { return $this->artifactPath; }
    public function getArtifactSha256(): ?string { return $this->artifactSha256; }
    public function getRawBytes(): int { return $this->rawBytes; }
    public function getRawTruncated(): bool { return $this->rawTruncated; }
    public function getDomBytes(): int { return $this->domBytes; }
    public function getDomTruncated(): bool { return $this->domTruncated; }
    public function getMetadata(): ?array { return $this->metadata; }
    public function getClassification(): ?array { return $this->classification; }
    public function getClassifiedAt(): ?\DateTimeImmutable { return $this->classifiedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
