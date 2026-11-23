<?php

namespace App\Cribrum;

final class CribrumClassifier
{
    private const VERSION = 'cribrum-1.3.8';

    private const DETECTOR_FAMILIES = [
        'reflection-triage-v1' => ['reflection'],
        'sql-triage-v1' => ['sql'],
        'file-triage-v1' => ['local_include', 'remote_include'],
        'context-triage-v1' => ['disclosure', 'waf', 'error_response'],
    ];

    private readonly string $privateDirectory;
    private readonly RulePackRepository $repository;

    public function __construct(?string $privateDirectory = null, ?string $candidateDirectory = null, private readonly string $candidateMode = 'shadow', ?array $candidatePacks = null)
    {
        $this->privateDirectory = $privateDirectory ?? dirname(__DIR__, 2).'/var/cribrum/packs';
        if (!in_array($candidateMode, ['shadow', 'active'], true)) throw new \InvalidArgumentException('Candidate mode must be shadow or active.');
        $this->repository = new RulePackRepository($this->privateDirectory, $candidateDirectory === '' ? null : $candidateDirectory, $candidatePacks);
    }

    /** Active versions are available even when an observation artifact is missing. */
    public function configuration(): array
    {
        $loaded = $this->repository->load();
        $packs = [];
        foreach ($loaded['packs'] as $pack) $packs[$pack->id] = $pack->version;
        return ['classifierVersion' => self::VERSION, 'packs' => $packs, 'diagnostics' => $loaded['diagnostics']];
    }

    public function matchesNativeDialogMarker(?string $expectedEvidence, ?string $dialogText): bool
    {
        return $this->nativeDialogMatchesMarker(trim((string) $expectedEvidence), $dialogText);
    }

