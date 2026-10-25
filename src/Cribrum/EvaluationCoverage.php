<?php

namespace App\Cribrum;

/** Versioned execution details are independent of evidence and capture completeness. */
final class EvaluationCoverage
{
    public const SCHEMA_VERSION = 1;
    private const STATUSES = ['completed', 'unavailable', 'limited', 'failed'];
    private const FAMILIES = [
        'reflection-triage-v1' => ['reflection'], 'sql-triage-v1' => ['sql'],
        'file-triage-v1' => ['local_include', 'remote_include'],
        'context-triage-v1' => ['disclosure', 'waf', 'error_response'],
    ];

    /** Mandatory work for these registered v1 detector identities; conditional candidate work is additive. */
    public static function requiredStages(string $detector, string $family): array
    {
        if ($detector === 'reflection-triage-v1') return ['input_sources', 'representation'];
        if ($detector === 'sql-triage-v1') return ['comparison', 'json_comparison', 'signature_search'];
        if ($detector === 'file-triage-v1') return ['operation_scan', 'prefixless_scan', 'template_scan', 'content_scan'];
        if ($detector !== 'context-triage-v1') return [];
        if ($family === 'error_response') return ['signature'];
        if ($family === 'disclosure') return [
            'signature.stack_trace.0', 'signature.stack_trace.1', 'signature.stack_trace.2', 'signature.stack_trace.3', 'signature.stack_trace.4',
            'signature.internal_path.0', 'signature.internal_path.1', 'signature.internal_path.2', 'signature.source_location.0', 'signature.query.0',
            'concreteDetails', 'additionalInternals', 'sourceBlocks',
        ];
        return [
            ...array_map(static fn ($vendor) => 'presence.'.$vendor, ['cloudflare', 'sucuri', 'imperva', 'akamai', 'aws_waf', 'cloudfront', 'safeline', 'go_away', 'admin_tools', 'wordfence', 'modsecurity', 'ninjafirewall', 'checkpoint', 'azure_waf', 'secupress', 'iis', 'apache', 'nginx', 'varnish', 'squid', 'haproxy', 'envoy']),
            'block.cloudflare.0', 'block.cloudflare.1', 'block.sucuri.2', 'block.imperva.3', 'block.akamai.4', 'block.aws_waf.5',
            'block.safeline.6', 'block.wordfence.7', 'block.modsecurity.8', 'block.ninjafirewall.9', 'block.checkpoint.10', 'block.azure_waf.11', 'block.secupress.12', 'block.unknown.13', 'additionalWafContexts',
        ];
    }

    public static function requiresEvaluation(string $version, array $coverage, array $packs = []): bool
    {
        if (($coverage === [] && !array_key_exists('behavior-triage', $packs)) || !str_starts_with($version, 'cribrum-')) return false;
        $numeric = explode('.', explode('-', explode('+', substr($version, 8), 2)[0], 2)[0]);
        return count($numeric) === 3 && ctype_digit($numeric[0]) && ctype_digit($numeric[1]) && ctype_digit($numeric[2])
            && version_compare(implode('.', $numeric), '1.1.0', '>=');
    }

