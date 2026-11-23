<?php

declare(strict_types=1);

namespace App\Cribrum;

use App\Cribrum\Detector\CheckedRegex;
use App\Cribrum\Detector\HtmlDiagnosticContext;
use App\Cribrum\Detector\SqlTriageDetector;

/** A core-only ORM/query-input hint. PHQL parsing is not native database execution. */
final class SqlPhqlInputSuspicion
{
    public const RULE_ID = 'builtin/phql-order-input';
    private const CARRIER = <<<'REGEX'
~(Syntax error,[ \t]+unexpected token[ \t]+[^,\r\n]{1,64},[ \t]+near to[ \t]+(?:\x27|&(?:apos|\#0*39|\#x0*27);)[\s\S]{1,2048}?(?:\x27|&(?:apos|\#0*39|\#x0*27);)),[ \t]+when parsing:[ \t]+(SELECT[\s\S]{1,4096}?) \(([1-9][0-9]{0,3})\)<br\s*/?>[ \t\r\n]*<pre\s*>[ \t\r\n]*(\#0[ \t]+\[internal function\]:[ \t]+Phalcon\\Mvc\\Model\\Query\\Lang::parsePHQL\(\))(?=[ \t\r\n]|</pre>)([\s\S]{0,8192}?)</pre\s*>~iu
REGEX;

    public function suggest(ObservationInput $input): ?ClaimSuggestion
    {
        $raw = $input->rawHtml;
        if ($raw === null || stripos($raw, 'when parsing:') === false || stripos($raw, 'parsePHQL') === false
            || !mb_check_encoding($raw, 'UTF-8')) return null;
        if (CheckedRegex::matchAll('~Syntax error,[ \t]+unexpected token\b~iu', $raw) !== 1
            || CheckedRegex::matchAll('~\bwhen parsing:~iu', $raw) !== 1
            || CheckedRegex::matchAll(self::CARRIER, $raw, $matches, PREG_OFFSET_CAPTURE) !== 1) return null;
        [$carrier] = $matches[0][0];
        [$error, $errorByte] = $matches[1][0];
        [$rawQuery, $queryByte] = $matches[2][0];
        [$declaredLength] = $matches[3][0];
        [$frame, $frameByte] = $matches[4][0];
        if (CheckedRegex::match('~</?pre\b~iu', $matches[5][0][0])) return null;
        if (!HtmlDiagnosticContext::eligible($raw, $errorByte)
            || !(new SqlTriageDetector())->isActiveQueryText($raw, $errorByte)) return null;
        $prefix = mb_strcut(substr($raw, 0, $errorByte), max(0, $errorByte - 160), null, 'UTF-8');
        if (CheckedRegex::match('~(?:example|documentation|sample|tutorial|manual|search)[ \t]*:[^\r\n]{0,160}$~iu', $prefix)) return null;
        $params = $this->parameters($input->url);
        if ($params === [] || $this->supplied($carrier, $error, $rawQuery, $params, $input->payload)) return null;

        $variants = [['text' => $rawQuery, 'endpoints' => null]];
        // Escaped diagnostics have one explicit comparison transform. Mixed
        // markup is retained literally; deleting tags could invent a binding.
        if (!str_contains($rawQuery, '<')) {
            $decoded = $this->decoded($rawQuery);
            if ($decoded['text'] !== $rawQuery) $variants[] = $decoded;
        }
        $bindings = [];
        foreach ($variants as $variant) {
            $query = $variant['text'];
            // Phalcon emits the original query's byte length, not a character
            // count or the size of its HTML-escaped rendering.
            if (strlen($query) !== (int) $declaredLength) continue;
            foreach ($params as $param) {
                $value = $param['value'];
                if (!$param['unique'] || !$this->distinctive($value)) continue;
                foreach ($params as $other) {
                    if ($other['index'] !== $param['index'] && str_contains($other['value'], $value)) continue 2;
                }
                $at = $this->orderPosition($query, $value);
                if ($at === null) continue;
                $start = mb_strlen(substr($query, 0, $at), 'UTF-8');
                $end = $start + mb_strlen($value, 'UTF-8');
                $rawStart = $variant['endpoints'] === null ? $at : ($variant['endpoints'][$start] ?? null);
                $rawEnd = $variant['endpoints'] === null ? $at + strlen($value) : ($variant['endpoints'][$end] ?? null);
                if ($rawStart === null || $rawEnd === null) continue;
                $bindings[$param['index'].':'.$rawStart.':'.$rawEnd] = [$rawStart, $rawEnd, $variant['endpoints'] !== null];
            }
        }
        if (count($bindings) !== 1) return null;
        [$start, $end, $entityDecoded] = array_values($bindings)[0];
        $span = static fn (string $excerpt, int $byte): array => [
            'representation' => 'raw', 'start' => mb_strlen(substr($raw, 0, $byte), 'UTF-8'),
            'length' => mb_strlen($excerpt, 'UTF-8'), 'excerpt' => $excerpt,
        ];
        $reason = 'A complete Phalcon PHQL ORM parser diagnostic discloses a byte-length-checked query with a distinctive, uniquely bound request value at an unquoted ORDER BY operand. This supports SQL-injection suspicion only: PHQL parsing does not establish native database execution, a native SQL error, or confirmed injection.';
        if ($entityDecoded) $reason .= ' HTML entities are decoded once for comparison; evidence retains the original raw response spans.';
        return new ClaimSuggestion(Taxonomy::SQL_INJECTION, Taxonomy::SUSPECTED, 88, [self::RULE_ID], [$reason], [
            $span($error, $errorByte), $span($rawQuery, $queryByte),
            $span(substr($rawQuery, $start, $end - $start), $queryByte + $start), $span($frame, $frameByte),
        ]);
    }

    private function distinctive(string $value): bool
    {
        return mb_strlen($value, 'UTF-8') >= 32 && strlen($value) <= 1024 && strpbrk($value, '\'"<>') !== false
            && CheckedRegex::matchAll('~[\pL\pN]~u', $value) >= 8
            && count(array_unique(mb_str_split($value, 1, 'UTF-8'))) >= 8
            && CheckedRegex::match('~^\x27(?:\x27\x27|[^\x27])*\x27$~sD', $value) !== 1
            && CheckedRegex::match('~^"(?:""|[^"])*"$~sD', $value) !== 1;
    }

    /** Every raw occurrence matters; unrelated repeated/nested roots do not invalidate a scalar. */
    private function parameters(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) return [];
        $pairs = explode('&', $query);
        if (count($pairs) > 128) return [];
        $params = []; $roots = [];
        foreach ($pairs as $index => $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name); $value = urldecode($value);
            if (!mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($value, 'UTF-8')) return [];
            $root = str_replace(['.', ' '], '_', explode('[', $name, 2)[0]);
            $roots[$root] = ($roots[$root] ?? 0) + 1;
            $params[] = ['index' => $index, 'root' => $root, 'value' => $value,
                'valid' => CheckedRegex::match('~^[A-Za-z_][A-Za-z0-9_-]*(?:\[[A-Za-z0-9_-]*\])?$~D', $name) === 1];
        }
        foreach ($params as &$param) $param['unique'] = $param['valid'] && $roots[$param['root']] === 1;
        return $params;
    }

    /** Locate one whole request value, without interpreting its own malformed PHQL as surrounding syntax. */
    private function orderPosition(string $query, string $value): ?int
    {
        if (substr_count($query, $value) !== 1) return null;
        $at = strpos($query, $value);
        $before = substr($query, 0, $at); $after = substr($query, $at + strlen($value));
        $words = $this->neutralWords($before);
        if ($words === null || ($words[0][0] ?? null) !== 'SELECT' || ($words[0][1] ?? null) !== 0) return null;
        $from = []; $orders = [];
        foreach ($words as $index => [$word, $start, $end]) {
            if ($word === 'FROM') $from[] = $end;
            if ($word === 'ORDER' && ($words[$index + 1][0] ?? null) === 'BY'
                && trim(substr($before, $end, $words[$index + 1][1] - $end)) === '') $orders[] = $words[$index + 1][2];
        }
        if (count($from) !== 1 || count($orders) !== 1 || $from[0] >= $orders[0]) return null;
        $identifier = '(?:[A-Za-z_][A-Za-z0-9_$]*|\[[A-Za-z_][A-Za-z0-9_\\\\]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\])';
        $column = $identifier.'(?:[ \t]*\.[ \t]*'.$identifier.')*';
        if (!CheckedRegex::match('~^[ \t\r\n]+'.$column.'(?=[ \t\r\n]|$)~u', substr($before, $from[0]))) return null;
        $term = $column.'(?:[ \t]+(?:ASC|DESC))?';
        if (!CheckedRegex::match('~^[ \t\r\n]*(?:'.$term.'[ \t\r\n]*,[ \t\r\n]*){0,12}$~iuD', substr($before, $orders[0]))) return null;
        $limit = '(?:[0-9]{1,8}|:[A-Za-z_][A-Za-z0-9_]{0,63}:)';
        if (!CheckedRegex::match('~^[ \t\r\n]*(?:(?:ASC|DESC)\b[ \t\r\n]*)?(?:,[ \t\r\n]*'.$term.'[ \t\r\n]*){0,12}(?:LIMIT[ \t]+'.$limit.'(?:[ \t]*,[ \t]*'.$limit.')?)?[ \t\r\n]*$~iuD', $after)) return null;
        return $at;
    }

    /** Top-level PHQL keywords only; bracketed model namespaces are identifiers, not escapes. */
    private function neutralWords(string $prefix): ?array
    {
        $words = []; $depth = 0;
        for ($i = 0, $n = strlen($prefix); $i < $n; ++$i) {
            $char = $prefix[$i]; $next = $prefix[$i + 1] ?? '';
            if (($char === '-' && $next === '-') || ($char === '/' && $next === '*') || $char === '#' || $char === ';' || $char === '<' || $char === '>') return null;
            if (str_contains('\'"`[', $char)) {
                $close = $char === '[' ? ']' : $char; $closed = false;
                for (++$i; $i < $n; ++$i) {
                    if ($prefix[$i] === '\\' && $char !== '[') return null;
                    if ($prefix[$i] !== $close) continue;
                    if (($prefix[$i + 1] ?? '') === $close) { ++$i; continue; }
                    $closed = true; break;
                }
                if (!$closed) return null;
                continue;
            }
            if ($char === ':' && CheckedRegex::match('~\G:[A-Za-z_][A-Za-z0-9_]{0,63}:~u', $prefix, $placeholder, 0, $i)) {
                $i += strlen($placeholder[0]) - 1; continue;
            }
            if ($char === '{') {
                if (CheckedRegex::match('~\G\{[A-Za-z_][A-Za-z0-9_]*:(?:array|int|str)\}~u', $prefix, $placeholder, 0, $i) !== 1) return null;
                $i += strlen($placeholder[0]) - 1; continue;
            }
            if ($char === '(') { if (++$depth > 16) return null; continue; }
            if ($char === ')') { if (--$depth < 0) return null; continue; }
            if ($depth === 0 && ($i === 0 || (ord($prefix[$i - 1]) < 128 && !ctype_alnum($prefix[$i - 1]) && !str_contains('_$.', $prefix[$i - 1])))
                && CheckedRegex::match('~\G[A-Za-z_][A-Za-z0-9_$]*~u', $prefix, $word, 0, $i)) {
                $end = $i + strlen($word[0]);
                $previous = $i - 1;
                while ($previous >= 0 && ctype_space($prefix[$previous])) --$previous;
                if ($previous < 0 || $prefix[$previous] !== '.') $words[] = [strtoupper($word[0]), $i, $end];
                $i = $end - 1;
            }
        }
        return $depth === 0 ? $words : null;
    }

    /** One HTML entity comparison pass; endpoints never split a raw entity. */
    private function decoded(string $raw): array
    {
        CheckedRegex::matchAll('~&(?:\#(?:[0-9]{1,7}|[xX][0-9a-fA-F]{1,6})|[A-Za-z][A-Za-z0-9]{1,31});|[\s\S]~u', $raw, $tokens, PREG_OFFSET_CAPTURE);
        $text = ''; $endpoints = [0 => 0]; $length = 0;
        foreach ($tokens[0] as [$token, $byte]) {
            $value = html_entity_decode($token, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text .= $value;
            if ($value === $token) {
                $end = $byte;
                foreach (mb_str_split($token, 1, 'UTF-8') as $char) $endpoints[++$length] = $end += strlen($char);
            } else $endpoints[$length += mb_strlen($value, 'UTF-8')] = $byte + strlen($token);
        }
        return ['text' => $text, 'endpoints' => $endpoints];
    }

    private function supplied(string $carrier, string $error, string $query, array $params, ?string $payload): bool
    {
        $normalize = static fn (string $value): string => trim(CheckedRegex::replace('~\s+~u', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $needles = array_map($normalize, [$carrier, $error, $query]);
        $values = array_column($params, 'value');
        if ($payload !== null) $values[] = $payload;
        foreach ($values as $value) {
            $value = $normalize($value);
            foreach ($needles as $needle) if ($needle !== '' && str_contains($value, $needle)) return true;
        }
        return false;
    }
}