    /** Pass an empty evaluation array to collect read-only rule diagnostics. */
    public function classify(ObservationInput $input, ?array &$evaluations = null): ClassificationResult
    {
        $loaded = $this->repository->load();
        $diagnostics = $loaded['diagnostics'];
        $packs = [];
        foreach ($loaded['packs'] as $pack) $packs[$pack->id] = $pack->version;

        $selectedOutputs = [];
        foreach ($loaded['packs'] as $pack) foreach ($pack->rules as $rule) {
            if ($rule->detector !== null) $selectedOutputs[$rule->detector] = array_unique([...($selectedOutputs[$rule->detector] ?? []), ...$rule->outputs]);
        }
        $completeSelection = true;
        foreach (Rule::detectorOutputs() as $id => $outputs) {
            if (array_diff($outputs, $selectedOutputs[$id] ?? []) !== []) $completeSelection = false;
        }
        if ($this->candidateMode === 'active' && (!$completeSelection || $diagnostics !== [])) {
            throw new \InvalidArgumentException('Active triage requires complete registered detector/output coverage and valid packs.');
        }
        $raw = $input->rawHtml;
        $engineIssues = [];
        $text = $input->domText;
        if ($text === null && $raw !== null) {
            try {
                $text = trim(Detector\CheckedRegex::replace('/\s+/u', ' ', html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            } catch (\Throwable $error) {
                $engineIssues[] = $this->engineIssue('text_preparation', 'raw', $error);
            }
        }
        $signals = [];
        $merged = [];
        $detectorsEvaluated = [];
        $detectorFailure = false;
        $evaluationRuns = [];
        $queryValues = [...$this->queryValues($input->url, $engineIssues, 'input_url_query'), ...$this->queryValues($input->finalUrl ?? '', $engineIssues, 'final_url_query')];
        $context = $this->context($input, $raw, $text, $engineIssues, $queryValues);
        // Source-bound carriers use a wider request-value set than the core
        // context: a payload is frequently carried in the path rather than the
        // query string. Path values stay out of the context needles, because a
        // reflected path alone must not qualify a page-wide error line.
        $needles = $this->sourceBoundNeedles($input, $queryValues);
        $coreMatches = [];
        foreach ($loaded['packs'] as $pack) {
            $shareCoreMatches = $this->repository->isShippedCore($pack);
            foreach ($pack->rules as $rule) {
                try {
                    if ($rule->detector !== null) {
                        $detector = match ($rule->detector) {
                            'reflection-triage-v1' => new Detector\ReflectionTriageDetector(),
                            'sql-triage-v1' => new Detector\SqlTriageDetector(),
                            'file-triage-v1' => new Detector\FileTriageDetector(),
                            'context-triage-v1' => new Detector\ContextTriageDetector(),
                        };
                        $detectorsEvaluated[$rule->detector] = true;
                        $evaluation = $detector->evaluate($input, $this->candidateMode);
                        $detected = $evaluation->signals;
                        $run = [
                            'pack' => $pack->id, 'version' => $pack->version, 'rule' => $rule->id,
                            'mode' => $this->candidateMode, 'outputs' => $rule->outputs,
                            ...$evaluation->toArray(),
                        ];
                        $diagnostics = [...$diagnostics, ...$evaluation->diagnostics];
                        $selectedCount = 0;
                        foreach ($detected as $signal) {
                            if (!in_array($signal['code'], $rule->outputs, true)) continue;
                            try {
                                $emitted = EvidenceSignal::make($signal['code'], $input, $signal['spans'],
                                    [...$signal['attributes'], 'rulePack' => $pack->id, 'rulePackVersion' => $pack->version, 'ruleId' => $rule->id],
                                    $signal['relations'], $signal['producer'], $this->candidateMode);
                                EvidenceSignal::validateAgainstInput($emitted, $input);
                                $signals[] = $emitted;
                                ++$selectedCount;
                            } catch (\Throwable) {
                                // A failed emission cannot invalidate earlier independently qualified signals.
                                $this->recordRunFailure($run, 'engine_emission', 'invalid_evidence_candidate');
                                $diagnostics[] = 'Rule '.$pack->id.'/'.$rule->id.' failed (invalid_evidence_candidate).';
                            }
                        }
                        $evaluationRuns[] = $run;
                        $evaluationStatus = Detector\DetectorEvaluation::aggregateStatus(array_column($run['familyOutcomes'], 'status'));
                        if ($evaluationStatus !== 'completed') $detectorFailure = true;
                        if ($evaluations !== null) $evaluations[] = [
                            'pack' => $pack->id, 'version' => $pack->version, 'rule' => $rule->id,
                            'detector' => $rule->detector, 'outcome' => $detected === [] ? 'excluded' : 'hit',
                            'shadow' => $this->candidateMode === 'shadow', 'evidenceCount' => count($detected),
                            'selectedEvidenceCount' => $selectedCount, 'evaluationStatus' => $evaluationStatus,
                            'selectedOutcome' => $selectedCount === 0 ? 'excluded' : 'hit',
                            'familyOutcomes' => $run['familyOutcomes'],
                        ];
                        continue;
                    }
                    $exclusion = null;
                    // Preserve ordered operands and gates; emission remains per original rule.
                    $matcherKey = $shareCoreMatches ? serialize([
                        $rule->representation, $rule->any, $rule->all, $rule->excludes, $rule->context, $rule->minStatus,
                    ]) : null;
                    if ($matcherKey !== null && array_key_exists($matcherKey, $coreMatches)) {
                        [$match, $exclusion] = $coreMatches[$matcherKey];
                    } else {
                        $match = $this->match($rule, $input, $raw, $text, $context, $exclusion);
                        // Exceptions follow each rule's existing catch path and are never cached.
                        if ($matcherKey !== null) $coreMatches[$matcherKey] = [$match, $exclusion];
                    }
                    if ($evaluations !== null) $evaluations[] = [
                        'pack' => $pack->id, 'version' => $pack->version, 'rule' => $rule->id,
                        'outcome' => $match === null ? 'excluded' : 'hit',
                        'exclusion' => $exclusion, 'shadow' => $rule->shadow,
                        'span' => $match === null ? null : $this->span($rule->representation, $rule->representation === 'raw' ? $raw : $text, $match['phrase']),
                    ];
                } catch (\Throwable $error) {
                    if ($rule->detector !== null) {
                        $detectorFailure = true;
                        $detectorsEvaluated[$rule->detector] = true;
                        $run = ['pack' => $pack->id, 'version' => $pack->version, 'rule' => $rule->id,
                            'mode' => $this->candidateMode, 'outputs' => $rule->outputs,
                            'detector' => $rule->detector, 'familyOutcomes' => []];
                        foreach (self::DETECTOR_FAMILIES[$rule->detector] as $family) {
                            $run['familyOutcomes'][] = ['family' => $family, 'status' => 'completed', 'stages' => [],
                                'counters' => ['candidatesConsidered' => 0, 'signalsQualified' => 0, 'candidatesExcluded' => 0]];
                        }
                        $this->recordRunFailure($run, 'engine_invocation', 'engine_error');
                        $evaluationRuns[] = $run;
                    }
                    $message = $rule->detector === null ? $error->getMessage() : 'Detector invocation failed.';
                    if ($evaluations !== null) $evaluations[] = ['pack' => $pack->id, 'version' => $pack->version, 'rule' => $rule->id, 'outcome' => 'error', 'error' => $message];
                    $diagnostics[] = 'Rule '.$pack->id.'/'.$rule->id.' failed: '.$message;
                    continue;
                }
                if ($match === null) continue;
                ['phrase' => $phrase, 'representation' => $representation] = $match;
                $signals[] = [
                    'pack' => $pack->id,
                    'version' => $pack->version,
                    'rule' => $rule->id,
                    'representation' => $representation,
                    'phrase' => $phrase,
                    'shadow' => $rule->shadow,
                ];
                if ($rule->shadow) continue;
                // Explicit active triage supersedes the old page-wide SQL/file suspicion heuristics.
                if ($this->candidateMode === 'active' && in_array($rule->claim, [Taxonomy::SQL_INJECTION, Taxonomy::LOCAL_FILE_INCLUSION, Taxonomy::REMOTE_FILE_INCLUSION], true)) continue;
                $span = $this->span($representation, $representation === 'raw' ? $raw : $text, $phrase);
                $suggestion = new ClaimSuggestion($rule->claim, $rule->state, $rule->score, [$pack->id.'/'.$rule->id], [$rule->reason], $span === null ? [] : [$span]);
                $key = $rule->claim;
                $previous = $merged[$key] ?? null;
                if ($previous === null
                    || ($previous->state !== Taxonomy::OBSERVED && $suggestion->state === Taxonomy::OBSERVED)
                    || ($previous->state === $suggestion->state && $suggestion->score > $previous->score)) {
                    $merged[$key] = $suggestion;
                } elseif ($suggestion->score === $previous->score && $suggestion->state === $previous->state) {
                    $merged[$key] = $this->merge($previous, $suggestion);
                }
            }
        }

        if ($context['payloadLooksUrl'] && isset($merged[Taxonomy::LOCAL_FILE_INCLUSION], $merged[Taxonomy::REMOTE_FILE_INCLUSION])) {
            unset($merged[Taxonomy::LOCAL_FILE_INCLUSION]);
        }

        // Qualified diagnostics are meaningful even when a broken application
        // returns HTTP 200. This does not activate candidate facts or certify
        // candidate coverage, and never promotes a diagnostic to SQL injection.
        $observedDiagnostics = (new DiagnosticClaimAdapter())->evaluate($input);
        $engineIssues = [...$engineIssues, ...$observedDiagnostics['issues']];
        foreach ($observedDiagnostics['suggestions'] as $suggestion) {
            $previous = $merged[$suggestion->claim] ?? null;
            if ($previous === null || $previous->state !== Taxonomy::OBSERVED || $suggestion->score > $previous->score) {
                $merged[$suggestion->claim] = $suggestion;
            } elseif ($suggestion->score === $previous->score) {
                $merged[$suggestion->claim] = $this->merge($previous, $suggestion);
            }
            $rule = substr($suggestion->ruleIds[0], strlen('builtin/'));
            $signals[] = ['pack' => 'builtin', 'version' => self::VERSION, 'rule' => $rule,
                'representation' => $suggestion->spans[0]['representation'],
                'phrase' => $suggestion->spans[0]['excerpt'], 'shadow' => false];
            if ($evaluations !== null) $evaluations[] = ['pack' => 'builtin', 'version' => self::VERSION,
                'rule' => $rule, 'outcome' => 'hit', 'shadow' => false, 'span' => $suggestion->spans[0]];
        }

        $sqlQuery = null;
        try { $sqlQuery = $this->sqlQueryDisclosure($input, $raw, $text, $needles, $engineIssues); }
        catch (\Throwable $error) { $engineIssues[] = $this->engineIssue('builtin_sql_query_echo', null, $error); }
        if ($sqlQuery !== null) {
            $previous = $merged[Taxonomy::INFORMATION_DISCLOSURE] ?? null;
            $merged[Taxonomy::INFORMATION_DISCLOSURE] = $previous !== null && $previous->state === $sqlQuery->state
                ? $this->merge($previous, $sqlQuery)
                : $sqlQuery;
            $signals[] = [
                'pack' => 'builtin',
                'version' => self::VERSION,
                'rule' => 'sql-query-echo',
                'representation' => $sqlQuery->spans[0]['representation'] ?? 'raw',
                'phrase' => 'SELECT ... FROM',
                'shadow' => false,
            ];
        }

        $sqlError = null;
        try {
            if ($this->candidateMode !== 'active') {
                $sqlError = $this->sqlErrorWithRequestValue($input, $raw, $text, $queryValues)
                    ?? (new SqlDiagnosticSuspicion())->suggest($input)
                    ?? (new SqlQueryInputSuspicion())->suggest($input)
                    ?? (new SqlPhqlInputSuspicion())->suggest($input);
            }
        }
        catch (\Throwable $error) { $engineIssues[] = $this->engineIssue('builtin_sql_error_request_value', null, $error); }
        if ($sqlError !== null) {
            $previous = $merged[Taxonomy::SQL_INJECTION] ?? null;
            $merged[Taxonomy::SQL_INJECTION] = $previous === null || $sqlError->score >= $previous->score
                ? $sqlError
                : $previous;
            $signals[] = [
                'pack' => 'builtin',
                'version' => self::VERSION,
                'rule' => substr($sqlError->ruleIds[0], strlen('builtin/')),
                'representation' => $sqlError->spans[0]['representation'] ?? 'raw',
                'phrase' => in_array(SqlPhqlInputSuspicion::RULE_ID, $sqlError->ruleIds, true)
                    ? 'PHQL parser error ... request value'
                    : 'database syntax error ... request value',
                'shadow' => false,
            ];
        }

        // These native carriers supply source-level observations and conservative
        // input suspicion. They do not relax the explicit active SQL detector or
        // manufacture typed facts for report qualification.
        if ($this->candidateMode !== 'active') {
            foreach ([
                'sql_serialized_diagnostic' => new SqlSerializedDiagnosticSuspicion(),
                'access_expression' => new SqlAccessExpressionSuspicion(),
                'sql_disclosed_query_input' => new SqlDisclosedQueryInputSuspicion(),
            ] as $stage => $helper) {
                try {
                    $result = $stage === 'sql_disclosed_query_input'
                        ? $helper->evaluate($input, $needles)
                        : $helper->evaluate($input);
                    $engineIssues = [...$engineIssues, ...$result['issues']];
                    foreach ($result['suggestions'] as $suggestion) {
                        $previous = $merged[$suggestion->claim] ?? null;
                        if ($previous === null
                            || ($previous->state !== Taxonomy::OBSERVED && $suggestion->state === Taxonomy::OBSERVED)
                            || ($previous->state === $suggestion->state && $suggestion->score > $previous->score)) {
                            $merged[$suggestion->claim] = $suggestion;
                        } elseif ($previous->state === $suggestion->state && $previous->score === $suggestion->score) {
                            $merged[$suggestion->claim] = $this->merge($previous, $suggestion);
                        }
                        $rule = substr($suggestion->ruleIds[0], strlen('builtin/'));
                        $signals[] = ['pack' => 'builtin', 'version' => self::VERSION, 'rule' => $rule,
                            'representation' => $suggestion->spans[0]['representation'],
                            'phrase' => $suggestion->spans[0]['excerpt'], 'shadow' => false];
                        if ($evaluations !== null) $evaluations[] = ['pack' => 'builtin', 'version' => self::VERSION,
                            'rule' => $rule, 'outcome' => 'hit', 'shadow' => false, 'span' => $suggestion->spans[0]];
                    }
                    if ($evaluations !== null && $result['suggestions'] === []) $evaluations[] = [
                        'pack' => 'builtin', 'version' => self::VERSION, 'rule' => $stage, 'outcome' => 'excluded',
                        'exclusion' => $result['issues'] === [] ? 'No qualified native carrier matched this helper family' : 'Helper evaluation was limited or failed',
                        'shadow' => false, 'span' => null,
                    ];
                } catch (\Throwable $error) {
                    $engineIssues[] = $this->engineIssue('builtin_'.$stage, 'raw', $error);
                }
            }
        }

        foreach ($this->xss($input, $raw, $text) as $suggestion) {
            if (!isset($merged[$suggestion->claim]) || $suggestion->score >= $merged[$suggestion->claim]->score) {
                $merged[$suggestion->claim] = $suggestion;
            }
        }
        foreach ($this->xssSignals($input, $raw, $text) as $signal) $signals[] = $signal;

        if ($evaluations !== null) {
            $builtinExclusions = [
                'sql-query-echo' => 'No returned SQL query disclosure matched',
                'sql-error-request-value' => 'No request-bound database syntax diagnostic matched',
                'dialog-marker' => 'Marker or native-dialog conditions not met',
                'reflection' => 'Marker or representation conditions not met',
                'reflection/entity-encoded' => 'Encoded marker conditions not met',
            ];
            foreach (['sql-query-echo', 'sql-error-request-value', 'dialog-marker', 'reflection', 'reflection/entity-encoded'] as $builtin) {
                $hit = null;
                foreach ($merged as $suggestion) {
                    if (in_array('builtin/'.$builtin, $suggestion->ruleIds, true)) $hit = $suggestion;
                }
                $encoded = $builtin === 'reflection/entity-encoded' && array_any($signals, static fn (array $signal): bool => $signal['rule'] === 'reflection/entity-encoded');
                $evaluations[] = [
                    'pack' => 'builtin', 'version' => self::VERSION, 'rule' => $builtin,
                    'outcome' => $hit !== null || $encoded ? 'hit' : 'excluded',
                    'exclusion' => $hit !== null || $encoded ? null : $builtinExclusions[$builtin],
                    'span' => $hit?->spans[0] ?? ($builtin === 'dialog-marker' && $hit !== null
                        ? $this->span('dialog', $input->dialogText, trim((string) $input->expectedEvidence))
                        : ($encoded ? $this->span('text', $text, trim((string) $input->expectedEvidence)) : null)),
                ];
            }
        }

        $suggestions = array_values($merged);
        usort($suggestions, static function (ClaimSuggestion $a, ClaimSuggestion $b): int {
            return [$a->claim, $a->state, $b->score, implode("\n", $a->ruleIds)] <=> [$b->claim, $b->state, $a->score, implode("\n", $b->ruleIds)];
        });
        usort($signals, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
        foreach ($engineIssues as $issue) {
            if (($issue['status'] ?? 'failed') !== 'limited') $diagnostics[] = 'Engine stage '.$issue['stage'].' failed ('.$issue['code'].').';
        }
        sort($diagnostics, SORT_STRING);
        $diagnostics = array_values(array_unique($diagnostics));

        $coverage = [];
        if ($detectorsEvaluated !== [] || $detectorFailure) {
            $families = [];
            foreach (self::DETECTOR_FAMILIES as $id => $supported) {
                if (isset($detectorsEvaluated[$id]) && array_diff(Rule::detectorOutputs()[$id], $selectedOutputs[$id] ?? []) === []) $families = [...$families, ...$supported];
            }
            sort($families, SORT_STRING);
            $required = ['disclosure', 'local_include', 'reflection', 'remote_include', 'sql', 'waf'];
            // A successful scan of a fragment cannot establish full capture coverage.
            $complete = $completeSelection && !$detectorFailure && $engineIssues === [] && $raw !== null && ($input->coverage['status'] ?? null) === 'complete'
                && ($input->coverage['truncated'] ?? false) !== true
                && array_diff($required, $families) === [];
            $coverage = [
                'profile' => 'behavior-triage-v1', 'version' => '1',
                'classifierVersion' => self::VERSION, 'packs' => $packs,
                'requiredFamilies' => $required, 'evaluatedFamilies' => $families,
                'status' => $raw === null ? 'unknown' : ($complete ? 'complete' : 'partial'), 'mode' => $this->candidateMode,
                'artifactSha256' => $raw === null ? null : hash('sha256', $raw),
                'inputUrlSha256' => hash('sha256', $input->url),
                'finalUrlSha256' => $input->finalUrl === null ? null : hash('sha256', $input->finalUrl),
                'limitations' => ['Supported signatures only; no exclusion of unknown vulnerabilities.', 'No browser execution proof from response reflection.'],
                'evaluation' => ['schemaVersion' => 1, 'runs' => $evaluationRuns, 'engineIssues' => $engineIssues],
            ];
        }
        return new ClassificationResult($suggestions, $signals, $packs, $diagnostics, self::VERSION, $coverage);
    }

    /** Safe execution metadata; never expose a failed pattern, input or exception message. */
    private function engineIssue(string $stage, ?string $representation, \Throwable $error): array
    {
        return ['code' => $error instanceof Detector\RegexFailure ? 'regex_error' : 'engine_error',
            'stage' => $stage, 'representation' => $representation,
            ...($error instanceof Detector\RegexFailure ? ['operation' => $error->operation, 'pcreCode' => $error->pcreCode] : [])];
    }

    private function recordRunFailure(array &$run, string $stage, string $code): void
    {
        foreach ($run['familyOutcomes'] as &$family) {
            $family['status'] = 'failed';
            $issue = [
                'code' => $code, 'detector' => $run['detector'], 'family' => $family['family'],
                'stage' => $stage, 'representation' => null,
            ];
            $existing = null;
            foreach ($family['stages'] as $index => $entry) {
                if ($entry['stage'] === $stage && $entry['representation'] === null) { $existing = $index; break; }
            }
            if ($existing === null) $family['stages'][] = ['stage' => $stage, 'representation' => null, 'status' => 'failed', 'issues' => [$issue]];
            else {
                $family['stages'][$existing]['status'] = 'failed';
                $family['stages'][$existing]['issues'][] = $issue;
            }
        }
        unset($family);
    }

    /** @return array{phrase:string,representation:string}|null */
    private function match(Rule $rule, ObservationInput $input, ?string $raw, ?string $text, array $context, ?string &$exclusion): ?array
    {
        if ($rule->minStatus !== null && ($input->httpStatus === null || $input->httpStatus < $rule->minStatus)) { $exclusion = 'httpStatus below '.$rule->minStatus.' or missing'; return null; }
        $subject = $rule->representation === 'raw' ? $raw : $text;
        if ($subject === null) { $exclusion = 'Missing '.$rule->representation.' representation'; return null; }
        foreach ($rule->context as $required) {
            if (!$context[$required]) { $exclusion = 'Missing context: '.$required; return null; }
        }
        foreach ($rule->excludes as $exclude) {
            if (mb_stripos($subject, $exclude) !== false) { $exclusion = 'Excluded phrase: '.$exclude; return null; }
        }
        if ($rule->any !== []) {
            foreach ($rule->any as $phrase) {
                if (mb_stripos($subject, $phrase) !== false) return ['phrase' => $phrase, 'representation' => $rule->representation];
            }
            $exclusion = 'No any-pattern matched';
            return null;
        }
        foreach ($rule->all as $phrase) {
            if (mb_stripos($subject, $phrase) === false) { $exclusion = 'Missing required phrase: '.$phrase; return null; }
        }

        return ['phrase' => $rule->all[0], 'representation' => $rule->representation];
    }

    /** @return array<string,bool> */
    private function context(ObservationInput $input, ?string $raw, ?string $text, array &$engineIssues, array $queryValues): array
    {
        $needles = [];
        foreach ([...$queryValues, $input->payload, $input->expectedEvidence] as $needle) {
            if (is_string($needle) && $needle !== '') $needles[] = $needle;
        }
        $haystacks = array_values(array_filter([$raw, $text], static fn (?string $value): bool => $value !== null));
        $needleInText = false;
        foreach ($needles as $needle) {
            foreach ($haystacks as $haystack) {
                if (mb_strlen($needle) >= 3 && mb_stripos($haystack, $needle) !== false) {
                    $needleInText = true;
                    break 2;
                }
            }
        }
        $filePhrases = ['failed to open stream', 'no such file or directory', 'failed opening for inclusion', 'failed opening', 'open_basedir restriction in effect'];
        $fileError = false;
        foreach ($filePhrases as $phrase) {
            foreach ($haystacks as $haystack) {
                if (mb_stripos($haystack, $phrase) !== false) { $fileError = true; break 2; }
            }
        }

        $optional = ['payloadLooksUrl' => false, 'payloadPathLike' => false];
        foreach ([
            'payloadLooksUrl' => static fn (): bool => array_any(array_filter($needles, static fn (string $needle): bool => Detector\CheckedRegex::match('/^(?:https?|ftp):\/\//iD', $needle) === 1), static fn (string $needle): bool => array_any($haystacks, static fn (string $haystack): bool => mb_strlen($needle) >= 3 && mb_stripos($haystack, $needle) !== false)),
            'payloadPathLike' => static fn (): bool => array_any($needles, static fn (string $needle): bool => Detector\CheckedRegex::match('/(?:\.\.\/|\.\\\\|\/(?:etc|proc|var|usr)\/)/', $needle) === 1),
        ] as $name => $check) {
            try { $optional[$name] = $check(); }
            catch (\Throwable $error) { $engineIssues[] = $this->engineIssue('context_'.$name, null, $error); }
        }
        return [
            'payloadPresent' => $needles !== [],
            'payloadInText' => $needleInText,
            ...$optional,
            'fileError' => $fileError,
        ];
    }

    /** @return list<string> */
    private function queryValues(?string $url, array &$engineIssues, string $stage): array
    {
        $query = parse_url((string) $url, PHP_URL_QUERY);
        if ($query === false) $engineIssues[] = ['code' => 'parser_error', 'stage' => $stage, 'representation' => null];
        if (!is_string($query) || $query === '') return [];
        $values = [];
        // PHP reports these resource limits only through warnings. Preserve its
        // parser/limits and partial values; capture only the known limit events.
        $limits = [];
        $previous = null;
        $handler = static function (int $severity, string $message, string $file, int $line) use (&$limits, &$previous): bool {
            if ($severity === E_WARNING && str_starts_with($message, 'parse_str(): Input variables exceeded ')) {
                $limits['input_variable_budget'] = (int) ini_get('max_input_vars');
                return true;
            }
            if ($severity === E_WARNING && str_starts_with($message, 'parse_str(): Input variable nesting level exceeded ')) {
                $limits['input_nesting_budget'] = (int) ini_get('max_input_nesting_level');
                return true;
            }
            return $previous === null ? false : (bool) $previous($severity, $message, $file, $line);
        };
        $previous = set_error_handler($handler, E_WARNING);
        try { parse_str($query, $values); }
        finally { restore_error_handler(); }
        // PHP 8.3 silently drops over-deep variables. Observe only key shape
        // for coverage; the native parser remains the sole source of values.
        if (!isset($limits['input_nesting_budget']) && $this->queryNestingLimited($query)) {
            $limits['input_nesting_budget'] = (int) ini_get('max_input_nesting_level');
        }
        foreach ($limits as $code => $limit) {
            $engineIssues[] = ['code' => $code, 'stage' => $stage, 'representation' => null,
                'status' => 'limited', 'counters' => ['limit' => max(0, $limit)]];
        }
        $result = [];
        foreach ($values as $value) if (is_string($value) && $value !== '') $result[] = $value;

        return $result;
    }

    private function queryNestingLimited(string $query): bool
    {
        $separators = (string) ini_get('arg_separator.input');
        $parts = $separators === '' ? [$query] : preg_split('/['.preg_quote($separators, '/').']/', $query);
        $maxVariables = (int) ini_get('max_input_vars');
        $maxDepth = (int) ini_get('max_input_nesting_level');
        $variables = 0;
        foreach ($parts as $part) {
            if ($part === '') continue;
            if ($maxVariables >= 0 && ++$variables > $maxVariables) break;
            $key = ltrim(explode("\0", urldecode(explode('=', $part, 2)[0]), 2)[0], ' ');
            $open = strpos($key, '[');
            if ($open === false || $open === 0) continue;
            $depth = 0;
            do {
                if (++$depth > $maxDepth) return true;
                $close = strpos($key, ']', $open + 1);
                if ($close === false || ($key[$close + 1] ?? '') !== '[') break;
                $open = $close + 1;
            } while (true);
        }
        return false;
    }

    /** @return array{representation:string,start:int,length:int,excerpt:string}|null */
    private function span(string $representation, ?string $subject, string $phrase): ?array
    {
        if ($subject === null) return null;
        $offset = mb_stripos($subject, $phrase);
        if ($offset === false) return null;
        $start = max(0, $offset - 60);
        $length = min(160, mb_strlen($subject) - $start);
        $excerpt = mb_substr($subject, $start, $length);

        return [
            'representation' => $representation,
            'start' => $offset,
            'length' => mb_strlen($phrase),
            'excerpt' => $excerpt,
        ];
    }

    private function merge(ClaimSuggestion $a, ClaimSuggestion $b): ClaimSuggestion
    {
        $ruleIds = array_values(array_unique([...$a->ruleIds, ...$b->ruleIds]));
        sort($ruleIds, SORT_STRING);
        $reasons = array_values(array_unique([...$a->reasons, ...$b->reasons]));
        sort($reasons, SORT_STRING);
        $spans = [];
        foreach ([...$a->spans, ...$b->spans] as $span) {
            $spans[json_encode($span)] = $span;
        }
        ksort($spans);

        return new ClaimSuggestion($a->claim, $a->state, $a->score, $ruleIds, $reasons, array_values($spans));
    }

    private function usableMarker(string $marker): bool
    {
        return mb_strlen($marker) >= 3 && count(array_unique(mb_str_split($marker))) >= 2;
    }

    private function nativeDialogMatchesMarker(string $marker, ?string $dialogText): bool
    {
        if ($marker === '' || $dialogText === null) return false;
        if ($this->usableMarker($marker)) return mb_stripos($dialogText, $marker) !== false;

        // Short markers such as alert(3)'s "3" are too weak for substring
        // matching, but exact equality with the complete dialog is execution
        // evidence supplied by the browser worker.
        return trim($dialogText) !== '' && mb_strtolower(trim($dialogText)) === mb_strtolower($marker);
    }

    /** @return list<string> */
    private function requestNeedles(ObservationInput $input, array $queryValues): array
    {
        $needles = $queryValues;
        if (is_string($input->payload) && trim($input->payload) !== '') $needles[] = $input->payload;

        return array_values(array_filter(
            array_map(static fn (string $needle): string => trim($needle), $needles),
            static fn (string $needle): bool => mb_strlen($needle) >= 3,
        ));
    }

    /**
     * Request values for source-bound carriers: query leaves, the explicit
     * payload and decoded path values that carry SQL quoting characters. A path
     * value without them cannot distinguish unescaped interpolation from a
     * bound parameter, so it stays outside these carriers.
     *
     * @return list<string>
     */
    private function sourceBoundNeedles(ObservationInput $input, array $queryValues): array
    {
        $needles = [...$queryValues];
        if (is_string($input->payload) && trim($input->payload) !== '') $needles[] = $input->payload;
        foreach ([$input->url, $input->finalUrl ?? ''] as $url) {
            foreach (Detector\RequestPathValues::fromUrl($url) as $value) {
                if (strpbrk($value, '\'"`\\') === false) continue;
                $needles[] = $value;
            }
        }

        $needles = array_filter($needles, static fn (string $needle): bool => mb_check_encoding($needle, 'UTF-8'));

        return array_values(array_unique(array_filter(
            array_map(static fn (string $needle): string => trim($needle), $needles),
            static fn (string $needle): bool => mb_strlen($needle) >= 3,
        )));
    }

    private function sqlQueryDisclosure(ObservationInput $input, ?string $raw, ?string $text, array $needles, array &$engineIssues): ?ClaimSuggestion
    {
        $queryDetector = new Detector\SqlTriageDetector();
        $queryEvidence = $queryDetector->evaluateQueryDisclosures($input, 'active');
        foreach ($queryEvidence->familyOutcomes as $family) {
            if (!in_array($family['status'], ['failed', 'limited'], true)) continue;
            $engineIssues[] = ['code' => 'query_disclosure_'.$family['status'], 'stage' => 'builtin_sql_query_echo',
                'representation' => $input->rawHtml !== null ? 'raw' : 'dom_text', 'status' => $family['status']];
        }
        if ($queryEvidence->signals !== []) {
            // Legacy suggestions carry only their original compact span shape;
            // typed evidence retains exact hashes, IDs, roles and relations.
            $spans = array_map(static fn (array $span): array => [
                'representation' => $span['representation'] === 'dom_text' ? 'text' : $span['representation'],
                'start' => $span['start'], 'length' => $span['length'], 'excerpt' => $span['excerpt'],
            ], $queryEvidence->signals[0]['spans']);
            return new ClaimSuggestion(Taxonomy::INFORMATION_DISCLOSURE, Taxonomy::OBSERVED, 86,
                ['builtin/sql-query-echo'], ['A SQL query was returned in a database diagnostic.'],
                $spans);
        }
        // Independently accepted request-specific query echoes remain supported,
        // including responses without a database-error wrapper. Their literal
        // binding is separate from the new diagnostic-only disclosure above.
        if ($needles === []) return null;

        foreach ([['raw', $raw], ['text', $text]] as [$representation, $subject]) {
            if (!is_string($subject)) continue;
            // Raw markup preserves the response's own line boundaries. The DOM
            // fallback is only used when raw is unavailable, because collapsed
            // DOM text can otherwise join an SQL example with a later reflection.
            if ($representation === 'text' && is_string($raw)) continue;
            try {
                $candidates = $queryDetector->responseQueries($subject, $input->url);
            } catch (Detector\RegexFailure) {
                $engineIssues[] = ['code' => 'query_disclosure_failed', 'stage' => 'builtin_sql_query_echo',
                    'representation' => $representation, 'status' => 'failed'];
                continue;
            }
            foreach ($candidates as $candidate) {
                $statement = $candidate['query'];
                foreach ($needles as $needle) {
                    if (!$this->needleInSqlContext($needle, $statement)) continue;
                    $start = mb_strlen(substr($subject, 0, $candidate['byte']), 'UTF-8');
                    $length = mb_strlen(substr($subject, $candidate['byte'], $candidate['rawLength'] ?? strlen($statement)), 'UTF-8');
                    $span = ['representation' => $representation, 'start' => $start,
                        'length' => $length, 'excerpt' => mb_substr($subject, $start, $length, 'UTF-8')];
                    return new ClaimSuggestion(
                        Taxonomy::INFORMATION_DISCLOSURE,
                        Taxonomy::OBSERVED,
                        86,
                        ['builtin/sql-query-echo'],
                        ['A request-specific SQL query was returned.'],
                        $span === null ? [] : [$span],
                    );
                }
            }
        }

        return null;
    }

    private function sqlErrorWithRequestValue(ObservationInput $input, ?string $raw, ?string $text, array $queryValues): ?ClaimSuggestion
    {
        static $diagnostics = [
            'You have an error in your SQL syntax',
            'MySQLSyntaxErrorException',
            'SQLException',
            'PostgreSQL ERROR:',
            'syntax error at or near',
            'SQLite3::query',
            'SQLITE_ERROR',
            'Unclosed quotation mark after the character string',
            'Microsoft SQL Server',
        ];
        $needles = $this->requestNeedles($input, $queryValues);
        if ($needles === []) return null;

        foreach ([['raw', $raw], ['text', $text]] as [$representation, $subject]) {
            if (!is_string($subject)) continue;
            // Oracle diagnostics have a numeric error code. A bare ORA- also
            // matches ordinary names such as hedemora-dack in reflected links.
            $subjectDiagnostics = $diagnostics;
            if (Detector\CheckedRegex::matchAll('/\bORA-\d{5}\b/i', $subject, $oracleCodes) > 0) {
                $subjectDiagnostics = [...$diagnostics, ...array_unique($oracleCodes[0])];
            }
            foreach ($subjectDiagnostics as $diagnostic) {
                $offset = 0;
                while (($position = mb_stripos($subject, $diagnostic, $offset)) !== false) {
                    // The SQL statement normally precedes its diagnostic on the
                    // same rendered line. A reflection elsewhere on the page is
                    // not enough to turn documentation into SQLi suspicion.
                    $lineStart = (int) mb_strrpos(mb_substr($subject, 0, $position), "\n");
                    $lineEnd = mb_strpos($subject, "\n", $position);
                    $line = mb_substr($subject, $lineStart === 0 ? 0 : $lineStart + 1, ($lineEnd === false ? mb_strlen($subject) : $lineEnd) - ($lineStart === 0 ? 0 : $lineStart + 1));
                    foreach ($needles as $needle) {
                        if ($representation === 'raw' ? mb_stripos($line, $needle) === false : !$this->needleInSqlContext($needle, $line)) continue;
                        $span = $this->span($representation, $subject, $line);
                        return new ClaimSuggestion(
                            Taxonomy::SQL_INJECTION,
                            Taxonomy::SUSPECTED,
                            90,
                            ['builtin/sql-error-request-value'],
                            ['A request value appeared inside a database syntax diagnostic.'],
                            $span === null ? [] : [$span],
                        );
                    }
                    $offset = $position + mb_strlen($diagnostic);
                }
            }
        }

        return null;
    }

    private function needleInSqlContext(string $needle, string $statement): bool
    {
        $escaped = preg_quote($needle, '/');
        // Prefer a quoted SQL literal. It binds the reflected request value to
        // the emitted query instead of a nearby prose or documentation echo.
        if (Detector\CheckedRegex::match('/(?:\'.{0,512}'.$escaped.'.{0,512}\'|".{0,512}'.$escaped.'.{0,512}")/is', $statement) === 1) {
            return true;
        }

        // Numeric or identifier-like payloads can be valid unquoted SQL values.
        // Keep this narrow: the complete needle must be one SQL token directly
        // bound by a comparison, LIKE, or IN operator.
        return Detector\CheckedRegex::match('/(?:=|LIKE|IN)\s*'.$escaped.'(?![A-Za-z0-9_])/i', $statement) === 1
            && Detector\CheckedRegex::match('/^(?:-?\d+(?:\.\d+)?|[A-Za-z_][A-Za-z0-9_]*)$/D', $needle) === 1;
    }

    /** @return list<ClaimSuggestion> */
    private function xss(ObservationInput $input, ?string $raw, ?string $text): array
    {
        $marker = trim((string) $input->expectedEvidence);
        if ($input->dialogSeen && $this->nativeDialogMatchesMarker($marker, $input->dialogText)) {
            return [new ClaimSuggestion(Taxonomy::XSS, Taxonomy::OBSERVED, 99, ['builtin/dialog-marker'], ['The expected marker appeared in a native dialog.'], [])];
        }
        if (!$this->usableMarker($marker)) return [];
        $plainRaw = $raw !== null && mb_stripos($raw, $marker) !== false;
        $plainText = $text !== null && mb_stripos($text, $marker) !== false;
        $decodedRaw = $raw === null ? null : html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!$plainRaw && $decodedRaw !== null && $plainText && mb_stripos($decodedRaw, $marker) !== false) {
            return [];
        }
        if ($plainRaw || $plainText) {
            $spans = [];
            foreach ([['raw', $raw], ['text', $text]] as [$representation, $subject]) {
                $span = $this->span($representation, $subject, $marker);
                if ($span !== null) $spans[] = $span;
            }
            return [new ClaimSuggestion(Taxonomy::XSS, Taxonomy::SUSPECTED, $plainText ? 72 : 60, ['builtin/reflection'], ['The expected marker appeared in the response without a native dialog.'], $spans)];
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function xssSignals(ObservationInput $input, ?string $raw, ?string $text): array
    {
        $marker = trim((string) $input->expectedEvidence);
        if (!$this->usableMarker($marker) || $raw === null || $text === null || mb_stripos($text, $marker) === false) return [];
        $plain = mb_stripos($raw, $marker) !== false;
        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!$plain && mb_stripos($decoded, $marker) !== false) {
            return [['pack' => 'builtin', 'version' => self::VERSION, 'rule' => 'reflection/entity-encoded', 'representation' => 'raw', 'phrase' => $marker, 'shadow' => false]];
        }

        return [];
    }
}
