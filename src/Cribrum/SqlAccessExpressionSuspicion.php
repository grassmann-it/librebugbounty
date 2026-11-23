<?php

declare(strict_types=1);

namespace App\Cribrum;

use App\Cribrum\Detector\CheckedRegex;
use App\Cribrum\Detector\HtmlDiagnosticContext;
use App\Cribrum\Detector\RegexFailure;
use App\Cribrum\Detector\SqlTriageDetector;

/** Core-only native Access observations and input suspicion; never structural facts. */
final class SqlAccessExpressionSuspicion
{
    public const RULE_ID = 'builtin/access-expression-input';
    public const DIAGNOSTIC_RULE_ID = 'builtin/access-expression-diagnostic';
    private const INPUT_TOKEN = '__cribrum_bound_input__';

    /** @return array{suggestions:list<ClaimSuggestion>,issues:list<array>} */
    public function evaluate(ObservationInput $input): array
    {
        try {
            return ['suggestions' => $this->suggestions($input), 'issues' => []];
        } catch (RegexFailure) {
            return ['suggestions' => [], 'issues' => [['code' => 'regex_error', 'stage' => 'builtin_access_expression',
                'representation' => 'raw', 'status' => 'failed']]];
        }
    }

    public function suggest(ObservationInput $input): ?ClaimSuggestion
    {
        foreach ($this->evaluate($input)['suggestions'] as $suggestion) {
            if ($suggestion->claim === Taxonomy::SQL_INJECTION) return $suggestion;
        }
        return null;
    }

