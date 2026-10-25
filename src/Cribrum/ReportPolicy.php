<?php

namespace App\Cribrum;

/** Pure evidence policy. No persistence, network, clock, model choice or claim mutation. */
final class ReportPolicy
{
    public const VERSION = 'behavior-report-v2';
    public const COVERAGE_FAMILIES = ['disclosure', 'local_include', 'reflection', 'remote_include', 'sql', 'waf'];

    public function evaluate(array $context): array
    {
        $c = $context;
        $reasons = []; $holds = []; $tags = []; $hints = []; $components = [];
        $poc = $c['poc'] ?? [];
        $identityValid = is_string($c['findingId'] ?? null) && $c['findingId'] !== ''
            && is_string($c['sourceInstanceId'] ?? null) && $c['sourceInstanceId'] !== '';
        if (!$identityValid) $holds[] = 'source_mismatch';
        if (($c['schemaVersion'] ?? 1) !== 1) $holds[] = 'unsupported_schema';
        if (isset($c['policyVersion']) && $c['policyVersion'] !== self::VERSION) $holds[] = 'unsupported_policy';
        if (!is_string($poc['effectiveUrl'] ?? null) || !is_int($poc['revision'] ?? null) || ($poc['revision'] ?? -1) < 0
            || ($poc['sha256'] ?? null) !== hash('sha256', $poc['effectiveUrl'] ?? '')) $holds[] = 'poc_changed';
        if (($c['basisRevision'] ?? 0) < 1 || ($c['decisionRevision'] ?? 0) < 1) $holds[] = 'basis_changed';
        foreach (['basisMatches' => 'basis_changed', 'sourceMatches' => 'source_mismatch', 'policyMatches' => 'policy_changed'] as $key => $reason) {
            if (($c[$key] ?? true) !== true) $holds[] = $reason;
        }

        $facts = []; $validFacts = []; $unknown = false;
        $known = ['execution_observed', 'input_reflected', 'input_in_sql_error', 'sql_error_observed', 'sql_input_structure_indication', 'internal_information_exposed', 'error_response', 'product_context', 'coverage_limited', 'file_include_diagnostic', 'lfi_suspected', 'rfi_suspected', 'sensitive_file_content_observed', 'sql_context_required'];
        foreach (($c['facts'] ?? []) as $fact) {
            if (!is_array($fact) || ($fact['mode'] ?? null) !== 'active') continue;
            if (!$this->bound($fact, $c) || ($fact['kind'] ?? null) !== 'behavior' || ($fact['schemaVersion'] ?? null) !== 1
                || empty($fact['factId']) || empty($fact['sourceRef']) || empty($fact['capturedAt']) || empty($fact['producer'])
                || (($fact['code'] ?? '') !== 'execution_observed' && !$this->spansValid($fact)) || !$this->hash($fact['contentSha256'] ?? $fact['factSha256'] ?? null)) {
                $unknown = true; $hints[] = 'invalid_or_stale_fact'; continue;
            }
            $code = $fact['code'] ?? '';
            if (!in_array($code, $known, true)) { $unknown = true; $hints[] = 'unknown_fact'; continue; }
            $validFacts[] = $fact;
        }
        [$excludedFactIds, $reviewedFactExclusions] = $this->reviewedFactExclusions($c, $validFacts);
        foreach ($validFacts as $fact) {
            if (isset($excludedFactIds[$fact['factId']])) continue;
            $facts[$fact['code']][] = $fact;
            $tags[] = $fact['code'];
        }
        $legacy = $c['legacyBasis'] ?? null;
        if (is_array($legacy) && isset($legacy['baselinePocRevision']) && (($legacy['baselinePocRevision'] !== ($poc['revision'] ?? null)) || ($legacy['baselinePocSha256'] ?? null) !== ($poc['sha256'] ?? null))) $holds[] = 'poc_changed';
        $xrefs = []; $x = false;
        if (is_array($legacy) && ($legacy['verified'] ?? false) === true && !empty($legacy['cohortId']) && $this->hash($legacy['cohortDigest'] ?? null)) {
            if (($legacy['baselinePocRevision'] ?? null) === ($poc['revision'] ?? null) && ($legacy['baselinePocSha256'] ?? null) === ($poc['sha256'] ?? null)
                && $this->hash($legacy['contentSha256'] ?? null)) {
                $x = true; $reasons[] = 'legacy_xss_basis'; $xrefs[] = $this->reference('legacy_member', (string) $c['findingId'], $legacy['contentSha256'], $c);
            } else $holds[] = 'poc_changed';
        }
        $proofs = $c['workerProof'] ?? [];
        if (isset($proofs['proofId'])) $proofs = [$proofs];
        foreach ($facts['execution_observed'] ?? [] as $fact) {
            $proof = $fact['attributes']['workerProof'] ?? null;
            if (is_array($proof)) $proofs[] = array_replace($proof, ['proofId' => $fact['factId'], 'contentSha256' => $fact['contentSha256'] ?? $fact['factSha256']]);
        }
        $badProof = false; $workerExecution = false;
        foreach ($proofs as $proof) {
            if (!is_array($proof)) { $badProof = true; continue; }
            if ($this->proofValid($proof, $c)) {
                $x = true; $workerExecution = true; $reasons[] = 'worker_execution_basis';
                $xrefs[] = $this->reference('worker_proof', $proof['proofId'], $proof['contentSha256'], $c);
            } else $badProof = true;
        }
        if ($x) { $tags[] = 'xss_execution_confirmed'; $components['xss_confirmed'] = $this->component('xss_confirmed', 'confirmed', $xrefs); }
        $sql = $this->qualified($facts['sql_input_structure_indication'] ?? [], 'sql');
        $disclosure = $this->qualified($facts['internal_information_exposed'] ?? [], 'disclosure');
        $errors = $this->qualified($facts['sql_error_observed'] ?? [], 'error');
        if ($sql) $components['sqli_suspected'] = $this->component('sqli_suspected', 'suspected', $this->factRefs($sql, $c));
        if ($disclosure) $components['information_disclosure'] = $this->component('information_disclosure', 'observed', $this->factRefs($disclosure, $c));
        if ($errors && ($x || $sql || $disclosure)) $components['sql_error_observation'] = $this->component('sql_error_observation', 'observed', $this->factRefs($errors, $c));
        foreach ($facts['input_in_sql_error'] ?? [] as $fact) {
            $value = $fact['attributes']['inputValue'] ?? '';
            if (($c['intake']['payloadKind'] ?? null) === 'xss' || (is_string($value) && preg_match('~<\s*(?:script|svg|img|iframe|body)\b|\bon(?:error|load|focus)\s*=|javascript\s*:~iu', $value))) {
                $tags[] = 'xss_payload_in_sql_error';
            }
        }
        foreach (['sql_error_observed', 'input_in_sql_error', 'sql_input_structure_indication', 'file_include_diagnostic', 'lfi_suspected', 'rfi_suspected', 'sensitive_file_content_observed', 'sql_context_required'] as $code) {
            if (!empty($facts[$code])) $hints[] = $code;
        }
        foreach (['lfi_suspected' => 'lfi_suspected', 'rfi_suspected' => 'rfi_suspected'] as $factCode => $component) {
            $qualified = $this->qualified($facts[$factCode] ?? [], 'include');
            if ($qualified) {
                $components[$component] = $this->component($component, 'suspected', $this->factRefs($qualified, $c));
                // Supported as an explicitly suspected component; never asserts inclusion success.
            }
        }
        foreach ($facts as $group) foreach ($group as $fact) {
            if (($fact['attributes']['contextRequired'] ?? false) === true
                && !$this->reflectionContextResolved($fact, $workerExecution)) $hints[] = 'context_required';
            if ($fact['code'] === 'internal_information_exposed' && !$this->qualified([$fact], 'disclosure')) { $unknown = true; $hints[] = 'disclosure_basis_incomplete'; }
        }
        $selection = $c['selection'] ?? [];
        $modes = [];
        foreach ($selection as $decision) if (is_array($decision) && isset($decision['component'])) $modes[$decision['component']] = $decision['mode'] ?? 'auto';
        $explicitX = ($c['explicitXssCandidate'] ?? $c['intake']['explicitXssCandidate'] ?? false) === true || ($modes['xss_confirmed'] ?? null) === 'include'
            || (bool) array_intersect(['xss', 'reflected_xss'], $c['intake']['declaredClaims'] ?? []);
        foreach (($c['claims'] ?? []) as $claim) {
            $name = $claim['claim'] ?? ''; $state = $claim['state'] ?? ''; $manual = ($claim['source'] ?? '') === 'manual';
            $disclosureFacet = $disclosure && in_array($name, ['information_disclosure','error_disclosure','stack_trace'], true);
            if ($name !== 'xss' && !$disclosureFacet && !in_array($state, ['rejected','resolved','stale'], true)) $hints[] = 'claim:'.$name;
            $component = ['xss' => 'xss_confirmed', 'sql_injection' => 'sqli_suspected', 'information_disclosure' => 'information_disclosure', 'local_file_inclusion' => 'lfi_suspected', 'remote_file_inclusion' => 'rfi_suspected'][$name] ?? null;
            if ($manual && in_array($state, ['rejected', 'resolved'], true) && $component && isset($components[$component])) $holds[] = 'manual_conflict';
            if ($manual && ($claim['reportSelected'] ?? null) === false && $component && isset($components[$component]) && !isset($modes[$component])) $holds[] = 'selection_intent_unknown';
            if (!in_array($state, ['rejected', 'resolved', 'stale'], true) && in_array($name, ['local_file_inclusion','remote_file_inclusion'], true)) {
                $hints[] = $name;
                if (!isset($components[$component]) && (($modes[$component] ?? null) === 'include' || ($manual && ($claim['reportSelected'] ?? false)))) $holds[] = 'unsupported_claim';
            }
        }
        if (!$x && $explicitX) $holds[] = $badProof ? 'worker_proof_unattributed' : 'execution_proof_missing';
        foreach (($c['reviewReasons'] ?? []) as $reason) if (is_string($reason)) $holds[] = $reason;
        foreach ($facts as $group) foreach ($group as $fact) {
            $a = $fact['attributes'] ?? [];
            if (($a['uncertaintyChangesComposition'] ?? false) === true) $holds[] = str_starts_with($fact['code'], 'sql') ? 'sql_basis_incomplete' : 'disclosure_basis_incomplete';
        }
        $combined = $x && ($sql || $errors);
        foreach ($modes as $code => $mode) {
            if ($mode === 'include' && !isset($components[$code]) && $code !== 'xss_confirmed') $holds[] = $code === 'sqli_suspected' ? 'sql_basis_incomplete' : 'disclosure_basis_incomplete';
            if ($mode === 'exclude' && isset($components[$code])) {
                if ($combined && in_array($code, ['xss_confirmed','sqli_suspected','sql_error_observation'], true)) $holds[] = 'combined_component_excluded';
                else unset($components[$code]);
            }
        }
        if (!isset($components['xss_confirmed'], $components['sqli_suspected']) && !isset($components['information_disclosure']) && !isset($components['xss_confirmed']) && !isset($components['sqli_suspected'])) unset($components['sql_error_observation']);
        $lane = $this->lane($components);
        $waf = $this->waf($c, $facts, $x);
        if ($waf['pending']) $holds[] = 'new_waf_block_unreviewed';
        $coverage = $this->coverage($c);
        if (!empty($facts['coverage_limited']) && $coverage['status'] === 'complete') $coverage['status'] = 'partial';
        if ($unknown) $coverage['status'] = 'unknown';
        $reasons = $this->sorted([...$reasons, ...$holds]);
        $gate = $holds ? 'hold' : ($lane !== 'none' ? 'eligible' : 'no_mail');
        if ($gate === 'no_mail') $reasons[] = $modes ? 'manual_excluded' : 'context_only';
        if (($c['reportSelection']['mode'] ?? 'auto') === 'exclude' && !$this->identityHold($holds)) {
            $gate = 'no_mail'; $reasons = ['manual_excluded'];
        }
        $codes = array_keys($components); sort($codes);
        $hints = $this->sorted($hints);
        $pure = $coverage['status'] === 'complete' && !$unknown && !$holds;
        $kind = $lane; $purity = 'unknown';
        if (!$unknown && (count($codes) > 1 || ($x && $hints))) $purity = 'mixed';
        if ($pure && $codes === ['xss_confirmed'] && !$hints) { $kind = 'pure_xss'; $purity = 'pure'; }
        elseif ($pure && isset($components['information_disclosure']) && !array_diff($codes, ['information_disclosure','sql_error_observation']) && !array_diff($hints, ['sql_error_observed'])) { $kind = 'pure_information_disclosure'; $purity = 'pure'; }
        elseif ($purity === 'mixed') $kind = 'mixed';
        ksort($components);
        $snapshot = array_intersect_key($c, array_flip(['sourceInstanceId','findingId','decisionRevision','basisRevision','intake','poc','legacyBasis','claims','selection','reportSelection','workflow']));
        return array_replace($snapshot, [
            'schemaVersion' => 1, 'policyVersion' => self::VERSION,
            'factIds' => $this->sorted(array_merge(...array_map(static fn(array $group): array => array_column($group, 'factId'), array_values($facts ?: [[]])))),
            'reviewedFactExclusions' => $reviewedFactExclusions,
            'tags' => $this->sorted($tags), 'lane' => $lane, 'components' => array_values($components),
            'gate' => $gate, 'reasonCodes' => $this->sorted($reasons), 'reviewRequired' => $gate === 'hold' && !$this->identityHold($holds),
            'urgencyAllowed' => isset($components['sqli_suspected']), 'decidedAt' => $c['decidedAt'] ?? null,
            'coverage' => $coverage, 'caseSummary' => ['kind' => $kind, 'purity' => $purity, 'components' => $codes, 'hints' => $hints, 'reviewReasons' => $this->sorted($holds)],
            'wafBlockReview' => $waf, 'wafBlockFingerprint' => $waf['fingerprint'],
            'observation' => $this->observationSummary($c['observation'] ?? []),
            'interpretationOnly' => ($c['interpretationOnly'] ?? false) === true,
        ]);
    }

