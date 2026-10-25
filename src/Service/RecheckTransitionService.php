<?php

namespace App\Service;

use App\Cribrum\ReportPolicy;

/** Semantic review episodes; a new interpretation is never a new target visit. */
final class RecheckTransitionService
{
    public function __construct(private readonly ReportPolicy $policy) {}

    /**
     * State is persisted with the report context, in the same transaction as its
     * basis revision. Missing responses and later successful checks do not ack it.
     */
    public function reconcile(array $previous, array $context): array
    {
        $pending = is_array($previous['pending'] ?? null) ? $previous['pending'] : [];
        $fingerprint = $this->policy->acceptedXssBasis($context) ? $this->policy->wafBlockFingerprint($context) : null;
        $acknowledged = [];
        foreach ($context['acknowledgements'] ?? [] as $ack) {
            if (is_array($ack) && is_string($ack['fingerprint'] ?? null) && !empty($ack['actor']) && !empty($ack['acknowledgedAt'])) {
                $acknowledged[$ack['fingerprint']] = true;
            }
        }
        if ($fingerprint !== null && !isset($pending[$fingerprint]) && !isset($acknowledged[$fingerprint])) {
            $observation = $context['observation'] ?? [];
            $evidenceRefs = $context['wafEvidenceRefs'] ?? [];
            if ($evidenceRefs === []) foreach ($context['facts'] ?? [] as $fact) {
                if (($fact['mode'] ?? null) !== 'active' || ($fact['code'] ?? null) !== 'coverage_limited' || ($fact['attributes']['wafBlock'] ?? false) !== true) continue;
                $evidenceRefs[] = ['kind' => 'behavior_fact', 'id' => $fact['factId'], 'sourceInstanceId' => $context['sourceInstanceId'], 'findingId' => $context['findingId'], 'pocRevision' => $context['poc']['revision'], 'pocSha256' => $context['poc']['sha256'], 'contentSha256' => $fact['contentSha256'] ?? $fact['factSha256'] ?? null];
            }
            $pending[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'reason' => 'new_waf_block_unreviewed',
                'observationId' => $observation['id'] ?? null,
                'interpretationOnly' => isset($observation['metadata']['reclassifiedFrom']),
                'observedAt' => $observation['observedAt'] ?? $observation['createdAt'] ?? null,
                'evidenceRefs' => $evidenceRefs,
            ];
        }
        foreach (array_keys($pending) as $key) {
            if (isset($acknowledged[$key])) unset($pending[$key]);
        }
        ksort($pending);
        return ['pending' => $pending, 'currentFingerprint' => $fingerprint];
    }

    /** Bounded review meaning; evidence IDs and report workflow do not define a new observation. */
    public function reportMeaning(array $snapshot): array
    {
        $components = array_values(array_filter(array_map(static fn(array $component): ?string => $component['code'] ?? null, $snapshot['components'] ?? [])));
        $tags = array_values(array_filter($snapshot['tags'] ?? [], 'is_string'));
        $hints = array_values(array_filter($snapshot['caseSummary']['hints'] ?? [], 'is_string'));
        sort($components); sort($tags); sort($hints);
        return ['components' => array_values(array_unique($components)), 'tags' => array_values(array_unique($tags)), 'hints' => array_values(array_unique($hints))];
    }

    /** Strip classifier bookkeeping when comparing the meaning of an interpretation. */
    public function semanticFingerprint(array $classification): string
    {
        $strip = function (array $value) use (&$strip): array {
            foreach (['packVersions', 'packVersion', 'version', 'ruleId', 'ruleIds', 'producer', 'score', 'classifiedAt', 'runId', 'requestId', 'evidenceId', 'factId', 'artifactSha256'] as $key) unset($value[$key]);
            foreach ($value as &$entry) if (is_array($entry)) $entry = $strip($entry);
            unset($entry);
            if (array_is_list($value)) usort($value, static fn ($a, $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            else ksort($value);
            return $value;
        };
        return hash('sha256', json_encode($strip($classification), JSON_THROW_ON_ERROR));
    }
}