    /** Preserve unknown future payloads in storage; never treat them as understood execution. */
    public static function validationErrors(mixed $evaluation, array $packs = []): array
    {
        if (!is_array($evaluation) || ($evaluation['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) return ['unsupported_evaluation_schema'];
        $runs = $evaluation['runs'] ?? null;
        if (!is_array($runs) || !array_is_list($runs) || $runs === []) return ['invalid_evaluation_runs'];
        $errors = []; $identities = [];
        $engineIssues = array_key_exists('engineIssues', $evaluation) ? $evaluation['engineIssues'] : [];
        if (!is_array($engineIssues) || !array_is_list($engineIssues)) $errors[] = 'invalid_engine_issues';
        else foreach ($engineIssues as $issue) {
            if (!is_array($issue) || !self::name($issue['code'] ?? null) || !self::name($issue['stage'] ?? null)
                || !array_key_exists('representation', $issue) || !self::representation($issue['representation'])) $errors[] = 'invalid_engine_issue';
            elseif ((array_key_exists('counters', $issue) && !self::counters($issue['counters']))
                || (array_key_exists('status', $issue) && !in_array($issue['status'], ['failed', 'limited'], true))
                || (array_key_exists('pcreCode', $issue) && (!is_int($issue['pcreCode']) || $issue['pcreCode'] < 0))
                || (array_key_exists('operation', $issue) && !in_array($issue['operation'], ['match', 'match_all', 'split', 'replace', 'replace_callback'], true))) $errors[] = 'invalid_engine_issue';
        }
        foreach ($runs as $run) {
            if (!is_array($run)) { $errors[] = 'invalid_evaluation_run'; continue; }
            $pack = is_string($run['pack'] ?? null) ? $run['pack'] : '';
            $detector = is_string($run['detector'] ?? null) ? $run['detector'] : '';
            foreach (['pack', 'version', 'rule', 'detector'] as $key) if (!self::name($run[$key] ?? null)) $errors[] = 'invalid_run_'.$key;
            if (!in_array($run['mode'] ?? null, ['active', 'shadow'], true)) $errors[] = 'invalid_run_mode';
            if ($packs !== [] && (!isset($packs[$pack]) || ($packs[$pack] ?? null) !== ($run['version'] ?? null))) $errors[] = 'run_pack_mismatch';
            $outputs = $run['outputs'] ?? null;
            if (!is_array($outputs) || !array_is_list($outputs) || $outputs === [] || count(array_unique($outputs, SORT_REGULAR)) !== count($outputs)
                || array_filter($outputs, static fn ($output) => !is_string($output)) !== []
                || array_diff($outputs, Rule::detectorOutputs()[$detector] ?? []) !== []) $errors[] = 'invalid_run_outputs';
            $identity = json_encode(array_map(static fn ($key) => $run[$key] ?? null, ['pack', 'version', 'rule', 'detector', 'mode']));
            if (isset($identities[$identity])) $errors[] = 'duplicate_evaluation_run';
            $identities[$identity] = true;
            $families = $run['familyOutcomes'] ?? null;
            if (!is_array($families) || !array_is_list($families) || $families === []) { $errors[] = 'invalid_family_outcomes'; continue; }
            $seen = [];
            foreach ($families as $family) {
                if (!is_array($family) || !self::name($family['family'] ?? null)) { $errors[] = 'invalid_evaluation_family'; continue; }
                $name = $family['family'];
                if (isset($seen[$name])) $errors[] = 'duplicate_evaluation_family';
                $seen[$name] = true;
                if (!in_array($family['status'] ?? null, self::STATUSES, true)) $errors[] = 'invalid_family_status';
                if (!self::counters($family['counters'] ?? null) || array_diff(['candidatesConsidered', 'signalsQualified', 'candidatesExcluded'], array_keys($family['counters'] ?? [])) !== []) $errors[] = 'invalid_family_counters';
                else foreach (['candidatesConsidered', 'signalsQualified', 'candidatesExcluded'] as $counter) if (!is_int($family['counters'][$counter])) $errors[] = 'invalid_family_counters';
                $stages = $family['stages'] ?? null;
                if (!is_array($stages) || !array_is_list($stages) || $stages === []) { $errors[] = 'invalid_family_stages'; continue; }
                $statuses = []; $stageKeys = [];
                foreach ($stages as $stage) {
                    if (!is_array($stage) || !self::name($stage['stage'] ?? null) || !array_key_exists('representation', $stage) || !self::representation($stage['representation']) || !in_array($stage['status'] ?? null, self::STATUSES, true)) { $errors[] = 'invalid_evaluation_stage'; continue; }
                    $key = json_encode([$stage['stage'], $stage['representation']]);
                    if (isset($stageKeys[$key])) $errors[] = 'duplicate_evaluation_stage';
                    $stageKeys[$key] = true; $statuses[] = $stage['status'];
                    $issues = $stage['issues'] ?? null;
                    if (!is_array($issues) || !array_is_list($issues)) { $errors[] = 'invalid_stage_issues'; continue; }
                    if ($stage['status'] !== 'completed' && $issues === []) $errors[] = 'missing_stage_issue';
                    if ($stage['status'] === 'completed' && $issues !== []) $errors[] = 'completed_stage_has_issues';
                    foreach ($issues as $issue) {
                        if (!is_array($issue) || !self::name($issue['code'] ?? null)
                            || ($issue['detector'] ?? null) !== ($run['detector'] ?? null) || ($issue['family'] ?? null) !== $name
                            || ($issue['stage'] ?? null) !== $stage['stage'] || !array_key_exists('representation', $issue) || $issue['representation'] !== $stage['representation']) { $errors[] = 'invalid_evaluation_issue'; continue; }
                        if (array_key_exists('counters', $issue) && !self::counters($issue['counters'])) $errors[] = 'invalid_issue_counters';
                        if (array_key_exists('pcreCode', $issue) && (!is_int($issue['pcreCode']) || $issue['pcreCode'] < 0)) $errors[] = 'invalid_issue_pcre_code';
                        if (array_key_exists('operation', $issue) && !in_array($issue['operation'], ['match', 'match_all', 'split', 'replace', 'replace_callback'], true)) $errors[] = 'invalid_issue_operation';
                        if (array_key_exists('candidate', $issue) && (!self::candidate($issue['candidate']) || ($stage['representation'] !== null && $issue['candidate']['representation'] !== $stage['representation']))) $errors[] = 'invalid_issue_candidate';
                    }
                }
                if (($family['status'] ?? null) !== self::aggregate($statuses)) $errors[] = 'family_status_mismatch';
                if (($family['status'] ?? null) === 'completed') {
                    $requiredStages = self::requiredStages($detector, $name);
                    if ($detector === 'sql-triage-v1' && ($family['counters']['candidatesConsidered'] ?? 0) > 0) $requiredStages[] = 'parameters';
                    $recorded = [];
                    foreach ($stages as $stage) {
                        if (!is_array($stage) || !is_string($stage['stage'] ?? null)) continue;
                        $representation = $stage['representation'] ?? null;
                        $validRepresentation = match ($detector) {
                            'sql-triage-v1', 'file-triage-v1' => $representation === 'raw',
                            'context-triage-v1' => in_array($representation, ['raw', 'dom_text'], true),
                            'reflection-triage-v1' => $stage['stage'] === 'input_sources' ? $representation === null : in_array($representation, ['raw', 'dom_text'], true),
                            default => false,
                        };
                        if ($validRepresentation) $recorded[] = $stage['stage'];
                    }
                    if (array_diff($requiredStages, $recorded) !== []) $errors[] = 'missing_required_evaluation_stage';
                }
            }
            $expected = self::FAMILIES[$detector] ?? null;
            if ($expected === null || array_diff($expected, array_keys($seen)) !== [] || array_diff(array_keys($seen), $expected) !== []) $errors[] = 'detector_family_mismatch';
        }
        return array_values(array_unique($errors));
    }

    public static function certifiesComplete(array $coverage, string $version, array $packs = []): bool
    {
        $required = self::requiresEvaluation($version, $coverage, $packs);
        if (!array_key_exists('evaluation', $coverage)) return !$required;
        if (self::validationErrors($coverage['evaluation'], $packs ?: (is_array($coverage['packs'] ?? null) ? $coverage['packs'] : [])) !== []) return false;
        if (($coverage['evaluation']['engineIssues'] ?? []) !== []) return false;
        $completed = []; $selected = [];
        foreach ($coverage['evaluation']['runs'] as $run) {
            if (($coverage['mode'] ?? null) !== $run['mode']) return false;
            $selected[$run['detector']] = [...($selected[$run['detector']] ?? []), ...$run['outputs']];
            foreach ($run['familyOutcomes'] as $family) {
                if ($family['status'] !== 'completed') return false;
                $completed[] = $family['family'];
            }
        }
        foreach (Rule::detectorOutputs() as $detector => $outputs) if (array_diff($outputs, $selected[$detector] ?? []) !== []) return false;
        return array_diff(['disclosure', 'local_include', 'reflection', 'remote_include', 'sql', 'waf'], $completed) === [];
    }

    private static function aggregate(array $statuses): string
    {
        foreach (['failed', 'limited', 'unavailable'] as $status) if (in_array($status, $statuses, true)) return $status;
        return 'completed';
    }
    private static function name(mixed $value): bool { return is_string($value) && $value !== '' && strlen($value) <= 256; }
    private static function representation(mixed $value): bool { return $value === null || in_array($value, ['raw', 'dom_text', 'dialog'], true); }
    private static function counters(mixed $value): bool
    {
        if (!is_array($value)) return false;
        foreach ($value as $key => $counter) if (!is_string($key) || !self::name($key) || ($counter !== null && (!is_int($counter) || $counter < 0))) return false;
        return true;
    }
    private static function candidate(mixed $value): bool
    {
        return is_array($value) && in_array($value['representation'] ?? null, ['raw', 'dom_text', 'dialog'], true)
            && is_string($value['representationSha256'] ?? null) && strlen($value['representationSha256']) === 64 && strspn($value['representationSha256'], 'abcdef0123456789') === 64
            && is_int($value['start'] ?? null) && $value['start'] >= 0 && is_int($value['length'] ?? null) && $value['length'] > 0;
    }
}