    /** @return list<ClaimSuggestion> */
    private function suggestions(ObservationInput $input): array
    {
        $raw = $input->rawHtml;
        if ($raw === null || !mb_check_encoding($raw, 'UTF-8')
            || stripos($raw, 'Microsoft Access Database Engine') === false) return [];
        if (in_array(ltrim($raw)[0] ?? '', ['{', '['], true)) return [];
        $format = '(?:[ \t\r\n]++|</?(?:font|p|br|b|strong|span)\b[^<>]{0,256}>){0,24}';
        $quote = '(?:\x27|&(?:apos|\#0*39|\#x0*27);)';
        // The expression and source location belong to one bounded native ASP
        // diagnostic. In particular, a page-wide provider label is insufficient.
        $grammar = '(?:Errore di sintassi nella stringa nell'.$quote.'espressione della query|Syntax error (?:in string |\(missing operator\) )?in query expression)';
        $pattern = '~(Microsoft Access Database Engine)'.$format.'(error[ \t]+'.$quote.'80040e14'.$quote.')'.$format
            .'('.$grammar.')[ \t]+'.$quote.'([\s\S]{1,4096}?)'.$quote.'\.'
            .$format.'((?:/[A-Za-z0-9_.-]{1,80}){1,12}\.asp)'.$format.',[ \t]*line[ \t]+([1-9][0-9]{0,7})(?=[ \t\r\n<]|$)~iu';
        if (CheckedRegex::matchAll('~Microsoft Access Database Engine~iu', $raw) !== 1
            || CheckedRegex::matchAll($pattern, $raw, $matches, PREG_OFFSET_CAPTURE) !== 1) return [];
        [$carrier, $carrierByte] = $matches[0][0];
        [, $codeByte] = $matches[2][0];
        [$message, $messageByte] = $matches[3][0];
        [$expressionRaw, $expressionByte] = $matches[4][0];
        [$path, $pathByte] = $matches[5][0];
        $context = new SqlTriageDetector();
        foreach ([$carrierByte, $codeByte, $messageByte, $pathByte] as $partByte) {
            if (!HtmlDiagnosticContext::eligible($raw, $partByte)
                || !$context->isActiveQueryText($raw, $partByte)) return [];
        }
        $prefix = mb_strcut(substr($raw, 0, $carrierByte), max(0, $carrierByte - 240), null, 'UTF-8');
        $plainPrefix = strip_tags($prefix);
        if (CheckedRegex::match('~(?:example|documentation|sample|tutorial|manual|search)[ \t]*[:\-][\s\S]{0,240}$~iu', $plainPrefix)
            || CheckedRegex::match('~\b(?:SELECT|INSERT|UPDATE|DELETE)\b[\s\S]{0,240}$~iu', $plainPrefix)) return [];
        $params = $this->parameters($input->url);
        if ($params === null || $this->supplied($carrier, $message, $expressionRaw, $params, $input)) return [];

        $span = static fn (string $excerpt, int $byte): array => [
            'representation' => 'raw', 'start' => mb_strlen(substr($raw, 0, $byte), 'UTF-8'),
            'length' => mb_strlen($excerpt, 'UTF-8'), 'excerpt' => $excerpt,
        ];
        $observed = new ClaimSuggestion(Taxonomy::ERROR_DISCLOSURE, Taxonomy::OBSERVED, 86,
            [self::DIAGNOSTIC_RULE_ID],
            ['A native Microsoft Access syntax diagnostic with its complete disclosed expression and adjacent ASP source location was returned. This alone does not establish SQL injection or execution.'],
            [$span($carrier, $carrierByte)]);
        $variants = [['text' => $expressionRaw, 'endpoints' => null]];
        $decoded = $this->decoded($expressionRaw);
        if ($decoded['text'] !== $expressionRaw) $variants[] = $decoded;
        $bindings = [];
        foreach ($variants as $variant) {
            $expression = $variant['text'];
            if (str_contains($expression, self::INPUT_TOKEN)) continue;
            foreach ($params as $param) {
                $value = $param['value'];
                if (!$param['unique'] || !$this->distinctive($value) || substr_count($expression, $value) !== 1) continue;
                foreach ($params as $other) {
                    if ($other['index'] !== $param['index'] && str_contains($other['value'], $value)) continue 2;
                }
                $at = strpos($expression, $value);
                $masked = substr($expression, 0, $at).self::INPUT_TOKEN.substr($expression, $at + strlen($value));
                if (!$this->comparisonExpression($masked)) continue;
                $start = mb_strlen(substr($expression, 0, $at), 'UTF-8');
                $end = $start + mb_strlen($value, 'UTF-8');
                $rawStart = $variant['endpoints'] === null ? $at : ($variant['endpoints'][$start] ?? null);
                $rawEnd = $variant['endpoints'] === null ? $at + strlen($value) : ($variant['endpoints'][$end] ?? null);
                if ($rawStart === null || $rawEnd === null) continue;
                $bindings[$param['index'].':'.$rawStart.':'.$rawEnd] = [$rawStart, $rawEnd, $variant['endpoints'] !== null];
            }
        }
        if (count($bindings) !== 1) return [$observed];
        [$start, $end, $entityDecoded] = array_values($bindings)[0];
        $reason = 'A distinctive complete scalar request value occurs uniquely at an unquoted comparison operand in the expression disclosed by a native Microsoft Access syntax diagnostic. This supports SQL-injection suspicion only; no complete SQL statement, structural indication or execution is established.';
        if ($entityDecoded) $reason .= ' HTML entities are decoded once for comparison; evidence spans retain the raw response text.';
        return [$observed, new ClaimSuggestion(Taxonomy::SQL_INJECTION, Taxonomy::SUSPECTED, 88, [self::RULE_ID], [$reason], [
            $span($carrier, $carrierByte), $span($expressionRaw, $expressionByte),
            $span(substr($expressionRaw, $start, $end - $start), $expressionByte + $start), $span($path, $pathByte),
        ])];
    }

    private function distinctive(string $value): bool
    {
        return mb_strlen($value, 'UTF-8') >= 32 && strlen($value) <= 1024 && strpbrk($value, '\'"<>') !== false
            && CheckedRegex::matchAll('~[\pL\pN]~u', $value) >= 8
            && count(array_unique(mb_str_split($value, 1, 'UTF-8'))) >= 8;
    }

