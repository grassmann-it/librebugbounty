<?php

namespace App\Tests;

use App\Cribrum\ObservationTransport;
use App\Dto\BrowserRetestRequest;
use App\Dto\BrowserScreenshotRequest;
use App\Service\PlaywrightBrowserRetestClient;
use App\Service\PlaywrightBrowserScreenshotClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PlaywrightObservationTransportTest extends TestCase
{
    private const URL = 'https://fixture.test/target';
    private const WORKER = 'http://playwright:3000';
    private const TOKEN = 'fixture-worker-token';
    private const PNG = 'iVBORw0KGgoAAAABUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    public function testScreenshotClientAcceptsAStrictObservation(): void
    {
        $http = new MockHttpClient(fn (): MockResponse => $this->json([
            'screenshotBase64' => self::PNG,
            'capturedAt' => '2026-10-19T00:00:00.000Z',
            'observation' => self::observation(),
        ]));
        $result = (new PlaywrightBrowserScreenshotClient($http, self::WORKER, self::TOKEN))
            ->capture(new BrowserScreenshotRequest(self::URL));

        self::assertSame(self::observation(), $result->observation);
    }

    public function testRetestClientAcceptsAStrictObservation(): void
    {
        $http = new MockHttpClient(fn (): MockResponse => $this->json([
            'result' => 'still_vulnerable',
            'raw' => ['fixture' => true],
            'observation' => self::observation(),
        ]));
        $result = (new PlaywrightBrowserRetestClient($http, self::WORKER, self::TOKEN))
            ->retest(new BrowserRetestRequest(self::URL, 'marker', 10000, true));

        self::assertSame(self::observation(), $result->observation);
    }

    public function testInvalidObservationsAreDroppedAtTheClientBoundary(): void
    {
        $cases = [
            [...self::observation(), 'schema' => 2],
            array_diff_key(self::observation(), ['rawHtml' => 0]),
            [...self::observation(), 'unexpected' => true],
            [...self::observation(), 'httpStatus' => 99],
            [...self::observation(), 'redirectChain' => ['start' => 'https://fixture.test/start']],
            [...self::observation(), 'rawHtml' => str_repeat('a', 1048577), 'rawHtmlBytes' => 1048577],
            [...self::observation(), 'domText' => str_repeat('a', 524289), 'domTextBytes' => 524289],
            [...self::observation(), 'rawHtmlTruncated' => 1],
            [...self::observation(), 'rawHtmlBytes' => 42],
        ];
        foreach ($cases as $case) {
            self::assertNull(ObservationTransport::normalize($case));
        }
    }

    public function testPrivateCredentialHeaderNamesAreRejected(): void
    {
        foreach (['x-api-key', 'x-auth-token', 'proxy-authorization'] as $name) {
            $observation = self::observation();
            $observation['headers'][$name] = 'secret';
            self::assertNull(ObservationTransport::normalize($observation));
        }
    }

    private static function observation(): array
    {
        return [
            'schema' => 1,
            'contentType' => 'text/html; charset=utf-8',
            'httpStatus' => 500,
            'finalUrl' => self::URL,
            'redirectChain' => ['https://fixture.test/start'],
            'headers' => ['content-type' => 'text/html; charset=utf-8', 'server' => 'fixture'],
            'rawHtml' => '<p>You have an error in your SQL syntax</p>',
            'rawHtmlBytes' => 43,
            'rawHtmlTruncated' => false,
            'domText' => 'You have an error in your SQL syntax',
            'domTextBytes' => 36,
            'domTextTruncated' => false,
        ];
    }

    private function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json'],
        ]);
    }
}
