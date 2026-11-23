<?php

declare(strict_types=1);

namespace App\Cribrum;

use App\Cribrum\Detector\CheckedRegex;
use App\Cribrum\Detector\RegexFailure;
use App\Cribrum\Detector\SqlTriageDetector;

/**
 * A request value retained verbatim inside a SQL statement the application
 * itself disclosed. This is an interpolation observation, never a structural
 * fact, an execution proof or a report component.
 */
final class SqlDisclosedQueryInputSuspicion
{
    public const RULE_ID = 'builtin/sql-disclosed-query-input';
    private const MAX_STATEMENTS = 32;
    private const MAX_STATEMENT_CODEPOINTS = 4096;
    private const MIN_VALUE_CODEPOINTS = 8;
    private const QUOTING = '\'"`\\';

    /**
     * @param list<string> $requestValues Decoded request values from the query
     *        string, the URL path and the explicit payload context.
     * @return array{suggestions:list<ClaimSuggestion>,issues:list<array>}
     */
    public function evaluate(ObservationInput $input, array $requestValues): array
    {
        try {
            return ['suggestions' => $this->suggestions($input, $requestValues), 'issues' => []];
        } catch (RegexFailure) {
            return ['suggestions' => [], 'issues' => [['code' => 'regex_error', 'stage' => 'builtin_sql_disclosed_query_input',
                'representation' => 'raw', 'status' => 'failed']]];
        }
    }

    /** @return list<ClaimSuggestion> */
    private function suggestions(ObservationInput $input, array $requestValues): array
    {
        $raw = $input->rawHtml;
        if ($raw === null || !mb_check_encoding($raw, 'UTF-8') || $requestValues === []) return [];
        $prefilter = false;
        foreach (['SELECT', 'UPDATE', 'INSERT', 'DELETE'] as $keyword) {
            if (stripos($raw, $keyword) !== false) { $prefilter = true; break; }
        }
        if (!$prefilter) return [];
        // Only values that carry SQL quoting can show unescaped interpolation.
        // A value without them is indistinguishable from a bound parameter or a
        // safely quoted literal, and would only add noise.
        $values = array_values(array_filter($requestValues, static function (string $value): bool {
            return mb_strlen($value, 'UTF-8') >= self::MIN_VALUE_CODEPOINTS && strpbrk($value, self::QUOTING) !== false;
        }));
        if ($values === []) return [];
        $statements = array_slice((new SqlTriageDetector())->responseQueries($raw, $input->url), 0, self::MAX_STATEMENTS);
        if ($statements === []) return [];

        $bindings = [];
        foreach ($statements as $statement) {
            $text = $statement['query'];
            if (mb_strlen($text, 'UTF-8') > self::MAX_STATEMENT_CODEPOINTS) continue;
            // A carrier whose text is not verbatim in the response, such as an
            // entity-decoded attribute value, cannot supply exact raw evidence.
            if (substr($raw, $statement['byte'], strlen($text)) !== $text) continue;
            if ($this->supplied($text, $requestValues)) continue;
            foreach ($values as $value) {
                $at = $this->binding($text, $value);
                if ($at === null) continue;
                $key = hash('sha256', $text."\0".$value);
                if (isset($bindings[$key])) continue;
                $bindings[$key] = ['text' => $text, 'byte' => $statement['byte'], 'value' => $value, 'valueByte' => $at];
            }
        }
        // One attributable occurrence keeps the observation usable. Nested
        // values from one request, for example a decoded path and one of its
        // segments, describe the same occurrence and collapse to the longest.
        // Separate occurrences stay ambiguous rather than picking a winner.
        $groups = $this->occurrenceGroups($bindings);
        if (count($groups) !== 1) return [];
        $binding = $groups[0][0];
        $span = static fn (string $excerpt, int $byte, string $role): array => [
            'representation' => 'raw', 'start' => mb_strlen(substr($raw, 0, $byte), 'UTF-8'),
            'length' => mb_strlen($excerpt, 'UTF-8'), 'excerpt' => $excerpt, 'role' => $role,
        ];
        return [new ClaimSuggestion(Taxonomy::SQL_INJECTION, Taxonomy::SUSPECTED, 86, [self::RULE_ID],
            ['A request value carrying SQL quoting characters appears verbatim inside a SQL statement the application disclosed, directly abutting that statement\'s quoting. The application interpolated the value into the statement without escaping it. This supports suspicion only: the statement is application output, and neither its execution nor a changed SQL structure is verified.'],
            [$span($binding['text'], $binding['byte'], 'query'), $span($binding['value'], $binding['byte'] + $binding['valueByte'], 'input')])];
    }

