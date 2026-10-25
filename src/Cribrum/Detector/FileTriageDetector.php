<?php

namespace App\Cribrum\Detector;

use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;

/** Conservative offline triage. An attempted include never proves file access or execution. */
final class FileTriageDetector
{
    public function detect(ObservationInput $input, string $mode = 'shadow'): array
    {
        return $this->evaluate($input, $mode)->signals;
    }

    public function evaluate(ObservationInput $input, string $mode = 'shadow'): DetectorEvaluation
    {
        $families = ['local_include', 'remote_include'];
        $run = new DetectorRun($input, 'file-triage-v1', $families);
        $raw = $input->rawHtml;
        if ($raw === null) {
            $run->unavailable($families, 'raw_input', 'raw', 'representation_missing');
            return $run->finish();
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $run->unavailable($families, 'raw_input', 'raw', 'representation_invalid');
            return $run->finish();
        }
        $jsonResponse = $this->isJsonResponse($raw);
        // Delimit by diagnostic line/HTML break, rather than joining unrelated page text.
        $pattern = '~(?:Warning|Fatal error|PHP Warning)(?:</?b>)?\s*:\s*(?:<[^>]*>\s*)*(?<operation>include(?:_once)?|require(?:_once)?|file_get_contents|fopen)\s*\((?<target>[^\r\n)]{0,2048})\)(?<tail>(?:(?!<br\b|</p>|</div>)[^\r\n]){0,512})~iu';
        $matches = $run->stage($families, 'operation_scan', 'raw', static function () use ($raw, $pattern) {
            CheckedRegex::matchAll($pattern, $raw, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            return $matches;
        }) ?? [];
        foreach ($matches as $match) {
            $run->stage($families, 'operation_candidate', 'raw', function () use ($run, $families, $input, $raw, $mode, $match, $jsonResponse) {
                foreach ($families as $family) $run->count($family, 'candidatesConsidered');
                [$candidateText, $candidateOffset] = $match[0];
                $this->candidate($run, $raw, $candidateOffset, strlen($candidateText));
                [$block, $offset] = $match[0];
                // Retain each warning separately even when PHP joins them with <br>.
                if (CheckedRegex::match('~<br\b|</p>|</div>~i', $block, $boundary, PREG_OFFSET_CAPTURE)) {
                    $block = substr($block, 0, $boundary[0][1]);
                }
                if (!HtmlDiagnosticContext::eligible($raw, $offset, false, static function () use ($run, $families): void {
                    $run->limited($families, 'operation_candidate', 'raw', 'context_budget', ['limit' => 128]);
                }) || ($jsonResponse && !$this->isJsonErrorResponse($raw, $offset))) return $this->excluded($run, $families);
                $text = html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!CheckedRegex::match('~failed (?:to open|opening)|failed to load|no such file|permission denied|wrapper is disabled|allow_url_include|open_basedir~i', $text)) return $this->excluded($run, $families);
                $operation = strtolower($match['operation'][0]);
                $targetRaw = $match['target'][0];
                $targetOffset = $match['target'][1];
                $target = trim(html_entity_decode(strip_tags($targetRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\"'");
                if ($target === '' && CheckedRegex::match('~Failed opening\s+(?:required\s+)?[\'\"]([^\'\"]+)[\'\"](?=\s+(?:for inclusion|\(include_path=))~i', $block, $opening, PREG_OFFSET_CAPTURE)) {
                    $targetRaw = $opening[1][0];
                    $targetOffset = $offset + $opening[1][1];
                    $target = html_entity_decode($targetRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $spans = [$this->span($raw, $offset, strlen($block))];
                $base = [
                    'family' => 'file_include', 'operation' => $operation, 'target' => $target,
                    'certainty' => 'observed', 'blockComplete' => true,
                    'contextRequired' => true, 'realPositiveCorpusValidated' => false,
                    'limitations' => ['Diagnostic is not proof of arbitrary file access or code execution.'],
                ];
                $run->add(EvidenceSignal::make('file_include_diagnostic', $input, $spans, $base, [], 'file-triage-v1', $mode));
                if (!in_array($operation, ['include', 'include_once', 'require', 'require_once'], true) || $target === '') return;
                $run->stage($families, 'input_relation', 'raw', function () use ($run, $families, $input, $raw, $offset, $block, $target, $targetRaw, $targetOffset, $base, $spans, $mode) {
                    foreach ($families as $family) $run->count($family, 'candidatesConsidered');
                    $this->candidate($run, $raw, $offset, strlen($block));
                    $values = $this->inputValues($input, $run, $families);
                    $mapped = $this->mappedInput($target, $values, $run, $families, $raw, $offset, strlen($block));
                    if ($mapped === null) return;
                    $remote = CheckedRegex::match('~^(?:https?|ftp)://~i', $target) === 1;
                    // Other stream wrappers are not silently treated as local filenames.
                    if (!$remote && CheckedRegex::match('~^[a-z][a-z0-9+.-]*://~i', $target)) return $this->excluded($run, $families);
                    if ($remote) {
                        $remoteAllowed = $run->stage('remote_include', 'remote_qualification', 'raw', function () use ($run, $raw, $offset, $block, $target, $input) {
                            $this->candidate($run, $raw, $offset, strlen($block));
                            $targetHost = parse_url($target, PHP_URL_HOST);
                            $originHost = parse_url($input->url, PHP_URL_HOST);
                            if ($targetHost === false || $originHost === false) {
                                $run->failed('remote_include', 'origin_parse', 'raw', 'parser_error');
                                return;
                            }
                            if (is_string($originHost) && !mb_check_encoding($originHost, 'UTF-8')) {
                                $run->unavailable('remote_include', 'origin_parse', null, 'input_encoding_invalid');
                                return;
                            }
                            $targetHost = strtolower((string) $targetHost);
                            $originHost = strtolower((string) $originHost);
                            if ($targetHost === '' || $targetHost === $originHost || $this->remoteIncludeDisabled($raw, $offset, strlen($block))) return $this->excluded($run, ['remote_include']);
                            // The prescribed adjacent-warning neighborhood is the rule's locality,
                            // not a page-wide search budget. Unrelated text outside it is inapplicable.
                            return true;
                        });
                        if ($remoteAllowed !== true) return;
                    }
                    $run->stage($remote ? 'remote_include' : 'local_include', 'relation_evidence', 'raw', function () use ($run, $remote, $raw, $targetOffset, $targetRaw, $base, $mapped, $input, $spans, $mode) {
                        $this->candidate($run, $raw, $targetOffset, strlen($targetRaw));
                        $targetSpan = $this->span($raw, $targetOffset, strlen($targetRaw));
                        $attributes = array_replace($base, [
                            'certainty' => 'suspected', 'contextRequired' => false,
                            'inputMapping' => $mapped, 'attemptOnly' => true,
                            'fileReadProven' => false, 'codeExecutionProven' => false,
                            'targetKind' => $remote ? 'remote' : 'local',
                        ]);
                        $run->add(EvidenceSignal::make($remote ? 'rfi_suspected' : 'lfi_suspected', $input,
                            [$spans[0], $targetSpan], $attributes,
                            [['type' => 'include_target_in_diagnostic', 'from' => 1, 'to' => 0, 'transformations' => ['html_entity_decode_once']]],
                            'file-triage-v1', $mode));
                    });
                });
            });
        }
        // Error templates can omit PHP's operation prefix. Keep this form diagnostic-only:
        // "required" does not identify require() versus require_once(), or prove an input relation.
        // A complete leaf wrapper is one diagnosis. Only blockquote may carry a leading
        // numeric badge; never concatenate target fragments across arbitrary elements.
        $headings = $run->stage($families, 'prefixless_scan', 'raw', static function () use ($raw) {
            CheckedRegex::matchAll('~<(?<element>title|h2|p|div|blockquote)\b[^>]*>(?<text>(?:\s*<span\b[^>]*>\s*\d+\s*</span>\s*)?[^<]{1,4096})</\k<element>\s*>~iu', $raw, $headings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            return $headings;
        }) ?? [];
        $quote = '(?:[\'\"]|&(?:apos|quot);|&#0*(?:39|34);|&#x0*(?:27|22);)';
        $openingPattern = '~^(?:(?:[^:\r\n]{1,40}:\s*\d+\s+)|(?:\d+\s*-\s*))?(?<block>Failed opening required\s+(?<quote>'.$quote.')(?<target>[^\r\n]{1,2048}?)\k<quote>\s+\(include_path=(?<pathQuote>'.$quote.')(?<path>[^\r\n]{1,1024}?)\k<pathQuote>\))\s*$~iu';
        foreach ($headings as $heading) {
            $run->stage($families, 'prefixless_candidate', 'raw', function () use ($run, $families, $input, $raw, $mode, $heading, $openingPattern) {
                foreach ($families as $family) $run->count($family, 'candidatesConsidered');
                [$candidateText, $candidateOffset] = $heading[0];
                $this->candidate($run, $raw, $candidateOffset, strlen($candidateText));
                $headingOffset = $heading[0][1];
                if (!HtmlDiagnosticContext::eligible($raw, $headingOffset, false, static function () use ($run, $families): void {
                    $run->limited($families, 'prefixless_candidate', 'raw', 'context_budget', ['limit' => 128]);
                }) || $this->inNonDiagnosticContainer($raw, $headingOffset)
                    || $this->inTemplate($raw, $headingOffset)) return $this->excluded($run, $families);
                $headingText = $heading['text'][0];
                $textOffset = $heading['text'][1];
                if (str_contains($headingText, '<')) {
                    if (strtolower($heading['element'][0]) !== 'blockquote'
                        || ($badgeLength = $this->numericBadgeLength($headingText)) === null) return $this->excluded($run, $families);
                    $headingText = substr($headingText, $badgeLength);
                    $textOffset += $badgeLength;
                }
                $textOffset += strspn($headingText, " \t\r\n\0\x0B");
                if (!CheckedRegex::match($openingPattern, trim($headingText), $opening, PREG_OFFSET_CAPTURE)) return $this->excluded($run, $families);
                $targetRaw = $opening['target'][0];
                $target = html_entity_decode($targetRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $path = html_entity_decode($opening['path'][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Broken or nested quote delimiters must not make an incomplete target look complete.
                if (trim($target) === '' || CheckedRegex::match('~[\'\"<>\r\n]~u', $target.$path)) return $this->excluded($run, $families);
                $run->add(EvidenceSignal::make('file_include_diagnostic', $input, [
                    $this->span($raw, $textOffset + $opening['block'][1], strlen($opening['block'][0])),
                    $this->span($raw, $textOffset + $opening['target'][1], strlen($targetRaw)),
                ], [
                    'family' => 'file_include', 'target' => $target,
                    'certainty' => 'observed', 'blockComplete' => true,
                    'contextRequired' => true, 'realPositiveCorpusValidated' => false,
                    'limitations' => ['Diagnostic is not proof of arbitrary file access or code execution.'],
                ], [], 'file-triage-v1', $mode));
            });
        }
        // Path rejection and fixed template loading errors remain hints without an include relation.
        $other = $run->stage($families, 'template_scan', 'raw', static function () use ($raw) {
            CheckedRegex::matchAll('~(?:Server\.MapPath[^\r\n<]{0,300}(?:Invalid Path|invalid character)|(?:cannot|could not|unable to) (?:find|load|open) (?:the )?template[^\r\n<]{0,300})~iu', $raw, $other, PREG_OFFSET_CAPTURE);
            return $other;
        }) ?? [];
        foreach (($other[0] ?? []) as [$block, $offset]) {
            $run->stage($families, 'template_candidate', 'raw', function () use ($run, $families, $input, $raw, $mode, $block, $offset, $jsonResponse) {
                foreach ($families as $family) $run->count($family, 'candidatesConsidered');
                [$candidateText, $candidateOffset] = [$block, $offset];
                $this->candidate($run, $raw, $candidateOffset, strlen($candidateText));
                if (!HtmlDiagnosticContext::eligible($raw, $offset, false, static function () use ($run, $families): void {
                    $run->limited($families, 'template_candidate', 'raw', 'context_budget', ['limit' => 128]);
                }) || ($jsonResponse && !$this->isJsonErrorResponse($raw, $offset))) return $this->excluded($run, $families);
                $run->add(EvidenceSignal::make('file_include_diagnostic', $input, [$this->span($raw, $offset, strlen($block))],
                    ['family' => 'file_include', 'certainty' => 'observed', 'contextRequired' => true, 'reason' => 'path_rejection_or_template_diagnostic', 'realPositiveCorpusValidated' => false], [], 'file-triage-v1', $mode));
            });
        }
        // Strong content shape only; its cause and relation to the request remain unproven.
        $content = $run->stage($families, 'content_scan', 'raw', static function () use ($raw) {
            CheckedRegex::matchAll('~(?:^|\n)(?:root:[^:\r\n]*:0:0:[^\r\n]*\n)(?:[a-z_][a-z0-9_-]*:[^:\r\n]*:\d+:\d+:[^\r\n]*)(?:\n|$)|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----[A-Za-z0-9+/=\s]{40,}-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----~m', $raw, $content, PREG_OFFSET_CAPTURE);
            return $content;
        }) ?? [];
        foreach (($content[0] ?? []) as [$block, $offset]) {
            $run->stage($families, 'content_candidate', 'raw', function () use ($run, $families, $input, $raw, $mode, $block, $offset) {
                foreach ($families as $family) $run->count($family, 'candidatesConsidered');
                [$candidateText, $candidateOffset] = [$block, $offset];
                $this->candidate($run, $raw, $candidateOffset, strlen($candidateText));
                if ($this->inCodeExample($raw, $offset)) return $this->excluded($run, $families);
                $run->add(EvidenceSignal::make('sensitive_file_content_observed', $input, [$this->span($raw, $offset, strlen($block))],
                    ['family' => 'sensitive_file_content', 'certainty' => 'observed', 'causeEstablished' => false, 'contextRequired' => true,
                        'limitations' => ['Content shape does not establish authenticity, inclusion, arbitrary read, or execution.'], 'realPositiveCorpusValidated' => false], [], 'file-triage-v1', $mode));
            });
        }
        return $run->finish();
    }

    private function excluded(DetectorRun $run, array $families): null
    {
        foreach ($families as $family) $run->count($family, 'candidatesExcluded');
        return null;
    }

    private function isJsonResponse(string $raw): bool
    {
        $trimmed = ltrim($raw);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) return false;
        json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function isJsonErrorResponse(string $raw, int $offset): bool
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || array_is_list($data)) return false;
        // Generic `message` alongside localization keys is a catalog, not an error envelope.
        $keys = array_keys($data);
        if (!array_intersect($keys, ['error', 'detail']) && count(array_diff($keys, ['message', 'code', 'status'])) > 0) return false;
        foreach ($this->jsonTopLevelFields($raw) as [$key, $start, $end]) {
            if (!in_array($key, ['error', 'message', 'detail'], true) || !array_key_exists($key, $data)
                || $offset < $start || $offset >= $end) continue;
            $value = $data[$key];
            if (is_string($value)) return true;
            if ($key === 'error' && is_array($value) && count($value) === 1 && isset($value['message']) && is_string($value['message'])) return true;
        }
        return false;
    }

    /** Byte ranges of top-level JSON values; string escapes and nested objects stay intact. */
    private function jsonTopLevelFields(string $raw): array
    {
        $fields = [];
        $length = strlen($raw);
        $i = strpos($raw, '{') + 1;
        while ($i < $length) {
            while ($i < $length && (ctype_space($raw[$i]) || $raw[$i] === ',')) ++$i;
            if ($i >= $length || $raw[$i] === '}') break;
            if ($raw[$i] !== '"') break;
            $keyStart = $i;
            $i = $this->jsonStringEnd($raw, $i);
            $key = json_decode(substr($raw, $keyStart, $i - $keyStart), true);
            while ($i < $length && ctype_space($raw[$i])) ++$i;
            if ($i >= $length || $raw[$i++] !== ':') break;
            while ($i < $length && ctype_space($raw[$i])) ++$i;
            $start = $i;
            $depth = 0;
            while ($i < $length) {
                $c = $raw[$i];
                if ($c === '"') { $i = $this->jsonStringEnd($raw, $i); continue; }
                if ($c === '{' || $c === '[') ++$depth;
                elseif ($c === '}' || $c === ']') { if ($depth === 0) break; --$depth; }
                elseif ($c === ',' && $depth === 0) break;
                ++$i;
            }
            $fields[] = [$key, $start, $i];
        }
        return $fields;
    }

    private function jsonStringEnd(string $raw, int $start): int
    {
        for ($i = $start + 1, $length = strlen($raw); $i < $length; ++$i) {
            if ($raw[$i] === '\\') { ++$i; continue; }
            if ($raw[$i] === '"') return $i + 1;
        }
        return strlen($raw);
    }

    private function candidate(DetectorRun $run, string $raw, int $offset, int $length): void
    {
        $run->candidate('raw', mb_strlen(substr($raw, 0, $offset), 'UTF-8'), mb_strlen(substr($raw, $offset, $length), 'UTF-8'));
    }

    private function span(string $raw, int $byteStart, int $byteLength): array
    {
        return EvidenceSignal::span($raw, mb_strlen(substr($raw, 0, $byteStart), 'UTF-8'), mb_strlen(substr($raw, $byteStart, $byteLength), 'UTF-8'));
    }

    private function inputValues(ObservationInput $input, DetectorRun $run, array $families): array
    {
        $values = [];
        if ($input->payload !== null && $input->payload !== '') $values[] = ['source' => 'payload', 'value' => $input->payload, 'transformations' => []];
        // The intake URL is authoritative. Redirect destinations are not attacker inputs.
        $query = $run->stage($families, 'query_input_parse', null, static function () use ($input, $run, $families) {
            $query = parse_url($input->url, PHP_URL_QUERY);
            if ($query === false) $run->failed($families, 'query_input_parse', null, 'parser_error');
            return $query;
        });
        if (is_string($query)) foreach (explode('&', $query) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) $values[] = ['source' => 'query_parameter', 'parameter' => urldecode($parts[0]), 'value' => urldecode($parts[1]), 'transformations' => ['url_decode_once']];
        }
        return $values;
    }

    private function mappedInput(string $target, array $values, DetectorRun $run, array $families, string $raw, int $offset, int $length): ?array
    {
        $validValues = [];
        foreach ($values as $value) {
            $valid = $run->stage($families, 'mapping_input', null, function () use ($value, $run, $families, $raw, $offset, $length) {
                $this->candidate($run, $raw, $offset, $length);
                if (!mb_check_encoding($value['value'], 'UTF-8')
                    || (isset($value['parameter']) && !mb_check_encoding($value['parameter'], 'UTF-8'))) {
                    $run->unavailable($families, 'mapping_input', null, 'input_encoding_invalid');
                    return false;
                }
                return true;
            });
            if ($valid === true) $validValues[] = $value;
        }
        foreach ($validValues as $value) {
            $mapped = $run->stage($families, 'mapping_candidate', 'raw', function () use ($target, $value, $run, $raw, $offset, $length) {
                $this->candidate($run, $raw, $offset, $length);
                $needle = $value['value'];
                if (mb_strlen($needle, 'UTF-8') < 4) return null;
                if ($target === $needle || CheckedRegex::match('~(?:^|[/\\\\])'.preg_quote($needle, '~').'(?:$|[./\\\\])~u', $target)) return $value;
                return null;
            });
            if ($mapped !== null) return $mapped;
        }
        return null;
    }

    private function remoteIncludeDisabled(string $raw, int $offset, int $length): bool
    {
        // PHP can report disabled-wrapper and failed-opening in adjacent warning lines.
        $nearby = substr($raw, max(0, $offset - 1000), $length + 2000);
        return CheckedRegex::match('~allow_url_include\s*(?:=|is|:)\s*(?:0|off|disabled)|(?:https?|ftp)(?:://)?[^\r\n]{0,80}wrapper is disabled|URL file-access is disabled~i', strip_tags($nearby)) === 1;
    }

    private function inCodeExample(string $raw, int $offset): bool
    {
        $prefix = substr($raw, 0, $offset);
        $open = strripos($prefix, '<code');
        $close = strripos($prefix, '</code');
        return $open !== false && ($close === false || $open > $close);
    }

    private function numericBadgeLength(string $text): ?int
    {
        if (!CheckedRegex::match('~^\s*<span\b(?<attributes>(?:[^\'"<>]|"[^"]*"|\'[^\']*\')*)>\s*\d+\s*</span>\s*~iu', $text, $badge)) return null;
        $attributes = $badge['attributes'];
        $offset = 0;
        $class = null;
        // Consume actual attributes, including their entire quoted values. Attribute-like
        // text inside title/data-class is not a class; HTML keeps the first duplicate.
        while ($offset < strlen($attributes) && trim(substr($attributes, $offset)) !== '') {
            if (!CheckedRegex::match('~\G\s+(?<name>[a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"(?<double>[^"]*)"|\'(?<single>[^\']*)\'|(?<plain>[^\s\'"=<>`]+)))?~iu', $attributes, $attribute, PREG_UNMATCHED_AS_NULL, $offset)) return null;
            if (strtolower($attribute['name']) === 'class' && $class === null) {
                $class = $attribute['double'] ?? $attribute['single'] ?? $attribute['plain'] ?? '';
            }
            $offset += strlen($attribute[0]);
        }
        if ($class === null || !in_array('badge', CheckedRegex::split('~\s+~u', trim(html_entity_decode($class, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), true)) return null;
        return strlen($badge[0]);
    }

    private function inTemplate(string $raw, int $offset): bool
    {
        $prefix = substr($raw, 0, $offset);
        CheckedRegex::matchAll('~</?template\b[^>]*>~iu', $prefix, $tags);
        $depth = 0;
        foreach ($tags[0] as $tag) $depth = str_starts_with($tag, '</') ? max(0, $depth - 1) : $depth + 1;
        return $depth > 0;
    }

    private function inNonDiagnosticContainer(string $raw, int $offset): bool
    {
        $prefix = substr($raw, 0, $offset);
        foreach (['pre', 'samp', 'script', 'style', 'textarea'] as $tag) {
            $open = strripos($prefix, '<'.$tag);
            $close = strripos($prefix, '</'.$tag);
            if ($open !== false && ($close === false || $open > $close)) return true;
        }
        $open = strrpos($prefix, '<!--');
        $close = strrpos($prefix, '-->');
        return $open !== false && ($close === false || $open > $close);
    }
}
