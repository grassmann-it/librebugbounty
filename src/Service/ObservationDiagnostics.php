<?php

namespace App\Service;

use App\Cribrum\CribrumClassifier;
use App\Cribrum\ObservationInput;
use App\Entity\HttpObservation;

/** Read-only evaluation of local evidence; never persists suggestions or calls a target. */
final class ObservationDiagnostics
{
    public function __construct(
        private readonly CribrumObservationStore $observations,
        private readonly CribrumClassifier $classifier,
    ) {
    }

    public function inspect(HttpObservation $observation): array
    {
        $view = [
            'id' => $observation->getId(),
            'pocRevision' => $observation->getPocRevision(),
            'stored' => $observation->getClassification(),
            'current' => null, 'rules' => [], 'errors' => [], 'warnings' => [],
            'active' => $this->classifier->configuration(),
            'rawBytes' => $observation->getRawBytes(), 'domBytes' => $observation->getDomBytes(),
            'truncated' => $observation->getRawTruncated() || $observation->getDomTruncated(),
        ];
        $transportError = $observation->getMetadata()['error'] ?? null;
        if (is_string($transportError)) $view['errors'][] = $transportError;
        $context = $observation->getMetadata()['classificationContext'] ?? null;
        if (!is_array($context) || !is_string($context['url'] ?? null)
            || !array_key_exists('payload', $context) || !array_key_exists('expectedEvidence', $context)
            || !is_bool($context['dialogSeen'] ?? null) || !array_key_exists('dialogText', $context)) {
            $view['warnings'][] = 'Legacy observation: captured classification context is unavailable. Current evaluation was skipped; stored results are preserved.';
            return $view;
        }
        try {
            $contents = $this->observations->load($observation);
            $rules = [];
            $result = $this->classifier->classify(ObservationInput::fromArray([
                ...$context, ...$contents,
                'contentType' => $observation->getContentType(),
                'httpStatus' => $observation->getHttpStatus(), 'finalUrl' => $observation->getFinalUrl(),
            ]), $rules);
            $view['current'] = $result->toArray();
            $view['rules'] = $rules;
            $view['errors'] = [...$view['errors'], ...$result->diagnostics];
        } catch (\Throwable $error) {
            $view['errors'][] = $error->getMessage();
        }

        return $view;
    }
}
