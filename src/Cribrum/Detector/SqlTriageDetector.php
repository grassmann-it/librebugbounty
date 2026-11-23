<?php

namespace App\Cribrum\Detector;

use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;

/** Bounded offline diagnostics; never an execution or confirmed-injection detector. */
final class SqlTriageDetector
{
    public const ID = 'sql-triage-v1';
    private const SIGNATURE = '~(?:(?:System\.Data\.)?SqlException\s*:[^\n]{1,160}|You have an error in your SQL syntax[^\n]{0,300}|ERROR:\s*(?:syntax error at or near|invalid input syntax for type|value too long for type|operator does not exist|relation [^\n]{1,120} does not exist)[^\n]{0,300}|(?:SQLSTATE\[[A-Z0-9]+\][^\n]{0,160})|(?:Falsche Syntax in der Nähe von|Incorrect syntax near|Unclosed quotation mark after the character string|Conversion failed when converting the varchar value|Fehler beim Konvertieren des varchar-Werts|String or binary data would be truncated|Cannot resolve the collation conflict|Illegal mix of collations|Can.t find FULLTEXT index matching the column list|Data too long for column|Incorrect (?:double|integer|string|datetime) value|Invalid object name|Unknown column|Table [^\n]{1,120} doesn.t exist|Data type mismatch in criteria expression)[^\n]{0,300}|Characters found after end of SQL statement\.|Syntaxfehler in Abfrageausdruck[^\n]{0,500}|Syntax error (?:\(missing operator\) )?in query expression[^\n]{0,500}|\bDB[ \t]+ERR[ \t]+(?=(?:SELECT|UPDATE|INSERT|DELETE)\b))~iu';

    /** Compatibility entry point; the full evaluation owns the single search. */
    public function detect(ObservationInput $input, string $mode = 'shadow'): array
    {
        return $this->evaluate($input, $mode)->signals;
    }

    public function evaluate(ObservationInput $input, string $mode = 'shadow'): DetectorEvaluation
    {
        $run = new DetectorRun($input, self::ID, ['sql']);
        $raw = $input->rawHtml;
        if ($raw === null) {
            $run->unavailable('sql', 'comparison', 'raw', 'representation_missing');
            return $run->finish();
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $run->unavailable('sql', 'comparison', 'raw', 'representation_invalid');
            return $run->finish();
        }
        $comparison = $run->stage('sql', 'comparison', 'raw', fn () => $this->comparison($raw, $run));
        if ($comparison === null) return $run->finish();
        [$text, $map, $jsonBlocks, $jsonRoot] = $comparison;
        $matches = $run->stage('sql', 'signature_search', 'raw', function () use ($text, $raw, $map, $jsonBlocks, $jsonRoot): array {
            CheckedRegex::matchAll(self::SIGNATURE, $text, $matches, PREG_OFFSET_CAPTURE);
            $found = $matches[0];
            if ($jsonRoot !== null) {
                $found = array_values(array_filter($found, function (array $match) use ($text, $raw, $map, $jsonRoot): bool {
                    $at = mb_strlen(substr($text, 0, $match[1]), 'UTF-8');
                    $source = $this->rawPosition($map, $at)[0];
                    return strlen(mb_substr($raw, 0, $source, 'UTF-8')) < $jsonRoot;
                }));
                foreach ($jsonBlocks as [$start, $end]) {
                    $block = mb_substr($text, $start, $end - $start, 'UTF-8');
                    CheckedRegex::matchAll(self::SIGNATURE, $block, $local, PREG_OFFSET_CAPTURE);
                    $base = strlen(mb_substr($text, 0, $start, 'UTF-8'));
                    foreach ($local[0] as [$signature, $byte]) $found[] = [$signature, $base + $byte];
                }
            }
            return $found;
        });
        if ($matches === null || $matches === []) return $run->finish();
        // The budget counts signature occurrences, including local examples and
        // repeated signatures in a block. It is not a budget of unique signals.
        if (count($matches) > 32) $run->limited('sql', 'signature_search', 'raw', 'match_budget', ['limit' => 32, 'observed' => count($matches), 'remaining' => count($matches) - 32]);
        $params = $run->stage('sql', 'parameters', 'raw', fn () => $this->parameters($input->url, $run));
        $seen = [];
        $genericCarriers = [];
        foreach (array_slice($matches, 0, 32) as [$signature, $byte]) {
            $run->count('sql', 'candidatesConsidered');
            $at = mb_strlen(substr($text, 0, $byte), 'UTF-8');
            $diagnostic = $run->stage('sql', 'diagnostic', 'raw', function () use ($run, $input, $mode, $raw, $text, $map, $jsonBlocks, $jsonRoot, $at, $signature, &$seen, &$genericCarriers, $params): ?array {
                $source = $this->rawPosition($map, $at);
                $run->candidate('raw', $source[0], $source[1]);
                foreach ($genericCarriers as [$start, $end]) {
                    // Error-looking text inside a disclosed query belongs to its
                    // generic carrier; it is no independent syntax diagnostic.
                    if ($at > $start && $at < $end) { $run->count('sql', 'candidatesExcluded'); return null; }
                }
                if (CheckedRegex::match('~^Can.t find FULLTEXT~iu', $signature)
                    && !$this->isActiveQueryText($raw, strlen(mb_substr($raw, 0, $source[0], 'UTF-8')))) return null;
                $jsonBlock = null;
                foreach ($jsonBlocks as $bounds) if ($at >= $bounds[0] && $at < $bounds[1]) $jsonBlock = $bounds;
                $sourceByte = strlen(mb_substr($raw, 0, $source[0], 'UTF-8'));
                if ($jsonBlock === null && $jsonRoot !== null && $sourceByte >= $jsonRoot) { $run->count('sql', 'candidatesExcluded'); return null; }
                if ($jsonBlock === null && !HtmlDiagnosticContext::eligible($raw, $sourceByte, false,
                    static fn () => $run->limited('sql', 'diagnostic', 'raw', 'parser_depth_budget', ['limit' => 128, 'remaining' => null]))) { $run->count('sql', 'candidatesExcluded'); return null; }
                $genericDbError = CheckedRegex::match('~^DB[ \t]+ERR[ \t]+~iu', $signature) === 1;
                $bounds = $genericDbError
                    ? $this->genericDbErrorBlock($raw, $text, $map, $at, $run)
                    : ($jsonBlock === null ? $this->block($raw, $text, $map, $at, $run) : [...$jsonBlock, true]);
                if ($bounds === null) { $run->count('sql', 'candidatesExcluded'); return null; }
                [$start, $end, $complete] = $bounds;
                if (isset($seen[$start.':'.$end])) {
                    $run->count('sql', 'duplicatesKnown');
                    return null;
                }
                $seen[$start.':'.$end] = true;
                $block = mb_substr($text, $start, $end - $start, 'UTF-8');
                if ($genericDbError && $this->suppliedGenericDiagnostic($block, $input, $params ?? [])) {
                    $run->count('sql', 'candidatesExcluded');
                    return null;
                }
                // Explicit trace sections are separate representations, even if
                // the renderer placed both in the same preformatted container.
                if (CheckedRegex::match('~(?:^|\n)\s*(?:Stack\s+trace|Call\s+stack|Traceback)\s*:?~iu', $block, $trace, PREG_OFFSET_CAPTURE)) {
                    $end = $start + mb_strlen(substr($block, 0, $trace[0][1]), 'UTF-8');
                    if ($at >= $end) return null;
                    $block = mb_substr($text, $start, $end - $start, 'UTF-8');
                }
                if (CheckedRegex::match('~^Can.t find FULLTEXT~iu', $signature) && !CheckedRegex::match("~^\s*(?:\[Emergency\]\s*)?Uncaught\s+[^\n]{1,180}DatabaseException:\s*Couldn't run query:~iu", $block)) return null;
                // A failed exclusion guard throws before this candidate qualifies.
                $rawStart = $this->rawPosition($map, $start)[0];
                $prefix = mb_substr($raw, max(0, $rawStart - 200), min(200, $rawStart), 'UTF-8');
                if ($this->insideExample($raw, $rawStart) || CheckedRegex::match('~<(?:code|samp)\b[^>]*>[^<]*$|(?:documentation|example error|sample output)\s*:?\s*$~iu', $prefix)
                    || CheckedRegex::match('~^\s*(?:example|documentation|sample|manual|search)\s*[:\-]~iu', $block)
                    || CheckedRegex::match('~^\s*You have an error in your SQL syntax\s+(?:(?:can|may)\s+occur\s+)?(?:when|if|means|indicates)\b[^;\n]{1,160}\.?\s*$~iu', $block)) {
                    $run->count('sql', 'candidatesExcluded');
                    return null;
                }
                if (!$complete) $run->limited('sql', 'diagnostic', 'raw', 'context_incomplete', ['observed' => $end - $start, 'remaining' => null]);
                $vendor = $genericDbError ? 'unknown' : $this->vendor($block);
                $nonstructure = (bool) CheckedRegex::match('~FULLTEXT index|collation|convert|conversion|Konvertieren|invalid input syntax for type|too long|truncat|data too long|incorrect (?:string|double|datetime)|does not exist|doesn.t exist|Invalid object name|Unknown column|Data type mismatch|parameter|ParamValues|\balias\s*=\s*\?~iu', $block);
                // A generic failed-query wrapper gives no syntax/engine evidence,
                // even when those words occur in a query identifier or value.
                $syntax = !$genericDbError && (bool) CheckedRegex::match('~syntax|missing operator|unclosed quotation~iu', $block);
                $attrs = ['vendor' => $vendor, 'diagnosticClass' => $genericDbError ? 'database_error' : ($nonstructure ? 'nonstructure' : ($syntax ? 'syntax' : 'database_error')), 'blockComplete' => $complete, 'assessment' => $syntax ? 'context_required' : 'diagnostic_only', 'inputAssessment' => 'context_required_no_supported_input_mapping', 'limitations' => ['offline_diagnostic_only', 'no_exploitation_or_xss_inference']];
                $spans = [$this->mappedSpan($raw, $map, $start, $end - $start, 'diagnostic_block'), $this->mappedSpan($raw, $map, $at, min($end - $at, mb_strlen($signature, 'UTF-8')), 'error')];
                foreach ($params ?? [] as $candidate) {
                    if ($candidate['ambiguous'] && $candidate['value'] !== '' && str_contains($block, $candidate['value'])) $attrs['inputAssessment'] = 'context_required_duplicate_parameter';
                }
                $run->add(EvidenceSignal::make('sql_error_observed', $input, $spans, $attrs, [], self::ID, $mode));
                if ($genericDbError) $genericCarriers[] = [$start, $end];
                return [$start, $block, $complete, $jsonBlock, $syntax, $nonstructure, $attrs, $spans, $source];
            });
            if ($diagnostic === null) continue;
            [$start, $block, $complete, $jsonBlock, $syntax, $nonstructure, $attrs, $spans, $source] = $diagnostic;
            if (!$complete || $params === null) continue;
            foreach ($params as $param) {
                $mapping = $run->stage('sql', 'input_mapping', 'raw', function () use ($run, $input, $mode, $raw, $map, $start, $block, $jsonBlock, $attrs, $spans, $param, $source): ?array {
                    $run->candidate('raw', $source[0], $source[1]);
                    $value = $param['value'];
                    if (mb_strlen($value, 'UTF-8') < 4) return null;
                    $pos = $this->inputPosition($block, $value);
                    $queryMapping = null;
                    if (CheckedRegex::match('~Can.t find FULLTEXT index matching the column list~iu', $block)) {
                        $parts = $this->disclosedQueryParts($block, $run);
                        if ($parts === null) return null;
                        [, , $query, $queryAt] = $parts;
                        $inQuery = $this->inputPosition($query, $value);
                        if ($inQuery === false) return null;
                        $pos = $queryAt + $inQuery;
                        $queryMapping = [$query, $queryAt, $this->withinSingleLiteral($query, $inQuery, $value)];
                    }
                    if ($pos === false || $param['ambiguous']) return null;
                    $relationSpans = [...$spans, $this->mappedSpan($raw, $map, $start + $pos, mb_strlen($value, 'UTF-8'), 'input')];
                    $relationAttrs = array_replace($attrs, ['inputAssessment' => 'locally_mapped', 'parameter' => $param['name'], 'inputSource' => 'url_query', 'inputValue' => $value, 'transformations' => ['url_query_decode_once', $jsonBlock === null ? 'html_entities_once_for_comparison' : 'json_string_decode_once']]);
                    $relations = [['type' => 'input_in_diagnostic', 'from' => 2, 'to' => 0, 'transformations' => $relationAttrs['transformations']]];
                    if ($queryMapping !== null) {
                        [$query, $queryAt, $withinLiteral] = $queryMapping;
                        $relationSpans[] = $this->mappedSpan($raw, $map, $start + $queryAt, mb_strlen($query, 'UTF-8'), 'query');
                        $relationAttrs['inputBinding'] = $withinLiteral ? 'whole_value_in_query_literal' : 'whole_value_in_query_expression';
                        $relations[] = ['type' => $withinLiteral ? 'input_in_query_literal' : 'input_in_query_expression', 'from' => 2, 'to' => 3, 'transformations' => $relationAttrs['transformations']];
                    }
                    $run->add(EvidenceSignal::make('input_in_sql_error', $input, $relationSpans, $relationAttrs, $relations, self::ID, $mode));
                    return [$relationSpans, $relationAttrs, $relations];
                });
                if ($mapping === null || !$syntax || $nonstructure) continue;
                [$relationSpans, $relationAttrs, $relations] = $mapping;
                $run->stage('sql', 'structure', 'raw', function () use ($run, $input, $mode, $raw, $map, $start, $block, $param, $relationSpans, $relationAttrs, $relations, $source): void {
                    $run->candidate('raw', $source[0], $source[1]);
                    $value = $param['value'];
                    $query = $this->query($block, $value, $run);
                    if ($query === null || !str_contains($query, $value) || !$this->structurePosition($query, $value)) return;
                    $queryPos = mb_strpos($block, $query, 0, 'UTF-8');
                    $relationSpans[] = $this->mappedSpan($raw, $map, $start + $queryPos, mb_strlen($query, 'UTF-8'), 'query');
                    $relationAttrs['assessment'] = 'suspected_only';
                    $relationAttrs['queryExtraction'] = 'bounded_diagnostic_expression';
                    $relations[] = ['type' => 'input_at_sql_structure_error', 'from' => 2, 'to' => 3, 'transformations' => $relationAttrs['transformations']];
                    $run->add(EvidenceSignal::make('sql_input_structure_indication', $input, $relationSpans, $relationAttrs, $relations, self::ID, $mode));
                });
            }
        }
        $run->candidate(null, null, null);
        return $run->finish();
    }

