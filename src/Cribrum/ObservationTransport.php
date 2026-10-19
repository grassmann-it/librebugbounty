<?php

namespace App\Cribrum;

/** Strict boundary for the optional observation returned by the browser worker. */
final class ObservationTransport
{
    private const ALLOWED_HEADERS = [
        'cache-control', 'content-disposition', 'content-encoding', 'content-language', 'content-length',
        'content-security-policy', 'content-type', 'date', 'etag', 'expires', 'last-modified', 'location',
        'pragma', 'referrer-policy', 'server', 'transfer-encoding', 'vary', 'x-aspnet-version',
        'x-aspnetmvc-version', 'x-content-type-options', 'x-drupal-cache', 'x-drupal-dynamic-cache',
        'x-frame-options', 'x-generator', 'x-powered-by', 'x-runtime', 'x-ua-compatible', 'x-xss-protection',
    ];

    private const MAX_HEADERS = 24;

    private const REQUIRED_KEYS = [
        'schema', 'contentType', 'httpStatus', 'finalUrl', 'redirectChain', 'headers',
        'rawHtml', 'rawHtmlBytes', 'rawHtmlTruncated', 'domText', 'domTextBytes', 'domTextTruncated',
    ];

    public static function normalize(mixed $value): ?array
    {
        if (!is_array($value) || ($value['schema'] ?? null) !== 1) {
            return null;
        }
        $allowed = [...self::REQUIRED_KEYS, 'error'];
        if (array_diff(array_keys($value), $allowed) !== [] || array_diff(self::REQUIRED_KEYS, array_keys($value)) !== []) {
            return null;
        }
        $contentType = $value['contentType'];
        $finalUrl = $value['finalUrl'];
        $rawHtml = $value['rawHtml'];
        $domText = $value['domText'];
        $error = $value['error'] ?? null;
        if (!self::nullableString($contentType, 255) || !self::nullableString($finalUrl, 8192)
            || !self::nullableString($rawHtml, 1048576) || !self::nullableString($domText, 524288)
            || !self::nullableString($error, 500)) {
            return null;
        }
        $status = $value['httpStatus'];
        if ($status !== null && (!is_int($status) || $status < 100 || $status > 599)) {
            return null;
        }
        $rawBytes = $value['rawHtmlBytes'];
        $domBytes = $value['domTextBytes'];
        if (($rawBytes !== null && (!is_int($rawBytes) || $rawBytes < 0 || $rawBytes > 2097152))
            || ($domBytes !== null && (!is_int($domBytes) || $domBytes < 0 || $domBytes > 1048576))) {
            return null;
        }
        if (($value['rawHtmlTruncated'] ?? null) !== true && ($value['rawHtmlTruncated'] ?? null) !== false) return null;
        if (($value['domTextTruncated'] ?? null) !== true && ($value['domTextTruncated'] ?? null) !== false) return null;
        if (!self::sizeMatches($rawHtml, $rawBytes, $value['rawHtmlTruncated'])) return null;
        if (!self::sizeMatches($domText, $domBytes, $value['domTextTruncated'])) return null;

        $redirects = $value['redirectChain'];
        if (!is_array($redirects) || !array_is_list($redirects) || count($redirects) > 32) return null;
        $normalizedRedirects = [];
        foreach ($redirects as $redirect) {
            if (!is_string($redirect) || $redirect === '' || strlen($redirect) > 8192) return null;
            $normalizedRedirects[] = $redirect;
        }
        $headers = $value['headers'];
        if (!is_array($headers) || count($headers) > self::MAX_HEADERS) return null;
        $normalizedHeaders = [];
        foreach ($headers as $name => $headerValue) {
            if (!is_string($name) || !in_array($name, self::ALLOWED_HEADERS, true)
                || !is_string($headerValue) || strlen($headerValue) > 1024) {
                return null;
            }
            $normalizedHeaders[$name] = $headerValue;
        }

        $normalized = [
            'schema' => 1,
            'contentType' => $contentType,
            'httpStatus' => $status,
            'finalUrl' => $finalUrl,
            'redirectChain' => $normalizedRedirects,
            'headers' => $normalizedHeaders,
            'rawHtml' => $rawHtml,
            'rawHtmlBytes' => $rawBytes,
            'rawHtmlTruncated' => $value['rawHtmlTruncated'],
            'domText' => $domText,
            'domTextBytes' => $domBytes,
            'domTextTruncated' => $value['domTextTruncated'],
        ];
        if ($error !== null) $normalized['error'] = $error;

        return $normalized;
    }

    private static function sizeMatches(?string $text, ?int $reportedBytes, bool $truncated): bool
    {
        if ($text === null) return $reportedBytes === null;
        if ($reportedBytes === null) return $truncated;
        if (!$truncated) return $reportedBytes === strlen($text);
        return $reportedBytes > strlen($text);
    }

    private static function nullableString(mixed $value, int $maximumBytes): bool
    {
        return $value === null || (is_string($value) && strlen($value) <= $maximumBytes);
    }

    private function __construct() {}
}