    /** Preserve raw key collisions. Only the bound unique scalar may establish attribution. */
    private function parameters(string $url): ?array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) return [];
        $pairs = explode('&', $query);
        if (count($pairs) > 128) return null;
        $params = []; $roots = [];
        foreach ($pairs as $index => $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name); $value = urldecode($value);
            if (!mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($value, 'UTF-8')) return null;
            $root = str_replace(['.', ' '], '_', explode('[', $name, 2)[0]);
            $roots[$root] = ($roots[$root] ?? 0) + 1;
            $params[] = ['index' => $index, 'root' => $root, 'value' => $value,
                'valid' => CheckedRegex::match('~^[A-Za-z_][A-Za-z0-9_-]*$~D', $name) === 1];
        }
        foreach ($params as &$param) $param['unique'] = $param['valid'] && $roots[$param['root']] === 1;
        return $params;
    }

    /**
     * Parse the surrounding expression after replacing only the exact input.
     * A token inside a string, identifier, function, comment or statement cannot
     * become an unquoted RHS operand. This intentionally supports simple native
     * comparison/AND/OR expressions, not arbitrary Access SQL.
     */
    private function comparisonExpression(string $expression): bool
    {
        $tokens = []; $offset = 0; $size = strlen($expression);
        $pattern = <<<'REGEX'
~\G\s*(?:(\[?(?:__cribrum_bound_input__)\]?)|([A-Za-z_][A-Za-z0-9_]{0,63}|\[[A-Za-z_][A-Za-z0-9_ ]{0,63}\])|(-?[0-9]{1,12}(?:\.[0-9]{1,12})?)|('(?:''|[^'\r\n]){0,512}'|"(?:""|[^"\r\n]){0,512}")|(<=|>=|<>|!=|=|<|>)|([().]))~u
REGEX;
        while ($offset < $size) {
            if (trim(substr($expression, $offset)) === '') break;
            if (count($tokens) >= 256 || CheckedRegex::match($pattern, $expression, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1) return false;
            $offset += strlen($match[0]);
            if ($match[1] !== null) $tokens[] = ['input', $match[1]];
            elseif ($match[2] !== null) {
                $upper = strtoupper($match[2]);
                $tokens[] = [in_array($upper, ['AND', 'OR'], true) ? 'boolean' : (in_array($upper, ['TRUE', 'FALSE', 'NULL'], true) ? 'value' : 'identifier'), $match[2]];
            } elseif ($match[3] !== null || $match[4] !== null) $tokens[] = ['value', $match[3] ?? $match[4]];
            elseif ($match[5] !== null) $tokens[] = ['operator', $match[5]];
            else $tokens[] = [$match[6], $match[6]];
        }
        $at = 0; $usedInput = 0;
        $parse = function (int $depth = 0) use (&$parse, &$at, &$usedInput, $tokens): bool {
            if ($depth > 16) return false;
            $primary = function () use (&$parse, &$at, &$usedInput, $tokens, $depth): bool {
                if (($tokens[$at][0] ?? null) === '(') {
                    ++$at;
                    if (!$parse($depth + 1) || ($tokens[$at][0] ?? null) !== ')') return false;
                    ++$at; return true;
                }
                if (($tokens[$at++][0] ?? null) !== 'identifier') return false;
                for ($parts = 0; ($tokens[$at][0] ?? null) === '.'; ++$parts) {
                    if ($parts >= 3 || ($tokens[++$at][0] ?? null) !== 'identifier') return false;
                    ++$at;
                }
                if (($tokens[$at++][0] ?? null) !== 'operator') return false;
                $rhs = $tokens[$at++] ?? null;
                if (($rhs[0] ?? null) === 'input') {
                    if ($rhs[1] !== self::INPUT_TOKEN) return false;
                    ++$usedInput; return true;
                }
                return ($rhs[0] ?? null) === 'value';
            };
            if (!$primary()) return false;
            while (($tokens[$at][0] ?? null) === 'boolean') {
                ++$at;
                if (!$primary()) return false;
            }
            return true;
        };
        return $parse() && $at === count($tokens) && $usedInput === 1;
    }

    /** One explicit entity decode, retaining only complete raw-token endpoints. */
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

    private function supplied(string $carrier, string $message, string $expression, array $params, ObservationInput $input): bool
    {
        $normalize = static fn (string $value): string => trim(CheckedRegex::replace('~\s+~u', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $needles = array_map($normalize, [$carrier, $message." '".$expression."'.", $message]);
        $values = array_column($params, 'value');
        if ($input->finalUrl !== null && $input->finalUrl !== $input->url) {
            $redirectParams = $this->parameters($input->finalUrl);
            if ($redirectParams === null) return true;
            $values = [...$values, ...array_column($redirectParams, 'value')];
        }
        if ($input->payload !== null) $values[] = $input->payload;
        foreach ($values as $value) {
            $value = $normalize($value);
            foreach ($needles as $needle) if ($needle !== '' && str_contains($value, $needle)) return true;
            // A request-provided vendor/code or source terminator cannot be
            // authenticated as an independent part of this native carrier.
            if (stripos($value, 'Microsoft Access Database Engine') !== false
                || CheckedRegex::match('~/(?:[A-Za-z0-9_.-]{1,80}/){0,11}[A-Za-z0-9_.-]{1,80}\.asp\s*,\s*line\s+[1-9][0-9]{0,7}\b~iu', $value)) return true;
        }
        return false;
    }
}
