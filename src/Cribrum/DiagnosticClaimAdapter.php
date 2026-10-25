<?php

declare(strict_types=1);

namespace App\Cribrum;

use App\Cribrum\Detector\ContextTriageDetector;
use App\Cribrum\Detector\DetectorEvaluation;
use App\Cribrum\Detector\RegexFailure;
use App\Cribrum\Detector\SqlTriageDetector;

/** Narrow core suggestions from captured diagnostics; never activates candidate facts. */
final class DiagnosticClaimAdapter
{
    /** @return array{suggestions:list<ClaimSuggestion>,issues:list<array>} */
    public function evaluate(ObservationInput $input): array
    {
        $suggestions = []; $issues = [];
        $evaluators = [
            'sql_observation' => fn (): DetectorEvaluation => (new SqlTriageDetector())->evaluate($input, 'shadow'),
            'runtime_observation' => fn (): DetectorEvaluation => (new ContextTriageDetector())->evaluateDiagnostics($input, 'shadow'),
        ];
        foreach ($evaluators as $stage => $evaluate) {
            try {
                $result = $evaluate();
                foreach ($result->familyOutcomes as $family) {
                    if ($family['status'] === 'unavailable') {
                        foreach ($family['stages'] as $entry) foreach ($entry['issues'] ?? [] as $issue) {
                            if (($issue['code'] ?? null) === 'representation_invalid') $issues[] = [
                                'code' => 'representation_invalid', 'stage' => 'builtin_'.$stage,
                                'representation' => $issue['representation'] ?? 'raw', 'status' => 'failed'];
                        }
                    }
                    if (!in_array($family['status'], ['failed', 'limited'], true)) continue;
                    $issues[] = ['code' => 'diagnostic_'.$family['status'], 'stage' => 'builtin_'.$stage,
                        'representation' => $input->rawHtml !== null ? 'raw' : 'dom_text', 'status' => $family['status']];
                }
                foreach ($result->signals as $signal) {
                    try {
                    $claim = match (true) {
                        $signal['code'] === 'sql_error_observed' => Taxonomy::ERROR_DISCLOSURE,
                        $signal['code'] === 'error_response' && is_array($signal['attributes']['runtimeDetails'] ?? null) => Taxonomy::ERROR_DISCLOSURE,
                        $signal['code'] === 'internal_information_exposed' && ($signal['attributes']['concreteInternals'] ?? false) === true => Taxonomy::INFORMATION_DISCLOSURE,
                        default => null,
                    };
                    if ($claim === null || $signal['spans'] === [] || $this->isSuppliedDiagnostic($signal, $input)) continue;
                    EvidenceSignal::validateAgainstInput($signal, $input);
                    $spans = array_map(static fn (array $span): array => [
                        'representation' => $span['representation'] === 'dom_text' ? 'text' : $span['representation'],
                        'start' => $span['start'], 'length' => $span['length'], 'excerpt' => $span['excerpt'],
                    ], $signal['spans']);
                    $suggestions[] = new ClaimSuggestion($claim, Taxonomy::OBSERVED, 86,
                        ['builtin/'.$stage],
                        [$claim === Taxonomy::ERROR_DISCLOSURE ? 'A concrete runtime or database diagnostic was returned.' : 'A runtime diagnostic exposes concrete internal details.'],
                        $spans);
                    } catch (\Throwable) {
                        // One bad emission cannot discard a later independent source.
                        $issues[] = ['code' => 'invalid_evidence_candidate', 'stage' => 'builtin_'.$stage,
                            'representation' => $input->rawHtml !== null ? 'raw' : 'dom_text', 'status' => 'failed'];
                    }
                }
            } catch (\Throwable $error) {
                $issues[] = ['code' => $error instanceof RegexFailure ? 'regex_error' : 'engine_error',
                    'stage' => 'builtin_'.$stage, 'representation' => $input->rawHtml !== null ? 'raw' : 'dom_text', 'status' => 'failed'];
            }
        }
        return ['suggestions' => $suggestions, 'issues' => $issues];
    }

    /** Reflecting a complete diagnostic supplied by the request proves no server diagnosis. */
    private function isSuppliedDiagnostic(array $signal, ObservationInput $input): bool
    {
        $values = array_filter([$input->payload], static fn ($value): bool => is_string($value) && $value !== '');
        foreach ([$input->url, $input->finalUrl] as $url) {
            if ($url === null) continue;
            $query = parse_url($url, PHP_URL_QUERY);
            if (!is_string($query)) continue;
            foreach (explode('&', $query) as $pair) {
                $parts = explode('=', $pair, 2);
                if (isset($parts[1])) $values[] = urldecode($parts[1]);
            }
        }
        // Formatting the user's whole diagnostic with <b>/<span> or entities
        // adds no independent source detail. Comparison never changes evidence.
        $normalize = static function (string $value): string {
            $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        };
        foreach ($signal['spans'] as $span) {
            $excerpt = $normalize($span['excerpt']);
            if ($excerpt === '') continue;
            $supplied = false;
            foreach ($values as $value) if (str_contains($normalize($value), $excerpt)) { $supplied = true; break; }
            if (!$supplied) return false;
        }
        return true;
    }
}