    /** A current attributed execution answers only reflection's browser-context question. */
    private function reflectionContextResolved(array $fact, bool $workerExecution): bool
    {
        if (!$workerExecution || ($fact['code'] ?? null) !== 'input_reflected' || ($fact['attributes']['family'] ?? null) !== 'reflection') return false;
        $reasons = $fact['attributes']['reviewReasons'] ?? [];
        if (!is_array($reasons) || !$reasons) return false;
        return !array_diff($reasons, [
            'short_input_value', 'ambiguous_input_source', 'raw_context_not_established',
            'html_representation_not_established', 'css_context_not_interpreted',
            'script_lexical_context_not_execution', 'attribute_value_requires_browser_context',
            'incomplete_tag_context', 'context_window_exhausted', 'markup_crossing_input',
        ]);
    }
    private function observationSummary(array $observation): array
    {
        $summary = [];
        foreach (['id','observedAt'] as $key) if (is_string($observation[$key] ?? null)) $summary[$key] = $observation[$key];
        if (is_string($observation['metadata']['reclassifiedFrom'] ?? null)) $summary['metadata'] = ['reclassifiedFrom' => $observation['metadata']['reclassifiedFrom']];
        return $summary;
    }
    private function bound(array $v, array $c): bool
    {
        return ($v['findingId'] ?? null) === ($c['findingId'] ?? null) && ($v['sourceInstanceId'] ?? null) === ($c['sourceInstanceId'] ?? null)
            && ($v['pocRevision'] ?? null) === ($c['poc']['revision'] ?? null) && ($v['pocSha256'] ?? null) === ($c['poc']['sha256'] ?? null);
    }
    private function proofValid(array $p, array $c): bool
    {
        if (!$this->bound($p, $c) || empty($p['proofId']) || !$this->hash($p['contentSha256'] ?? null)
            || !in_array($p['sourceKind'] ?? '', ['screenshot_job','retest_run'], true) || empty($p['sourceId']) || empty($p['capturedAt'])
            || empty($p['sourcePageUrl']) || ($p['attribution'] ?? null) !== 'matched') return false;
        if (isset($c['requestContextSha256']) && ($p['requestContextSha256'] ?? null) !== $c['requestContextSha256']) return false;
        if (($p['basisMatches'] ?? true) !== true || (isset($c['expectedMarker']) && ($p['expectedMarker'] ?? null) !== $c['expectedMarker'])) return false;
        $review = $p['review'] ?? [];
        if (($p['matchPolicy'] ?? '') === 'reviewed_worker_proof_v1') return !empty($review['actor']) && !empty($review['reason']) && !empty($review['sourceRef']) && ($review['explicit'] ?? false) === true && in_array($p['dialogOrigin'] ?? '', ['native','hook'], true) && is_string($p['dialogText'] ?? null) && $p['dialogText'] !== '';
        $marker = $p['expectedMarker'] ?? null;
        return ($p['matchPolicy'] ?? null) === 'native_marker_cs_substring_v1' && ($p['dialogOrigin'] ?? null) === 'native'
            && is_string($marker) && mb_strlen($marker, 'UTF-8') >= 3 && count(array_unique(mb_str_split($marker))) >= 2
            && is_string($p['dialogText'] ?? null) && str_contains($p['dialogText'], $marker);
    }
    private function spansValid(array $fact): bool
    {
        $spans = $fact['spans'] ?? [];
        if (!is_array($spans) || !$spans) return false;
        $ids = [];
        foreach ($spans as $span) {
            if (!is_array($span) || empty($span['id']) || isset($ids[$span['id']]) || !$this->hash($span['representationSha256'] ?? null)
                || !$this->hash($span['excerptSha256'] ?? null) || !is_string($span['excerpt'] ?? null)
                || !is_int($span['start'] ?? null) || !is_int($span['length'] ?? null) || !is_int($span['representationLength'] ?? null)
                || $span['start'] < 0 || $span['length'] <= 0 || $span['start'] + $span['length'] > $span['representationLength']
                || !mb_check_encoding($span['excerpt'], 'UTF-8') || mb_strlen($span['excerpt'], 'UTF-8') !== $span['length']
                || !hash_equals($span['excerptSha256'], hash('sha256', $span['excerpt']))) return false;
            $ids[$span['id']] = true;
        }
        foreach ($fact['relations'] ?? [] as $relation) if (empty($relation['type']) || !isset($ids[$relation['from'] ?? ''], $ids[$relation['to'] ?? ''])) return false;
        return true;
    }
    private function qualified(array $facts, string $type): array
    {
        return array_values(array_filter($facts, static function(array $f) use ($type): bool {
            $a = $f['attributes'] ?? []; $spans = $f['spans'] ?? [];
            if (!$spans || ($a['blockComplete'] ?? $a['evidenceRegionComplete'] ?? true) !== true || ($a['isDocumentationExample'] ?? false)) return false;
            if ($type === 'sql') return in_array('input_at_sql_structure_error', array_column($f['relations'] ?? [], 'type'), true) && !array_diff(['diagnostic_block','error','input','query'], array_column($spans, 'role')) && (in_array($a['basisType'] ?? $a['evidenceType'] ?? '', ['syntax_error_at_input','query_structure_break'], true) || ($a['assessment'] ?? '') === 'suspected_only');
            if ($type === 'disclosure') return in_array($a['detailKind'] ?? '', ['stack_trace','internal_path','source_location','query','configuration','source_code','credentials'], true);
            if ($type === 'include') return !empty($a['inputMapping']) && !empty($f['relations']) && ($a['certainty'] ?? '') === 'suspected';
            return true;
        }));
    }
    private function reference(string $kind, string $id, string $hash, array $c): array
    {
        return ['kind' => $kind, 'id' => $id, 'sourceInstanceId' => $c['sourceInstanceId'], 'findingId' => $c['findingId'], 'pocRevision' => $c['poc']['revision'], 'pocSha256' => $c['poc']['sha256'], 'contentSha256' => $hash];
    }
    private function factRefs(array $facts, array $c): array { return array_map(fn(array $f): array => $this->reference('behavior_fact', $f['factId'], $f['contentSha256'] ?? $f['factSha256'], $c), $facts); }
    /** A negative review applies only to exact active observation facts in this request context. */
    private function reviewedFactExclusions(array $c, array $validFacts): array
    {
        $available = [];
        $reviewable = ['input_reflected', 'input_in_sql_error', 'sql_error_observed', 'sql_input_structure_indication',
            'internal_information_exposed', 'error_response', 'file_include_diagnostic', 'lfi_suspected',
            'rfi_suspected', 'sensitive_file_content_observed'];
        foreach ($validFacts as $fact) {
            if (!in_array($fact['code'], $reviewable, true) || ($fact['sourceRef']['kind'] ?? null) !== 'http_observation'
                || ($fact['requestContextSha256'] ?? null) !== ($c['requestContextSha256'] ?? null)
                || !$this->hash($fact['requestContextSha256'] ?? null)) continue;
            $available[$fact['factId']] = $fact;
        }
        $excluded = []; $reviews = [];
        foreach ($c['selection'] ?? [] as $decision) {
            if (!is_array($decision) || ($decision['kind'] ?? null) !== 'fact_review' || ($decision['decision'] ?? null) !== 'not_applicable') continue;
            $refs = $decision['factRefs'] ?? null;
            if (!is_string($decision['code'] ?? null) || !is_string($decision['observationId'] ?? null) || $decision['observationId'] === ''
                || ($decision['pocRevision'] ?? null) !== ($c['poc']['revision'] ?? null)
                || !$this->hash($decision['requestContextSha256'] ?? null)
                || ($decision['requestContextSha256'] ?? null) !== ($c['requestContextSha256'] ?? null)
                || !is_string($decision['actor'] ?? null) || trim($decision['actor']) === ''
                || !is_string($decision['reason'] ?? null) || trim($decision['reason']) === ''
                || !is_string($decision['reviewedAt'] ?? null) || $decision['reviewedAt'] === ''
                || !is_array($refs) || !array_is_list($refs) || !$refs) continue;
            $matched = []; $seen = []; $valid = true;
            foreach ($refs as $ref) {
                $id = is_array($ref) ? ($ref['id'] ?? null) : null;
                $fact = is_string($id) ? ($available[$id] ?? null) : null;
                if (!$fact || isset($seen[$id]) || ($ref['contentSha256'] ?? null) !== ($fact['contentSha256'] ?? $fact['factSha256'] ?? null)
                    || ($fact['code'] ?? null) !== $decision['code']
                    || ($fact['sourceRef']['id'] ?? null) !== $decision['observationId']) { $valid = false; break; }
                $seen[$id] = true; $matched[] = $fact;
            }
            if (!$valid) continue;
            foreach ($matched as $fact) $excluded[$fact['factId']] = true;
            $reviews[] = [
                'kind' => 'fact_review', 'decision' => 'not_applicable', 'code' => $decision['code'],
                'observationId' => $decision['observationId'], 'pocRevision' => $decision['pocRevision'],
                'requestContextSha256' => $decision['requestContextSha256'],
                'factRefs' => array_map(static fn(array $fact): array => ['id' => $fact['factId'], 'contentSha256' => $fact['contentSha256'] ?? $fact['factSha256']], $matched),
                'evidenceRefs' => $this->factRefs($matched, $c),
                'actor' => $decision['actor'], 'reason' => $decision['reason'], 'reviewedAt' => $decision['reviewedAt'],
            ];
        }
        return [$excluded, $reviews];
    }
    private function component(string $code, string $certainty, array $refs): array { return ['code' => $code, 'certainty' => $certainty, 'evidenceRefs' => $refs]; }
    private function lane(array $p): string
    {
        if (isset($p['lfi_suspected']) || isset($p['rfi_suspected'])) {
            $suffix = isset($p['lfi_suspected'], $p['rfi_suspected']) ? 'file_inclusion_suspected' : (isset($p['lfi_suspected']) ? 'lfi_suspected' : 'rfi_suspected');
            return isset($p['xss_confirmed']) ? 'xss_with_'.$suffix : (isset($p['sqli_suspected']) || isset($p['information_disclosure']) ? 'mixed' : $suffix);
        }
        if (isset($p['xss_confirmed'])) return isset($p['sqli_suspected']) ? 'xss_with_sqli_suspected' : (isset($p['sql_error_observation']) ? 'xss_with_sql_error' : (isset($p['information_disclosure']) ? 'xss_with_disclosure' : 'xss'));
        return isset($p['sqli_suspected']) ? 'sqli_suspected' : (isset($p['information_disclosure']) ? 'information_disclosure' : 'none');
    }
    private function coverage(array $c): array
    {
        $v = $c['coverage'] ?? []; $required = self::COVERAGE_FAMILIES;
        $evaluated = $this->sorted($v['evaluatedFamilies'] ?? []);
        $current = ($v['pocRevision'] ?? null) === ($c['poc']['revision'] ?? null) && ($v['pocSha256'] ?? null) === ($c['poc']['sha256'] ?? null)
            && $this->hash($v['artifactSha256'] ?? null) && !empty($v['resultVersion'])
            && ($v['artifactSha256'] ?? null) === ($c['artifactSha256'] ?? null)
            && ($v['resultVersion'] ?? null) === ($c['resultVersion'] ?? null) && ($v['mode'] ?? null) === 'active';
        $profile = in_array($v['profile'] ?? $v['profileId'] ?? '', ['triage-v1','behavior-triage-v1'], true) && in_array($v['version'] ?? $v['profileVersion'] ?? null, [1, '1'], true);
        $status = !$current || !$profile ? 'unknown' : (($v['status'] ?? null) === 'complete' && !array_diff($required, $evaluated) ? 'complete' : 'partial');
        if ($status === 'complete' && !EvaluationCoverage::certifiesComplete($v, (string) ($c['resultVersion'] ?? ''))) $status = 'partial';
        return array_replace($v, ['profile' => $v['profile'] ?? $v['profileId'] ?? 'behavior-triage-v1', 'version' => $v['version'] ?? $v['profileVersion'] ?? 1, 'requiredFamilies' => $required, 'evaluatedFamilies' => $evaluated, 'status' => $status]);
    }
    public function acceptedXssBasis(array $context): bool
    {
        $legacy = $context['legacyBasis'] ?? [];
        if (($legacy['verified'] ?? false) === true && !empty($legacy['cohortId']) && $this->hash($legacy['cohortDigest'] ?? null)
            && $this->hash($legacy['contentSha256'] ?? null) && ($legacy['baselinePocRevision'] ?? null) === ($context['poc']['revision'] ?? null)
            && ($legacy['baselinePocSha256'] ?? null) === ($context['poc']['sha256'] ?? null)) return true;
        $proofs = $context['workerProof'] ?? [];
        if (isset($proofs['proofId'])) $proofs = [$proofs];
        foreach ($proofs as $proof) if (is_array($proof) && $this->proofValid($proof, $context)) return true;
        foreach ($context['facts'] ?? [] as $fact) {
            if (($fact['mode'] ?? null) === 'active' && ($fact['kind'] ?? null) === 'behavior' && ($fact['code'] ?? null) === 'execution_observed'
                && $this->bound($fact, $context) && is_array($fact['attributes']['workerProof'] ?? null) && $this->proofValid($fact['attributes']['workerProof'], $context)) return true;
        }
        return false;
    }
    /** Semantic block identity shared with transition reconciliation. No run IDs or timestamps. */
    public function wafBlockFingerprint(array $context): ?string
    {
        if (!$this->acceptedXssBasis($context)) return null;
        $blocks = [];
        foreach ($context['facts'] ?? [] as $fact) {
            $a = $fact['attributes'] ?? [];
            if (($fact['schemaVersion'] ?? null) !== 1 || empty($fact['factId']) || !$this->hash($fact['contentSha256'] ?? $fact['factSha256'] ?? null) || ($fact['kind'] ?? null) !== 'behavior' || ($fact['mode'] ?? null) !== 'active' || ($fact['code'] ?? '') !== 'coverage_limited' || !$this->bound($fact, $context)) continue;
            if (($a['wafBlock'] ?? false) !== true || empty($a['blockEvidence']) || !in_array($a['reason'] ?? '', ['waf_block','waf_challenge'], true)) continue;
            if (($a['reason'] ?? '') === 'waf_challenge' && ($a['relevantRequestActuallyBlocked'] ?? false) !== true) continue;
            if (($a['relevantRequestActuallyBlocked'] ?? true) !== true) continue;
            // Successful execution in the same capture is incompatible with an inferred block.
            $proofs = $context['workerProof'] ?? [];
            if (isset($proofs['proofId'])) $proofs = [$proofs];
            $sameExecution = false;
            $captureRef = $fact['sourceRef']['captureRef'] ?? [];
            foreach ($proofs as $proof) {
                if (is_array($proof) && $this->proofValid($proof, $context)
                    && ($captureRef['kind'] ?? null) === ($proof['sourceKind'] ?? null)
                    && ($captureRef['id'] ?? null) === ($proof['sourceId'] ?? null)) $sameExecution = true;
            }
            if ($sameExecution && ($a['relevantRequestActuallyBlocked'] ?? false) !== true) continue;
            $blocks[] = ['reason' => $a['reason'], 'vendor' => $a['vendor'] ?? 'unknown', 'blockEvidence' => $a['blockEvidence']];
        }
        if (!$blocks) return null;
        usort($blocks, static fn(array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
        return hash('sha256', json_encode(['sourceInstanceId' => $context['sourceInstanceId'] ?? null, 'findingId' => $context['findingId'] ?? null, 'poc' => [$context['poc']['revision'] ?? null, $context['poc']['sha256'] ?? null], 'requestContextSha256'=>$context['requestContextSha256'] ?? null, 'blocks' => array_values(array_unique(array_map('json_encode', $blocks)))]));
    }
    private function waf(array $c, array $facts, bool $x): array
    {
        $current = $this->wafBlockFingerprint($c);
        $pending = $c['workflow']['wafReview']['pending'] ?? [];
        // First authoritative block of an existing X basis also holds before reconciliation.
        if ($x && $current !== null && !isset($pending[$current])) $pending[$current] = ['fingerprint' => $current];
        $unacked = []; $accepted = false;
        foreach ($pending as $key => $review) {
            $fingerprint = is_string($key) ? $key : ($review['fingerprint'] ?? null);
            if (!$fingerprint) continue;
            $ok = false;
            foreach ($c['acknowledgements'] ?? [] as $ack) {
                if (is_array($ack) && ($ack['fingerprint'] ?? null) === $fingerprint && !empty($ack['actor']) && !empty($ack['acknowledgedAt'])) $ok = true;
            }
            if ($ok) $accepted = true; else $unacked[] = $fingerprint;
        }
        return ['pending' => $x && (bool) $unacked, 'acknowledgementAccepted' => $accepted, 'fingerprint' => $current ?? ($unacked[0] ?? null), 'pendingFingerprints' => $unacked];
    }
    private function hash(mixed $v): bool { return is_string($v) && preg_match('/^[a-f0-9]{64}$/D', $v) === 1; }
    private function sorted(array $v): array { $v = array_values(array_unique($v)); sort($v); return $v; }
    private function identityHold(array $holds): bool { return (bool) array_intersect($holds, ['unsupported_schema','unsupported_policy','source_mismatch','basis_changed','poc_changed','policy_changed']); }
}