    /**
     * Query disclosure has its own bounded evaluation and never needs a request
     * value. Consumers must retain its coverage issues alongside the signals.
     */
    public function evaluateQueryDisclosures(ObservationInput $input, string $mode = 'shadow'): DetectorEvaluation
    {
        $run = new DetectorRun($input, 'sql-query-disclosure-v1', ['disclosure']);
        $raw = $input->rawHtml ?? $input->domText;
        $representation = $input->rawHtml !== null ? 'raw' : 'dom_text';
        if ($raw === null || !mb_check_encoding($raw, 'UTF-8')) {
            $run->unavailable('disclosure', 'representation', $representation, $raw === null ? 'representation_missing' : 'representation_invalid');
            return $run->finish();
        }
        SqlQueryDiagnosticFamilies::evaluate($input, $run, $mode,
            fn (string $query): bool => $this->actualStatement($query),
            fn (string $prefix): string => $this->sqlContext($prefix),
            fn (string $source, int $byte): bool => $this->isActiveQueryText($source, $byte));
        $comparison = $run->stage('disclosure', 'comparison', $representation, fn () => $representation === 'raw' ? $this->comparison($raw, $run, 'disclosure', false) : [$raw, [[0, mb_strlen($raw, 'UTF-8'), 0, mb_strlen($raw, 'UTF-8')]], []]);
        if ($comparison === null) return $run->finish();
        [$text, $map] = $comparison;
        $candidates = $run->stage('disclosure', 'query_search', $representation, function () use ($raw, $representation, $run): array {
            if ($representation === 'dom_text' || !str_contains($raw, '<')) {
                CheckedRegex::matchAll('~[^\r\n]+~u', $raw, $lines, PREG_OFFSET_CAPTURE);
                return array_values(array_filter($lines[0], static function (array $line) use ($run, $representation): bool {
                    if (strlen($line[0]) <= 8192) return true;
                    $run->limited('disclosure', 'query_search', $representation, 'context_budget', ['limit' => 8192, 'observed' => strlen($line[0]), 'remaining' => null]);
                    return false;
                }));
            }
            return $this->queryContainers($raw, $run, $representation);
        });
        if ($candidates === null) return $run->finish();
        // Count containers, including excluded examples; omitted work stays visible.
        if (count($candidates) > 128) $run->limited('disclosure', 'query_search', $representation, 'match_budget', ['limit' => 128, 'observed' => count($candidates), 'remaining' => count($candidates) - 128]);
        $seen = [];
        foreach (array_slice($candidates, 0, 128) as [$container, $byte]) {
            if (count($seen) >= 12) {
                $run->limited('disclosure', 'query_candidate', $representation, 'match_budget', ['limit' => 12, 'remaining' => null]);
                break;
            }
            $run->stage('disclosure', 'query_candidate', $representation, function () use ($run, $input, $mode, $raw, $text, $map, $container, $byte, $representation, &$seen): void {
                $rawStart = mb_strlen(substr($raw, 0, $byte), 'UTF-8');
                $run->candidate($representation, $rawStart, mb_strlen($container, 'UTF-8'));
                $run->count('disclosure', 'candidatesConsidered');
                if ($this->insideQueryExample($raw, $rawStart)) {
                    $run->count('disclosure', 'candidatesExcluded');
                    return;
                }
                // Only this closed container contributes text. Later trace/params,
                // links, source windows and another error object cannot bind it.
                $start = null; $end = null;
                foreach ($map as [$point, $length, $source, $sourceLength]) {
                    $rawEnd = $rawStart + mb_strlen($container, 'UTF-8');
                    if ($source + $sourceLength <= $rawStart || $source >= $rawEnd) continue;
                    $start ??= $point + ($length === $sourceLength ? max(0, $rawStart - $source) : 0);
                    $end = $point + $length - ($length === $sourceLength ? max(0, $source + $sourceLength - $rawEnd) : 0);
                }
                if ($start === null || $end === null) return;
                $block = mb_substr($text, $start, $end - $start, 'UTF-8');
                $parts = $this->disclosedQueryParts($block, $run, $representation);
                if ($parts === null) {
                    if (mb_strlen($block, 'UTF-8') > 4096 && CheckedRegex::match("~Couldn't run query:|An exception occurred while executing~iu", $block)) {
                        $run->limited('disclosure', 'query_candidate', $representation, 'context_budget', ['limit' => 4096, 'observed' => mb_strlen($block, 'UTF-8'), 'remaining' => null]);
                    }
                    return;
                }
                [$error, $errorAt, $query, $queryAt] = $parts;
                if (!$this->actualStatement($query)) return;
                $key = hash('sha256', $query."\0".$error);
                if (isset($seen[$key])) { $run->count('disclosure', 'duplicatesKnown'); return; }
                $seen[$key] = true;
                $spans = [
                    $this->mappedSpan($raw, $map, $start + $errorAt, mb_strlen($error, 'UTF-8'), 'error'),
                    $this->mappedSpan($raw, $map, $start + $queryAt, mb_strlen($query, 'UTF-8'), 'query'),
                ];
                foreach ($spans as &$span) $span['representation'] = $representation;
                unset($span);
                $run->add(EvidenceSignal::make('internal_information_exposed', $input, $spans, [
                    'detailKind' => 'query', 'concreteInternals' => true, 'source' => 'response_body',
                    'inputAssessment' => 'no_input_binding_claimed', 'queryExtraction' => 'bounded_database_diagnostic',
                    'limitations' => ['query_disclosure_is_not_sql_injection', 'trace_and_parameters_are_not_statement_literals'],
                ], [['type' => 'query_in_diagnostic', 'from' => 1, 'to' => 0, 'transformations' => $representation === 'raw' ? ['html_entities_once_for_comparison'] : []]], 'sql-query-disclosure-v1', $mode));
            });
        }
        if ($representation === 'raw') {
            $existing = $run->finish()->signals;
            foreach ($run->stage('disclosure', 'response_statement_search', 'raw', fn () => $this->responseQueries($raw, $input->url, $run)) ?? [] as $candidate) {
                if (!in_array($candidate['source'], ['hidden_squery', 'diagnostic_adjacent'], true)) continue;
                $covered = false;
                foreach ($existing as $signal) {
                    if ($signal['code'] !== 'internal_information_exposed') continue;
                    foreach ($signal['spans'] as $span) {
                        if (($span['role'] ?? null) === 'query' && str_contains($span['excerpt'], $candidate['query'])) $covered = true;
                    }
                }
                if ($covered) continue;
                $start = mb_strlen(substr($raw, 0, $candidate['byte']), 'UTF-8');
                $length = mb_strlen(substr($raw, $candidate['byte'], $candidate['rawLength'] ?? strlen($candidate['query'])), 'UTF-8');
                $excerpt = mb_substr($raw, $start, $length, 'UTF-8');
                $run->stage('disclosure', 'response_statement', 'raw', function () use ($run, $input, $mode, $start, $length, $excerpt, $candidate): void {
                $run->add(EvidenceSignal::make('internal_information_exposed', $input, [[
                    'representation' => 'raw', 'start' => $start, 'length' => $length,
                    'excerpt' => $excerpt, 'role' => 'query',
                ]], [
                    'detailKind' => 'query', 'concreteInternals' => true, 'source' => 'response_body',
                    'inputAssessment' => 'no_input_binding_claimed', 'queryExtraction' => $candidate['source'] === 'hidden_squery' ? 'hidden_server_generated_field' : 'bounded_response_statement',
                    'limitations' => ['query_disclosure_is_not_sql_injection'],
                ], [], 'sql-query-disclosure-v1', $mode));
                });
            }
        }
        return $run->finish();
    }

