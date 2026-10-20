<?php

namespace App\Service;

use App\Cribrum\ObservationTransport;
use App\Entity\Finding;
use App\Entity\HttpObservation;
use Doctrine\ORM\EntityManagerInterface;

/** Persists bounded, eligible response evidence produced by an existing browser run. */
final class CribrumObservationStore
{
    public const MAX_COMPRESSED_BYTES = 2097152;
    public const RETAINED_RAW_BYTES = 65536;
    public const RETAINED_DOM_BYTES = 32768;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EvidenceStorageInterface $storage,
    ) {
    }

    public function store(
        Finding $finding,
        array $observation,
        ?string $screenshotJobId = null,
        ?string $retestRunId = null,
        ?int $pocRevision = null,
        ?string $contextUrl = null,
        ?array $classificationContext = null,
    ): HttpObservation {
        if ($screenshotJobId !== null) {
            $existing = $this->entityManager->getRepository(HttpObservation::class)->findOneBy(['screenshotJobId' => $screenshotJobId]);
            if ($existing instanceof HttpObservation) return $existing;
        }
        if ($retestRunId !== null) {
            $existing = $this->entityManager->getRepository(HttpObservation::class)->findOneBy(['retestRunId' => $retestRunId]);
            if ($existing instanceof HttpObservation) return $existing;
        }

        $trusted = ObservationTransport::normalize($observation);
        if ($trusted === null || !$this->eligible($trusted, $contextUrl ?? $finding->getPocUrl())) {
            throw new \InvalidArgumentException('HTTP observation is not eligible for Cribrum storage.');
        }
        $raw = $trusted['rawHtml'];
        $dom = $trusted['domText'];
        $metadata = [
            'classificationContext' => $classificationContext,
            'redirectChain' => $trusted['redirectChain'],
            'headers' => $trusted['headers'],
            'manifest' => [
                'schema' => 1,
                'rawSha256' => $raw === null ? null : hash('sha256', $raw),
                'domSha256' => $dom === null ? null : hash('sha256', $dom),
                'rawStoredBytes' => $raw === null ? 0 : strlen($raw),
                'domStoredBytes' => $dom === null ? 0 : strlen($dom),
                'rawTransportTruncated' => $trusted['rawHtmlTruncated'],
                'domTransportTruncated' => $trusted['domTextTruncated'],
            ],
        ];
        foreach ([
            'error' => 'error',
            'rawHtmlBytesReported' => 'rawHtmlBytes',
            'domTextBytesReported' => 'domTextBytes',
        ] as $metadataKey => $sourceKey) {
            $value = $observation[$sourceKey] ?? null;
            if ($value !== null) $metadata[$metadataKey] = $value;
        }

        $retainedRaw = $raw;
        $retainedDom = $dom;
        $artifact = [
            'schema' => 1,
            'manifest' => $metadata['manifest'],
            'rawHtml' => $raw,
            'domText' => $dom,
        ];
        $encoded = gzencode(json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 9, FORCE_GZIP);
        if ($encoded === false) throw new \RuntimeException('HTTP observation could not be compressed.');
        if (strlen($encoded) > self::MAX_COMPRESSED_BYTES) {
            $retainedRaw = $raw === null ? null : mb_strcut($raw, 0, self::RETAINED_RAW_BYTES, 'UTF-8');
            $retainedDom = $dom === null ? null : mb_strcut($dom, 0, self::RETAINED_DOM_BYTES, 'UTF-8');
            $metadata['manifest']['originalRawSha256'] = $metadata['manifest']['rawSha256'];
            $metadata['manifest']['originalDomSha256'] = $metadata['manifest']['domSha256'];
            $metadata['manifest']['retainedOnly'] = true;
            $metadata['manifest']['rawSha256'] = $retainedRaw === null ? null : hash('sha256', $retainedRaw);
            $metadata['manifest']['domSha256'] = $retainedDom === null ? null : hash('sha256', $retainedDom);
            $metadata['manifest']['retainedRawBytes'] = $retainedRaw === null ? 0 : strlen($retainedRaw);
            $metadata['manifest']['retainedDomBytes'] = $retainedDom === null ? 0 : strlen($retainedDom);
            $artifact = ['schema' => 1, 'manifest' => $metadata['manifest'], 'rawHtml' => $retainedRaw, 'domText' => $retainedDom];
            $encoded = gzencode(json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 9, FORCE_GZIP);
            if ($encoded === false || strlen($encoded) > self::MAX_COMPRESSED_BYTES) {
                throw new \RuntimeException('Bounded HTTP observation artifact exceeds its storage limit.');
            }
        }

        $entity = new HttpObservation(
            $finding,
            $pocRevision ?? $finding->getPocRevision(),
            $trusted['httpStatus'],
            $trusted['finalUrl'],
            $trusted['contentType'],
            $raw === null ? 0 : strlen($raw),
            $trusted['rawHtmlTruncated'] || $retainedRaw !== $raw,
            $dom === null ? 0 : strlen($dom),
            $trusted['domTextTruncated'] || $retainedDom !== $dom,
            $metadata,
        );
        $entity->setScreenshotJobId($screenshotJobId);
        $entity->setRetestRunId($retestRunId);

        $stored = $this->storage->storeContents($finding, $encoded, 'observation.json.gz');
        $entity->setArtifact($stored->relativePath, $stored->sha256);
        try {
            $this->entityManager->persist($entity);
            $this->entityManager->flush();
        } catch (\Throwable $error) {
            try { $this->storage->deleteFile($stored->relativePath); } catch (\Throwable) {}
            throw $error;
        }

        return $entity;
    }

    /** @return array{rawHtml:?string,domText:?string} */
    public function load(HttpObservation $observation): array
    {
        $path = $observation->getArtifactPath();
        $hash = $observation->getArtifactSha256();
        if ($path === null || $hash === null) throw new \RuntimeException('HTTP observation has no stored artifact.');
        $compressed = $this->storage->read($path);
        if (hash('sha256', $compressed) !== $hash) throw new \RuntimeException('HTTP observation artifact hash mismatch.');
        $json = gzdecode($compressed);
        if ($json === false) throw new \RuntimeException('HTTP observation artifact is not valid gzip.');
        $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data['manifest'] ?? null)
            || !array_key_exists('rawHtml', $data) || !array_key_exists('domText', $data)) {
            throw new \RuntimeException('HTTP observation artifact is invalid.');
        }
        $raw = $data['rawHtml'];
        $dom = $data['domText'];
        if (($raw !== null && !is_string($raw)) || ($dom !== null && !is_string($dom))) {
            throw new \RuntimeException('HTTP observation artifact text is invalid.');
        }
        if (($raw !== null && hash('sha256', $raw) !== ($data['manifest']['rawSha256'] ?? null))
            || ($dom !== null && hash('sha256', $dom) !== ($data['manifest']['domSha256'] ?? null))) {
            throw new \RuntimeException('HTTP observation representation hash mismatch.');
        }

        return ['rawHtml' => $raw, 'domText' => $dom];
    }

    /** @param array<string,mixed> $observation */
    private function eligible(array $observation, string $contextUrl): bool
    {
        $contentType = strtolower(explode(';', (string) $observation['contentType'])[0] ?? '');
        $htmlish = str_starts_with($contentType, 'text/html') || $contentType === 'application/xhtml+xml';
        $errorish = is_int($observation['httpStatus']) && $observation['httpStatus'] >= 400;
        if ($htmlish || $errorish) return true;

        $textish = $contentType === 'text/plain' || $contentType === '';
        if (!$textish || ($observation['rawHtml'] === null && $observation['domText'] === null)) return false;
        $body = (string) ($observation['domText'] ?? $observation['rawHtml']);
        $suspicious = (bool) preg_match('/(?:root:x:0:0:|\[extensions\]|BEGIN (?:RSA|OPENSSH) PRIVATE KEY)/i', $body);
        $query = parse_url($contextUrl, PHP_URL_QUERY);
        if (!is_string($query) || !$suspicious) return false;
        $values = [];
        parse_str($query, $values);
        foreach ($values as $value) {
            if (is_string($value) && (str_contains($value, '../') || str_contains($value, '..\\') || str_starts_with($value, '/'))) {
                return true;
            }
        }

        return false;
    }
}
