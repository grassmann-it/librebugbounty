<?php

namespace App\Cribrum\Detector;

/** PHP-compatible regex calls with explicit errors, including negative guards. */
final class CheckedRegex
{
    public static function match(string $pattern, string $subject, mixed &$matches = null, int $flags = 0, int $offset = 0): int
    {
        $result = @preg_match($pattern, $subject, $matches, $flags, $offset);
        if ($result === false) throw new RegexFailure('match', preg_last_error());
        return $result;
    }

    public static function matchAll(string $pattern, string $subject, mixed &$matches = null, int $flags = 0, int $offset = 0): int
    {
        $result = @preg_match_all($pattern, $subject, $matches, $flags, $offset);
        if ($result === false) throw new RegexFailure('match_all', preg_last_error());
        return $result;
    }

    public static function split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array
    {
        $result = @preg_split($pattern, $subject, $limit, $flags);
        if ($result === false) throw new RegexFailure('split', preg_last_error());
        return $result;
    }

    public static function replace(string|array $pattern, string|array $replacement, string|array $subject, int $limit = -1, mixed &$count = null): string|array
    {
        // PHP silently omits a failed element of an array subject, and a later
        // element can reset preg_last_error(). Check each element immediately.
        if (is_array($subject)) {
            $result = []; $count = 0;
            foreach ($subject as $key => $value) {
                $result[$key] = self::replace($pattern, $replacement, $value, $limit, $partCount);
                $count += $partCount;
            }
            return $result;
        }
        $result = @preg_replace($pattern, $replacement, $subject, $limit, $count);
        $error = preg_last_error();
        if ($result === null || $error !== PREG_NO_ERROR) throw new RegexFailure('replace', $error);
        return $result;
    }

    public static function replaceCallback(string|array $pattern, callable $callback, string|array $subject, int $limit = -1, mixed &$count = null, int $flags = 0): string|array
    {
        if (is_array($subject)) {
            $result = []; $count = 0;
            foreach ($subject as $key => $value) {
                $result[$key] = self::replaceCallback($pattern, $callback, $value, $limit, $partCount, $flags);
                $count += $partCount;
            }
            return $result;
        }
        $result = @preg_replace_callback($pattern, $callback, $subject, $limit, $count, $flags);
        $error = preg_last_error();
        if ($result === null || $error !== PREG_NO_ERROR) throw new RegexFailure('replace_callback', $error);
        return $result;
    }
}
