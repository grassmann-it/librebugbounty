<?php

namespace App\Cribrum\Detector;

/** Lexical HTML carrier guard. Quoted tags, comments and raw-text contents remain data. */
final class HtmlDiagnosticContext
{
    public static function eligible(string $raw, int $target, bool $allowComment = false, ?callable $onLimit = null): bool
    {
        $stack = [];
        $offset = 0;
        $length = strlen($raw);
        while (($open = strpos($raw, '<', $offset)) !== false && $open <= $target) {
            if ($open === $target) {
                if (substr_compare($raw, '<!--', $open, 4) === 0 && !$allowComment) return false;
                return !in_array(true, array_column($stack, 1), true);
            }
            $offset = $open + 1;
            if (substr_compare($raw, '<!--', $open, 4) === 0) {
                $end = strpos($raw, '-->', $open + 4);
                if ($end === false || $target < $end + 3) return $allowComment && !in_array(true, array_column($stack, 1), true);
                $offset = $end + 3;
                continue;
            }
            if (CheckedRegex::match('~\G<(/?)([A-Za-z][\w:-]*)\b~', $raw, $tag, 0, $open) !== 1) continue;
            $quote = null;
            for ($end = $open + strlen($tag[0]); $end < $length; ++$end) {
                $byte = $raw[$end];
                if ($quote !== null) { if ($byte === $quote) $quote = null; }
                elseif ($byte === '"' || $byte === "'") $quote = $byte;
                elseif ($byte === '>') break;
            }
            if ($target <= $end) return false;
            if ($end === $length) return false;
            $offset = $end + 1;
            $name = strtolower($tag[2]);
            if ($tag[1] === '/') {
                for ($i = count($stack) - 1; $i >= 0; --$i) {
                    if ($stack[$i][0] === $name) { $stack = array_slice($stack, 0, $i); break; }
                }
                continue;
            }
            if (in_array($name, ['script', 'style', 'textarea', 'title'], true)) {
                if (CheckedRegex::match('~</'.$name.'\s*>~i', $raw, $closing, PREG_OFFSET_CAPTURE, $offset) !== 1 || $target < $closing[0][1] + strlen($closing[0][0])) return false;
                $offset = $closing[0][1] + strlen($closing[0][0]);
                continue;
            }
            $inert = in_array($name, ['code', 'template', 'noscript', 'example'], true);
            $attrs = substr($raw, $open + strlen($tag[0]), $end - $open - strlen($tag[0]));
            CheckedRegex::matchAll(<<<'REGEX'
~[\t\n\f\r ]+([^\s"'<>/=]+)(?:[\t\n\f\r ]*=[\t\n\f\r ]*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?~
REGEX, $attrs, $attributes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($attributes as $attribute) {
                if (!in_array(strtolower($attribute[1]), ['class', 'id'], true)) continue;
                $value = html_entity_decode($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (CheckedRegex::match('~(?:^|[\s_-])(?:example|examples|sample|tutorial|documentation)(?:$|[\s_-])~i', $value) === 1) $inert = true;
            }
            if (!in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true) && !str_ends_with(substr($raw, $open, $end + 1 - $open), '/>')) $stack[] = [$name, $inert];
            // Conservatively reject malformed excessive nesting, never erase an example ancestor.
            if (count($stack) > 128) { if ($onLimit !== null) $onLimit(); return false; }
        }
        return !in_array(true, array_column($stack, 1), true);
    }
}