    /** Offsets refer to the once-decoded comparison, never to a repaired SQL parse. */
    private function disclosedQueryParts(string $block, DetectorRun $run, string $representation = 'raw'): ?array
    {
        // Boundary newlines are retained so raw-span offsets stay exact.
        // SilverStripe's complete header names the failed query and terminal cause.
        if (CheckedRegex::match("~^\\s*(?:\\[Emergency\\]\\s*)?Uncaught\\s+(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*DatabaseException:\\s*Couldn't run query:\\s*((?:SELECT|UPDATE|INSERT|DELETE)\\b[\\s\\S]{1,4096}?)\\s+([A-Za-z][^\\n]{2,400})\\s*$~iu", $block, $m, PREG_OFFSET_CAPTURE) && CheckedRegex::match(self::SIGNATURE, $m[2][0])) {
            return [$m[2][0], mb_strlen(substr($block, 0, $m[2][1]), 'UTF-8'), trim($m[1][0]), mb_strlen(substr($block, 0, $m[1][1]), 'UTF-8')];
        }
        // DBAL's query and params are explicitly delimited; params never become SQL.
        if (CheckedRegex::match("~^\\s*An exception occurred while executing '((?:SELECT|UPDATE|INSERT|DELETE)\\b[\\s\\S]{1,4096}?)' with params \\[[^\\n]{0,1500}\\]:\\s*([^\\n]{3,800})\\s*$~iu", $block, $m, PREG_OFFSET_CAPTURE)
            && CheckedRegex::match(self::SIGNATURE, $m[2][0])) {
            return [$m[2][0], mb_strlen(substr($block, 0, $m[2][1]), 'UTF-8'), $m[1][0], mb_strlen(substr($block, 0, $m[1][1]), 'UTF-8')];
        }
        // Existing inline/multiline diagnostic wrappers retain their old scope.
        if (!CheckedRegex::match(self::SIGNATURE, $block, $error, PREG_OFFSET_CAPTURE)
            && !CheckedRegex::match('~(?:SQL\s+error|database\s+error|Query\s+(?:failed|error))~iu', $block, $error, PREG_OFFSET_CAPTURE)) return null;
        if (CheckedRegex::match('~[\"\'{};=]|\b(?:function|return|const|let|var|documentation|example|sample|tutorial)\b~iu', substr($block, 0, $error[0][1]))) return null;
        if (!CheckedRegex::match('~\b(?:The SQL statement executed was:|Query\s*:|SQL\s*:|STATEMENT\s*:)\s*((?:SELECT|UPDATE|INSERT|DELETE)\b[^\n]{1,2000})~iu', $block, $m, PREG_OFFSET_CAPTURE)) return null;
        if (CheckedRegex::matchAll('~\b(?:The SQL statement executed was:|Query\s*:|SQL\s*:|STATEMENT\s*:)~iu', $block) !== 1) return null;
        if ($this->queryClipped($block, '~\b(?:The SQL statement executed was:|Query\s*:|SQL\s*:|STATEMENT\s*:)\s*(?:SELECT|UPDATE|INSERT|DELETE)\b~iu', $run, false, 'disclosure', $representation)) return null;
        $query = rtrim($m[1][0]);
        CheckedRegex::matchAll('~\s+(?:Params|Parameters|Stack\s+trace|Call\s+stack)\s*:~iu', $query, $tails, PREG_OFFSET_CAPTURE);
        foreach ($tails[0] as [, $byte]) {
            if ($this->sqlContext(substr($query, 0, $byte)) !== 'neutral') continue;
            $query = rtrim(substr($query, 0, $byte));
            break;
        }
        $errorText = trim(substr($block, $error[0][1], $m[0][1] - $error[0][1]));
        if ($errorText === '' || $m[0][1] <= $error[0][1] || CheckedRegex::match('~\b(?:stack\s+trace|call\s+stack|trace|params)\s*:~iu', $errorText)) return null;
        return [$errorText, mb_strlen(substr($block, 0, $error[0][1]), 'UTF-8'), $query, mb_strlen(substr($block, 0, $m[1][1]), 'UTF-8')];
    }

    /** One forward lexical pass; an unfinished quoted tag consumes its tail. */
    private function queryHtmlTokens(string $raw): array
    {
        $tokens = []; $offset = 0; $rawText = null; $size = strlen($raw);
        while ($offset < $size) {
            $open = $rawText === null ? strpos($raw, '<', $offset) : stripos($raw, '</'.$rawText, $offset);
            if ($open === false) break;
            if (substr($raw, $open, 4) === '<!--') {
                $close = strpos($raw, '-->', $open + 4);
                $end = $close === false ? $size : $close + 3;
            } else {
                $quote = null; $end = $open + 1;
                for (; $end < $size; ++$end) {
                    $char = $raw[$end];
                    if ($quote !== null) { if ($char === $quote) $quote = null; continue; }
                    if ($char === '"' || $char === "'") $quote = $char;
                    elseif ($char === '>') { ++$end; break; }
                }
            }
            $token = substr($raw, $open, $end - $open);
            $tokens[] = [$token, $open];
            if (CheckedRegex::match('~^</(script|style|textarea|title)\b~iu', $token)) $rawText = null;
            elseif (CheckedRegex::match('~^<(script|style|textarea|title)\b~iu', $token, $tag)) $rawText = strtolower($tag[1]);
            $offset = $end;
        }
        return $tokens;
    }

