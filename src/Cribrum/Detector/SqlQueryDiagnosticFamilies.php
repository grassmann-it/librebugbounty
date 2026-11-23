<?php

declare(strict_types=1);

namespace App\Cribrum\Detector;

use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;

/** Bounded native query carriers, independent of later request/trace dumps. */
final class SqlQueryDiagnosticFamilies
{
    private const MAX_QUERY_BYTES = 4096;
    private const MAX_HEADERS = 12;
    private const HEADER = '~(SQLSTATE\[2201X\]:[ \t]*(?:<<Unknown error>>|&lt;&lt;Unknown error&gt;&gt;):[ \t]*7[ \t]+ERROR:[ \t]*OFFSET[ \t]+must[ \t]+not[ \t]+be[ \t]+negative),[ \t]*query[ \t]+was:[ \t\r\n]*~iu';

    /**
     * Statement and lexical checks are shared with the existing SQL detector.
     * This family emits only query disclosure; no request relation or SQL fact.
     */
    public static function evaluate(ObservationInput $input, DetectorRun $run, string $mode,
        callable $isStatement, callable $sqlContext, callable $isActiveQueryText): void
    {
        $raw = $input->rawHtml;
        if ($raw === null || stripos($raw, 'SQLSTATE[2201X]') === false) return;
        $run->stage('disclosure', 'native_query_was', 'raw', function () use ($input, $run, $mode, $raw, $isStatement, $sqlContext, $isActiveQueryText): void {
            CheckedRegex::matchAll(self::HEADER, $raw, $headers, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            if (count($headers) > self::MAX_HEADERS) $run->limited('disclosure', 'native_query_was', 'raw', 'match_budget',
                ['limit' => self::MAX_HEADERS, 'observed' => count($headers), 'remaining' => count($headers) - self::MAX_HEADERS]);
            $values = self::requestValues($input, $run);
            if ($values === null) return;
            $seen = [];
            foreach (array_slice($headers, 0, self::MAX_HEADERS) as $header) {
                [$headerRaw, $headerByte] = $header[0];
                [$errorRaw, $errorByte] = $header[1];
                $queryByte = $headerByte + strlen($headerRaw);
                $run->candidate('raw', mb_strlen(substr($raw, 0, $headerByte), 'UTF-8'), mb_strlen($headerRaw, 'UTF-8'));
                $run->count('disclosure', 'candidatesConsidered');
                if (!self::carrierEligible($raw, $headerByte, $queryByte, $isActiveQueryText)) {
                    $run->count('disclosure', 'candidatesExcluded'); continue;
                }
                // The first explicit break closes this native query carrier.
                // A later pre/print_r body is never read as part of its query.
                $tail = mb_strcut($raw, $queryByte, self::MAX_QUERY_BYTES + 128, 'UTF-8');
                if (CheckedRegex::match('~<br[ \t\r\n]*/?>~iu', $tail, $break, PREG_OFFSET_CAPTURE) !== 1) {
                    $run->limited('disclosure', 'native_query_was', 'raw', strlen($tail) > self::MAX_QUERY_BYTES ? 'context_budget' : 'context_incomplete',
                        ['limit' => self::MAX_QUERY_BYTES, 'remaining' => null]);
                    continue;
                }
                $queryLength = $break[0][1];
                if ($queryLength > self::MAX_QUERY_BYTES) {
                    $run->limited('disclosure', 'native_query_was', 'raw', 'context_budget',
                        ['limit' => self::MAX_QUERY_BYTES, 'observed' => $queryLength, 'remaining' => null]);
                    continue;
                }
                $queryRaw = rtrim(substr($tail, 0, $queryLength));
                $query = html_entity_decode($queryRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!self::queryComplete($query, $isStatement, $sqlContext)) {
                    $run->limited('disclosure', 'native_query_was', 'raw', 'unsupported_grammar', ['remaining' => null]);
                    continue;
                }
                if (CheckedRegex::match('~<[/!?A-Za-z][^>]{0,512}>~u', $queryRaw)
                    || self::supplied($query, $errorRaw, $values)) {
                    $run->count('disclosure', 'candidatesExcluded'); continue;
                }
                $key = hash('sha256', $query."\0".$errorRaw);
                if (isset($seen[$key])) { $run->count('disclosure', 'duplicatesKnown'); continue; }
                $seen[$key] = true;
                $span = static fn (string $excerpt, int $byte, string $role): array => [
                    'representation' => 'raw', 'start' => mb_strlen(substr($raw, 0, $byte), 'UTF-8'),
                    'length' => mb_strlen($excerpt, 'UTF-8'), 'excerpt' => $excerpt, 'role' => $role,
                ];
                $run->add(EvidenceSignal::make('internal_information_exposed', $input, [
                    $span($errorRaw, $errorByte, 'error'), $span($queryRaw, $queryByte, 'query'),
                ], [
                    'detailKind' => 'query', 'concreteInternals' => true, 'source' => 'response_body',
                    'inputAssessment' => 'no_input_binding_claimed', 'queryExtraction' => 'native_query_was_break',
                    'limitations' => ['query_disclosure_is_not_sql_injection', 'trace_and_parameters_are_not_statement_literals'],
                ], [['type' => 'query_in_diagnostic', 'from' => 1, 'to' => 0,
                    'transformations' => $query === $queryRaw ? [] : ['html_entities_once_for_comparison']]],
                    'sql-query-disclosure-v1', $mode));
            }
        });
    }

    private static function carrierEligible(string $raw, int $headerByte, int $queryByte, callable $active): bool
    {
        foreach ([$headerByte, $queryByte] as $at) {
            if (!HtmlDiagnosticContext::eligible($raw, $at) || !$active($raw, $at)) return false;
        }
        $prefix = mb_strcut(substr($raw, 0, $headerByte), max(0, $headerByte - 300), null, 'UTF-8');
        $plain = strip_tags($prefix);
        if (CheckedRegex::match('~(?:example|documentation|sample|tutorial|manual|search)[ \t]*[:\-][\s\S]{0,300}$~iu', $plain)
            || CheckedRegex::match('~\b(?:SELECT|INSERT|UPDATE|DELETE)\b[\s\S]{0,300}$~iu', $plain)) return false;
        // A Zend diagnostic starts a rendered line, not an arbitrary prose,
        // assignment or SQL literal substring in a document.
        return trim(substr($raw, 0, $headerByte)) === ''
            || CheckedRegex::match('~</?(?:br|p|div|pre|body)\b[^<>]{0,256}>[ \t\r\n]*$~iu', $prefix) === 1;
    }

    /** Complete relational SELECT and matching native negative OFFSET clause. */
    private static function queryComplete(string $query, callable $isStatement, callable $context): bool
    {
        if (!CheckedRegex::match('~^SELECT[ \t\r\n]+~iu', $query) || !$isStatement($query) || $context($query) !== 'neutral') return false;
        if (!CheckedRegex::match('~(?<![\pL\pN_$.])(?:LIMIT[ \t\r\n]+(?:[0-9]{1,9}|ALL)[ \t\r\n]+OFFSET[ \t\r\n]+-[1-9][0-9]{0,8}|OFFSET[ \t\r\n]+-[1-9][0-9]{0,8}(?:[ \t\r\n]+LIMIT[ \t\r\n]+(?:[0-9]{1,9}|ALL))?)[ \t\r\n]*;?[ \t\r\n]*$~iu', $query, $tail, PREG_OFFSET_CAPTURE)
            || $context(substr($query, 0, $tail[0][1])) !== 'neutral') return false;
        $beforeTail = rtrim(substr($query, 0, $tail[0][1]));
        if (CheckedRegex::match('~[.\+\-/*%=<>|&,:(]$|\b(?:WHERE|ON|AND|OR|AS|BY|JOIN|SELECT|FROM|IN|IS|NOT|LIKE|BETWEEN|DISTINCT|OFFSET|LIMIT)$~iu', $beforeTail)) return false;
        $depth = 0; $froms = []; $tailDepth = null;
        CheckedRegex::matchAll('~(?<![\pL\pN_$.])(?:FROM|LIMIT|OFFSET)(?![\pL\pN_$])|[();#]|--|/\*~iu', $query, $tokens, PREG_OFFSET_CAPTURE);
        foreach ($tokens[0] as [$token, $byte]) {
            if ($context(substr($query, 0, $byte)) !== 'neutral') continue;
            if ($byte === $tail[0][1]) $tailDepth = $depth;
            if ($token === '(') { if (++$depth > 32) return false; }
            elseif ($token === ')') { if (--$depth < 0) return false; }
            elseif ($token === ';') { if (trim(substr($query, $byte + 1)) !== '') return false; }
            elseif (in_array($token, ['#', '--', '/*'], true)) return false;
            elseif ($depth === 0 && strcasecmp($token, 'FROM') === 0) $froms[] = $byte;
        }
        if (count($froms) !== 1 || $depth !== 0 || $tailDepth !== 0 || $froms[0] >= $tail[0][1]) return false;
        $identifier = '(?:[\pL_][\pL\pN_$]{0,63}|"(?:[^"\r\n]|""){1,64}")';
        if (!CheckedRegex::match('~\G[ \t\r\n]+('.$identifier.'(?:[ \t]*\.[ \t]*'.$identifier.'){0,3})(?=[ \t\r\n,]|$)~u',
            $query, $relation, PREG_OFFSET_CAPTURE, $froms[0] + 4)) return false;
        if ($relation[1][1] + strlen($relation[1][0]) > $tail[0][1]) return false;
        return !CheckedRegex::match('~^(?:OFFSET|LIMIT|WHERE|FROM|SELECT|ORDER|GROUP|HAVING|UNION|JOIN|AND|OR|AS|ON|TRUE|FALSE|NULL)$~iu', $relation[1][0]);
    }

    /** Raw duplicate/array occurrences still count as possible echo sources. */
    private static function requestValues(ObservationInput $input, DetectorRun $run): ?array
    {
        $values = [];
        foreach (array_unique(array_filter([$input->url, $input->finalUrl], static fn (?string $url): bool => $url !== null)) as $url) {
            $query = parse_url($url, PHP_URL_QUERY);
            if (!is_string($query)) continue;
            $pairs = explode('&', $query);
            if (count($pairs) > 128) {
                $run->limited('disclosure', 'native_query_was', 'raw', 'input_budget', ['limit' => 128, 'observed' => count($pairs), 'remaining' => count($pairs) - 128]);
                return null;
            }
            foreach ($pairs as $pair) {
                foreach (array_pad(explode('=', $pair, 2), 2, '') as $part) {
                    $value = urldecode($part);
                    if (!mb_check_encoding($value, 'UTF-8')) {
                        $run->unavailable('disclosure', 'native_query_was', 'raw', 'input_invalid_encoding'); return null;
                    }
                    $values[] = $value;
                }
            }
        }
        if ($input->payload !== null) {
            if (!mb_check_encoding($input->payload, 'UTF-8')) {
                $run->unavailable('disclosure', 'native_query_was', 'raw', 'input_invalid_encoding'); return null;
            }
            $values[] = $input->payload;
        }
        return $values;
    }

    private static function supplied(string $query, string $error, array $values): bool
    {
        $normalize = static fn (string $value): string => trim(CheckedRegex::replace('~\s+~u', ' ', rtrim(trim($value), ';')));
        $query = $normalize($query);
        $error = html_entity_decode($error, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $errors = [$normalize($error), $normalize(strip_tags($error))];
        foreach ($values as $value) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach ([$normalize($value), $normalize(strip_tags($value))] as $candidate) {
                if (str_contains($candidate, $query)) return true;
                foreach ($errors as $error) if ($error !== '' && str_contains($candidate, $error)) return true;
            }
        }
        return false;
    }
}
