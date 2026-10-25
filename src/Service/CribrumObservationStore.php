<?php

namespace App\Service;

use App\Cribrum\ObservationTransport;
use App\Entity\Finding;
use App\Entity\HttpObservation;
use Doctrine\ORM\EntityManagerInterface;

/** Persists every bounded response observation produced by an existing browser run. */
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
        ?string $requestContextSha256 = null,
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
        if ($trusted === null) throw new \InvalidArgumentException('HTTP observation is invalid for Cribrum storage.');
        $raw = $trusted['rawHtml'];
        $dom = $trusted['domText'];
        $metadata = [
            'classificationContext' => $classificationContext,
            // Captured by the request owner before browser work, never reconstructed on replay.
            'requestContextSha256' => $requestContextSha256,
            'requestUrl' => $contextUrl ?? $finding->getPocUrl(),
            'captureRef' => $screenshotJobId!==null ? ['kind'=>'screenshot_job','id'=>$screenshotJobId] : ($retestRunId!==null ? ['kind'=>'retest_run','id'=>$retestRunId] : null),
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

    /** Re-authenticate historical fact sources during a body-less projection refresh. */
    public function authenticateFact(array $fact, ?Finding $currentFinding = null): void
    {
        $ref = $fact['sourceRef'] ?? [];
        $observation = $this->entityManager->find(HttpObservation::class, $ref['id'] ?? '');
        if (!$observation instanceof HttpObservation) throw new \RuntimeException('Behavior source observation is unavailable.');
        $this->entityManager->refresh($observation);
        if ($observation->getFinding()->getId() !== ($fact['findingId'] ?? null) || $observation->getPocRevision() !== ($fact['pocRevision'] ?? null)
            || $observation->getArtifactSha256() !== ($ref['artifactSha256'] ?? null)) throw new \RuntimeException('Behavior source binding changed.');
        if ($currentFinding !== null && (!$this->matchesCurrentRequest($currentFinding,$observation) || ($fact['requestContextSha256'] ?? null)!==WorkerProofResolver::requestContextSha256($currentFinding))) throw new \RuntimeException('Behavior request context changed.');
        $artifact = $this->load($observation);
        foreach ($fact['spans'] ?? [] as $span) {
            $raw = match ($span['representation']) { 'raw' => $artifact['rawHtml'], 'dom_text' => $artifact['domText'], default => null };
            if ($raw === null || hash('sha256', $raw) !== $span['representationSha256'] || mb_substr($raw, $span['start'], $span['length'], 'UTF-8') !== $span['excerpt']) throw new \RuntimeException('Behavior source span changed.');
        }
    }

    /** A missing old capture pin is unknown, never a credential for today's request. */
    public function matchesCurrentRequest(Finding $finding, HttpObservation $observation): bool
    {
        $meta=$observation->getMetadata() ?? [];
        return $observation->getFinding()->getId()===$finding->getId()
            && $observation->getPocRevision()===$finding->getPocRevision()
            && ($meta['requestUrl'] ?? $meta['classificationContext']['url'] ?? null)===$finding->getPocUrl()
            && ($meta['requestContextSha256'] ?? null)===WorkerProofResolver::requestContextSha256($finding);
    }

    /** Reload coverage's independently retained observation, including zero-fact captures. */
    public function coverageSource(Finding $finding, array $ref): ?HttpObservation
    {
        if (($ref['kind'] ?? null)!=='http_observation' || !is_string($ref['id'] ?? null)) return null;
        $observation=$this->entityManager->find(HttpObservation::class,$ref['id']);
        if (!$observation instanceof HttpObservation) return null;
        $this->entityManager->refresh($observation);
        if (!$this->matchesCurrentRequest($finding,$observation) || ($ref['artifactSha256'] ?? null)!==$observation->getArtifactSha256()) return null;
        $json=$this->entityManager->getConnection()->fetchOne('SELECT classification FROM http_observation WHERE id=?',[$observation->getId()]);
        if (!is_string($json) || ($ref['classificationSha256'] ?? null)!==hash('sha256',$json)) return null;
        return $observation;
    }

    public function coverageReference(HttpObservation $observation): array
    {
        $json=$this->entityManager->getConnection()->fetchOne('SELECT classification FROM http_observation WHERE id=?',[$observation->getId()]);
        if (!is_string($json)) throw new \RuntimeException('Coverage classification is unavailable.');
        return ['kind'=>'http_observation','id'=>$observation->getId(),'artifactSha256'=>$observation->getArtifactSha256(),'classificationSha256'=>hash('sha256',$json)];
    }

}
