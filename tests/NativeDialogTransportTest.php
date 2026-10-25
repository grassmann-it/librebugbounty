<?php

namespace App\Tests;

use App\Dto\BrowserScreenshotRequest;
use App\Service\PlaywrightBrowserScreenshotClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NativeDialogTransportTest extends TestCase
{
    public function testScreenshotTransportKeepsNativeAndHookedEventsSeparate(): void
    {
        $native = [['type' => 'alert', 'message' => 'native OPENBUG', 'origin' => 'native']];
        $hooked = [['type' => 'alert', 'message' => 'hooked OPENBUG']];
        $result = $this->capture(['dialogEvents' => $native, 'hookedDialogEvents' => $hooked, 'dialogOrigin' => 'native']);
        self::assertSame($native, $result->metadata['dialogEvents']);
        self::assertSame($hooked, $result->metadata['hookedDialogEvents']);
        self::assertSame('native', $result->metadata['dialogOrigin']);
    }

    public function testLegacyDialogTextDoesNotBecomeNativeEvidence(): void
    {
        $result = $this->capture(['dialogText' => 'OPENBUG', 'dialogSeen' => true, 'metadata' => ['dialogOrigin' => 'native']]);
        self::assertSame('OPENBUG', $result->dialogText);
        self::assertSame([], $result->metadata['dialogEvents']);
        self::assertSame([], $result->metadata['hookedDialogEvents']);
        self::assertArrayNotHasKey('dialogOrigin', $result->metadata);
    }

    public function testMalformedAndExcessiveEventsAreBounded(): void
    {
        $events = array_merge([['message' => 'missing type']], array_fill(0, 100, ['type' => 'alert', 'message' => 'OPENBUG']));
        $result = $this->capture(['dialogEvents' => $events]);
        self::assertCount(63, $result->metadata['dialogEvents']);
    }

    private function capture(array $data): \App\Dto\BrowserScreenshotResult
    {
        $data += ['screenshotBase64' => 'fixture-image', 'capturedAt' => '2026-10-22T12:00:00.000Z'];
        $http = new MockHttpClient(new MockResponse(json_encode($data), ['response_headers' => ['Content-Type' => 'application/json']]));
        return (new PlaywrightBrowserScreenshotClient($http, 'http://unused.test', 'fixture-token'))->capture(new BrowserScreenshotRequest('https://fixture.test/'));
    }
}
