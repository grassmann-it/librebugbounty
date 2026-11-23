<?php

declare(strict_types=1);

namespace App\Cribrum\Detector;

/** Decoded request values carried in the URL path, next to the query string. */
final class RequestPathValues
{
    public const MAX_SEGMENTS = 64;
    public const MAX_VALUE_CODEPOINTS = 512;

    /**
     * A payload is frequently placed in the path rather than the query string.
     * The complete decoded path and each of its segments are returned, because a
     * payload can itself contain path separators. Percent escapes are decoded
     * once, as RFC 3986 path semantics require; callers still apply their own
     * qualification rules to the returned values.
     *
     * @return list<string>
     */
    public static function fromUrl(string $url): array
    {
        $path = @parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || !mb_check_encoding($path, 'UTF-8')) return [];
        $decoded = rawurldecode($path);
        if (!mb_check_encoding($decoded, 'UTF-8')) return [];
        $trimmed = trim($decoded, '/');
        if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') > self::MAX_VALUE_CODEPOINTS) return [];
        $values = [$trimmed];
        $segments = explode('/', $trimmed);
        if (count($segments) <= self::MAX_SEGMENTS) {
            foreach ($segments as $segment) {
                $segment = trim($segment);
                if ($segment !== '' && mb_strlen($segment, 'UTF-8') <= self::MAX_VALUE_CODEPOINTS) $values[] = $segment;
            }
        }

        return array_values(array_unique($values));
    }
}
