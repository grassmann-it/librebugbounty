<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Explicit, one-off CLI input. Does not use findings, queues or artifact storage. */
final class CribrumProbeSource
{
    public const RAW_LIMIT = 1048576;
    public const HEADER_LIMIT = 65536;
    public const DOM_LIMIT = 524288;

    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    public static function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f]/', $url)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Use an absolute HTTP(S) URL without credentials or whitespace.');
        }
    }

    /** @return array{body:string,httpStatus:?int,contentType:?string,finalUrl:?string} */
    public function fetch(string $url): array
    {
        self::validateUrl($url);
        $response = null;
        try {
            $response = $this->client->request('GET', $url, [
                'proxy' => '', 'max_redirects' => 0, 'timeout' => 5, 'max_duration' => 15,
                'verify_peer' => true, 'verify_host' => true, 'buffer' => false,
                'headers' => ['Accept' => 'text/html, text/plain, application/json', 'User-Agent' => 'LibreBugBounty Cribrum CLI probe'],
                'on_progress' => static function (int $downloaded, int $size, array $info): void {
                    if (max($downloaded, $size) > self::RAW_LIMIT) throw new \RuntimeException('Response exceeds the 1 MiB input limit.');
                    if (array_sum(array_map(static fn (string $line): int => strlen($line) + 2, $info['response_headers'] ?? [])) > self::HEADER_LIMIT) {
                        throw new \RuntimeException('HTTP headers exceed the 64 KiB input limit.');
                    }
                },
            ]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = '';
            foreach ($this->client->stream($response, 5) as $chunk) {
                if ($chunk->isTimeout()) throw new \RuntimeException('HTTP request timed out.');
                $body .= $chunk->getContent();
                if (strlen($body) > self::RAW_LIMIT) throw new \RuntimeException('Response exceeds the 1 MiB input limit.');
            }
            self::validateText($body);

            return ['body' => $body, 'httpStatus' => $status, 'contentType' => $headers['content-type'][0] ?? null,
                'finalUrl' => $response->getInfo('url') ?: $url];
        } catch (TransportExceptionInterface $error) {
            throw new \RuntimeException('HTTP request failed or exceeded its time/size limit.', previous: $error);
        } finally {
            $response?->cancel();
        }
    }

    /** Parse a decoded HTTP response export (including informational headers), or a body alone.
     * @return array{body:string,httpStatus:?int,contentType:?string,finalUrl:null}
     */
    public function parse(string $contents): array
    {
        if (strlen($contents) > self::RAW_LIMIT + self::HEADER_LIMIT) {
            throw new \InvalidArgumentException('Response exceeds the input limit.');
        }
        $status = $type = null;
        $headerBytes = 0;
        while (str_starts_with($contents, 'HTTP/')) {
            if (!preg_match('/\AHTTP\/(?:1\.[01]|2(?:\.0)?|3(?:\.0)?) ([1-5][0-9]{2})(?:[ \t][^\r\n]*)?\r?\n/', $contents, $match)
                || !preg_match('/\r?\n\r?\n/', $contents, $separator, PREG_OFFSET_CAPTURE)) {
                throw new \InvalidArgumentException('Malformed HTTP response: expected a status line, headers and a blank line.');
            }
            $offset = $separator[0][1];
            $length = $offset + strlen($separator[0][0]);
            $headerBytes += $length;
            if ($headerBytes > self::HEADER_LIMIT) throw new \InvalidArgumentException('HTTP headers exceed the 64 KiB input limit.');
            $status = (int) $match[1];
            $type = null;
            foreach (explode("\n", substr($contents, strlen($match[0]), max(0, $offset - strlen($match[0])))) as $line) {
                $line = rtrim($line, "\r");
                if ($line === '') continue;
                if (!preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*([^\x00-\x08\x0b\x0c\x0e-\x1f\x7f]*)$/D', $line, $header)) {
                    throw new \InvalidArgumentException('Malformed HTTP response header.');
                }
                if (strtolower($header[1]) === 'content-type') $type = trim($header[2]);
            }
            $contents = substr($contents, $length);
            if ($status >= 200) break;
            if (!str_starts_with($contents, 'HTTP/')) throw new \InvalidArgumentException('HTTP response contains no final status after informational headers.');
        }
        if (strlen($contents) > self::RAW_LIMIT) throw new \InvalidArgumentException('Response body exceeds the 1 MiB input limit.');
        self::validateText($contents);

        return ['body' => $contents, 'httpStatus' => $status, 'contentType' => $type, 'finalUrl' => null];
    }

    public static function validateText(string $contents): void
    {
        if (str_contains($contents, "\0") || !mb_check_encoding($contents, 'UTF-8')) {
            throw new \InvalidArgumentException('Provide decoded UTF-8 text, not binary or compressed response bytes.');
        }
    }
}
