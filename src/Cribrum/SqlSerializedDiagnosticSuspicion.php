<?php

declare(strict_types=1);

namespace App\Cribrum;

use App\Cribrum\Detector\CheckedRegex;
use App\Cribrum\Detector\HtmlDiagnosticContext;

/** Source-level native diagnostics in an isolated alert frame; never execution facts. */
final class SqlSerializedDiagnosticSuspicion
{
    public const RULE_ID = 'builtin/sql-serialized-native-diagnostic';
    private const MAX_MESSAGE_BYTES = 4096;
    private const MAX_CARRIERS = 32;
    private const NATIVE = '~\A(?:SQLSTATE\[42000\]:[ \t]*Syntax error or access violation:[ \t]*1064[ \t]+)?You have an error in your SQL syntax;[ \t\r\n]+check the manual that corresponds to your (?:MySQL|MariaDB) server version for the right syntax to use[ \t\r\n]+near[ \t]+\x27([\s\S]{1,2048})\x27[ \t]+at line[ \t]+[1-9]\d{0,5}\z~iu';

    /** @return array{suggestions:list<ClaimSuggestion>,issues:list<array>} */
    public function evaluate(ObservationInput $input): array
    {
        $raw = $input->rawHtml;
        $empty = ['suggestions' => [], 'issues' => []];
        if ($raw === null || !mb_check_encoding($raw, 'UTF-8') || stripos($raw, 'You have an error in your SQL syntax;') === false
            || stripos($raw, '<script') === false || in_array(ltrim($raw)[0] ?? '', ['{', '['], true)) return $empty;
        if ($input->contentType !== null && CheckedRegex::match('~^(?:text/html|application/xhtml\+xml)(?:\s*;|$)~i', $input->contentType) !== 1) return $empty;
        CheckedRegex::matchAll('~<script\b[^>]{0,512}>[ \t\r\n]*(?:window\.)?alert\([ \t]*([\x22\x27])~iu', $raw, $frames, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (count($frames) > self::MAX_CARRIERS) return ['suggestions' => [], 'issues' => [
            ['code' => 'serialized_diagnostic_match_budget', 'stage' => 'builtin_sql_serialized_diagnostic', 'representation' => 'raw', 'status' => 'limited'],
        ]];
        $params = $this->parameters($input->url);
        $carriers = [];
        foreach ($frames as $frame) {
            [$opening, $frameByte] = $frame[0];
            if (!$this->eligibleFrame($raw, $frameByte, $opening)) continue;
            $quoteByte = $frame[1][1];
            $parsed = $this->string($raw, $quoteByte);
            if ($parsed === null) continue;
            [$message, $map, $afterQuote] = $parsed;
            $rawMessage = substr($raw, $quoteByte + 1, $afterQuote - $quoteByte - 2);
            // A complete isolated source frame, including any literal closing
            // script tag in the diagnostic's request data. This says nothing
            // about whether the browser could execute the resulting markup.
            if (CheckedRegex::match('~\G[ \t\r\n]*\)[ \t]*;[ \t\r\n]*(?:(?:window\.)?history\.go\([ \t]*-1[ \t]*\)[ \t]*;[ \t\r\n]*)?</script\s*>~iu', $raw, $ending, 0, $afterQuote) !== 1) continue;
            if (CheckedRegex::match(self::NATIVE, $message, $native, PREG_OFFSET_CAPTURE) !== 1
                || CheckedRegex::matchAll('~\bnear[ \t]+\x27~iu', $message) !== 1
                || CheckedRegex::matchAll('~\x27[ \t]+at line[ \t]+[1-9]\d{0,5}\b~iu', $message) !== 1
                || CheckedRegex::matchAll('~You have an error in your SQL syntax;~iu', $message) !== 1
                || $this->supplied($message, $rawMessage, $input, $params)) continue;
            $messageSpan = $this->mappedSpan($raw, $map, 0, mb_strlen($message, 'UTF-8'));
            if ($messageSpan === null) continue;
            [$fragment, $fragmentByte] = $native[1];
            $bindings = [];
            foreach ($params as $param) {
                if (!$param['unique']) continue;
                $suffix = $this->boundSuffix($fragment, $param['value']);
                if ($suffix === null) continue;
                $start = mb_strlen(substr($message, 0, $fragmentByte), 'UTF-8');
                $span = $this->mappedSpan($raw, $map, $start, mb_strlen($suffix['value'], 'UTF-8'));
                if ($span !== null) $bindings[] = ['span' => $span, 'partial' => $suffix['partial'], 'clipped' => $suffix['clipped']];
            }
            $carriers[] = ['span' => $messageSpan, 'bindings' => $bindings, 'escaped' => $rawMessage !== $message];
        }
        if ($carriers === []) return $empty;
        $spans = array_column($carriers, 'span');
        $reason = 'A complete native MySQL/MariaDB syntax diagnostic was returned inside an isolated alert source frame. The captured source does not establish valid JavaScript execution or a complete SQL query.';
        $suggestions = [new ClaimSuggestion(Taxonomy::ERROR_DISCLOSURE, Taxonomy::OBSERVED, 86, [self::RULE_ID], [$reason], $spans)];
        if (count($carriers) === 1 && count($carriers[0]['bindings']) === 1) {
            $binding = $carriers[0]['bindings'][0];
            $reason = 'A unique scalar request value is retained at the native syntax-error location in a serialized alert diagnostic. This supports SQL-injection suspicion only; the complete SQL query, structure and execution remain unverified.';
            if ($binding['partial']) $reason .= ' Only a distinctive suffix is retained; at most eight leading punctuation codepoints and at most ten percent of the value are omitted.';
            if ($binding['clipped']) $reason .= ' The native near excerpt is clipped; omitted SQL text is not reconstructed.';
            if ($carriers[0]['escaped']) $reason .= ' JavaScript string escapes are decoded once for comparison; evidence retains the exact raw response tokens.';
            $suggestions[] = new ClaimSuggestion(Taxonomy::SQL_INJECTION, Taxonomy::SUSPECTED, 88, [self::RULE_ID], [$reason], [$carriers[0]['span'], $binding['span']]);
        }
        return ['suggestions' => $suggestions, 'issues' => []];
    }

    private function eligibleFrame(string $raw, int $at, string $opening): bool
    {
        if (!HtmlDiagnosticContext::eligible($raw, $at)) return false;
        // A guard-only projection adds conventional source/example wrappers to
        // the shared lexical parser. It never supplies matched evidence or input
        // text; all matching and spans below still use the untouched response.
        $prefix = substr($raw, 0, $at);
        $guardPrefix = CheckedRegex::replace('~<(/?)(?:pre|samp)\b~i', '<$1code', $prefix);
        $guardPrefix = CheckedRegex::replace('~\b(?:docs|source-code|sourcecode|syntaxhighlighter|prettyprint|language-(?:php|sql|javascript|js))\b~i', 'documentation', $guardPrefix);
        if (!HtmlDiagnosticContext::eligible($guardPrefix, strlen($guardPrefix))) return false;
        $local = mb_substr($prefix, -240, null, 'UTF-8');
        if (CheckedRegex::match('~(?:documentation|docs|example|sample|tutorial|manual)\s*:?\s*(?:<[^>]{1,100}>\s*)*$~iu', $local)) return false;
        if (CheckedRegex::match('~(?:documentation|docs|example|sample|tutorial|manual)\s*:\s*[^\r\n]{0,180}\z~iu', strip_tags($local))) return false;
        if (CheckedRegex::match('~\A<script\b([^>]*)>~iu', $opening, $tag) !== 1) return false;
        // Only plain JavaScript type/language declarations, not external sources,
        // inert data scripts, event attributes or an unknown carrier convention.
        $attrs = $tag[1];
        CheckedRegex::matchAll('~[ \t\r\n]+(type|language)[ \t]*=[ \t]*(?:\x22([^\x22]*)\x22|\x27([^\x27]*)\x27|([^\s\x22\x27=<>`]+))~iu', $attrs, $found, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $seen = [];
        foreach ($found as $attr) {
            $name = strtolower($attr[1]);
            $value = strtolower(trim($attr[2] ?? $attr[3] ?? $attr[4] ?? ''));
            if (isset($seen[$name]) || !in_array($value, $name === 'type' ? ['text/javascript', 'application/javascript'] : ['javascript'], true)) return false;
            $seen[$name] = true;
        }
        $remaining = CheckedRegex::replace('~[ \t\r\n]+(?:type|language)[ \t]*=[ \t]*(?:\x22[^\x22]*\x22|\x27[^\x27]*\x27|[^\s\x22\x27=<>`]+)~iu', '', $attrs);
        return trim($remaining) === '';
    }

    /** One JS string decoding pass, with codepoint-boundary to raw-byte mapping. */
    private function string(string $raw, int $quoteAt): ?array
    {
        $quote = $raw[$quoteAt];
        $at = $quoteAt + 1;
        $limit = min(strlen($raw), $at + self::MAX_MESSAGE_BYTES);
        $decoded = '';
        $map = [0 => $at];
        $length = 0;
        while ($at < $limit) {
            $start = $at;
            if ($raw[$at] === $quote) return [$decoded, $map, $at + 1];
            if ($raw[$at] === "\r" || $raw[$at] === "\n" || $raw[$at] === "\0") return null;
            if ($raw[$at] !== '\\') {
                if (CheckedRegex::match('~\G[\s\S]~u', $raw, $char, 0, $at) !== 1) return null;
                $value = $char[0];
                $at += strlen($value);
            } else {
                $escape = $raw[$at + 1] ?? '';
                $value = match ($escape) {
                    '\\' => '\\', '/' => '/', '"' => '"', "'" => "'", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c", 'v' => "\x0b", default => null,
                };
                if ($value !== null) {
                    $at += 2;
                } elseif ($escape === 'u' && CheckedRegex::match('~\G\\\\u([0-9a-fA-F]{4})(?:\\\\u([0-9a-fA-F]{4}))?~', $raw, $unicode, 0, $at) === 1) {
                    $first = hexdec($unicode[1]);
                    $token = substr($raw, $at, 6);
                    if ($first >= 0xd800 && $first <= 0xdbff) {
                        $second = isset($unicode[2]) ? hexdec($unicode[2]) : 0;
                        if ($second < 0xdc00 || $second > 0xdfff) return null;
                        $token .= substr($raw, $at + 6, 6);
                    } elseif ($first >= 0xdc00 && $first <= 0xdfff) return null;
                    $value = json_decode('"'.$token.'"', true);
                    if (!is_string($value) || $value === "\0") return null;
                    $at += strlen($token);
                } elseif ($escape === 'x' && CheckedRegex::match('~\G\\\\x([0-9a-fA-F]{2})~', $raw, $hex, 0, $at) === 1 && hexdec($hex[1]) > 0 && hexdec($hex[1]) < 128) {
                    $value = chr(hexdec($hex[1]));
                    $at += 4;
                } else return null;
            }
            if ($at > $limit || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') !== 1) return null;
            $decoded .= $value;
            $map[$length] = $start;
            $map[++$length] = $at;
        }
        return null;
    }

    private function boundSuffix(string $fragment, string $value): ?array
    {
        $length = mb_strlen($value, 'UTF-8');
        if ($length < 32 || strpbrk($value, '\'"') === false
            || CheckedRegex::matchAll('~[\pL\pN]~u', $value) < 8
            || count(array_unique(mb_str_split($value, 1, 'UTF-8'))) < 8) return null;
        for ($omitted = 0; $omitted <= 8; ++$omitted) {
            if (($length - $omitted) * 10 < $length * 9 || $length - $omitted < 32) break;
            if (CheckedRegex::match('~\A[^\pL\pN\s]*\z~u', mb_substr($value, 0, $omitted, 'UTF-8')) !== 1) break;
            $suffix = mb_substr($value, $omitted, null, 'UTF-8');
            if (!str_starts_with($fragment, $suffix)) continue;
            $tail = substr($fragment, strlen($suffix));
            if (CheckedRegex::match('~\A[\t (),;%\x27\x22]{0,8}\z~', $tail)) return ['value' => $suffix, 'partial' => $omitted > 0, 'clipped' => false];
            if (strlen($fragment) !== 80) continue;
            // Canonical native excerpts may clip a following SQL keyword, or a
            // LIKE-literal terminator/conjunction and a short identifier. The
            // omitted continuation remains unknown; never infer a full clause.
            if (CheckedRegex::match('~\A[ \t]{1,2}([A-Z]{2,6})\.\.\.\z~i', $tail, $clipped)) {
                foreach (['LIMIT','ORDER','GROUP','WHERE','HAVING','AND','OR','UNION','JOIN','OFFSET'] as $keyword) {
                    if (str_starts_with($keyword, strtoupper($clipped[1]))) return ['value' => $suffix, 'partial' => $omitted > 0, 'clipped' => true];
                }
            }
            if (CheckedRegex::match('~\A[(),;%\x27\x22]{0,4}[ \t]{1,2}(?:AND|OR)[ \t]{1,2}[A-Za-z_][A-Za-z0-9_]{0,7}\.\.\.\z~i', $tail)) {
                return ['value' => $suffix, 'partial' => $omitted > 0, 'clipped' => true];
            }
        }
        return null;
    }

    private function mappedSpan(string $raw, array $map, int $start, int $length): ?array
    {
        if (!isset($map[$start], $map[$start + $length])) return null;
        $byte = $map[$start];
        $text = substr($raw, $byte, $map[$start + $length] - $byte);
        return ['representation' => 'raw', 'start' => mb_strlen(substr($raw, 0, $byte), 'UTF-8'), 'length' => mb_strlen($text, 'UTF-8'), 'excerpt' => $text];
    }

    /** Preserve all occurrences for alias/root collisions and request-supplied guards. */
    private function parameters(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) return [];
        $params = []; $roots = [];
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name); $value = urldecode($value);
            $utf8 = mb_check_encoding($name, 'UTF-8') && mb_check_encoding($value, 'UTF-8');
            // PHP trims leading spaces and terminates a key at NUL before
            // normalizing dots/spaces and parsing bracket paths. Retain those
            // original occurrences so an invalid alias still blocks attribution.
            $normalized = ltrim(explode("\0", $name, 2)[0], ' ');
            $bracket = strpos($normalized, '[');
            $root = $bracket !== false && strpos($normalized, ']', $bracket) !== false
                ? substr($normalized, 0, $bracket) : str_replace('[', '_', $normalized);
            $root = str_replace(['.', ' '], '_', $root);
            $roots[$root] = ($roots[$root] ?? 0) + 1;
            $params[] = ['root' => $root, 'value' => $value, 'valid' => $utf8 && CheckedRegex::match('~\A[A-Za-z_][A-Za-z0-9_-]*\z~D', $name) === 1];
        }
        foreach ($params as &$param) $param['unique'] = $param['valid'] && $roots[$param['root']] === 1;
        return $params;
    }

    private function supplied(string $message, string $rawMessage, ObservationInput $input, array $params): bool
    {
        $values = array_column($params, 'value');
        if ($input->payload !== null) $values[] = $input->payload;
        if ($input->finalUrl !== null && $input->finalUrl !== $input->url) {
            $values = [...$values, ...array_column($this->parameters($input->finalUrl), 'value')];
        }
        // Script text does not expand HTML entities or remove tags. Comparing
        // those transformed strings would invent a request relationship here.
        foreach ($values as $value) if (str_contains($value, $message) || str_contains($value, $rawMessage)) return true;
        return false;
    }
}
