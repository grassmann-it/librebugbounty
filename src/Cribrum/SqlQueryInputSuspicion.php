<?php

declare(strict_types=1);

namespace App\Cribrum;

use App\Cribrum\Detector\CheckedRegex;
use App\Cribrum\Detector\SqlTriageDetector;

/** A source-bound query/input hint, never a structural fact or execution proof. */
final class SqlQueryInputSuspicion
{
    public const RULE_ID = 'builtin/sql-query-array-input';
    private const WRAPPER = '~(PDO[ \t]+Error:[ \t]*SQLSTATE\[HY000\]:[ \t]*General error:[ \t]*20018[ \t]+Incorrect syntax near[ \t]+\x27[^\x27\r\n]{1,64}\x27\.[ \t]*\[20018\][ \t]*\(severity[ \t]+15\))[ \t]*\[[ \t\r\n]*(SELECT[\s\S]{1,4096}?)\][ \t\r\n]+Query:[ \t\r\n]*(SELECT[\s\S]{1,4096}?)<br\s*/?>~iu';

    public function suggest(ObservationInput $input): ?ClaimSuggestion
    {
        $raw = $input->rawHtml;
        if ($raw === null || stripos($raw, 'PDO Error:') === false || stripos($raw, '20018') === false
            || stripos($raw, 'Query:') === false || !mb_check_encoding($raw, 'UTF-8')) return null;
        $params = $this->parameters($input->url);
        if ($params === [] || CheckedRegex::matchAll(self::WRAPPER, $raw, $wrappers, PREG_OFFSET_CAPTURE) !== 1) return null;
        [$carrier] = $wrappers[0][0];
        [$error, $errorByte] = $wrappers[1][0];
        [$first] = $wrappers[2][0];
        [$query, $queryByte] = $wrappers[3][0];
        $query = rtrim($query, " \t\r\n");
        if (rtrim($first, " \t\r\n") !== $query || $this->supplied($carrier, $error, $query, $params, $input->payload)) return null;

        // The native wrapper must start inside the same qualified raw error
        // carrier. Later sibling queries cannot qualify an unrelated diagnostic.
        $qualified = 0;
        foreach ((new SqlTriageDetector())->evaluate($input, 'shadow')->signals as $signal) {
            if ($signal['code'] !== 'sql_error_observed' || ($signal['attributes']['diagnosticClass'] ?? null) !== 'syntax'
                || ($signal['attributes']['blockComplete'] ?? false) !== true) continue;
            $blocks = array_values(array_filter($signal['spans'], static fn (array $s): bool => ($s['role'] ?? '') === 'diagnostic_block' && $s['representation'] === 'raw'));
            $errors = array_values(array_filter($signal['spans'], static fn (array $s): bool => ($s['role'] ?? '') === 'error' && $s['representation'] === 'raw'));
            if (count($blocks) !== 1 || count($errors) !== 1) continue;
            $start = mb_strlen(substr($raw, 0, $errorByte), 'UTF-8');
            $end = $start + mb_strlen($error, 'UTF-8');
            if ($start >= $blocks[0]['start'] && $start < $blocks[0]['start'] + $blocks[0]['length']
                && $errors[0]['start'] >= $start && $errors[0]['start'] < $end) ++$qualified;
        }
        if ($qualified !== 1) return null;

        $bindings = [];
        foreach ($params as $root => $group) {
            if (count($group) < 2 || count($group) > 12 || array_filter($group, static fn (array $p): bool => $p['name'] !== $root.'[]')) continue;
            $values = array_column($group, 'value');
            if (count(array_unique($values)) !== count($values)) continue;
            $long = array_keys(array_filter($values, fn (string $v): bool => $this->distinctive($v)));
            if (count($long) !== 1) continue;
            $value = $values[$long[0]];
            foreach ($params as $otherRoot => $other) {
                if ($root !== $otherRoot && array_filter($other, static fn (array $p): bool => str_contains($p['value'], $value))) continue 2;
            }
            foreach ($values as $index => $leaf) {
                if ($index !== $long[0] && CheckedRegex::match('~^-?(?:0|[1-9][0-9]{0,9})$~D', $leaf) !== 1) continue 2;
            }
            $at = $this->arrayPosition($query, $values, $value);
            if ($at !== null) $bindings[] = [$value, $at];
        }
        if (count($bindings) !== 1) return null;
        [$value, $valueByte] = $bindings[0];
        $span = static fn (string $excerpt, int $byte): array => ['representation' => 'raw', 'start' => mb_strlen(substr($raw, 0, $byte), 'UTF-8'), 'length' => mb_strlen($excerpt, 'UTF-8'), 'excerpt' => $excerpt];
        return new ClaimSuggestion(Taxonomy::SQL_INJECTION, Taxonomy::SUSPECTED, 89, [self::RULE_ID],
            ['A distinctive request value and its ordered append-array companions appear in an unquoted IN list in a complete disclosed PDO syntax-error query. This supports suspicion only; execution and input-driven SQL structure remain unverified.'],
            [$span($error, $errorByte), $span($query, $queryByte), $span($value, $queryByte + $valueByte)]);
    }

