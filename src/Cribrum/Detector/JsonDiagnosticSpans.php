<?php

namespace App\Cribrum\Detector;

/** Exact lexical spans in one validated JSON object; no HTML/entity repair or recursive search. */
final class JsonDiagnosticSpans
{
    /** @return array{value: mixed, match: array{string,int}}|null */
    public static function field(string $json, array $path): ?array
    {
        try {
            json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $start = strspn($json, " \t\r\n");
        $end = strlen($json);
        foreach ($path as $key) {
            if (is_int($key)) {
                if (($json[$start] ?? '') !== '[' || $key < 0) return null;
                $cursor = $start + 1;
                $found = null;
                for ($index = 0; $cursor < $end; ++$index) {
                    $cursor += strspn($json, " \t\r\n", $cursor);
                    if (($json[$cursor] ?? '') === ']') break;
                    $valueEnd = self::valueEnd($json, $cursor);
                    if ($index === $key) { $found = [$cursor, $valueEnd]; break; }
                    $cursor = $valueEnd + strspn($json, " \t\r\n", $valueEnd);
                    if (($json[$cursor] ?? '') !== ',') break;
                    ++$cursor;
                }
                if ($found === null) return null;
                [$start, $end] = $found;
                continue;
            }
            if (!is_string($key)) return null;
            if (($json[$start] ?? '') !== '{') return null;
            $cursor = $start + 1;
            $found = null;
            $seen = [];
            while ($cursor < $end) {
                $cursor += strspn($json, " \t\r\n", $cursor);
                if (($json[$cursor] ?? '') === '}') break;
                if (($json[$cursor] ?? '') !== '"') return null;
                $keyEnd = self::valueEnd($json, $cursor);
                $name = json_decode(substr($json, $cursor, $keyEnd - $cursor), true, 32, JSON_THROW_ON_ERROR);
                // Duplicate members make this object's field provenance ambiguous.
                if (isset($seen[$name])) return null;
                $seen[$name] = true;
                $cursor = $keyEnd + strspn($json, " \t\r\n", $keyEnd);
                if (($json[$cursor] ?? '') !== ':') return null;
                ++$cursor;
                $cursor += strspn($json, " \t\r\n", $cursor);
                $valueEnd = self::valueEnd($json, $cursor);
                if ($name === $key) $found = [$cursor, $valueEnd];
                $cursor = $valueEnd + strspn($json, " \t\r\n", $valueEnd);
                if (($json[$cursor] ?? '') === '}') break;
                if (($json[$cursor] ?? '') !== ',') return null;
                ++$cursor;
            }
            if ($found === null) return null;
            [$start, $end] = $found;
        }
        $value = substr($json, $start, $end - $start);
        return ['value' => json_decode($value, true, 32, JSON_THROW_ON_ERROR), 'match' => [$value, $start]];
    }

    /** Find a lexical candidate end; callers must validate JSON before accepting any value. */
    public static function valueEnd(string $json, int $start): int
    {
        $depth = 0;
        $quoted = false;
        for ($i = $start, $size = strlen($json); $i < $size; ++$i) {
            $byte = $json[$i];
            if ($quoted) {
                if ($byte === '\\') ++$i;
                elseif ($byte === '"') {
                    $quoted = false;
                    if ($depth === 0) return $i + 1;
                }
                continue;
            }
            if ($byte === '"') $quoted = true;
            elseif ($byte === '{' || $byte === '[') ++$depth;
            elseif ($byte === '}' || $byte === ']') {
                if ($depth === 0) return $i;
                if (--$depth === 0) return $i + 1;
            } elseif ($depth === 0 && ($byte === ',' || ctype_space($byte))) return $i;
        }
        return strlen($json);
    }
}
