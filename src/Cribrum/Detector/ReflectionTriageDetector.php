<?php

namespace App\Cribrum\Detector;

use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;

/** Offline, bounded reflection observations. Neither a parser nor an execution proof. */
final class ReflectionTriageDetector
{
    private const MAX_INPUTS = 32;
    private const MAX_SIGNALS = 24;
    private const MAX_VALUE_LENGTH = 1024;
    private const CONTEXT_WINDOW = 8192;

    public function detect(ObservationInput $input, string $mode = 'shadow'): array
    {
        return $this->evaluate($input, $mode)->signals;
    }

    public function evaluate(ObservationInput $input, string $mode = 'shadow'): DetectorEvaluation
    {
        $run = new DetectorRun($input, 'reflection-triage-v1', ['reflection']);
        $sources = [];
        $qualified = 0;
        $run->stage('reflection', 'input_sources', null, function () use ($input, $run, &$sources): void {
            $sources = $this->sources($input, $run);
        });
        // Raw and captured DOM are independent; absent optional sources are not failures.
        if ($input->rawHtml === null && $input->domText === null) {
            $run->unavailable('reflection', 'representation', null, 'missing_representation');
        }
        foreach (['raw' => $input->rawHtml, 'dom_text' => $input->domText] as $representation => $raw) {
            if ($raw === null) continue;
            if (!mb_check_encoding($raw, 'UTF-8')) {
                $run->unavailable('reflection', 'representation', $representation, 'invalid_representation');
                continue;
            }
            $run->stage('reflection', 'representation', $representation, static function (): void {});
            $seen = [];
            foreach ($sources as $source) {
                $value = $source['value'];
                $short = mb_strlen($value, 'UTF-8') < 4;
                $literal = preg_quote($value, '~');
                $encoded = ($source['comparisonTransformations'] ?? []) === [] ? $this->entityPattern($value) : $literal;
                $boundary = $short ? '(?<![\pL\pN_])' : '';
                $endBoundary = $short ? '(?![\pL\pN_])' : '';
                foreach (array_unique([$literal, $encoded]) as $pattern) {
                    $run->stage('reflection', 'match', $representation, function () use ($run, $representation, $raw, $source, $value, $short, $boundary, $endBoundary, $pattern, $input, $mode, &$seen, &$qualified): void {
                        $offset = 0;
                        $pattern = '~'.$boundary.'(?:'.$pattern.')'.$endBoundary.'~u';
                        while ($qualified < self::MAX_SIGNALS && CheckedRegex::match($pattern, $raw, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                            [$excerpt, $start] = $match[0];
                            $offset = $start + strlen($excerpt);
                            $key = $start.':'.strlen($excerpt).':'.$value.':'.implode(',', $source['comparisonTransformations'] ?? []);
                            if (isset($seen[$key])) continue;
                            $seen[$key] = true;
                            $run->count('reflection', 'candidatesConsidered');
                            $run->stage('reflection', 'context', $representation, function () use ($run, $representation, $raw, $start, $excerpt, $value, $input, $source, $short, $mode, &$qualified): void {
                                $run->candidate($representation, mb_strlen(substr($raw, 0, $start), 'UTF-8'), mb_strlen($excerpt, 'UTF-8'));
                                $transformed = ($source['comparisonTransformations'] ?? []) !== [];
                                $encoding = $transformed ? 'base64_decoded_input' : ($excerpt === $value ? 'literal' : 'html_entity_encoded');
                                $context = $representation === 'dom_text'
                                    ? ['context' => 'captured_dom_text', 'contextRequired' => true, 'reason' => 'raw_context_not_established']
                                    : $this->context($raw, $start, strlen($excerpt), $input->contentType);
                                if ($context['reason'] === 'context_window_exhausted') {
                                    $run->limited('reflection', 'context', $representation, 'context_budget', ['limit' => self::CONTEXT_WINDOW]);
                                }
                                $reasons = [];
                                if ($short) $reasons[] = 'short_input_value';
                                if ($source['ambiguous']) $reasons[] = 'ambiguous_input_source';
                                if ($context['contextRequired']) $reasons[] = $context['reason'];
                                $span = EvidenceSignal::span($raw, mb_strlen(substr($raw, 0, $start), 'UTF-8'), mb_strlen($excerpt, 'UTF-8'));
                                $span['representation'] = $representation;
                                $run->add(EvidenceSignal::make('input_reflected', $input, [$span], [
                                    'family' => 'reflection', 'certainty' => 'observed',
                                    'encoding' => $encoding, 'context' => $context['context'],
                                    'contextBasis' => 'bounded_lexical_observation',
                                    'contextRequired' => $reasons !== [], 'reviewReasons' => $reasons,
                                    'inputSources' => $source['sources'],
                                    'comparisonTransformations' => [...($source['comparisonTransformations'] ?? []), ...($excerpt === $value ? [] : ['html_entity_decode_once'])],
                                    'inputValueEquality' => $transformed ? 'transformed_correspondence' : 'original_value',
                                    ...($context['attributes'] ?? []),
                                    'executionProven' => false, 'xssEstablished' => false,
                                    'limitations' => ['Reflection and lexical context do not establish browser interpretation or script execution.'],
                                ], [], 'reflection-triage-v1', $mode));
                                ++$qualified;
                            });
                        }
                    });
                    if ($qualified >= self::MAX_SIGNALS) {
                        // No extra search just to prove exhaustion: the rest remains unknown.
                        $run->limited('reflection', 'match', $representation, 'match_budget', ['limit' => self::MAX_SIGNALS, 'remaining' => null]);
                        return $run->finish();
                    }
                }
            }
        }
        return $run->finish();
    }

    private function sources(ObservationInput $input, DetectorRun $run): array
    {
        $values = [];
        $add = static function (string $value, array $source) use (&$values, $run): void {
            if ($value === '') return;
            if (!mb_check_encoding($value, 'UTF-8') || (isset($source['parameter']) && !mb_check_encoding($source['parameter'], 'UTF-8'))) {
                $run->unavailable('reflection', 'input_sources', null, 'invalid_representation');
                return;
            }
            if (mb_strlen($value, 'UTF-8') > self::MAX_VALUE_LENGTH) {
                $run->limited('reflection', 'input_sources', null, 'input_value_budget', ['limit' => self::MAX_VALUE_LENGTH]);
                return;
            }
            // Base64 key avoids numeric-string key coercion without altering original bytes.
            $key = base64_encode($value);
            if (!isset($values[$key]) && count($values) >= self::MAX_INPUTS) {
                $run->limited('reflection', 'input_sources', null, 'input_budget', ['limit' => self::MAX_INPUTS]);
                return;
            }
            $values[$key] ??= ['value' => $value, 'sources' => [], 'ambiguous' => false];
            $values[$key]['sources'][] = $source;
        };
        if ($input->payload !== null) $add($input->payload, ['source' => 'payload', 'transformations' => []]);
        if ($input->expectedEvidence !== null) $add($input->expectedEvidence, ['source' => 'expected_marker', 'transformations' => []]);
        $query = parse_url($input->url, PHP_URL_QUERY);
        if ($query === false) $run->unavailable('reflection', 'input_sources', null, 'invalid_query_representation');
        $names = [];
        $sourceLimitExceeded = false;
        if (is_string($query)) {
            $pairs = explode('&', $query);
            $sourceLimitExceeded = count($pairs) > self::MAX_INPUTS;
            if ($sourceLimitExceeded) $run->limited('reflection', 'input_sources', null, 'query_pair_budget', ['limit' => self::MAX_INPUTS, 'available' => count($pairs)]);
            foreach (array_slice($pairs, 0, self::MAX_INPUTS) as $index => $pair) {
                $parts = explode('=', $pair, 2);
                if (count($parts) !== 2) continue;
                $name = urldecode($parts[0]);
                $names[$name] = ($names[$name] ?? 0) + 1;
                $add(urldecode($parts[1]), ['source' => 'query_parameter', 'parameter' => $name, 'occurrence' => $index, 'transformations' => ['url_decode_once']]);
            }
        }
        foreach ($values as &$value) {
            $value['ambiguous'] = $sourceLimitExceeded || count($value['sources']) > 1;
            foreach ($value['sources'] as $source) {
                if (isset($source['parameter']) && ($names[$source['parameter']] ?? 0) > 1) $value['ambiguous'] = true;
            }
        }
        unset($value);
        $result = array_values($values);
        // Decode only a complete canonical Standard-Base64 input, once. The
        // response remains untouched and URL/entity comparisons remain first.
        foreach (array_values($values) as $value) {
            $encoded = $value['value'];
            if (strlen($encoded) < 8 || strlen($encoded) % 4 !== 0
                || !CheckedRegex::match('~^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$~D', $encoded)) continue;
            $decoded = base64_decode($encoded, true);
            if ($decoded === false || base64_encode($decoded) !== $encoded || $decoded === $encoded
                || !mb_check_encoding($decoded, 'UTF-8') || mb_strlen($decoded, 'UTF-8') < 4
                || CheckedRegex::match('~[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]~', $decoded)) continue;
            $result[] = [...$value, 'value' => $decoded, 'comparisonTransformations' => ['base64_decode_once']];
        }
        return $result;
    }

    /** Compare one entity layer directly, retaining offsets into the original bytes. */
    private function entityPattern(string $value): string
    {
        $named = ['<' => '&lt;', '>' => '&gt;', '&' => '&amp;', '"' => '&quot;', "'" => '&apos;'];
        $pattern = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $character) {
            $codepoint = mb_ord($character, 'UTF-8');
            $alternatives = [preg_quote($character, '~'), '&#0*'.$codepoint.';', '&#[xX]0*(?i:'.dechex($codepoint).');'];
            if (isset($named[$character])) $alternatives[] = $named[$character];
            $pattern .= '(?:'.implode('|', $alternatives).')';
        }
        return $pattern;
    }