    /** Shared exclusion gate for the legacy request-specific raw SQL echo path. */
    public function isActiveQueryText(string $raw, int $byteOffset): bool
    {
        if ($byteOffset < 0 || $byteOffset >= strlen($raw) || !mb_check_encoding($raw, 'UTF-8')) return false;
        $tokens = $this->queryHtmlTokens($raw);
        $stack = [];
        foreach ($tokens as [$token, $byte]) {
            if ($byte > $byteOffset) break;
            if ($byteOffset < $byte + strlen($token)) return false;
            if (!CheckedRegex::match('~^<(\/?)([a-z][a-z0-9:-]*)\b~iu', $token, $tag)) continue;
            $name = strtolower($tag[2]);
            if ($tag[1] === '/') {
                $index = count($stack) - 1;
                while ($index >= 0 && $stack[$index]['name'] !== $name) --$index;
                if ($index >= 0) $stack = array_slice($stack, 0, $index);
                continue;
            }
            $parent = $stack === [] ? null : $stack[array_key_last($stack)];
            if ($parent !== null && in_array($parent['name'], ['script', 'style', 'textarea', 'title'], true)) continue;
            if (in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true) || str_ends_with($token, '/>')) continue;
            $stack[] = ['name' => $name, 'blocked' => ($parent['blocked'] ?? false) || $this->queryTagBlocked($token, $name)];
        }
        if ($stack !== [] && $stack[array_key_last($stack)]['blocked']) return false;
        $prefix = substr($raw, 0, $byteOffset);
        // Unfinished comments and quoted tags cannot turn a later SELECT into text.
        $comment = strrpos($prefix, '<!--');
        $closed = strrpos($prefix, '-->');
        if ($comment !== false && ($closed === false || $comment > $closed)) return false;
        $open = strrpos($prefix, '<');
        $close = strrpos($prefix, '>');
        return $open === false || ($close !== false && $close > $open);
    }

    private function queryTagBlocked(string $token, string $name): bool
    {
        if (in_array($name, ['script', 'style', 'textarea', 'title', 'template', 'code', 'samp'], true)) return true;
        foreach ($this->queryTagAttributes($token) as $name => $value) {
            if (!in_array($name, ['class', 'id', 'role'], true)) continue;
            if (CheckedRegex::match('~\b(?:documentation|docs|example|sample|tutorial|source-code|sourcecode|syntaxhighlighter|prettyprint|language-(?:php|sql|javascript|js|java|jsp))\b~iu', $value)) return true;
        }
        return false;
    }

    /** First actual attributes, without interpreting attribute-looking data. */
    private function queryTagAttributes(string $token, bool $withSpans = false): array
    {
        if (!CheckedRegex::match('~^</?[A-Za-z][A-Za-z0-9:-]*~', $token, $tag)) return [];
        $offset = strlen($tag[0]); $size = strlen($token); $attributes = [];
        while ($offset < $size) {
            while ($offset < $size && ctype_space($token[$offset])) ++$offset;
            if ($offset >= $size || $token[$offset] === '>' || $token[$offset] === '/') break;
            if (!CheckedRegex::match('~\G([^\s=/>]+)~', $token, $match, 0, $offset)) break;
            $name = strtolower($match[1]); $offset += strlen($match[0]);
            while ($offset < $size && ctype_space($token[$offset])) ++$offset;
            $value = ''; $valueStart = $offset;
            if ($offset < $size && $token[$offset] === '=') {
                ++$offset;
                while ($offset < $size && ctype_space($token[$offset])) ++$offset;
                if ($offset < $size && ($token[$offset] === '"' || $token[$offset] === "'")) {
                    $quote = $token[$offset++]; $valueStart = $offset; $end = strpos($token, $quote, $offset);
                    if ($end === false) break;
                    $value = substr($token, $offset, $end - $offset); $offset = $end + 1;
                } else {
                    $start = $offset; $valueStart = $offset;
                    while ($offset < $size && !ctype_space($token[$offset]) && $token[$offset] !== '>') ++$offset;
                    $value = substr($token, $start, $offset - $start);
                }
            }
            if (!array_key_exists($name, $attributes)) $attributes[$name] = $withSpans
                ? ['value' => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'raw' => $value, 'byte' => $valueStart]
                : html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attributes;
    }

    /** Lexical element boundaries: quoted tag text is never a child element. */
    private function queryContainers(string $raw, DetectorRun $run, string $representation): array
    {
        $tokens = $this->queryHtmlTokens($raw);
        $stack = []; $result = []; $candidates = 0;
        foreach ($tokens as [$token, $byte]) {
            if (!CheckedRegex::match('~^<(\/?)([a-z][a-z0-9:-]*)\b~iu', $token, $tag)) continue;
            $name = strtolower($tag[2]);
            if ($tag[1] === '/') {
                $index = count($stack) - 1;
                while ($index >= 0 && $stack[$index]['name'] !== $name) --$index;
                if ($index < 0) continue;
                $open = $stack[$index];
                $stack = array_slice($stack, 0, $index);
                if (!$open['candidate'] || $open['blocked']) continue;
                if ($byte + strlen($token) - $open['byte'] > 8192) {
                    $run->limited('disclosure', 'query_search', $representation, 'context_budget', ['limit' => 8192, 'remaining' => null]);
                    continue;
                }
                $result[] = [substr($raw, $open['byte'], $byte + strlen($token) - $open['byte']), $open['byte']];
                continue;
            }
            // Inside raw-text elements only their own closing tag has meaning.
            if ($stack !== [] && in_array($stack[array_key_last($stack)]['name'], ['script', 'style', 'textarea', 'title'], true)) continue;
            $parent = $stack === [] ? null : $stack[array_key_last($stack)];
            $candidate = in_array($name, ['h1', 'strong', 'pre', 'p', 'td'], true);
            if ($candidate && ++$candidates > 128) {
                $run->limited('disclosure', 'query_search', $representation, 'match_budget', ['limit' => 128, 'remaining' => null]);
                break;
            }
            $blocked = ($parent['blocked'] ?? false) || $this->queryTagBlocked($token, $name);
            if (in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true) || str_ends_with($token, '/>')) continue;
            if (count($stack) >= 64) {
                $run->limited('disclosure', 'query_search', $representation, 'parser_depth_budget', ['limit' => 64, 'remaining' => null]);
                break;
            }
            $stack[] = ['name' => $name, 'byte' => $byte, 'candidate' => $candidate, 'blocked' => $blocked];
        }
        foreach ($stack as $open) {
            if ($open['candidate'] && !$open['blocked'] && CheckedRegex::match('~SQLSTATE|DatabaseException|SQL\s+error|database\s+error|Query\s*(?::|failed|error)|An exception occurred while executing~iu', substr($raw, $open['byte'], 8192))) {
                $run->limited('disclosure', 'query_search', $representation, 'context_incomplete', ['limit' => 8192, 'remaining' => null]);
            }
        }
        usort($result, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        return $result;
    }

    private function insideQueryExample(string $raw, int $at): bool
    {
        if ($this->insideExample($raw, $at)) return true;
        $prefix = mb_substr($raw, 0, $at, 'UTF-8');
        foreach (['script', 'style'] as $tag) {
            $open = mb_strripos($prefix, '<'.$tag, 0, 'UTF-8');
            $close = mb_strripos($prefix, '</'.$tag, 0, 'UTF-8');
            if ($open !== false && ($close === false || $open > $close)) return true;
        }
        $local = mb_substr($prefix, -300, null, 'UTF-8');
        return (bool) CheckedRegex::match('~(?:documentation|example|sample|tutorial)\s*:?\s*(?:<[^>]{1,100}>\s*)*$~iu', $local);
    }

    private function actualStatement(string $query): bool
    {
        // Require an actual relational statement, not a lone keyword/quote/comment.
        return (bool) CheckedRegex::match('~^(?:SELECT\s+[^;]{1,3500}?\s+FROM\s+[\w.`"\[\]]{1,180}|UPDATE\s+[\w.`"\[\]]{1,180}\s+SET\s+|INSERT\s+INTO\s+[\w.`"\[\]]{1,180}\s*\(|DELETE\s+FROM\s+[\w.`"\[\]]{1,180})~isu', $query);
    }

    /** Bounded SQL-shaped response text, including only a form's sQuery value. */
    public function responseQueries(string $subject, ?string $requestUrl = null, ?DetectorRun $run = null): array
    {
        if (!mb_check_encoding($subject, 'UTF-8')) return [];
        $requestValues = [];
        if ($requestUrl !== null) {
            parse_str((string) parse_url($requestUrl, PHP_URL_QUERY), $params);
            array_walk_recursive($params, static function ($value) use (&$requestValues): void {
                if (is_scalar($value)) $requestValues[] = (string) $value;
            });
        }
        $candidates = [];
        // A rendered statement may contain reflected HTML inside an SQL
        // literal. Read one response line, rather than ending it at the first
        // '<', and still require an active SQL-shaped line and local structure.
        if (CheckedRegex::matchAll('~(?:^|[\r\n])[ \t]*(SELECT[^\r\n]{1,4096})~iu', $subject, $lines, PREG_OFFSET_CAPTURE) > 0) {
            if (count($lines[1]) > 128) $run?->limited('disclosure', 'response_statement_search', 'raw', 'match_budget', ['limit' => 128, 'observed' => count($lines[1]), 'remaining' => count($lines[1]) - 128]);
            foreach (array_slice($lines[1], 0, 128) as [$query, $byte]) {
                $query = rtrim($query);
                if (!$this->isActiveQueryText($subject, $byte) || !$this->actualStatement($query) || !$this->markupInsideSqlLiterals($query) || $this->requestContainsQuery($query, $requestValues)) continue;
                if (!CheckedRegex::match('~\b(?:WHERE|JOIN|GROUP\s+BY|ORDER\s+BY|HAVING|LIMIT)\b~iu', $query)) continue;
                $candidates[] = ['query' => $query, 'byte' => $byte, 'source' => 'response_text'];
            }
        }
        // A debug page can echo one complete statement across several rendered
        // lines. Read a bounded block instead of a single line, and keep every
        // guard the line candidate applies, including markup only inside SQL
        // literals. A blank line, a closing block tag or the response end bounds
        // the statement; no following dump is read into it.
        if (CheckedRegex::matchAll('~(?:^|[\r\n])[ \t]*(SELECT\b[\s\S]{1,4096}?)(?=[\r\n][ \t]*[\r\n]|<(?:/p\b|/div\b|/pre\b|/li\b|/td\b|/tr\b|/h[1-6]\b|/body\b|br\b)|$)~iu', $subject, $blocks, PREG_OFFSET_CAPTURE) > 0) {
            if (count($blocks[1]) > 32) $run?->limited('disclosure', 'response_statement_search', 'raw', 'match_budget', ['limit' => 32, 'observed' => count($blocks[1]), 'remaining' => count($blocks[1]) - 32]);
            foreach (array_slice($blocks[1], 0, 32) as [$query, $byte]) {
                $query = rtrim($query);
                if (!$this->isActiveQueryText($subject, $byte) || !$this->actualStatement($query) || !$this->markupInsideSqlLiterals($query) || $this->requestContainsQuery($query, $requestValues)) continue;
                if (!CheckedRegex::match('~\b(?:WHERE|JOIN|GROUP\s+BY|ORDER\s+BY|HAVING|LIMIT|SET|VALUES)\b~iu', $query)) continue;
                $candidates[] = ['query' => $query, 'byte' => $byte, 'source' => 'response_block'];
            }
        }
        if (CheckedRegex::matchAll('~<p>\s*(SELECT\s+[\s\S]{1,2000}?\s+FROM\s+[\s\S]{1,2000}?)</p>~iu', $subject, $paragraphs, PREG_OFFSET_CAPTURE) > 0) {
            if (count($paragraphs[1]) > 32) $run?->limited('disclosure', 'response_statement_search', 'raw', 'match_budget', ['limit' => 32, 'observed' => count($paragraphs[1]), 'remaining' => count($paragraphs[1]) - 32]);
            foreach (array_slice($paragraphs[1], 0, 32) as [$query, $byte]) {
                if (!$this->isActiveQueryText($subject, $byte) || !$this->actualStatement($query) || $this->requestContainsQuery($query, $requestValues)) continue;
                $candidates[] = ['query' => $query, 'byte' => $byte, 'source' => 'response_text'];
            }
        }
        if (CheckedRegex::match('~^\s*SELECT\s+~iu', $subject)) {
            $line = strtok($subject, "\r\n");
            $line = explode(' :: ', (string) $line, 2)[0];
            if ($this->actualStatement($line) && !$this->requestContainsQuery(trim($line), $requestValues)) {
                $candidates[] = ['query' => trim($line), 'byte' => strspn($subject, " \t"), 'source' => 'response_text'];
            }
        }
        if (CheckedRegex::matchAll('~(?:^|\r?\n)[ \t]*(SELECT\s+COUNT\s*\([^\r\n]{1,2000}?\s+FROM\s+[A-Za-z_`][A-Za-z0-9_.`]*[^\r\n]{0,1000})~iu', $subject, $aggregate, PREG_OFFSET_CAPTURE) > 0) {
            if (count($aggregate[1]) > 32) $run?->limited('disclosure', 'response_statement_search', 'raw', 'match_budget', ['limit' => 32, 'observed' => count($aggregate[1]), 'remaining' => count($aggregate[1]) - 32]);
            foreach (array_slice($aggregate[1], 0, 32) as [$query, $byte]) {
                if (!$this->isActiveQueryText($subject, $byte) || !$this->actualStatement($query) || $this->requestContainsQuery(trim($query), $requestValues)) continue;
                $after = substr($subject, $byte + strlen($query), 1200);
                if (!CheckedRegex::match('~^(?:\s|<br\s*/?>){0,80}(You have an error in your SQL syntax[^\r\n]{0,300})~iu', $after)) continue;
                $candidates[] = ['query' => rtrim($query), 'byte' => $byte, 'source' => 'diagnostic_adjacent'];
            }
        }
        $inputsSeen = 0;
        foreach ($this->queryHtmlTokens($subject) as [$token, $byte]) {
            if (!CheckedRegex::match('~^<input\b~iu', $token)) continue;
            if (++$inputsSeen > 128) {
                $run?->limited('disclosure', 'response_statement_search', 'raw', 'match_budget', ['limit' => 128, 'remaining' => null]);
                break;
            }
            if (!$this->isActiveQueryText(substr($subject, 0, $byte).' X'.substr($subject, $byte), $byte + 1)) continue;
            $attrs = $this->queryTagAttributes($token, true);
            if (strtolower($attrs['type']['value'] ?? '') !== 'hidden' || strtolower($attrs['name']['value'] ?? '') !== 'squery') continue;
            if (!isset($attrs['value'])) continue;
            $query = $attrs['value']['value'];
            if (!$this->actualStatement($query) || $this->requestContainsQuery($query, $requestValues)) continue;
            $candidates[] = ['query' => $query, 'byte' => $byte + $attrs['value']['byte'], 'rawLength' => strlen($attrs['value']['raw']), 'source' => 'hidden_squery'];
        }
        if (CheckedRegex::matchAll('~\b(?:SELECT\s+[^\r\n<>]{1,2000}?\s+FROM\s+[A-Za-z_`][A-Za-z0-9_.`]*(?:\s+[^\r\n<>]{1,2000})?|UPDATE\s+[A-Za-z_`][A-Za-z0-9_.`]*\s+SET\s+[^\r\n<>]{1,2000}|INSERT\s+INTO\s+[A-Za-z_`][A-Za-z0-9_.`]*\s*\([^\r\n<>]{1,2000}|DELETE\s+FROM\s+[A-Za-z_`][A-Za-z0-9_.`]*[^\r\n<>]{1,2000})~iu', $subject, $matches, PREG_OFFSET_CAPTURE) > 0) {
            if (count($matches[0]) > 128) $run?->limited('disclosure', 'response_statement_search', 'raw', 'match_budget', ['limit' => 128, 'observed' => count($matches[0]), 'remaining' => count($matches[0]) - 128]);
            foreach (array_slice($matches[0], 0, 128) as [$query, $byte]) {
                if (!$this->isActiveQueryText($subject, $byte) || !$this->actualStatement($query) || $this->requestContainsQuery($query, $requestValues)) continue;
                // A statement requires SQL structure beyond SELECT/FROM words.
                if (!CheckedRegex::match('~\b(?:WHERE|JOIN|GROUP\s+BY|ORDER\s+BY|LIMIT|HAVING|SET|VALUES)\b|\b(?:COUNT|SUM|AVG|MIN|MAX)\s*\(~iu', $query)) continue;
                $candidates[] = ['query' => trim($query), 'byte' => $byte, 'source' => 'response_text'];
            }
        }
        return $candidates;
    }

    /** Tags in a rendered SQL line are only credible inside quoted values. */
    private function markupInsideSqlLiterals(string $query): bool
    {
        $quote = null;
        for ($i = 0, $size = strlen($query); $i < $size; ++$i) {
            $char = $query[$i];
            if ($quote !== null) {
                if ($char === '\\') { ++$i; continue; }
                if ($char === $quote) {
                    if (($query[$i + 1] ?? null) === $quote) { ++$i; continue; }
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') { $quote = $char; continue; }
            if ($char === '<' && CheckedRegex::match('~^<(?:/?[A-Za-z]|!|\?)[^>]{0,512}>~', substr($query, $i))) return false;
        }
        return true;
    }

    private function requestContainsQuery(string $query, array $values): bool
    {
        $normalized = static fn (string $value): string => trim((string) CheckedRegex::replace('~\s+~u', ' ', rtrim(trim($value), ';')));
        $query = $normalized($query);
        foreach ($values as $value) {
            if ($query === $normalized($value)) return true;
        }
        return false;
    }

    private function vendor(string $block): string
    {
        // Query identifiers and values cannot identify the engine. Only inspect
        // the provider prefix and the beginning of the local diagnostic message.
        $diagnostic = CheckedRegex::split('~\bThe SQL statement executed was:|\b(?:Query|SQL|STATEMENT)\s*:\s*(?=(?:SELECT|UPDATE|INSERT|DELETE)\b)|\bSQL\s+(?=(?:SELECT|UPDATE|INSERT|DELETE)\b)~iu', $block, 2)[0];
        if (!CheckedRegex::match(self::SIGNATURE, $diagnostic, $error, PREG_OFFSET_CAPTURE)) return 'unknown';
        $prefix = substr($diagnostic, 0, $error[0][1]);
        // Quoted preamble fragments may themselves be reflected input.
        $prefix = CheckedRegex::replace('~[\'"`].*$~su', '', $prefix);
        $message = substr($diagnostic, $error[0][1]);
        $message = CheckedRegex::replace('~^SQLSTATE\[[A-Z0-9]+\]\s*:?\s*(?:(?:General error|Syntax error or access violation)\s*:\s*)?(?:\d+\s+)?~iu', '', $message);
        if (CheckedRegex::match('~\[ODBC Microsoft Access Driver\]|\bMicrosoft\s+(?:JET Database Engine|Access (?:Database Engine|Driver))\b|\bMicrosoft\.(?:JET|ACE)\.OLEDB\b~iu', $prefix)
            || CheckedRegex::match('~^(?:Syntax error (?:\(missing operator\) )?in query expression|Syntaxfehler in Abfrageausdruck)\b~iu', $message)) return 'access_jet';
        if (CheckedRegex::match('~\[MySQL(?: (?:ODBC |JDBC )?Driver)?\]|\b(?:MySql\.Data\.MySqlClient\.MySqlException|mysqli_sql_exception)\b|^\s*MySQL\s*$~iu', $prefix)
            || CheckedRegex::match('~^(?:You have an error in your SQL syntax|Illegal mix of collations|Data too long for column|Incorrect (?:double|integer|string|datetime) value)\b~iu', $message)) return 'mysql';
        if (CheckedRegex::match('~\[PostgreSQL(?: (?:ODBC |JDBC )?Driver)?\]|\bPG::[A-Za-z]*Error\b|^\s*PostgreSQL\s*$~iu', $prefix)
            || CheckedRegex::match('~^ERROR:\s*(?:syntax error at or near|invalid input syntax for type|value too long for type character varying|relation [^\n]{1,120} does not exist)\b~iu', $message)) return 'postgresql';
        if (CheckedRegex::match('~\[SQLServer\]|\[ODBC SQL Server Driver\]|\[SQL Server\]|\bMicrosoft\s+(?:OLE DB Provider for SQL Server|(?:SQL\s+)?Server)\b~iu', $prefix)
            || CheckedRegex::match('~^(?:System\.Data\.SqlException\s*:|Conversion failed when converting the varchar value|Fehler beim Konvertieren des varchar-Werts|Unclosed quotation mark after the character string|String or binary data would be truncated|Cannot resolve the collation conflict|Invalid object name)~iu', $message)) return 'sql_server';
        // SQLSTATE, a generic ERROR prefix and framework names do not identify an engine.
        return 'unknown';
    }

    private function insideExample(string $raw, int $at): bool
    {
        $prefix = mb_substr($raw, 0, $at, 'UTF-8');
        foreach (['code', 'samp'] as $tag) {
            if (!CheckedRegex::matchAll('~<'.$tag.'\b[^>]*>~iu', $prefix, $opens, PREG_OFFSET_CAPTURE)) continue;
            $last = $opens[0][count($opens[0]) - 1];
            $open = mb_strlen(substr($prefix, 0, $last[1]), 'UTF-8');
            $close = mb_strripos($prefix, '</'.$tag, 0, 'UTF-8');
            if ($close === false || $open > $close) return true;
        }
        return false;
    }

    private function inputPosition(string $block, string $value): int|false
    {
        $offset = 0; $length = mb_strlen($value, 'UTF-8');
        while (($pos = mb_strpos($block, $value, $offset, 'UTF-8')) !== false) {
            $before = $pos > 0 ? mb_substr($block, $pos - 1, 1, 'UTF-8') : '';
            $after = mb_substr($block, $pos + $length, 1, 'UTF-8');
            $word = '~[\\pL\\pN_]~u';
            if (!(CheckedRegex::match($word, mb_substr($value, 0, 1, 'UTF-8')) && CheckedRegex::match($word, $before))
                && !(CheckedRegex::match($word, mb_substr($value, -1, 1, 'UTF-8')) && CheckedRegex::match($word, $after))) return $pos;
            $offset = $pos + 1;
        }
        return false;
    }

    private function parameters(string $url, DetectorRun $run): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) return [];
        $result = []; $counts = [];
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name); $value = urldecode($value);
            if (!mb_check_encoding($value, 'UTF-8') || !mb_check_encoding($name, 'UTF-8')) {
                $run->unavailable('sql', 'parameters', 'raw', 'input_invalid_encoding');
                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + 1;
            $result[] = ['name' => $name, 'value' => $value];
        }
        foreach ($result as &$param) $param['ambiguous'] = $counts[$param['name']] > 1 || str_contains($param['name'], '[');
        return $result;
    }

    private function query(string $block, string $value, DetectorRun $run): ?string
    {
        if (CheckedRegex::match("~(?:query expression|Abfrageausdruck)\\s+'(.+)'\\s*\\.?\\s*$~isu", trim($block), $m)) return $m[1];
        // Yii's executed SQL belongs to this same complete diagnostic block.
        // Repeated object markers are ambiguous, even within one HTML container.
        $executed = CheckedRegex::matchAll('~\\bThe SQL statement executed was:\\s*~iu', $block);
        if ($executed > 0) {
            if ($executed === 1 && $this->queryClipped($block, '~\bThe SQL statement executed was:\s*(?:SELECT|UPDATE|INSERT|DELETE)\b~iu', $run)) return null;
            if ($executed === 1 && CheckedRegex::match('~\\bThe SQL statement executed was:\\s*((?:SELECT|UPDATE|INSERT|DELETE)\\b[^\\n]{1,2000})\\s*$~iu', trim($block), $m)) return trim($m[1]);
            return null;
        }
        if ($this->queryClipped($block, '~\b(?:Query|SQL|STATEMENT)\s*:\s*(?:SELECT|UPDATE|INSERT|DELETE)\b~iu', $run)) return null;
        if (CheckedRegex::match('~\b(?:Query|SQL|STATEMENT)\s*:\s*((?:SELECT|UPDATE|INSERT|DELETE)\b[^\n]{1,2000})~iu', $block, $m)) return $m[1];
        // Lucee exposes adjacent Message/SQL cells in one database error object.
        // The full input must also occur in the near diagnostic, not only in SQL.
        if ($this->queryClipped($block, '~^\s*Lucee[^\n]{0,80}Error\s*\(database\)\s+Message\s+.*?\s+SQL\s+(?:SELECT|UPDATE|INSERT|DELETE)\b~isu', $run, true)) return null;
        if (CheckedRegex::match('~^\s*Lucee[^\n]{0,80}Error\s*\(database\)\s+Message\s+(.*?)\s+SQL\s+((?:SELECT|UPDATE|INSERT|DELETE)\b.{1,2000})\s*$~isu', $block, $m)
            && CheckedRegex::match("~\\bnear\\s+'(.*?)'\\s+at line \\d+~isu", $m[1], $near)
            && ($this->inputPosition($near[1], $value) !== false
                // MySQL may start near just after the quote that broke the literal.
                // The complete input is still required verbatim in the SQL cell.
                || (str_starts_with($value, "'") && !str_starts_with($value, "''")
                    && $this->inputPosition($near[1], mb_substr($value, 1, null, 'UTF-8')) !== false))) return trim($m[2]);
        return null;
    }

    /** Detect a remainder actually clipped by the existing 2000-character capture. */
    private function queryClipped(string $block, string $prefixPattern, DetectorRun $run, bool $multiline = false, string $family = 'sql', string $representation = 'raw'): bool
    {
        if (!CheckedRegex::match($prefixPattern, $block, $prefix, PREG_OFFSET_CAPTURE)) return false;
        $tail = substr($block, $prefix[0][1] + strlen($prefix[0][0]));
        if (!$multiline) $tail = substr($tail, 0, strcspn($tail, "\n"));
        if (mb_strlen(rtrim($tail), 'UTF-8') <= 2000) return false;
        $run->limited($family, 'structure', $representation, 'context_budget', ['limit' => 2000, 'observed' => mb_strlen(rtrim($tail), 'UTF-8'), 'remaining' => null]);
        return true;
    }

    private function structurePosition(string $query, string $value): bool
    {
        if (CheckedRegex::match('~\?|\$\d+|@[a-z_]\w*|:[a-z_]\w*~iu', $query)) return false;
        $pos = $this->inputPosition($query, $value);
        if ($pos === false) return false;
        $before = mb_substr($query, 0, $pos, 'UTF-8');
        $context = $this->sqlContext($before);
        // Only a neutral operand or the supported single-quoted literal may qualify.
        if ($context === 'neutral' && $this->comparisonOperand($before, false) && !is_numeric($value)) return true;
        // A quote must terminate a literal, rather than be a doubled data quote.
        if ($context !== 'single' || str_contains($value, "\\") || !CheckedRegex::match("~(?<!')'(?:'')*(?!')~u", $value)) return false;
        // Quote-bearing input at a string operand can break that operand's syntax.
        if (str_contains($value, "'") && $this->comparisonOperand($before, true)) return true;
        // A leading, unpaired quote closes the application's wildcard prefix.
        return str_starts_with($value, "'") && !str_starts_with($value, "''")
            && $this->comparisonOperand($before, true, true);
    }

    /** A reflected link key after a broken quote is not itself a SQL operand. */
    private function comparisonOperand(string $prefix, bool $quoted, bool $wildcard = false): bool
    {
        $identifier = '(?:[\\pL_][\\pL\\pN_$]*|"(?:[^"\\\\]|"")+"|`(?:[^`\\\\]|``)+`|\\[(?:[^\\]\\\\]|\\]\\])+\\])';
        $column = $identifier.'(?:\\s*\\.\\s*'.$identifier.')*';
        // Access may concatenate columns and literal separators before LIKE.
        // Keep the terms bounded and rooted in a column, rather than URL tails.
        $term = $column.'(?:\\s*\\(\\s*'.$column.'\\s*\\))?';
        $literal = "'(?:[^'\\\\]|''){0,128}'";
        $operand = $term.'(?:\\s*&\\s*(?:'.$term.'|'.$literal.')){0,8}';
        $definitions = '(?(DEFINE)(?<operand0>'.$operand.'))';
        $alternatives = ['(?&operand0)'];
        for ($depth = 1; $depth <= 4; ++$depth) {
            $definitions = substr($definitions, 0, -1).'(?<operand'.$depth.'>\\(\\s*(?&operand'.($depth - 1).')\\s*\\)))';
            $alternatives[] = '(?&operand'.$depth.')';
        }
        $operand = '(?:'.implode('|', $alternatives).')';
        $operator = $wildcard ? '\\bLIKE' : ($quoted ? '(?:=|<>|!=|\\bLIKE)' : '(?:=|<>|!=|<=|>=|<|>)');
        $suffix = $quoted ? "'".($wildcard ? '[%_]+' : '') : '';
        $pattern = '~'.$definitions.'(?:^\\s*|(?<!\\S)(?:WHERE|AND|OR|ON|HAVING)\\s+)(?:\\(\\s*){0,8}'.$operand.'\\s*'.$operator.'\\s*'.$suffix.'$~iu';
        if (!CheckedRegex::match($pattern, $prefix, $match, PREG_OFFSET_CAPTURE)) return false;
        // A clause-looking substring inside a literal/comment is still data.
        return $this->sqlContext(substr($prefix, 0, $match[0][1])) === 'neutral';
    }

    private function withinSingleLiteral(string $query, int $point, string $value): bool
    {
        if ($this->sqlContext(mb_substr($query, 0, $point, 'UTF-8')) !== 'single') return false;
        for ($i = 0, $length = strlen($value); $i < $length; ++$i) {
            if ($value[$i] === '\\') return false;
            if ($value[$i] !== "'") continue;
            if (($value[$i + 1] ?? '') !== "'") return false;
            ++$i; // SQL's doubled quote remains inside this same literal.
        }
        return true;
    }

    /** Bounded lexical context only; uncertain escape dialects stay unqualified. */
    private function sqlContext(string $prefix): string
    {
        $state = 'neutral'; $commentDepth = 0;
        for ($i = 0, $length = strlen($prefix); $i < $length; ++$i) {
            $char = $prefix[$i]; $next = $prefix[$i + 1] ?? '';
            if ($state === 'line_comment') {
                if ($char === "\n" || $char === "\r") $state = 'neutral';
                continue;
            }
            if ($state === 'block_comment') {
                if ($char === '/' && $next === '*') { ++$commentDepth; ++$i; }
                elseif ($char === '*' && $next === '/') {
                    ++$i;
                    if (--$commentDepth === 0) $state = 'neutral';
                }
                continue;
            }
            if ($state !== 'neutral') {
                // Backslash escaping varies by database and session settings.
                if ($char === "\\") return 'unknown';
                $close = match ($state) { 'single' => "'", 'double' => '"', 'backtick' => '`', 'bracket' => ']' };
                if ($char !== $close) continue;
                if ($next === $close) { ++$i; continue; }
                $state = 'neutral';
                continue;
            }
            if ($char === '-' && $next === '-' || $char === '#') { $state = 'line_comment'; continue; }
            if ($char === '/' && $next === '*') { $state = 'block_comment'; $commentDepth = 1; ++$i; continue; }
            if ($char === '$' && CheckedRegex::match('~^\$(?:[a-z_][a-z0-9_]*)?\$~i', substr($prefix, $i))) return 'unknown';
            $state = match ($char) { "'" => 'single', '"' => 'double', '`' => 'backtick', '[' => 'bracket', default => 'neutral' };
        }
        return $state;
    }

    /** Comparison is single entity decoding with a codepoint map to exact raw spans. */
    private function comparison(string $raw, DetectorRun $run, string $family = 'sql', bool $includeJson = true): array
    {
        $text = ''; $map = []; $offset = 0; $rawPoint = 0; $textPoint = 0; $jsonBlocks = [];
        $append = static function (string $piece, int $rawStart, int $rawLength) use (&$text, &$map, &$textPoint): void {
            $length = mb_strlen($piece, 'UTF-8');
            if ($length === 0) return;
            $map[] = [$textPoint, $length, $rawStart, $rawLength];
            $text .= $piece; $textPoint += $length;
        };
        CheckedRegex::matchAll('~<!--.*?-->|</?[a-zA-Z][^>]{0,2048}>|&(?:#[0-9]{1,8}|\#x[0-9a-fA-F]{1,8}|[a-zA-Z][a-zA-Z0-9]{1,31});~su', $raw, $tokens, PREG_OFFSET_CAPTURE);
        $events = $tokens[0];
        // Raw-token comparison is independent of JSON-tail recognition. A
        // failed JSON search must not erase this already available comparison.
        $jsonResult = $includeJson ? $run->stage($family, 'json_comparison', 'raw', fn () => $this->jsonMessages($raw, $run, $family)) : null;
        foreach ($jsonResult['messages'] ?? [] as [$token, $byte]) {
            $decodedPieces = $run->stage($family, 'json_string_comparison', 'raw', static function () use ($run, $raw, $token, $byte): array {
                $run->candidate('raw', mb_strlen(substr($raw, 0, $byte), 'UTF-8'), mb_strlen($token, 'UTF-8'));
                CheckedRegex::matchAll('~\\\\u[dD][89aAbB][0-9a-fA-F]{2}\\\\u[dD][c-fC-F][0-9a-fA-F]{2}|\\\\u[0-9a-fA-F]{4}|\\\\.|[^\\\\]+~su', substr($token, 1, -1), $pieces, PREG_OFFSET_CAPTURE);
                $decoded = [];
                foreach ($pieces[0] as [$piece, $pieceByte]) {
                    $decoded[] = [json_decode('"'.$piece.'"', true, 2, JSON_THROW_ON_ERROR), 1 + mb_strlen(substr($token, 1, $pieceByte), 'UTF-8'), mb_strlen($piece, 'UTF-8')];
                }
                return $decoded;
            });
            if ($decodedPieces === null) continue;
            $events = array_values(array_filter($events, static fn (array $event): bool => $event[1] < $byte || $event[1] >= $byte + strlen($token)));
            $events[] = [$token, $byte, true, $decodedPieces];
        }
        usort($events, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        foreach ($events as $event) {
            [$token, $byte] = $event;
            $plain = substr($raw, $offset, $byte - $offset);
            $plainLength = mb_strlen($plain, 'UTF-8');
            $append($plain, $rawPoint, $plainLength); $rawPoint += $plainLength;
            $tokenLength = mb_strlen($token, 'UTF-8');
            if ($event[2] ?? false) {
                $start = $textPoint;
                // Decode JSON escapes exactly once, mapping a surrogate pair as
                // one codepoint to both raw escapes. No recursive entity pass.
                foreach ($event[3] as [$decoded, $piecePoint, $pieceLength]) {
                    $append($decoded, $rawPoint + $piecePoint, $pieceLength);
                }
                $jsonBlocks[] = [$start, $textPoint];
            }
            elseif ($token[0] === '&') $append(html_entity_decode($token, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $rawPoint, $tokenLength);
            elseif (CheckedRegex::match('~^</?(?:br|p|div|pre|td|tr|li|h[1-6])\\b~i', $token)) $append("\n", $rawPoint, $tokenLength);
            $rawPoint += $tokenLength; $offset = $byte + strlen($token);
        }
        $plain = substr($raw, $offset);
        $append($plain, $rawPoint, mb_strlen($plain, 'UTF-8'));
        $jsonRoot = $jsonResult['root'] ?? null;
        if (($jsonResult['messages'] ?? []) !== [] && $jsonBlocks === []) $jsonRoot = null;
        return [$text, $map, $jsonBlocks, $jsonRoot];
    }

    /** Complete JSON response/tail, with an immediate error.message value only. */
    private function jsonMessages(string $raw, DetectorRun $run, string $family): array
    {
        $tailByte = max(0, strlen($raw) - 32768);
        // A byte budget may land inside a valid multibyte codepoint. Discard
        // that partial codepoint before applying Unicode regular expressions.
        while ($tailByte < strlen($raw) && (ord($raw[$tailByte]) & 0xc0) === 0x80) ++$tailByte;
        if ($tailByte > 0) $run->limited($family, 'json_comparison', 'raw', 'representation_budget', ['limit' => 32768, 'observed' => strlen($raw), 'remaining' => $tailByte]);
        $tail = substr($raw, $tailByte);
        CheckedRegex::matchAll('~(?:^|[>\r\n])\s*(\{)~u', $tail, $starts, PREG_OFFSET_CAPTURE);
        $startsCount = count($starts[1]);
        foreach (array_reverse(array_slice($starts[1], -16)) as [, $relative]) {
            $byte = $tailByte + $relative;
            if ($byte === $tailByte && $byte > 0 && !str_contains(">\r\n", $raw[$byte - 1])) continue;
            $json = trim(substr($raw, $byte));
            $run->candidate('raw', mb_strlen(substr($raw, 0, $byte), 'UTF-8'), mb_strlen($json, 'UTF-8'));
            $object = json_decode($json, true, 64);
            if (json_last_error() === JSON_ERROR_DEPTH) $run->limited($family, 'json_comparison', 'raw', 'parser_depth_budget', ['limit' => 64, 'remaining' => null]);
            // Non-JSON text is outside this representation's grammar.
            if (!is_array($object) || json_last_error() !== JSON_ERROR_NONE) continue;
            CheckedRegex::matchAll('~"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^\s{}\[\]:,]+~su', $json, $tokens, PREG_OFFSET_CAPTURE);
            $index = 0; $messages = [];
            $this->jsonMessageValues($tokens[0], $index, [], $messages);
            return ['messages' => array_map(static fn (array $message): array => [$message[0], $byte + $message[1]], $messages), 'root' => $byte];
        }
        if ($startsCount > 16) $run->limited($family, 'json_comparison', 'raw', 'input_budget', ['limit' => 16, 'observed' => $startsCount, 'remaining' => $startsCount - 16]);
        return ['messages' => [], 'root' => null];
    }

    private function jsonMessageValues(array $tokens, int &$index, array $path, array &$messages): void
    {
        [$token, $byte] = $tokens[$index++];
        if ($token === '{' || $token === '[') {
            $close = $token === '{' ? '}' : ']';
            while ($tokens[$index][0] !== $close) {
                $key = null;
                if ($token === '{') {
                    $key = json_decode($tokens[$index++][0], true);
                    ++$index; // colon; the enclosing JSON has already validated.
                }
                $this->jsonMessageValues($tokens, $index, [...$path, $key], $messages);
                if ($tokens[$index][0] === ',') ++$index;
            }
            ++$index;
        } elseif ($token[0] === '"' && ($path === ['data', 'error', 'message'] || $path === ['error', 'message'])) {
            $messages[] = [$token, $byte];
        }
    }

    /** Locate a comparison codepoint in a compact, piecewise raw mapping. */
    private function rawPosition(array $map, int $point): array
    {
        $lo = 0; $hi = count($map) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2); [$start, $length, $rawStart, $rawLength] = $map[$mid];
            if ($point < $start) { $hi = $mid - 1; continue; }
            if ($point >= $start + $length) { $lo = $mid + 1; continue; }
            return $length === $rawLength ? [$rawStart + $point - $start, 1] : [$rawStart, $rawLength];
        }
        throw new \LogicException('Comparison span is outside the mapped raw artifact.');
    }

    /** A terse DB ERR label qualifies only with its adjacent, SQL-shaped text. */
    private function genericDbErrorBlock(string $raw, string $text, array $map, int $at, DetectorRun $run): ?array
    {
        // The label starts a rendered line; prose, code assignments and quoted
        // examples cannot borrow a later query. Shared HTML guards run first.
        $before = mb_substr($text, 0, $at, 'UTF-8');
        $newline = strrpos($before, "\n");
        if (trim($newline === false ? $before : substr($before, $newline + 1)) !== '') return null;
        $rawAt = $this->rawPosition($map, $at)[0];
        $tail = mb_substr($raw, $rawAt, 4097, 'UTF-8');
        $clipped = mb_strlen($tail, 'UTF-8') > 4096;
        if ($clipped) $tail = mb_substr($tail, 0, 4096, 'UTF-8');
        // A single raw text region may contain newlines. Never combine a label
        // in one HTML container with SQL or FROM in another container.
        $markup = strpos($tail, '<');
        if ($clipped && $markup === false) $run->limited('sql', 'diagnostic', 'raw', 'context_budget', ['limit' => 4096, 'remaining' => null]);
        $fragment = $markup === false ? $tail : substr($tail, 0, $markup);
        if (!CheckedRegex::match('~^DB[ \t]+ERR[ \t]+((?:SELECT|UPDATE|INSERT|DELETE)\b[\s\S]+)$~iu', $fragment, $match)
            || !$this->actualStatement($match[1])) return null;
        if (CheckedRegex::match('~^SELECT\b~iu', $match[1])) {
            CheckedRegex::matchAll('~\bFROM\s+[\w.`"\[\]]{1,180}~iu', $match[1], $froms, PREG_OFFSET_CAPTURE);
            $relational = false;
            foreach ($froms[0] as [, $byte]) {
                if ($this->sqlContext(substr($match[1], 0, $byte)) === 'neutral') { $relational = true; break; }
            }
            if (!$relational) return null;
        }
        $rawEnd = $rawAt + mb_strlen($fragment, 'UTF-8');
        $end = $at;
        $length = mb_strlen($text, 'UTF-8');
        while ($end < $length && $this->rawPosition($map, $end)[0] < $rawEnd) ++$end;
        $rawByte = strlen(mb_substr($raw, 0, $rawAt, 'UTF-8'));
        $after = substr($raw, $rawByte + strlen($fragment));
        $complete = CheckedRegex::match('~^</(?:div|p|pre|strong|td|h1)\s*>~iu', $after) === 1
            || ($after === '' && !str_contains(substr($raw, 0, $rawByte), '<'));
        return [$at, $end, $complete];
    }

    /** A full diagnostic supplied by the request adds no server error evidence. */
    private function suppliedGenericDiagnostic(string $block, ObservationInput $input, array $params): bool
    {
        $normalize = static fn (string $value): string => trim(CheckedRegex::replace('~\s+~u', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $diagnostic = $normalize($block);
        $values = array_column($params, 'value');
        if ($input->payload !== null) $values[] = $input->payload;
        foreach ($values as $value) if (str_contains($normalize($value), $diagnostic)) return true;
        return false;
    }

    private function block(string $raw, string $text, array $map, int $at, DetectorRun $run): array
    {
        $textLength = mb_strlen($text, 'UTF-8');
        $rawAt = $this->rawPosition($map, $at)[0];
        $before = mb_substr($raw, 0, $rawAt, 'UTF-8');
        // An unvalidated JSON-looking message cannot borrow completeness from
        // EOF or an outer HTML container. Valid local values are handled above.
        if (CheckedRegex::match('~"message"\s*:\s*"(?:[^"\\\\]|\\\\.)*$~su', mb_substr($before, -4096, null, 'UTF-8'))) {
            $end = $at;
            while ($end < $textLength && $end - $at < 4096 && mb_substr($text, $end, 1, 'UTF-8') !== "\n") ++$end;
            return [$at, $end, false];
        }
        // Classic IIS names one Error Type item. Its provider/message pair ends
        // at the next BR; browser, page and support-footer items are separate.
        $localPrefix = mb_substr($before, max(0, $rawAt - 1024), null, 'UTF-8');
        $iis = '~<li\b[^>]{0,256}>\s*Error Type:\s*<br\s*/?>\s*(Microsoft OLE DB Provider for ODBC Drivers\s*\(0x[0-9a-f]+\)\s*<br\s*/?>\s*(?:\[[^\]\r\n]{1,80}\]){1,4}\s*)$~iu';
        $jet = '~(Microsoft JET Database Engine</font>\s*<font\b[^>]{0,256}>error\s*[\'"][0-9a-f]{8}[\'"]</font>\s*<p>\s*<font\b[^>]{0,256}>)$~iu';
        $isJet = str_starts_with(mb_substr($raw, $rawAt, 80, 'UTF-8'), 'Characters found after end of SQL statement.');
        if (CheckedRegex::match($iis, $localPrefix, $local, PREG_OFFSET_CAPTURE)
            || ($isJet && CheckedRegex::match($jet, $localPrefix, $local, PREG_OFFSET_CAPTURE))) {
            $open = max(0, $rawAt - 1024) + mb_strlen(substr($localPrefix, 0, $local[1][1]), 'UTF-8');
            $suffix = mb_substr($raw, $rawAt, 2048, 'UTF-8');
            if (CheckedRegex::match($isJet ? '~</font>~iu' : '~<br\s*/?>~iu', $suffix, $boundary, PREG_OFFSET_CAPTURE)) {
                $rawEnd = $rawAt + mb_strlen(substr($suffix, 0, $boundary[0][1]), 'UTF-8');
                $start = $at; $end = $at;
                while ($start > 0 && $this->rawPosition($map, $start - 1)[0] >= $open) --$start;
                while ($end < $textLength && $this->rawPosition($map, $end)[0] < $rawEnd) ++$end;
                return [$start, $end, true];
            }
        }
        // Recognize only the leading, adjacent Message and SQL rows of a Lucee
        // database error table. Do not join arbitrary cells or later stack text.
        $tablePrefix = mb_substr($before, max(0, $rawAt - 4096), null, 'UTF-8');
        if (CheckedRegex::matchAll('~<table\b[^>]{0,512}\bid=["\']-lucee-err["\'][^>]{0,512}>~iu', $tablePrefix, $tables, PREG_OFFSET_CAPTURE)) {
            $last = $tables[0][count($tables[0]) - 1];
            $open = max(0, $rawAt - 4096) + mb_strlen(substr($tablePrefix, 0, $last[1]), 'UTF-8');
            $fragment = mb_substr($raw, $open, 8192, 'UTF-8');
            $cell = '(?:(?!</?(?:td|tr|table)\b).)*';
            $pattern = '~^<table\b[^>]*>\s*<tr\b[^>]*>\s*<td\b[^>]*>Lucee[^<]{0,80}Error\s*\(database\)</td>\s*</tr>\s*'
                .'<tr\b[^>]*>\s*<td\b[^>]*>Message</td>\s*<td\b[^>]*>('.$cell.')</td>\s*</tr>\s*'
                .'<tr\b[^>]*>\s*<td\b[^>]*>SQL</td>\s*<td\b[^>]*>('.$cell.')</td>\s*</tr>~isu';
            if (CheckedRegex::match($pattern, $fragment, $object, PREG_OFFSET_CAPTURE)) {
                $messageStart = $open + mb_strlen(substr($fragment, 0, $object[1][1]), 'UTF-8');
                $messageEnd = $messageStart + mb_strlen($object[1][0], 'UTF-8');
                if ($rawAt >= $messageStart && $rawAt < $messageEnd) {
                    $rawEnd = $open + mb_strlen($object[0][0], 'UTF-8');
                    $start = $at; $end = $at;
                    while ($start > 0 && $at - $start < 2048 && $this->rawPosition($map, $start - 1)[0] > $open) --$start;
                    while ($end < $textLength && $this->rawPosition($map, $end)[0] < $rawEnd && $end - $start < 4096) ++$end;
                    return [$start, $end, $end - $start < 4096 && $at - $start < 2048];
                }
            }
        }
        $nearest = null;
        foreach (['pre', 'strong', 'h1', 'p', 'td'] as $tag) {
            if (!CheckedRegex::matchAll('~<'.$tag.'\\b[^>]*>~iu', $before, $opens, PREG_OFFSET_CAPTURE)) continue;
            $last = $opens[0][count($opens[0]) - 1];
            $open = mb_strlen(substr($before, 0, $last[1]), 'UTF-8');
            $closeBefore = mb_strripos($before, '</'.$tag, 0, 'UTF-8');
            if ($closeBefore !== false && $closeBefore > $open) continue;
            if ($nearest === null || $open > $nearest[0]) $nearest = [$open, $tag];
        }
        if ($nearest !== null) {
            [$open, $tag] = $nearest;
            $close = mb_stripos($raw, '</'.$tag, $rawAt, 'UTF-8');
            // HTML paragraphs may close implicitly at the next block element.
            if ($tag === 'p' && CheckedRegex::match('~</?p\\b|<div\\b|</(?:body|html)\\b|<hr\\b~iu', mb_substr($raw, $rawAt, null, 'UTF-8'), $implicit, PREG_OFFSET_CAPTURE)) {
                $implicitClose = $rawAt + mb_strlen(substr(mb_substr($raw, $rawAt, null, 'UTF-8'), 0, $implicit[0][1]), 'UTF-8');
                if ($close === false || $implicitClose < $close) $close = $implicitClose;
            }
            $rawEnd = $close === false ? mb_strlen($raw, 'UTF-8') : $close;
            $start = $at; $end = $at;
            while ($start > 0 && $at - $start < 2048 && $this->rawPosition($map, $start - 1)[0] > $open) --$start;
            while ($end < $textLength && $this->rawPosition($map, $end)[0] < $rawEnd && $end - $start < 4096) ++$end;
            return [$start, $end, $close !== false && $end - $start < 4096 && $at - $start < 2048];
        }
        $start = $at; $end = $at; $length = mb_strlen($text, 'UTF-8');
        while ($start > 0 && mb_substr($text, $start - 1, 1, 'UTF-8') !== "\n" && $at - $start < 1024) --$start;
        $prefixComplete = $start === 0 || mb_substr($text, $start - 1, 1, 'UTF-8') === "\n";
        // The complete visible diagnostic/query still qualifies locally. Only
        // the unexamined preceding line context lacks a completion proof.
        if (!$prefixComplete) $run->limited('sql', 'diagnostic', 'raw', 'context_budget', ['limit' => 1024, 'observed' => $at - $start, 'remaining' => null]);
        while ($end < $length && mb_substr($text, $end, 1, 'UTF-8') !== "\n" && $end - $start < 4096) ++$end;
        // EOF is acceptable for plain text, but an unclosed HTML tag/block is not.
        $tail = mb_substr($raw, $this->rawPosition($map, max($start, $end - 1))[0], null, 'UTF-8');
        $complete = $end - $start < 4096 && !CheckedRegex::match('~<[^>]*$~s', $tail);
        return [$start, $end, $complete];
    }

    private function mappedSpan(string $raw, array $map, int $start, int $length, string $role): array
    {
        $first = $this->rawPosition($map, $start); $last = $this->rawPosition($map, $start + $length - 1);
        return EvidenceSignal::span($raw, $first[0], $last[0] + $last[1] - $first[0]) + ['role' => $role];
    }
}
