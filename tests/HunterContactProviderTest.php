<?php

namespace App\Tests;

use App\Service\HunterContactProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HunterContactProviderTest extends TestCase
{
    private const KEY = 'synthetic-hunter-key-not-a-secret';

    public function testFixedBoundedRequestUsesHeaderAuthenticationAndDoesNotFollowSources(): void
    {
        $observed = [];
        $client = new MockHttpClient(function ($method, $url, $options) use (&$observed): MockResponse {
            $observed = compact('method', 'url', 'options');
            return $this->response($this->payload());
        });
        $provider = new HunterContactProvider($client, self::KEY);
        self::assertTrue($provider->configured());
        self::assertSame('hunter', $provider->id());
        self::assertSame('Hunter.io', $provider->label());
        $result = $provider->discover('EXAMPLE.ORG.');
        self::assertSame('found', $result->status);
        self::assertSame('GET', $observed['method']);
        self::assertSame('https', parse_url($observed['url'], PHP_URL_SCHEME));
        self::assertSame('api.hunter.io', parse_url($observed['url'], PHP_URL_HOST));
        self::assertSame('/v2/domain-search', parse_url($observed['url'], PHP_URL_PATH));
        parse_str(parse_url($observed['url'], PHP_URL_QUERY) ?? '', $query);
        self::assertSame(['domain' => 'example.org', 'limit' => '10', 'offset' => '0'], $query);
        self::assertStringNotContainsString(self::KEY, $observed['url']);
        self::assertSame(['X-API-Key: '.self::KEY], $observed['options']['normalized_headers']['x-api-key']);
        self::assertSame(0, $observed['options']['max_redirects']);
        self::assertLessThanOrEqual(25, $observed['options']['max_duration']);
        self::assertGreaterThan(0, $observed['options']['max_duration']);
        self::assertFalse($observed['options']['buffer']);
        self::assertSame(1, $client->getRequestsCount(), 'Contact source URLs must never be fetched by the provider.');
        self::assertSame('https://hunter.io/search/example.org', $result->source);
        self::assertSame(['security@example.org'], array_column($result->contacts, 'value'));
        self::assertStringNotContainsString(self::KEY, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        $expires = new \DateTimeImmutable($result->expires);
        self::assertGreaterThan(new \DateTimeImmutable('+6 days 23 hours'), $expires);
        self::assertLessThan(new \DateTimeImmutable('+7 days 1 hour'), $expires);
    }

    public function testCandidateMetadataRemainsProviderEvidenceRatherThanSecurityAuthorization(): void
    {
        $result = (new HunterContactProvider(new MockHttpClient($this->response($this->payload())), self::KEY))->discover('example.org');
        $contact = $result->contacts[0];
        self::assertSame('email', $contact['channel']);
        self::assertSame('generic', $contact['hunter']['type']);
        self::assertSame('found', $contact['hunter']['provenance']);
        self::assertSame(92, $contact['hunter']['confidence']);
        self::assertSame('it', $contact['hunter']['department']);
        self::assertSame('Security team', $contact['hunter']['position']);
        self::assertNotEmpty($contact['hunter']['sources']);
        self::assertStringContainsString('https://example.org/security', json_encode($contact['hunter']['sources'], JSON_UNESCAPED_SLASHES));
        self::assertArrayNotHasKey('securityApproved', $contact);
        self::assertArrayNotHasKey('authorized', $contact);
        self::assertNotEmpty($result->warnings);
        self::assertSame([], $result->policies, 'Domain Search does not publish a disclosure policy.');
    }

    #[DataProvider('invalidHosts')]
    public function testPrivateOrMalformedHostnamesNeverReachHunter(string $hostname): void
    {
        $client = new MockHttpClient(static function (): void { self::fail('Rejected hostname reached HTTP transport.'); });
        self::assertSame('error', (new HunterContactProvider($client, self::KEY))->discover($hostname)->status);
        self::assertSame(0, $client->getRequestsCount());
    }

    public static function invalidHosts(): iterable
    {
        foreach (['127.0.0.1', '[::1]', 'localhost', 'a.local', 'a.internal', 'a.invalid', 'a.test',
            'https://example.org', 'example.org/path', 'example.org:443', 'user@example.org', "example.org\r\nX: bad"] as $host) yield [$host];
    }

    #[DataProvider('invalidKeys')]
    public function testMissingOrMalformedKeyCannotTriggerARequest(string $key): void
    {
        $client = new MockHttpClient(static function (): void { self::fail('Unconfigured provider reached HTTP transport.'); });
        $provider = new HunterContactProvider($client, $key);
        self::assertFalse($provider->configured());
        self::assertSame('error', $provider->discover('example.org')->status);
        self::assertSame(0, $client->getRequestsCount());
    }

    public static function invalidKeys(): iterable
    {
        foreach (['', ' ', "bad\r\nheader", "bad\0key"] as $key) yield [$key];
    }

    #[DataProvider('httpFailures')]
    public function testProviderFailuresAreBoundedDoNotRetryAndDoNotExposeSecrets(int $status): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['errors' => [['details' => self::KEY]]]),
            ['http_code' => $status, 'response_headers' => ['Content-Type: application/json', 'Location: https://elsewhere.example/hunter']]));
        $result = (new HunterContactProvider($client, self::KEY))->discover('example.org');
        self::assertSame('error', $result->status);
        self::assertSame([], $result->contacts);
        self::assertSame(1, $client->getRequestsCount());
        self::assertStringNotContainsString(self::KEY, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public static function httpFailures(): iterable
    {
        foreach ([302, 401, 403, 429, 500] as $status) yield [$status];
    }

    public function testMalformedOversizedAndTimedOutResponsesCannotYieldCandidates(): void
    {
        foreach ([new MockResponse('{broken', ['response_headers' => ['Content-Type: application/json']]),
            new MockResponse(str_repeat('a', 1048577), ['response_headers' => ['Content-Type: application/json']]),
            new MockResponse((static function () { yield ''; })(), ['response_headers' => ['Content-Type: application/json']])] as $response) {
            $client = new MockHttpClient($response);
            $result = (new HunterContactProvider($client, self::KEY))->discover('example.org');
            self::assertContains($result->status, ['error', 'invalid']);
            self::assertSame([], $result->contacts);
            self::assertSame(1, $client->getRequestsCount());
        }
    }

    public function testMismatchCannotBroadenTheRequestedDomainAndUnsupportedDomainIsNotFound(): void
    {
        foreach (['elsewhere.org', 'org', 'sub.example.org'] as $domain) {
            $payload = $this->payload(); $payload['data']['domain'] = $domain;
            $result = (new HunterContactProvider(new MockHttpClient($this->response($payload)), self::KEY))->discover('example.org');
            self::assertContains($result->status, ['invalid', 'error']);
            self::assertSame([], $result->contacts);
        }
        $empty = new MockResponse(json_encode(['data' => ['domain' => null, 'emails' => []], 'meta' => ['results' => 0]], JSON_THROW_ON_ERROR),
            ['response_headers' => ['Content-Type: application/json', 'X-Credits-Used: 0', 'X-Credits-Unit: search']]);
        $result = (new HunterContactProvider(new MockHttpClient($empty), self::KEY))->discover('example.org');
        self::assertSame('not_found', $result->status);
        self::assertSame([], $result->contacts);
        self::assertNotNull($result->expires);
        self::assertGreaterThan(new \DateTimeImmutable('+6 days 23 hours'), new \DateTimeImmutable($result->expires));
        self::assertSame('0', $result->toArray()['metadata']['credits_used']);
        self::assertSame('search', $result->toArray()['metadata']['credits_unit']);
    }

    public function testMissingDomainFieldIsUnknownRatherThanACacheableEmptyResult(): void
    {
        $client = new MockHttpClient($this->response(['data' => ['emails' => []], 'meta' => ['results' => 0]]));
        $result = (new HunterContactProvider($client, self::KEY))->discover('example.org');
        self::assertSame('error', $result->status);
        self::assertSame('unknown', $result->toArray()['metadata']['request_state']);
        self::assertSame([], $result->contacts);
        self::assertNull($result->expires);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testUnsafeForeignAndDuplicateAddressesAreNotPresentedAsUsableContacts(): void
    {
        $payload = $this->payload();
        $original = $payload['data']['emails'][0];
        foreach (['security@example.org', 'security@elsewhere.org', 'security@sub.example.org', "a@example.org\r\nBcc:other@example.org", '<script>@example.org', 'not-an-email'] as $address) {
            $payload['data']['emails'][] = ['value' => $address] + $original;
        }
        $result = (new HunterContactProvider(new MockHttpClient($this->response($payload)), self::KEY))->discover('example.org');
        self::assertSame(['security@example.org'], array_column($result->contacts, 'value'));
    }

    public function testSourceMetadataIsBoundedAndUnsafeLinksDoNotSurvive(): void
    {
        $payload = $this->payload();
        $payload['data']['emails'][0]['sources'] = array_map(static fn ($url) => ['uri' => $url], [
            'javascript:alert(1)', 'https://user:password@example.org/private', 'file:///etc/passwd',
            'https://example.org/one', 'https://example.org/two', 'https://example.org/three', 'https://example.org/four']);
        $payload['data']['emails'][0]['confidence'] = 999;
        $payload['data']['emails'][0]['type'] = 'new-provider-type';
        $payload['data']['emails'][0]['provenance'] = 'new-provider-origin';
        $result = (new HunterContactProvider(new MockHttpClient($this->response($payload)), self::KEY))->discover('example.org');
        $metadata = $result->contacts[0]['hunter'];
        self::assertLessThanOrEqual(3, count($metadata['sources']));
        $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('javascript:', $encoded);
        self::assertStringNotContainsString('password', $encoded);
        self::assertStringNotContainsString('file:', $encoded);
        self::assertNotSame(999, $metadata['confidence']);
        self::assertNotSame('generic', $metadata['type']);
        self::assertNotSame('found', $metadata['provenance']);
    }

    public function testOversizedCandidateListAndCredentialEchoRemainUnknownWithoutPersistingContent(): void
    {
        $many = $this->payload(); $many['data']['emails'] = array_fill(0, 11, $many['data']['emails'][0]);
        $echo = $this->payload(); $echo['data']['emails'][0]['position'] = self::KEY;
        foreach ([$many, $echo] as $payload) {
            $client = new MockHttpClient($this->response($payload));
            $result = (new HunterContactProvider($client, self::KEY))->discover('example.org');
            self::assertSame('error', $result->status);
            self::assertSame('unknown', $result->toArray()['metadata']['request_state']);
            self::assertSame([], $result->contacts);
            self::assertStringNotContainsString(self::KEY, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
            self::assertSame(1, $client->getRequestsCount());
        }
    }

    public function testOnlyValidatedCostHeadersAreRecordedWithoutInventingBillingInformation(): void
    {
        foreach ([['0.5', 'credit', true], ['1', 'search', true], ['unbounded', 'credit', false], ['1', 'invented', false]] as [$amount, $unit, $accepted]) {
            $response = new MockResponse(json_encode($this->payload(), JSON_THROW_ON_ERROR), ['response_headers' => [
                'Content-Type: application/json', 'X-Credits-Used: '.$amount, 'X-Credits-Unit: '.$unit]]);
            $result = (new HunterContactProvider(new MockHttpClient($response), self::KEY))->discover('example.org')->toArray();
            if ($accepted) {
                self::assertSame($amount, $result['metadata']['credits_used']);
                self::assertSame($unit, $result['metadata']['credits_unit']);
            } else {
                self::assertArrayNotHasKey('credits_used', $result['metadata']);
                self::assertArrayNotHasKey('credits_unit', $result['metadata']);
            }
        }
    }

    public function testUnicodeEscapedCredentialEchoCannotEnterPersistedCandidateMetadata(): void
    {
        $payload = $this->payload(); $payload['data']['emails'][0]['position'] = self::KEY;
        $escapedKey = implode('', array_map(static fn (string $character): string => sprintf('\\u%04x', ord($character)), str_split(self::KEY)));
        $raw = str_replace(self::KEY, $escapedKey, json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::KEY, $raw, 'The regression must exercise an encoded rather than literal echo.');
        $response = new MockResponse($raw, ['response_headers' => ['Content-Type: application/json']]);
        $client = new MockHttpClient($response);
        $result = (new HunterContactProvider($client, self::KEY))->discover('example.org');
        self::assertSame('error', $result->status);
        self::assertSame([], $result->contacts);
        self::assertSame('unknown', $result->toArray()['metadata']['request_state']);
        self::assertStringNotContainsString(self::KEY, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testInferredAndUnknownVerificationStayExplicitCandidateMetadata(): void
    {
        $payload = $this->payload();
        $payload['data']['emails'][0]['provenance'] = 'inferred';
        $payload['data']['emails'][0]['verification']['status'] = 'provider-added-status';
        $result = (new HunterContactProvider(new MockHttpClient($this->response($payload)), self::KEY))->discover('example.org');
        self::assertSame('found', $result->status, 'Found describes the lookup, not publication of this particular email.');
        self::assertSame('inferred', $result->contacts[0]['hunter']['provenance']);
        self::assertSame('unknown', $result->contacts[0]['hunter']['verification']);
        self::assertNotEmpty($result->warnings);
    }

    private function payload(): array
    {
        return ['data' => ['domain' => 'example.org', 'emails' => [[
            'value' => 'security@example.org', 'type' => 'generic', 'provenance' => 'found', 'confidence' => 92,
            'department' => 'it', 'position' => 'Security team',
            'sources' => [['uri' => 'https://example.org/security', 'extracted_on' => '2026-01-02', 'last_seen_on' => '2026-10-01', 'still_on_page' => true]],
            'verification' => ['status' => 'valid', 'date' => '2026-10-01'],
        ]]], 'meta' => ['results' => 1]];
    }

    private function response(array $payload): MockResponse
    {
        return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']]);
    }
}
