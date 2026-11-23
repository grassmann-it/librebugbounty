<?php

declare(strict_types=1);

namespace App\Cribrum;

use App\Cribrum\Detector\CheckedRegex;
use App\Cribrum\Detector\SqlTriageDetector;

/** Narrow suspicion from a native error location; never structural/execution evidence. */
final class SqlDiagnosticSuspicion
{
    public const RULE_ID = 'builtin/mysql-syntax-input-suffix';
    private const MIN_SUFFIX = 32;
    private const MAX_OMITTED_PREFIX = 8;
    private const NATIVE_ERROR = '~(?:\bSQLSTATE\[42000\]:[ \t]*Syntax error or access violation:[ \t]*1064[ \t]+)?You have an error in your SQL syntax;[ \t]+check the manual that corresponds to your (?:MySQL|MariaDB) server version for the right syntax to use[ \t]+near[ \t]+\x27([\s\S]{1,2048})\x27[ \t]+at line[ \t]+[1-9]\d{0,5}(?=\s|<|$)~iu';
    private const CLIPPED_KEYWORDS = ['LIMIT', 'ORDER', 'GROUP', 'WHERE', 'HAVING', 'AND', 'OR', 'UNION', 'JOIN', 'OFFSET'];

    public function suggest(ObservationInput $input): ?ClaimSuggestion
    {
        $raw = $input->rawHtml;
        // Ordinary responses do not need another detector evaluation.
        if ($raw === null || stripos($raw, 'You have an error in your SQL syntax;') === false
            || stripos($raw, 'near') === false) return null;
        if (in_array(ltrim($raw)[0] ?? '', ['{', '['], true)) return null;
        $params = $this->parameters($input->url);
        if ($params === []) return null;
        $matches = [];
        foreach ((new SqlTriageDetector())->evaluate($input, 'shadow')->signals as $signal) {
            if ($signal['code'] !== 'sql_error_observed' || ($signal['attributes']['vendor'] ?? null) !== 'mysql'
                || ($signal['attributes']['diagnosticClass'] ?? null) !== 'syntax') continue;
            $blocks = array_values(array_filter($signal['spans'], static fn (array $span): bool => ($span['role'] ?? null) === 'diagnostic_block' && $span['representation'] === 'raw'));
            if (count($blocks) !== 1) continue;
            $block = $blocks[0];
            $text = $block['excerpt'];
            if (CheckedRegex::matchAll(self::NATIVE_ERROR, $text, $errors, PREG_OFFSET_CAPTURE) !== 1
                || CheckedRegex::matchAll('~\bnear[ \t]+\x27~iu', $text) !== 1
                || CheckedRegex::matchAll('~\x27[ \t]+at line[ \t]+[1-9]\d{0,5}\b~iu', $text) !== 1) continue;
            [$native, $nativeByte] = $errors[0][0];
            [$fragment, $fragmentByte] = $errors[1][0];
            // An optional wrapper must not let a conflicting SQLSTATE/code be
            // skipped in favour of the otherwise canonical native message.
            $states = CheckedRegex::matchAll('~SQLSTATE\b~iu', $text);
            if ($states !== 0 && ($states !== 1 || stripos($native, 'SQLSTATE[42000]:') !== 0)) continue;
            // A native-looking string inside a disclosed SQL statement remains
            // query data. The strict detector also excludes generic DB ERR carriers.
            $prefix = strip_tags(html_entity_decode(substr($text, 0, $nativeByte), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (CheckedRegex::match('~\b(?:DB\s+ERR|SELECT|UPDATE|INSERT|DELETE)\b~iu', $prefix)) continue;
            CheckedRegex::matchAll('~(?|\bERROR[ \t]+([0-9]{3,5})\b|\b(?:mysqli_sql_exception|PDOException)[ \t]*\([ \t]*([0-9]{3,5})[ \t]*\)|\b([0-9]{3,5})[ \t]+-[ \t]+|\bSyntax error or access violation:[ \t]*([0-9]{3,5})\b)~iu', $prefix, $codes);
            if (array_filter($codes[1], static fn (string $code): bool => $code !== '1064') !== []) continue;
            CheckedRegex::matchAll('~\(([A-Z0-9]{5})/([0-9]{3,5})\)~iu', $prefix, $mysqliStates);
            if (array_filter($mysqliStates[1], static fn (string $state): bool => strtoupper($state) !== '42000') !== []
                || array_filter($mysqliStates[2], static fn (string $code): bool => $code !== '1064') !== []) continue;
            if ($this->suppliedDiagnostic($native, $params, $input->payload)) continue;
            $decoded = $this->decodedFragment($fragment);
            if ($decoded['text'] !== $fragment && (CheckedRegex::match('~\bnear[ \t]+\x27~iu', $decoded['text'])
                || CheckedRegex::match('~\x27[ \t]+at line[ \t]+[1-9]\d{0,5}\b~iu', $decoded['text']))) continue;
            $variants = [['text' => $fragment, 'endpoints' => null]];
            if ($decoded['text'] !== $fragment) $variants[] = $decoded;
            $bindings = [];
            foreach ($params as $index => $param) {
                foreach ($variants as $variant) {
                    $retained = $this->retainedSuffix($variant['text'], $param['value']);
                    if ($retained === null) continue;
                    $rawEnd = $variant['endpoints'] === null ? strlen($retained['suffix'])
                        : ($variant['endpoints'][mb_strlen($retained['suffix'], 'UTF-8')] ?? null);
                    // A suffix may not stop inside an entity that decodes to
                    // multiple codepoints; evidence always spans complete raw tokens.
                    if ($rawEnd === null) continue;
                    $rawSuffix = substr($fragment, 0, $rawEnd);
                    $bindings[$index.':'.$rawEnd.':'.(int) $retained['clipped']] = [$param, $rawSuffix, $retained['clipped'], $variant['endpoints'] !== null];
                }
            }
            if (count($bindings) !== 1) continue;
            [$param, $retained, $clipped, $entityDecoded] = array_values($bindings)[0];
            if (!$param['unique']) continue;
            $nativeStart = $block['start'] + mb_strlen(substr($text, 0, $nativeByte), 'UTF-8');
            $inputStart = $block['start'] + mb_strlen(substr($text, 0, $fragmentByte), 'UTF-8');
            $matches[] = ['clipped' => $clipped, 'entityDecoded' => $entityDecoded, 'spans' => [
                ['representation' => 'raw', 'start' => $nativeStart, 'length' => mb_strlen($native, 'UTF-8'), 'excerpt' => $native],
                ['representation' => 'raw', 'start' => $inputStart, 'length' => mb_strlen($retained, 'UTF-8'), 'excerpt' => $retained],
            ]];
        }
        // Two native error carriers or bindings are not one unambiguous source.
        if (count($matches) !== 1) return null;
        $reason = 'A distinctive request-value suffix appears at a native MySQL/MariaDB syntax-error location. This supports suspicion only; partial input binding and the full SQL query remain unverified.';
        if ($matches[0]['entityDecoded']) $reason .= ' HTML entities are decoded once within the native near fragment for comparison; evidence spans retain the raw response text.';
        if ($matches[0]['clipped']) $reason .= ' The native near fragment ends in an ellipsis; the following SQL text remains incomplete and unverified.';
        return new ClaimSuggestion(Taxonomy::SQL_INJECTION, Taxonomy::SUSPECTED, 88, [self::RULE_ID], [$reason], $matches[0]['spans']);
    }

    /** @return array{suffix: string, clipped: bool}|null */
    private function retainedSuffix(string $fragment, string $value): ?array
    {
        $length = mb_strlen($value, 'UTF-8');
        if ($length < self::MIN_SUFFIX || strpbrk($value, '\'"') === false) return null;
        // Long padding and generic markers are not distinctive request evidence.
        if (CheckedRegex::matchAll('~[\pL\pN]~u', $value) < 8
            || count(array_unique(mb_str_split($value, 1, 'UTF-8'))) < 8) return null;
        for ($missing = 0; $missing <= self::MAX_OMITTED_PREFIX; ++$missing) {
            if (($length - $missing) * 10 < $length * 9 || $length - $missing < self::MIN_SUFFIX) break;
            $prefix = mb_substr($value, 0, $missing, 'UTF-8');
            if (CheckedRegex::match('~^[^\pL\pN\s]*$~u', $prefix) !== 1) break;
            $suffix = mb_substr($value, $missing, null, 'UTF-8');
            if (!str_starts_with($fragment, $suffix)) continue;
            $tail = substr($fragment, strlen($suffix));
            if (CheckedRegex::match('~^[\t (),;%\x27"]{0,8}$~D', $tail) === 1) return ['suffix' => $suffix, 'clipped' => false];
            // A clipped near fragment may show a short SQL-keyword prefix. No
            // missing text or complete keyword is reconstructed from that tail.
            // Deliberately limit this extension to the retained 80-byte native
            // snippet shape; this is not a general vendor truncation contract.
            if (strlen($fragment) === 80 && CheckedRegex::match('~^[ \t]{1,2}([A-Z]{2,6})\.\.\.$~iD', $tail, $clipped) === 1) {
                foreach (self::CLIPPED_KEYWORDS as $keyword) {
                    if (str_starts_with($keyword, strtoupper($clipped[1]))) return ['suffix' => $suffix, 'clipped' => true];
                }
            }
        }
        return null;
    }

    /** @return array{text: string, endpoints: array<int, int>} */
    private function decodedFragment(string $fragment): array
    {
        CheckedRegex::matchAll('~&(?:\#(?:[0-9]{1,7}|[xX][0-9a-fA-F]{1,6})|[A-Za-z][A-Za-z0-9]{1,31});|[\s\S]~u', $fragment, $tokens, PREG_OFFSET_CAPTURE);
        $decoded = ''; $endpoints = [0 => 0]; $length = 0;
        foreach ($tokens[0] as [$token, $byte]) {
            $value = html_entity_decode($token, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded .= $value;
            if ($value === $token) {
                $rawEnd = $byte;
                foreach (mb_str_split($token, 1, 'UTF-8') as $char) $endpoints[++$length] = $rawEnd += strlen($char);
            } else {
                $length += mb_strlen($value, 'UTF-8');
                $endpoints[$length] = $byte + strlen($token);
            }
        }
        return ['text' => $decoded, 'endpoints' => $endpoints];
    }

    /** Preserve every raw key occurrence; PHP array flattening loses conflicts. */
    private function parameters(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) return [];
        $params = []; $roots = [];
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name); $value = urldecode($value);
            if (!mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($value, 'UTF-8')) return [];
            $root = str_replace(['.', ' '], '_', explode('[', $name, 2)[0]);
            $roots[$root] = ($roots[$root] ?? 0) + 1;
            $valid = CheckedRegex::match('~^[A-Za-z_][A-Za-z0-9_-]*(?:\[[A-Za-z0-9_-]*\])?$~D', $name) === 1;
            $params[] = ['root' => $root, 'value' => $value, 'valid' => $valid];
        }
        foreach ($params as &$param) $param['unique'] = $param['valid'] && $roots[$param['root']] === 1;
        return $params;
    }

    private function suppliedDiagnostic(string $diagnostic, array $params, ?string $payload): bool
    {
        $normalize = static fn (string $value): string => trim(CheckedRegex::replace('~\s+~u', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $diagnostic = $normalize($diagnostic);
        $values = array_column($params, 'value');
        if ($payload !== null) $values[] = $payload;
        foreach ($values as $value) if (str_contains($normalize($value), $diagnostic)) return true;
        return false;
    }
}
