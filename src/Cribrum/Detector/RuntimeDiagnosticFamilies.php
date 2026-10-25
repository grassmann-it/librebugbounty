<?php

namespace App\Cribrum\Detector;

use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;

/** Concrete, bounded runtime diagnostics. These observations never qualify a disclosure claim. */
final class RuntimeDiagnosticFamilies
{
    public const ID = 'runtime-diagnostic-families-v1';
    private const MAX_OBJECT_BYTES = 65536;
    private const MAX_CANDIDATES = 32;
    private const MAX_SIGNALS = 16;

    public function evaluate(ObservationInput $input, string $mode = 'shadow'): DetectorEvaluation
    {
        $run = new DetectorRun($input, self::ID, ['error_response']);
        $raw = $input->rawHtml ?? $input->domText;
        $representation = $input->rawHtml !== null ? 'raw' : 'dom_text';
        if ($raw === null || !mb_check_encoding($raw, 'UTF-8')) {
            $run->unavailable('error_response', 'representation', $representation, $raw === null ? 'representation_missing' : 'representation_invalid');
            return $run->finish();
        }
        $seen = [];
        $emit = function (array $match, array $details) use ($raw, $input, $mode, $representation, $run, &$seen): void {
            // Repeated HTML/text renderings of the same cause remain one runtime observation.
            $key = $details['family'].':'.$details['diagnosticKind'].':'.html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (isset($seen[$key])) { $run->count('error_response', 'candidatesExcluded'); return; }
            if (count($seen) >= self::MAX_SIGNALS) {
                $run->limited('error_response', 'diagnostics', $representation, 'context_budget', ['signalsRetained' => count($seen)]);
                return;
            }
            $seen[$key] = true;
            $span = EvidenceSignal::span($raw, mb_strlen(substr($raw, 0, $match[1]), 'UTF-8'), mb_strlen($match[0], 'UTF-8'));
            $span['representation'] = $representation;
            $run->add(EvidenceSignal::make('error_response', $input, [$span], ['source' => 'response_body', 'httpStatus' => $input->httpStatus, 'runtimeDetails' => $details], [], self::ID, $mode));
        };
        $eligible = static function (int $offset, bool $comment = false) use ($raw, $run, $representation): bool {
            return HtmlDiagnosticContext::eligible($raw, $offset, $comment, static function () use ($run, $representation): void {
                $run->limited('error_response', 'html_context', $representation, 'context_budget');
            });
        };
        $run->stage('error_response', 'structured', $representation, function () use ($raw, $run, $representation, $emit, $eligible): void {
            $offset = 0;
            $count = 0;
            // Only known response-error roots; no recursive search through arbitrary JSON strings.
            while (CheckedRegex::match('~\{\s*"(?:code|errors|error|status|serverResponse|traceback)"\s*:~', $raw, $candidate, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $start = $candidate[0][1];
                $offset = $start + strlen($candidate[0][0]);
                $run->candidate($representation, mb_strlen(substr($raw, 0, $start), 'UTF-8'), mb_strlen($candidate[0][0], 'UTF-8'));
                $run->count('error_response', 'candidatesConsidered');
                if (++$count > self::MAX_CANDIDATES) { $run->limited('error_response', 'structured', $representation, 'context_budget', ['candidatesConsidered' => $count]); break; }
                $object = self::objectAt($raw, $start);
                if ($object === null) {
                    if ($eligible($start)) {
                        if (strlen($raw) - $start >= self::MAX_OBJECT_BYTES) $run->limited('error_response', 'structured', $representation, 'context_budget', ['objectByteLimit' => self::MAX_OBJECT_BYTES]);
                        else $run->failed('error_response', 'structured', $representation, 'parser_error');
                    }
                    continue;
                }
                $json = $object;
                $offset = $start + strlen($json);
                // A JSON response is its whole root. An HTML response may carry one
                // interrupted terminal error object; arbitrary JSON subobjects are data.
                $isRoot = trim(substr($raw, 0, $start)) === '' && trim(substr($raw, $offset)) === '';
                $htmlTail = str_starts_with(ltrim($raw), '<') && trim(substr($raw, $offset)) === '';
                if (!$isRoot && !$htmlTail) { $run->count('error_response', 'candidatesExcluded'); continue; }
                $allowed = $eligible($start);
                if ($allowed && !$isRoot && !self::htmlRootBoundary($raw, $start)) { $run->count('error_response', 'candidatesExcluded'); continue; }
                if (!$allowed && str_starts_with($json, '{"code":"internal_server_error"') && trim(substr($raw, $offset)) === '') {
                    $allowed = self::runtimeTailEligible($raw, $start, static function () use ($run, $representation): void {
                        $run->limited('error_response', 'html_context', $representation, 'context_budget');
                    });
                }
                if (!$allowed) {
                    // Actual interrupted HTML carriers are handled by the shared lexical context guard.
                    $run->count('error_response', 'candidatesExcluded');
                    continue;
                }
                try { $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException $error) {
                    if ($error->getCode() === JSON_ERROR_DEPTH) $run->limited('error_response', 'structured', $representation, 'context_budget', ['jsonDepthLimit' => 32]);
                    else $run->failed('error_response', 'structured', $representation, 'parser_error');
                    continue;
                }
                if (!is_array($data)) continue;
                $paths = [];
                if (($data['code'] ?? null) === 'internal_server_error' && ($data['data']['status'] ?? null) === 500) {
                    $error = $data['data']['error'] ?? null;
                    if (is_array($error) && is_int($error['type'] ?? null) && in_array($error['type'], [1, 2, 4, 8, 64], true) && is_string($error['file'] ?? null) && $error['file'] !== '' && is_int($error['line'] ?? null) && $error['line'] > 0) {
                        $paths[] = [['data', 'error', 'message'], 'php', ['errorType' => $error['type']]];
                    }
                }
                if (isset($data['errors']) && is_array($data['errors']) && array_is_list($data['errors'])) {
                    $queue = [];
                    foreach (array_slice($data['errors'], 0, self::MAX_CANDIDATES) as $i => $error) $queue[] = [$error, ['errors', $i], 0];
                    $visited = 0;
                    while ($queue !== []) {
                        [$error, $path, $depth] = array_shift($queue);
                        if (++$visited > self::MAX_CANDIDATES) { $run->limited('error_response', 'structured', $representation, 'context_budget', ['errorObjectsRetained' => self::MAX_CANDIDATES]); break; }
                        if (!is_array($error) || !in_array($error['status'] ?? null, [500, '500'], true) || ($error['title'] ?? null) !== 'Internal Server Error') continue;
                        $paths[] = [[...$path, 'detail'], 'php', []];
                        $previous = $error['meta']['previous'] ?? null;
                        if (!is_array($previous) || !array_is_list($previous) || $previous === []) continue;
                        if ($depth >= 4) { $run->limited('error_response', 'structured', $representation, 'context_budget', ['previousDepthLimit' => 4]); continue; }
                        foreach (array_slice($previous, 0, self::MAX_CANDIDATES) as $i => $child) $queue[] = [$child, [...$path, 'meta', 'previous', $i], $depth + 1];
                        if (count($previous) > self::MAX_CANDIDATES) $run->limited('error_response', 'structured', $representation, 'context_budget', ['errorObjectsRetained' => self::MAX_CANDIDATES]);
                    }
                    if (count($data['errors']) > self::MAX_CANDIDATES) $run->limited('error_response', 'structured', $representation, 'context_budget', ['errorObjectsRetained' => self::MAX_CANDIDATES]);
                }
                foreach ($paths as [$path, $family, $metadata]) {
                    $field = JsonDiagnosticSpans::field($json, $path);
                    if ($field === null || !is_string($field['value'])) continue;
                    $kind = self::phpKind($field['value']);
                    if ($kind !== null) $emit([$field['match'][0], $start + $field['match'][1]], ['family' => $family, 'diagnosticKind' => $kind, 'contextKind' => 'structured_error', 'fieldPath' => implode('.', $path), ...$metadata]);
                }
                if (trim(substr($raw, 0, $start)) !== '' || trim(substr($raw, $offset)) !== '') continue;
                $specific = [];
                if (($data['error']['status_code'] ?? null) === 500) $specific[] = [['error', 'details', 0], 'postgresql', 'invalid_offset', '~^(?:ActiveRecord::StatementInvalid:\r?\n)?PG::InvalidRowCountInResultOffsetClause: ERROR:\s+OFFSET must not be negative\s*$~'];
                if (($data['status'] ?? null) === 500 && ($data['name'] ?? null) === 'TypeError') $specific[] = [['message'], 'javascript', 'null_property', '~^Cannot read properties of (?:null|undefined) \(reading [\x27"][^\r\n]{1,100}[\x27"]\)$~'];
                $specific[] = [['serverResponse'], 'java', 'enum_validation', '~^IllegalArgumentException: No enum constant (?:[\w$]+\.){2,20}[^\r\n]{1,300}$~'];
                if (is_string($data['traceback'] ?? null) && str_contains($data['traceback'], "\nstack traceback:\n") && CheckedRegex::match('~(?:^|\n)\s*(?:\[C\]|[^\r\n]{1,180}:\d{1,8}): in function [\x27"][^\r\n]{1,100}[\x27"]~', $data['traceback']) === 1) $specific[] = [['error'], 'lua', 'argument_type', '~^[^\r\n]{1,180}:\d{1,8}: bad argument #\d{1,3} to [\x27"][^\r\n]{1,80}[\x27"] \([\w ]{1,80} expected, got [\w ]{1,80}\)$~'];
                foreach ($specific as [$path, $family, $kind, $pattern]) {
                    $field = JsonDiagnosticSpans::field($json, $path);
                    if ($field !== null && is_string($field['value']) && CheckedRegex::match($pattern, $field['value']) === 1) $emit([$field['match'][0], $start + $field['match'][1]], ['family' => $family, 'diagnosticKind' => $kind, 'contextKind' => 'structured_error', ...($family === 'postgresql' ? ['fieldPath' => 'error.details.0'] : [])]);
                }
            }
        });
        $run->stage('error_response', 'nuxt_error', $representation, function () use ($raw, $run, $representation, $eligible, $emit): void {
            $offset = 0;
            $count = 0;
            while (CheckedRegex::match('~<script\b~i', $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $offset = $m[0][1] + strlen($m[0][0]);
                if (!$eligible($m[0][1])) continue;
                $opening = self::scriptOpening($raw, $m[0][1]);
                if ($opening === null) {
                    if (str_contains(substr($raw, $m[0][1], 1000), '__NUXT_DATA__')) $run->limited('error_response', 'nuxt_error', $representation, 'context_budget', ['openingByteLimit' => 1000]);
                    continue;
                }
                $offset = $m[0][1] + strlen($opening);
                if (!str_contains($opening, '__NUXT_DATA__')) continue;
                $run->candidate($representation, mb_strlen(substr($raw, 0, $m[0][1]), 'UTF-8'), mb_strlen($m[0][0], 'UTF-8'));
                if (++$count > self::MAX_CANDIDATES) { $run->limited('error_response', 'nuxt_error', $representation, 'context_budget'); break; }
                $attrs = self::scriptAttributes($opening);
                if ($attrs === null) continue;
                if (($attrs['id'] ?? null) !== '__NUXT_DATA__' || ($attrs['type'] ?? null) !== 'application/json' || ($attrs['data-ssr'] ?? null) !== 'true') continue;
                if (CheckedRegex::match('~</script\s*>~i', $raw, $closing, PREG_OFFSET_CAPTURE, $offset) !== 1) { $run->failed('error_response', 'nuxt_error', $representation, 'parser_error'); continue; }
                $end = $closing[0][1];
                if ($end - $offset > self::MAX_OBJECT_BYTES) { $run->limited('error_response', 'nuxt_error', $representation, 'context_budget', ['objectByteLimit' => self::MAX_OBJECT_BYTES]); continue; }
                $json = substr($raw, $offset, $end - $offset);
                try { $table = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException $error) {
                    if ($error->getCode() === JSON_ERROR_DEPTH) $run->limited('error_response', 'nuxt_error', $representation, 'context_budget', ['jsonDepthLimit' => 32]);
                    else $run->failed('error_response', 'nuxt_error', $representation, 'parser_error');
                    continue;
                }
                if (!is_array($table) || !array_is_list($table)) continue;
                if (count($table) > 1024) { $run->limited('error_response', 'nuxt_error', $representation, 'context_budget', ['tableSlotLimit' => 1024]); continue; }
                $field = self::nuxtErrorMessage($json, $table);
                if ($field === null) continue;
                $family = $kind = null;
                if (CheckedRegex::match('~^Variable "\$[\w]{1,100}" got invalid value "[^\r\n]{1,500}"; Value "[^\r\n]{1,500}" does not exist in "[\w]{1,100}" enum\.$~', $field['value']) === 1) { $family = 'graphql'; $kind = 'enum_validation'; }
                elseif (CheckedRegex::match('~^Invalid character in header content \["[\w-]{1,80}"\]$~', $field['value']) === 1) { $family = 'javascript'; $kind = 'header_serialization'; }
                if ($kind !== null) $emit([$field['match'][0], $offset + $field['match'][1]], ['family' => $family, 'diagnosticKind' => $kind, 'contextKind' => 'structured_error', 'fieldPath' => 'nuxt.error.message', 'statusCode' => 500]);
            }
        });
        $run->stage('error_response', 'malformed_wp_tail', $representation, function () use ($raw, $run, $representation, $eligible, $emit): void {
            // The sole supported malformed carrier: a terminal WordPress response
            // whose translated outer HTML message has unescaped attribute quotes.
            if (!str_starts_with(ltrim($raw), '<')) return;
            if (CheckedRegex::match('~\{"code":"internal_server_error","message":"~', $raw, $root, PREG_OFFSET_CAPTURE) !== 1) return;
            $rootStart = $root[0][1];
            $tail = substr($raw, $rootStart);
            if (strlen($tail) > self::MAX_OBJECT_BYTES) return;
            try { json_decode($tail, true, 32, JSON_THROW_ON_ERROR); return; } catch (\JsonException) {}
            $allowed = $eligible($rootStart);
            if ($allowed && !self::htmlRootBoundary($raw, $rootStart)) return;
            if (!$allowed && !self::runtimeTailEligible($raw, $rootStart, static function () use ($run, $representation): void { $run->limited('error_response', 'malformed_wp_tail', $representation, 'context_budget'); })) return;
            if (substr_count($tail, ',"data":{"status":500,"error":') !== 1) return;
            $boundary = strpos($tail, ',"data":{"status":500,"error":');
            $start = $rootStart + $boundary + strlen(',"data":{"status":500,"error":');
            $json = self::objectAt($raw, $start);
            if ($json === null || trim(substr($raw, $start + strlen($json))) !== '},"additional_errors":[]}') return;
            $error = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($error) || !is_int($error['type'] ?? null) || !in_array($error['type'], [1, 2, 4, 8, 64], true) || !is_string($error['file'] ?? null) || $error['file'] === '' || !is_int($error['line'] ?? null) || $error['line'] < 1) return;
            $field = JsonDiagnosticSpans::field($json, ['message']);
            if ($field === null || !is_string($field['value']) || ($kind = self::phpKind($field['value'])) === null) return;
            $run->failed('error_response', 'malformed_wp_tail', $representation, 'parser_error');
            $emit([$field['match'][0], $start + $field['match'][1]], ['family' => 'php', 'diagnosticKind' => $kind, 'contextKind' => 'structured_error', 'fieldPath' => 'data.error.message', 'errorType' => $error['type'], 'envelopeStatus' => 'malformed']);
        });
        $run->stage('error_response', 'php_rendered_diagnostics', $representation, function () use ($raw, $run, $representation, $emit, $eligible): void {
            $pattern = '~(?:<b>\s*)?(?:Notice|Warning)(?:\s*</b>)?\s*:\s*(?:Undefined (?:index|array key|variable)|Trying to access array offset)[^\r\n]{0,500}?\bin\s+(?:<b>\s*)?(?:[A-Za-z]:[\\/]|/(?:var|container|home|homepages|customers|srv|opt|usr|app|www|mnt|data)/)[^<>\r\n]{1,300}?(?:\s*</b>)?\s+on\s+line\s+(?:<b>\s*)?[1-9]\d{0,7}(?:\s*</b>)?~i';
            $offset = $count = 0;
            while (CheckedRegex::match($pattern, $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $offset = $m[0][1] + max(1, strlen($m[0][0]));
                $run->candidate($representation, mb_strlen(substr($raw, 0, $m[0][1]), 'UTF-8'), mb_strlen($m[0][0], 'UTF-8'));
                $run->count('error_response', 'candidatesConsidered');
                if (++$count > self::MAX_CANDIDATES) { $run->limited('error_response', 'php_rendered_diagnostics', $representation, 'context_budget'); break; }
                if (!$eligible($m[0][1]) || self::inDiagnosticExample($raw, $m[0][1])) { $run->count('error_response', 'candidatesExcluded'); continue; }
                $kind = str_contains(strtolower($m[0][0]), 'undefined variable') ? 'undefined_variable' : (str_contains(strtolower($m[0][0]), 'trying to access array offset') ? 'null_offset' : 'undefined_index');
                $emit($m[0], ['family' => 'php', 'diagnosticKind' => $kind, 'contextKind' => 'runtime_diagnostic']);
            }
        });
        $run->stage('error_response', 'backend_diagnostics', $representation, function () use ($raw, $run, $representation, $emit, $eligible): void {
            $offset = 0;
            $count = 0;
            // Region starts are structural error carriers, never arbitrary words across the page.
            $region = '~<(pre|blockquote|h1|p|font|div)\b([^>]{0,500})>([\s\S]{0,4096}?)</\1\s*>~i';
            while (CheckedRegex::match($region, $raw, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $offset = $m[0][1] + strlen($m[0][0]);
                if (CheckedRegex::match('~(?:error-message|exception-message|ExceptionSubject|stacktrace|Exception-Header|jsn-error|label-inverse|InvalidQueryException|IllegalArgumentException|Illegal characters in node|titleerror|Fatal Error|User Error)~i', $m[0][0]) !== 1) continue;
                $run->candidate($representation, mb_strlen(substr($raw, 0, $m[0][1]), 'UTF-8'), mb_strlen($m[0][0], 'UTF-8'));
                $run->count('error_response', 'candidatesConsidered');
                if (++$count > self::MAX_CANDIDATES) { $run->limited('error_response', 'backend_diagnostics', $representation, 'context_budget'); break; }
                if (!$eligible($m[0][1])) continue;
                $content = $m[3][0];
                if (!$eligible($m[3][1])) continue;
                $tag = strtolower($m[1][0]);
                $attrs = $m[2][0];
                $context = $m[0][0];
                // A concrete diagnosis in an explicit error carrier. h1/blockquote require
                // the delivered error-page context in the same small neighborhood.
                $near = substr($raw, max(0, $m[0][1] - 400), min(700, $m[0][1] + strlen($m[0][0])));
                $errorCarrier = CheckedRegex::match('~(?:error-message|exception-message|ExceptionSubject|stacktrace|Exception-Header|info error|jsn-error|error-page|500 Internal Server Error|label-inverse)~i', $attrs.' '.$near) === 1;
                CheckedRegex::match('~^(?:\s|</?(?:span|b|strong|i)\b[^>]{0,200}>){0,30}~i', $content, $leading);
                if ($errorCarrier && $eligible($m[3][1] + strlen($leading[0])) && CheckedRegex::match('~<(?:code|script|template|textarea|style)\b~i', $content) !== 1 && ($kind = self::phpKind(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) !== null) {
                    $emit([$content, $m[3][1]], ['family' => 'php', 'diagnosticKind' => $kind, 'contextKind' => 'backend_diagnostic']);
                }
                foreach (self::backendPatterns() as [$family, $kind, $pattern, $guard]) {
                    if ($guard !== null && CheckedRegex::match($guard, $context) !== 1) continue;
                    if (CheckedRegex::match($pattern, $content, $diagnostic, PREG_OFFSET_CAPTURE) === 1 && $eligible($m[3][1] + $diagnostic[0][1])) $emit([$diagnostic[0][0], $m[3][1] + $diagnostic[0][1]], ['family' => $family, 'diagnosticKind' => $kind, 'contextKind' => 'backend_diagnostic']);
                }
                if ($tag === 'div' && str_contains($attrs, 'titleerror') && CheckedRegex::match('~CloudException: Invalid expression: Syntax error:[\s\S]{1,1500}?Parameter name: \$filter~', $content, $diag, PREG_OFFSET_CAPTURE) === 1) {
                    $after = substr($raw, $offset, 700);
                    if (CheckedRegex::match('~^\s*<p\b[^>]{0,100}class=[\x27"]location[\x27"][^>]{0,100}>Microsoft\.Azure\.Search\.[^<]{1,500}</p>~', $after) === 1) $emit([$diag[0][0], $m[3][1] + $diag[0][1]], ['family' => 'odata', 'diagnosticKind' => 'filter_parse', 'contextKind' => 'backend_diagnostic']);
                }
            }
            // Self-contained plaintext diagnostics and explicit fatal/error prefixes.
            $plain = trim($raw);
            if (strlen($plain) <= 4096 && !str_starts_with($plain, '<')) {
                foreach (self::plainPatterns() as [$family, $kind, $pattern]) {
                    if (CheckedRegex::match($pattern, $plain, $m, PREG_OFFSET_CAPTURE) === 1 && $eligible(strpos($raw, $plain))) $emit([$m[0][0], strpos($raw, $plain) + $m[0][1]], ['family' => $family, 'diagnosticKind' => $kind, 'contextKind' => 'backend_diagnostic']);
                }
                if (str_starts_with($plain, 'Error: ') && ($kind = self::phpKind($plain)) !== null && $kind !== 'object_context' && $eligible(strpos($raw, $plain))) $emit([$plain, strpos($raw, $plain)], ['family' => 'php', 'diagnosticKind' => $kind, 'contextKind' => 'backend_diagnostic']);
            }
            foreach ([
                ['application', 'file_read', '~\bFatal Error Can\x27t read file: [^<>\r\n]{1,500}~'],
                ['php', 'object_context', '~\bError: Using \$this when not in object context[^<>\r\n]{0,500}~'],
                ['notes', 'array_index', '~HTTP Web Server: HCL Notes Exception - Array index out of bounds~'],
                ['notes', 'unid_validation', '~HTTP Web Server: HCL Notes Exception - Invalid UNID; UNID must be a 32-digit hex string\.~'],
                ['php', 'signature_compatibility', '~<b>Fatal error</b>:\s*Declaration of [^\r\n]{1,350} must be compatible with [^\r\n]{1,350}? on line <b>\d{1,8}</b>~'],
            ] as [$family, $kind, $pattern]) {
                $candidateOffset = 0;
                $considered = 0;
                while (CheckedRegex::match($pattern, $raw, $m, PREG_OFFSET_CAPTURE, $candidateOffset) === 1) {
                    $candidateOffset = $m[0][1] + strlen($m[0][0]);
                    if (++$considered > self::MAX_CANDIDATES) { $run->limited('error_response', 'backend_diagnostics', $representation, 'context_budget'); break; }
                    if ($eligible($m[0][1])) $emit($m[0], ['family' => $family, 'diagnosticKind' => $kind, 'contextKind' => 'backend_diagnostic']);
                }
            }
            if (CheckedRegex::match('~<pre\b[^>]{0,200}class=[\x27"]stacktrace[\x27"][^>]{0,200}>\s*Joomla\\\\CMS\\\\WebAsset\\\\Exception\\\\UnknownAssetException:\s*(There is no [^<>\r\n]{1,300} asset of a [^<>\r\n]{1,150} type in the registry\.)~', $raw, $m, PREG_OFFSET_CAPTURE) === 1 && $eligible($m[1][1])) $emit($m[1], ['family' => 'joomla', 'diagnosticKind' => 'asset_registry', 'contextKind' => 'backend_diagnostic']);
            $commentOffset = 0;
            while (($start = strpos($raw, '<!--', $commentOffset)) !== false) {
                $end = strpos($raw, '-->', $start + 4);
                if ($end === false) break;
                $commentOffset = $end + 3;
                if ($end - $start > 8192) continue;
                $comment = substr($raw, $start, $end + 3 - $start);
                if (CheckedRegex::match('~([\w$.]{1,120}) (?:request\(\)|pageContext\.getRequest\(\))\.getParameterMap\(\) = [^\r\n]{1,200}\r?\n(?:\1 versionS = null\r?\n)?\1 e = (java\.lang\.NumberFormatException: null)\r?\n\1 version = \d{1,8}~', $comment, $m, PREG_OFFSET_CAPTURE) === 1 && $eligible($start + $m[2][1], true)) $emit([$m[2][0], $start + $m[2][1]], ['family' => 'java', 'diagnosticKind' => 'numeric_parse_debug', 'contextKind' => 'backend_diagnostic']);
            }
        });
        return $run->finish();
    }

    private static function inDiagnosticExample(string $raw, int $offset): bool
    {
        $trimmed = trim($raw);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[') && json_validate($trimmed)) return true;
        $prefix = substr($raw, max(0, $offset - 600), min(600, $offset));
        $paragraph = strripos($prefix, '<p');
        if ($paragraph !== false && (($closing = strripos($prefix, '</p>')) === false || $closing < $paragraph)) {
            $opening = strpos($prefix, '>', $paragraph);
            if ($opening !== false && CheckedRegex::match('~^\s*(?:<[^>]{1,80}>\s*){0,3}(?:Example|Sample|For example)\s*:~i', substr($prefix, $opening + 1)) === 1) return true;
        }
        return CheckedRegex::match('~(?:^|[\r\n])\s*(?:Example|Sample|For example)\s*:[^\r\n]{0,500}$~i', $prefix) === 1;
    }

    private static function plainPatterns(): array
    {
        return [
            ['urlpattern', 'syntax_error', '~^Failed to parse URLPattern from "[^\r\n]{1,1500}": parser error: expected token \w{1,80}, found [\x27"][^\r\n]{1,80}[\x27"] of type \w{1,80}$~'],
            ['datetime', 'parse_error', '~^Failed to parse time string \([^\r\n]{1,500}\) at position \d{1,8} \([^\r\n]{1,30}\): [^\r\n]{1,300}$~'],
            ['dotnet', 'idx_parameter', '~^InternalServerError: IDX10000: The parameter \x27([\w]{1,80})\x27 cannot be a \x27null\x27 or an empty object\.\r?\nParameter name: \1$~'],
        ];
    }

    /** Small quoted-aware lexer; attribute-looking bytes inside values remain data. */
    private static function scriptOpening(string $raw, int $start): ?string
    {
        $quote = null;
        for ($cursor = $start + 7, $end = min(strlen($raw), $start + 1000); $cursor < $end; ++$cursor) {
            $byte = $raw[$cursor];
            if ($quote !== null) { if ($byte === $quote) $quote = null; }
            elseif ($byte === '"' || $byte === "'") $quote = $byte;
            elseif ($byte === '>') return substr($raw, $start, $cursor + 1 - $start);
        }
        return null;
    }

    /** Small quoted-aware lexer; attribute-looking bytes inside values remain data. */
    private static function scriptAttributes(string $opening): ?array
    {
        $cursor = 7; // <script
        $size = strlen($opening);
        $attributes = [];
        while ($cursor < $size) {
            $cursor += strspn($opening, " \t\r\n\f", $cursor);
            if (($opening[$cursor] ?? '') === '>') return $cursor === $size - 1 ? $attributes : null;
            if (CheckedRegex::match('~\G([^\s\x27"<>/=]+)~', $opening, $name, 0, $cursor) !== 1) return null;
            $cursor += strlen($name[0]);
            $key = strtolower($name[1]);
            if (array_key_exists($key, $attributes)) return null;
            $cursor += strspn($opening, " \t\r\n\f", $cursor);
            $value = true;
            if (($opening[$cursor] ?? '') === '=') {
                ++$cursor;
                $cursor += strspn($opening, " \t\r\n\f", $cursor);
                $quote = $opening[$cursor] ?? '';
                if ($quote === '"' || $quote === "'") {
                    $end = strpos($opening, $quote, ++$cursor);
                    if ($end === false) return null;
                    $value = substr($opening, $cursor, $end - $cursor);
                    $cursor = $end + 1;
                } else {
                    if (CheckedRegex::match('~\G[^\s\x27"=<>`]+~', $opening, $unquoted, 0, $cursor) !== 1) return null;
                    $value = $unquoted[0];
                    $cursor += strlen($value);
                }
            }
            $attributes[$key] = $value;
        }
        return null;
    }

    /** Resolve only Nuxt's known root.error chain, never arbitrary table references. */
    private static function nuxtErrorMessage(string $json, array $table): ?array
    {
        $wrapper = $table[0] ?? null;
        if (!is_array($wrapper) || !array_is_list($wrapper) || count($wrapper) !== 2 || !in_array($wrapper[0], ['Reactive', 'ShallowReactive'], true) || !is_int($wrapper[1]) || $wrapper[1] < 1) return null;
        $root = $wrapper[1];
        $reference = JsonDiagnosticSpans::field($json, [$root, 'error']);
        if ($reference === null || !is_int($reference['value']) || $reference['value'] < 1 || $reference['value'] === $root || !array_key_exists($reference['value'], $table)) return null;
        $index = $reference['value'];
        $error = $table[$index];
        if (!is_array($error)) return null;
        if (array_is_list($error)) {
            if (count($error) < 3 || count($error) % 2 !== 1 || $error[0] !== 'null') return null;
            $map = [];
            for ($i = 1; $i < count($error); $i += 2) {
                if (!is_string($error[$i]) || array_key_exists($error[$i], $map)) return null;
                $map[$error[$i]] = $error[$i + 1];
            }
        } else {
            $message = JsonDiagnosticSpans::field($json, [$index, 'message']);
            $status = JsonDiagnosticSpans::field($json, [$index, 'statusCode']);
            if ($message === null || $status === null) return null;
            $map = ['message' => $message['value'], 'statusCode' => $status['value']];
        }
        foreach (['message', 'statusCode'] as $key) if (!isset($map[$key]) || !is_int($map[$key]) || $map[$key] < 0 || !array_key_exists($map[$key], $table)) return null;
        if ($table[$map['statusCode']] !== 500 || !is_string($table[$map['message']])) return null;
        return JsonDiagnosticSpans::field($json, [$map['message']]);
    }

    private static function backendPatterns(): array
    {
        return [
            ['jcr', 'query_parse', '~javax\.jcr\.query\.InvalidQueryException: Encountered [\s\S]{1,300}? at line \d{1,8}, column \d{1,8}\.(?:\s|<br\s*/?>){1,30}Was expecting:(?:\s|<br\s*/?>){1,30}[^\r\n<]{1,200}~', null],
            ['java', 'locale_validation', '~java\.lang\.IllegalArgumentException: Locale part [^<>\r\n]{1,400} contains invalid characters~', '~(?:Message|Exception|stacktrace)~'],
            ['perl', 'validation', '~Illegal characters in node \([^\r\n]{1,400}\) at [\w./-]{1,180}\.pm line \d{1,8}\.~', null],
            ['joomla', 'asset_registry', '~There is no [^<>\r\n]{1,300} asset of a [^<>\r\n]{1,150} type in the registry\.~', '~Joomla\\\\CMS\\\\WebAsset\\\\Exception\\\\UnknownAssetException:~'],
            ['neos', 'node_path_validation', '~Only absolute paths are allowed for Context::getNode\(\)~', '~(?:Flow-Debug-Exception-Header|ExceptionSubject)[\s\S]{1,500}Could not convert target type~'],
            ['php', 'locale_validation', '~Uncaught InvalidArgumentException: Invalid locale [^<>\r\n]{1,400}~', '~(?:info error|User Error)~'],
        ];
    }

    /** Override an interrupted raw carrier only for a complete terminal WP response object. */
    private static function htmlRootBoundary(string $raw, int $start): bool
    {
        $prefix = substr($raw, 0, $start);
        $tagEnd = strrpos($prefix, '>');
        // The interstitial rendered text may describe the interrupted widget;
        // it cannot already have opened another JSON/source expression.
        return $tagEnd !== false && CheckedRegex::match('~[{}\[\]=;]~', substr($prefix, $tagEnd + 1)) !== 1;
    }

    /** Override an interrupted raw carrier only for a complete terminal WP response object. */
    private static function runtimeTailEligible(string $raw, int $start, callable $onLimit): bool
    {
        $prefix = substr($raw, 0, $start);
        $comment = strrpos($prefix, '<!--');
        $commentEnd = strrpos($prefix, '-->');
        if ($comment !== false && ($commentEnd === false || $comment > $commentEnd)) return false;
        // Remove only rawtext delimiters / quoted attribute carrier state for the guard;
        // preserve surrounding real code/template/documentation ancestors verbatim.
        $lastOpen = strrpos($prefix, '<');
        $lastClose = strrpos($prefix, '>');
        if ($lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose)) {
            $unfinished = substr($prefix, $lastOpen);
            if (CheckedRegex::match('~^<(?:img|a|link|input)\b[^>]{0,500}\b(?:src|href|value)\s*=\s*[\x27"][^\x27"]{0,1000}$~i', $unfinished) !== 1) return false;
            $prefix = substr($prefix, 0, $lastOpen);
        }
        $script = strripos($prefix, '<script');
        $scriptEnd = strripos($prefix, '</script');
        if ($script !== false && ($scriptEnd === false || $script > $scriptEnd)) {
            $content = strpos($prefix, '>', $script);
            if ($content === false) return false;
            $javascript = substr($prefix, $content + 1);
            // An ordinary complete variable assignment is a dataset, not an aborted
            // response. Only an already interrupted list/call carrier is admitted.
            if (CheckedRegex::match('~(?:catalog|example|documentation|tutorial)~i', $javascript) === 1 || CheckedRegex::match('~[\[(]\s*$~', $javascript) !== 1) return false;
            $prefix = substr($prefix, 0, $script);
        }
        if (strrpos($prefix, '<!--') > (strrpos($prefix, '-->') === false ? -1 : strrpos($prefix, '-->'))) return false;
        return HtmlDiagnosticContext::eligible($prefix, strlen($prefix), false, $onLimit);
    }

    /** Complete object only; bounded scanning respects JSON quoting and never repairs bytes. */
    private static function objectAt(string $raw, int $start): ?string
    {
        $quoted = false;
        $depth = 0;
        for ($i = $start, $end = min(strlen($raw), $start + self::MAX_OBJECT_BYTES); $i < $end; ++$i) {
            $byte = $raw[$i];
            if ($quoted) {
                if ($byte === '\\') ++$i;
                elseif ($byte === '"') $quoted = false;
            } elseif ($byte === '"') $quoted = true;
            elseif ($byte === '{' || $byte === '[') ++$depth;
            elseif ($byte === '}' || $byte === ']') {
                if (--$depth === 0) return substr($raw, $start, $i + 1 - $start);
            }
        }
        return null;
    }

    /** Called only for a qualified error field or a coherent backend error carrier. */
    private static function phpKind(string $message): ?string
    {
        $message = trim($message);
        if (CheckedRegex::match('~^(?:0\s+)?(?:(?:Uncaught\s+)?(?:[\\w\\\\]+(?:Exception|Error)|Error):\s*)?~', $message, $prefix) === 1 && $prefix[0] !== '') $message = substr($message, strlen($prefix[0]));
        $patterns = [
            'undefined_function' => '~^Call to undefined function [A-Za-z_\\\\][\w\\\\]{0,180}\(\)~',
            'undefined_method' => '~^Call to undefined method [A-Za-z_\\\\][\w\\\\]{0,180}::[\w]{1,100}\(\)~',
            'offset_type' => '~^(?:Cannot access offset of type [\w]{1,40} on [\w]{1,40}|Cannot use (?:string|object|array) offset as an array)\b~',
            'object_context' => '~^Using \$this when not in object context\b~',
            'argument_type' => '~^[\w\\\\:]{1,240}\(\): Argument #\d{1,3} \(\$[\w]{1,80}\) must be of type [\w\\\\?|]{1,120}, [\w\\\\]{1,120} given\b~',
            'object_to_string' => '~^Object of class [\w\\\\]{1,180} could not be converted to string\b~',
            'undefined_constant' => '~^Undefined constant ["\x27][\w\\\\]{1,180}["\x27]~',
            'operand_type' => '~^Unsupported operand types: [\w\\\\]{1,80} [+*/%&|^.-] [\w\\\\]{1,80}\b~',
            'argument_count' => '~^(?:\d{1,3} arguments are required, \d{1,3} given|Too few arguments to function [^\r\n]{1,180}, \d{1,3} passed[^\r\n]{0,300} and (?:exactly|at least) \d{1,3} expected)\b~',
            'class_not_found' => '~^Class ["\x27][\w\\\\]{1,180}["\x27] not found\b~',
            'syntax_error' => '~^syntax error, unexpected (?:identifier|token|end of file|[A-Z_]{1,40})\b~',
            'redeclare' => '~^Cannot redeclare [\w\\\\]{1,180}\(\) \(previously declared in [^\r\n]{1,500}:\d{1,8}\)~',
            'signature_compatibility' => '~^Declaration of [^\r\n]{1,350} must be compatible with [^\r\n]{1,350}~',
            'curly_offset_compile' => '~^Array and string offset access syntax with curly braces is no longer supported\b~',
            'ternary_compile' => '~^Unparenthesized `a \? b : c \? d : e` is not supported\b~',
        ];
        foreach ($patterns as $kind => $pattern) if (CheckedRegex::match($pattern, $message) === 1) return $kind;
        return null;
    }
}