    /**
     * Bindings whose spans overlap describe one occurrence. The longest value
     * of a group is the most complete description of it.
     *
     * @param list<array> $bindings
     * @return list<list<array>>
     */
    private function occurrenceGroups(array $bindings): array
    {
        usort($bindings, static fn (array $a, array $b): int => [$a['byte'], $a['valueByte'], strlen($b['value'])]
            <=> [$b['byte'], $b['valueByte'], strlen($a['value'])]);
        $groups = [];
        foreach ($bindings as $binding) {
            $group = null;
            foreach ($groups as $index => $members) {
                foreach ($members as $existing) {
                    if ($this->overlaps($binding, $existing)) { $group = $index; break 2; }
                }
            }
            if ($group === null) $groups[] = [$binding];
            else $groups[$group][] = $binding;
        }

        return array_map(static function (array $members): array {
            usort($members, static fn (array $a, array $b): int => strlen($b['value']) <=> strlen($a['value']));
            return $members;
        }, $groups);
    }

    /** @param array $a @param array $b */
    private function overlaps(array $a, array $b): bool
    {
        $aStart = $a['byte'] + $a['valueByte'];
        $bStart = $b['byte'] + $b['valueByte'];

        return $aStart < $bStart + strlen($b['value']) && $bStart < $aStart + strlen($a['value']);
    }

    /**
     * Exact byte position, but only where the value abuts the statement's own
     * quoting. An escaped or commented occurrence is not an interpolation.
     */
    private function binding(string $statement, string $value): ?int
    {
        if (substr_count($statement, $value) !== 1) return null;
        $at = strpos($statement, $value);
        if ($at === false) return null;
        $before = $at === 0 ? '' : $statement[$at - 1];
        $after = $statement[$at + strlen($value)] ?? '';
        if (!in_array($before, ['', "'", '"', '`'], true) && !in_array($after, ['', "'", '"', '`'], true)) return null;
        if ($before !== '' && $this->escaped($statement, $at)) return null;
        if ($this->commented($statement, $at)) return null;

        return $at;
    }

    /** An odd run of backslashes directly before the value escapes it. */
    private function escaped(string $statement, int $at): bool
    {
        $slashes = 0;
        for ($i = $at - 1; $i >= 0 && $statement[$i] === '\\'; --$i) ++$slashes;

        return $slashes % 2 === 1;
    }

    /** Line and block comments are statement text, not a value position. */
    private function commented(string $statement, int $at): bool
    {
        $quote = null;
        for ($i = 0, $size = strlen($statement); $i < $size; ++$i) {
            if ($i >= $at) return false;
            $char = $statement[$i];
            $next = $statement[$i + 1] ?? '';
            if ($quote !== null) {
                if ($char === $quote) { if ($next === $quote) ++$i; else $quote = null; }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; continue; }
            if (($char === '-' && $next === '-') || $char === '#') return true;
            if ($char === '/' && $next === '*') {
                $end = strpos($statement, '*/', $i + 2);
                if ($end === false) return true;
                $i = $end + 1;
            }
        }

        return false;
    }

    /** A request value containing the whole statement supplied it, not the application. */
    private function supplied(string $statement, array $values): bool
    {
        $normalize = static fn (string $value): string => trim((string) CheckedRegex::replace('~\s+~u', ' ', rtrim(trim($value), ';')));
        $statement = $normalize($statement);
        if ($statement === '') return true;
        foreach ($values as $value) {
            if (mb_strlen($value, 'UTF-8') < self::MIN_VALUE_CODEPOINTS) continue;
            if (str_contains($normalize($value), $statement)) return true;
        }

        return false;
    }
}
