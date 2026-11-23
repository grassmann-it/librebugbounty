<?php

namespace App\Tests;

use App\Service\EmailPreparationException;
use App\Service\OpenAiEmailDraftClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiEmailDraftClientTest extends TestCase
{
    private const PROSE = ['subject' => 'Security report for example.org', 'opening' => 'Hello, I would like to report a security issue.', 'closing' => 'Thank you for reviewing this report.'];

    public function testMissingKeyDoesNotReachTheNetwork(): void
    {
        $http = new MockHttpClient(static function () { self::fail('A missing key must not trigger a request.'); });
        $client = new OpenAiEmailDraftClient($http);
        self::assertFalse($client->configured());
        try { $client->generate($this->request()); self::fail('Missing key was accepted.'); }
        catch (EmailPreparationException) { self::assertSame(0, $http->getRequestsCount()); }
    }

    public function testBoundedDirectRequestReturnsOnlyValidatedDraftFieldsAndUsage(): void
    {
        $request = $this->request();
        $http = new MockHttpClient(static function ($method, $url, $options) use ($request): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.openai.com/v1/responses', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertGreaterThan(0, $options['timeout']);
            self::assertLessThanOrEqual(180, $options['max_duration']);
            self::assertSame($request, json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR));
            self::assertContains('Authorization: Bearer test-only-key', $options['headers']);
            return self::response(self::PROSE, ['usage' => ['input_tokens' => 120, 'output_tokens' => 70, 'total_tokens' => 190]]);
        });
        $client = new OpenAiEmailDraftClient($http, 'test-only-key');
        self::assertTrue($client->configured());
        self::assertSame('gpt-6.2-sol', $client->model());
        self::assertSame('medium', $client->reasoning());
        $draft = $client->generate($request);
        foreach (self::PROSE as $key => $value) self::assertSame($value, $draft[$key]);
        self::assertSame(120, $draft['usage']['input_tokens']);
        self::assertArrayNotHasKey('id', $draft);
        self::assertSame(1, $http->getRequestsCount());
    }

    #[DataProvider('unusableResponses')]
    public function testFailedRefusedIncompleteAndMalformedResponsesNeverBecomeDrafts(array|string $body, int $status): void
    {
        $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
        $http = new MockHttpClient(new MockResponse($raw, ['http_code' => $status]));
        try { (new OpenAiEmailDraftClient($http, 'test-only-key'))->generate($this->request()); self::fail('Unusable output was accepted.'); }
        catch (EmailPreparationException $error) {
            self::assertStringNotContainsString('PROVIDER_PRIVATE_DETAIL', $error->getMessage());
            self::assertStringNotContainsString('test-only-key', $error->getMessage());
            self::assertSame(1, $http->getRequestsCount(), 'Errors must not automatically retry a potentially billed generation.');
        }
    }

    public static function unusableResponses(): iterable
    {
        yield 'authorization' => [['error' => ['message' => 'PROVIDER_PRIVATE_DETAIL']], 401];
        yield 'rate limit' => [['error' => ['message' => 'PROVIDER_PRIVATE_DETAIL']], 429];
        yield 'redirect' => ['PROVIDER_PRIVATE_DETAIL', 302];
        yield 'server error' => ['PROVIDER_PRIVATE_DETAIL', 500];
        yield 'invalid JSON' => ['PROVIDER_PRIVATE_DETAIL', 200];
        yield 'incomplete' => [['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => []], 200];
        yield 'refusal' => [['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'refusal', 'refusal' => 'PROVIDER_PRIVATE_DETAIL']]]]], 200];
        yield 'empty output' => [['status' => 'completed', 'output' => []], 200];
        yield 'missing field' => [self::envelope(['subject' => 'A', 'opening' => 'B']), 200];
        yield 'wrong field type' => [self::envelope(['subject' => ['private'], 'opening' => 'B', 'closing' => 'C']), 200];
        yield 'extra field' => [self::envelope(self::PROSE + ['recipient' => 'wrong@example.org']), 200];
        yield 'header injection' => [self::envelope(array_replace(self::PROSE, ['subject' => "A\r\nBcc: wrong@example.org"])), 200];
    }

    public function testTransportExceptionsDoNotLeakProviderDetailsOrRetry(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): never {
            ++$calls;
            throw new TransportException('PROVIDER_PRIVATE_DETAIL test-only-key');
        });
        try { (new OpenAiEmailDraftClient($http, 'test-only-key'))->generate($this->request()); self::fail('Transport failure accepted.'); }
        catch (EmailPreparationException $error) {
            self::assertStringNotContainsString('PROVIDER_PRIVATE_DETAIL', $error->getMessage());
            self::assertStringNotContainsString('test-only-key', $error->getMessage());
            self::assertSame(1, $calls);
        }
    }

    private function request(): array
    {
        return ['model' => 'gpt-6.2-sol', 'store' => false, 'reasoning' => ['effort' => 'medium'], 'input' => [['role' => 'user', 'content' => 'Test fixture']]];
    }

    private static function response(array $prose, array $extra = []): MockResponse
    {
        return new MockResponse(json_encode(array_replace(self::envelope($prose), $extra), JSON_THROW_ON_ERROR));
    }

    private static function envelope(array $prose): array
    {
        return ['id' => 'resp_private_receipt', 'status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => json_encode($prose, JSON_THROW_ON_ERROR)]]]]];
    }
}