    private function distinctive(string $value): bool
    {
        return mb_strlen($value, 'UTF-8') >= 32 && strpbrk($value, '\'"') !== false
            && CheckedRegex::match('~^\x27(?:\x27\x27|[^\x27])*\x27$~sD', $value) !== 1
            && CheckedRegex::match('~^"(?:""|[^"])*"$~sD', $value) !== 1
            && CheckedRegex::matchAll('~[\pL\pN]~u', $value) >= 8
            && count(array_unique(mb_str_split($value, 1, 'UTF-8'))) >= 8;
    }

    /** @return array<string, list<array{name:string,value:string}>> */
    private function parameters(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) return [];
        $pairs = explode('&', $query);
        if (count($pairs) > 64) return [];
        $params = [];
        foreach ($pairs as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name); $value = urldecode($value);
            if (!mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($value, 'UTF-8')) return [];
            $root = str_replace(['.', ' '], '_', explode('[', $name, 2)[0]);
            if (CheckedRegex::match('~^[A-Za-z_][A-Za-z0-9_-]*$~D', $root) !== 1) return [];
            $params[$root][] = ['name' => $name, 'value' => $value];
        }
        return $params;
    }

    /** Return the exact input byte position only for one neutral IN expression. */
    private function arrayPosition(string $query, array $values, string $value): ?int
    {
        if (!str_starts_with(strtoupper($query), 'SELECT ') || substr_count($query, $value) !== 1) return null;
        $masked = str_replace($value, 'INPUT', $query);
        if (str_contains($masked, ';') || str_contains($masked, '...') || CheckedRegex::match('~<\s*/?\s*[A-Za-z][^>]*>~u', $masked)) return null;
        CheckedRegex::matchAll('~\bFROM\s+[A-Za-z_][A-Za-z0-9_.]*\b~iu', $query, $froms, PREG_OFFSET_CAPTURE);
        $from = null;
        foreach ($froms[0] as [, $byte]) if ($this->neutral(substr($query, 0, $byte))) { $from = $byte; break; }
        if ($from === null) return null;
        $list = implode('[ \t\r\n]*,[ \t\r\n]*', array_map(static fn (string $v): string => preg_quote($v, '~'), $values));
        if (CheckedRegex::matchAll('~\b[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?[ \t\r\n]+IN[ \t\r\n]*\([ \t\r\n]*('.$list.')[ \t\r\n]*\)~iu', $query, $matches, PREG_OFFSET_CAPTURE) !== 1) return null;
        [, $at] = $matches[0][0];
        if ($at <= $from || !$this->neutral(substr($query, 0, $at))) return null;
        CheckedRegex::matchAll('~\bWHERE\b~iu', substr($query, 0, $at), $wheres, PREG_OFFSET_CAPTURE);
        $where = false;
        foreach ($wheres[0] as [, $byte]) if ($byte > $from && $this->neutral(substr($query, 0, $byte))) $where = true;
        if (!$where) return null;
        [$matchedList, $listByte] = $matches[1][0];
        // SQL keywords are case insensitive; the request bytes are not.
        $position = strpos($matchedList, $value);
        return $position === false ? null : $listByte + $position;
    }

    private function neutral(string $prefix): bool
    {
        $quote = null;
        for ($i = 0, $n = strlen($prefix); $i < $n; ++$i) {
            $char = $prefix[$i]; $next = $prefix[$i + 1] ?? '';
            if ($quote !== null) {
                $close = $quote === '[' ? ']' : $quote;
                if ($char === $close) { if ($next === $close) ++$i; else $quote = null; }
                continue;
            }
            if (($char === '-' && $next === '-') || ($char === '/' && $next === '*')) return false;
            if (str_contains('\'"`[', $char)) $quote = $char;
        }
        return $quote === null;
    }

    private function supplied(string $carrier, string $error, string $query, array $params, ?string $payload): bool
    {
        $normalize = static fn (string $v): string => trim(CheckedRegex::replace('~\s+~u', ' ', strip_tags(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $carrier = $normalize($carrier); $error = $normalize($error); $query = $normalize($query);
        $native = CheckedRegex::replace('~^PDO[ \t]+Error:[ \t]*~i', '', $error);
        $values = [];
        foreach ($params as $group) foreach ($group as $param) $values[] = $param['value'];
        if ($payload !== null) $values[] = $payload;
        foreach ($values as $value) {
            $value = $normalize($value);
            if (str_contains($value, $carrier) || str_contains($value, $native) || str_contains($value, $query)) return true;
        }
        return false;
    }
}
