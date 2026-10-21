<?php

namespace App\Tests;

use App\Service\CribrumProbeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CribrumProbeSourceTest extends TestCase
{
    public function testParsesResponseMetadataAndPreservesBodyExactly(): void
    {
        $source = new CribrumProbeSource(new MockHttpClient(static fn () => self::fail('Offline input fetched a target.')));
        $body = "<html>Private &lt;MARKER&gt;\r\nHTTP/1.1 body text</html>\n";
        $capture = $source->parse("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/html; charset=UTF-8\r\nSet-Cookie: private=secret\r\n\r\n".$body);
        self::assertSame(['body' => $body, 'httpStatus' => 500, 'contentType' => 'text/html; charset=UTF-8', 'finalUrl' => null], $capture);
        self::assertSame(['body' => $body, 'httpStatus' => null, 'contentType' => null, 'finalUrl' => null], $source->parse($body));
        self::assertSame('', $source->parse('')['body']);
        self::assertSame('No headers', $source->parse("HTTP/2 404\n\nNo headers")['body']);
    }

    public function testInformationalHeadersDoNotOverrideFinalStatus(): void
    {
        $source = new CribrumProbeSource(new MockHttpClient());
        $result = $source->parse("HTTP/1.1 103 Early Hints\r\nLink: private\r\n\r\nHTTP/2 503\r\ncontent-type: text/plain\r\n\r\nService unavailable");
        self::assertSame(503, $result['httpStatus']);
        self::assertSame('text/plain', $result['contentType']);
        self::assertSame('Service unavailable', $result['body']);
    }

    public static function malformedResponses(): iterable
    {
        yield 'status' => ['HTTP/1.1 nope\n\nbody'];
        yield 'missing separator' => ["HTTP/1.1 500 Error\nContent-Type: text/html\nbody"];
        yield 'header' => ["HTTP/1.1 500 Error\nNot a header\n\nbody"];
        yield 'control' => ["HTTP/1.1 500 Error\nX-Test: \x1b[31m\n\nbody"];
        yield 'informational only' => ["HTTP/1.1 100 Continue\n\n"];
        yield 'compressed bytes' => ["\x1f\x8b\0private"];
        yield 'invalid encoding' => ["\xff\xfe"];
        yield 'oversize body' => [str_repeat('a', CribrumProbeSource::RAW_LIMIT + 1)];
        yield 'oversize headers' => ["HTTP/1.1 500 Error\nX-Long: ".str_repeat('a', CribrumProbeSource::HEADER_LIMIT)."\n\nbody"];
    }

    #[DataProvider('malformedResponses')]
    public function testRejectsMalformedBinaryAndOversizeInputs(string $contents): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CribrumProbeSource(new MockHttpClient()))->parse($contents);
    }

    public function testExactBodyLimitIsAcceptedWithoutTruncation(): void
    {
        $body = str_repeat('a', CribrumProbeSource::RAW_LIMIT);
        self::assertSame($body, (new CribrumProbeSource(new MockHttpClient()))->parse("HTTP/1.1 200 OK\n\n".$body)['body']);
    }

    public function testFetchIsOneBoundedGetAndClassifiesErrorResponsesInsteadOfThrowingForStatus(): void
    {
        $url = 'https://fixture.test/?q=%3CEXACT%3E&case=MiXeD';
        $client = new MockHttpClient(static function (string $method, string $actual, array $options) use ($url): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame($url, $actual);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(15.0, $options['max_duration']);
            self::assertSame(5.0, $options['timeout']);
            self::assertSame('', $options['proxy']);
            self::assertFalse($options['buffer']);
            self::assertTrue($options['verify_peer']);
            self::assertTrue($options['verify_host']);
            self::assertArrayNotHasKey('authorization', $options['normalized_headers']);
            return new MockResponse('SQL error', ['http_code' => 500, 'response_headers' => ['Content-Type: text/html']]);
        });
        $capture = (new CribrumProbeSource($client))->fetch($url);
        self::assertSame(500, $capture['httpStatus']);
        self::assertSame('SQL error', $capture['body']);
        self::assertSame($url, $capture['finalUrl']);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testRedirectsAreReturnedWithoutFollowingLocation(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://elsewhere.invalid/']]));
        self::assertSame(302, (new CribrumProbeSource($client))->fetch('https://fixture.test/')['httpStatus']);
        self::assertSame(1, $client->getRequestsCount());
    }

    public static function oversizeProgress(): iterable
    {
        yield 'announced body' => [0, CribrumProbeSource::RAW_LIMIT + 1, []];
        yield 'downloaded body' => [CribrumProbeSource::RAW_LIMIT + 1, 0, []];
        yield 'headers' => [0, 0, ['response_headers' => [str_repeat('a', CribrumProbeSource::HEADER_LIMIT)]]];
    }

    #[DataProvider('oversizeProgress')]
    public function testTransportProgressRejectsOversizedInputs(int $downloaded, int $size, array $info): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($downloaded, $size, $info): MockResponse {
            ($options['on_progress'])($downloaded, $size, $info);
            self::fail('Oversized response was accepted.');
        });
        $this->expectException(\RuntimeException::class);
        (new CribrumProbeSource($client))->fetch('https://fixture.test/');
    }

    public static function transportFailures(): iterable
    {
        yield 'timeout' => [(static function () { yield ''; })()];
        yield 'partial failure' => [(static function () { yield 'partial'; throw new TransportException('private token'); })()];
        yield 'oversize stream' => [(static function () { yield str_repeat('a', CribrumProbeSource::RAW_LIMIT); yield 'x'; })()];
    }

    #[DataProvider('transportFailures')]
    public function testIncompleteFetchNeverReturnsAClassificationInput(iterable $body): void
    {
        $client = new MockHttpClient(new MockResponse($body));
        $this->expectException(\RuntimeException::class);
        (new CribrumProbeSource($client))->fetch('https://fixture.test/');
    }

    public static function invalidUrls(): iterable
    {
        foreach (['file:///etc/passwd', 'ftp://fixture.test/', 'https://user:secret@fixture.test/', 'https://fixture.test:99999/', "https://fixture.test/\nheader", 'fixture.test', ''] as $url) yield [$url];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRejectedBeforeAnyNetworkRequest(string $url): void
    {
        $client = new MockHttpClient(static fn () => self::fail('Invalid URL reached transport.'));
        $this->expectException(\InvalidArgumentException::class);
        (new CribrumProbeSource($client))->fetch($url);
    }
}
