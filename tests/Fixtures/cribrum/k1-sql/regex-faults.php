<?php

/** Loaded exclusively in isolated PHPUnit processes; no production fault API. */
namespace App\Cribrum\Detector;

final class SqlEvaluationRegexFault
{
    public static string $operation = '';
    public static string $needle = '';
    public static int $occurrence = 1;
    public static int $seen = 0;
    public static int $injected = 0;

    public static function applies(string $operation, string $pattern): bool
    {
        if ($operation !== self::$operation || !str_contains($pattern, self::$needle)) return false;
        if (++self::$seen !== self::$occurrence) return false;
        ++self::$injected;
        @\preg_match('~[~', ''); // Real PCRE error code consumed immediately by CheckedRegex.
        return true;
    }
}

function preg_match(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int|false
{
    if (SqlEvaluationRegexFault::applies('match', $pattern)) return false;
    return \preg_match($pattern, $subject, $matches, $flags, $offset);
}

function preg_match_all(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int|false
{
    if (SqlEvaluationRegexFault::applies('match_all', $pattern)) return false;
    return \preg_match_all($pattern, $subject, $matches, $flags, $offset);
}

function preg_split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array|false
{
    if (SqlEvaluationRegexFault::applies('split', $pattern)) return false;
    return \preg_split($pattern, $subject, $limit, $flags);
}

function preg_replace(string|array $pattern, string|array $replacement, string|array $subject, int $limit = -1, ?int &$count = null): string|array|null
{
    if (is_string($pattern) && SqlEvaluationRegexFault::applies('replace', $pattern)) return null;
    return \preg_replace($pattern, $replacement, $subject, $limit, $count);
}