    private function context(string $raw, int $start, int $length, ?string $contentType): array
    {
        $prefixStart = max(0, $start - self::CONTEXT_WINDOW);
        $prefix = substr($raw, $prefixStart, $start - $prefixStart);
        $result = static fn (string $context, bool $required = false, string $reason = ''): array => ['context' => $context, 'contextRequired' => $required, 'reason' => $reason];
        // A nearby diagnostic is a quote/echo, never a script or breakout proof.
        $lineStart = max(strrpos($prefix, "\n") === false ? 0 : strrpos($prefix, "\n") + 1, strlen($prefix) - 600);
        $local = substr($prefix, $lineStart).substr($raw, $start, min($length + 200, 1500));
        if (CheckedRegex::match('~(?:SQLSTATE|SQL\s+syntax|database\s+error|Warning\s*:|Fatal\s+error|invalid\s+(?:input|integer)|Exception\s*:)~i', $local)) return $result('diagnostic_quote');
        if ($contentType === null || !CheckedRegex::match('~(?:text/html|application/xhtml\+xml)\b~i', $contentType)) return $result('unknown', true, 'html_representation_not_established');
        $commentOpen = strrpos($prefix, '<!--');
        $commentClose = strrpos($prefix, '-->');
        if ($commentOpen !== false && ($commentClose === false || $commentOpen > $commentClose)) return $result('html_comment');
        CheckedRegex::matchAll('~</?(?:script|style)\b[^>]{0,1000}>~i', $prefix, $tags, PREG_OFFSET_CAPTURE);
        if ($tags[0] !== []) {
            [$tag, $tagOffset] = $tags[0][array_key_last($tags[0])];
            if ($tag[1] !== '/') {
                if (stripos($tag, '<style') === 0) return $result('style', true, 'css_context_not_interpreted');
                $script = substr($prefix, $tagOffset + strlen($tag));
                $quote = $this->scriptQuote($script);
                return $result($quote === null ? 'script' : 'script_string', true, 'script_lexical_context_not_execution');
            }
        }
        // A > inside a quoted attribute does not finish the HTML tag.
        $open = strrpos($prefix, '<');
        $tagPrefix = $open === false ? '' : substr($prefix, $open);
        $insideTag = false;
        if (CheckedRegex::match('~^<[A-Za-z][A-Za-z0-9:-]*\b~', $tagPrefix, $tagName)) {
            $insideTag = true;
            $quote = null;
            for ($i = strlen($tagName[0]), $size = strlen($tagPrefix); $i < $size; ++$i) {
                $character = $tagPrefix[$i];
                if ($quote !== null) { if ($character === $quote) $quote = null; continue; }
                if ($character === '"' || $character === "'") $quote = $character;
                elseif ($character === '>') { $insideTag = false; break; }
            }
        }
        if ($insideTag) {
            $tail = substr($tagPrefix, strlen($tagName[0]));
            if (CheckedRegex::match('~\b([A-Za-z_:][A-Za-z0-9_.:-]*)\s*=\s*("[^"]*|\x27[^\x27]*|[^\s"\x27=<>`]*)$~s', $tail, $attribute)) {
                $quote = str_starts_with($attribute[2], '"') ? '"' : (str_starts_with($attribute[2], "'") ? "'" : null);
                $excerpt = substr($raw, $start, $length);
                $crosses = $quote === null ? (bool) CheckedRegex::match('~[\s>]~', $excerpt) : str_contains($excerpt, $quote);
                return [...$result('attribute', true, 'attribute_value_requires_browser_context'), 'attributes' => [
                    'attributeName' => strtolower($attribute[1]),
                    'attributeQuote' => $quote === '"' ? 'double' : ($quote === "'" ? 'single' : 'unquoted'),
                    'inputCrossesAttributeBoundary' => $crosses,
                ]];
            }
            return $result('unknown', true, 'incomplete_tag_context');
        }
        if ($prefixStart > 0) return $result('unknown', true, 'context_window_exhausted');
        if (str_contains(substr($raw, $start, $length), '<')) return $result('unknown', true, 'markup_crossing_input');
        return $result('text');
    }

    /** Deliberately limited lexical state; JS syntax/executability is not claimed. */
    private function scriptQuote(string $script): ?string
    {
        $quote = null;
        $escaped = false;
        for ($i = 0, $size = strlen($script); $i < $size; ++$i) {
            $character = $script[$i];
            if ($escaped) { $escaped = false; continue; }
            if ($character === '\\') { $escaped = true; continue; }
            if ($quote !== null) { if ($character === $quote) $quote = null; continue; }
            if (in_array($character, ["'", '"', '`'], true)) $quote = $character;
        }
        return $quote;
    }
}
