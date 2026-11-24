<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Finding;
use App\Service\ContactDiscoveryService;
use App\Service\ContactRouteService;
use App\Service\FindingWorkPolicy;
use App\Service\FindingService;
use App\Service\FollowUpService;
use App\Service\HunterContactProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class HunterContactDiscoveryTest extends DatabaseTestCase
{
    private MockHttpClient $http;
    private ContactDiscoveryService $contacts;
    private ContactRouteService $routes;
    private Session $session;
    private ?\Closure $duringRequest = null;
    private int $responseStatus = 200;
    private bool $emptyResult = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__).'/migrations/Version20261124020000.php';
        $migration = new \DoctrineMigrations\Version20261124020000($this->db(), new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) $this->db()->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        $this->session = new Session(new MockArraySessionStorage());
        $this->routes = self::getContainer()->get(ContactRouteService::class);
        $this->http = new MockHttpClient(function (): MockResponse {
            if ($this->duringRequest !== null) ($this->duringRequest)();
            $payload = $this->emptyResult ? ['data' => ['domain' => null, 'emails' => []], 'meta' => ['results' => 0]] : ['data' => ['domain' => 'example.org', 'emails' => [[
                'value' => 'security@example.org', 'type' => 'generic', 'provenance' => 'found', 'confidence' => 87,
                'department' => 'it', 'position' => '<script>synthetic-position</script>',
                'sources' => [['uri' => 'https://example.org/contact', 'last_seen_on' => '2026-10-01']],
            ]]]];
            return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => $this->responseStatus, 'response_headers' => ['Content-Type: application/json']]);
        });
        $this->contacts = $this->service(new HunterContactProvider($this->http, 'synthetic-hunter-test-key'));
        self::getContainer()->set(ContactDiscoveryService::class, $this->contacts);
    }

    public function testDomainAndCreditAcknowledgementAreRequiredBeforeAnyReservationOrRequest(): void
    {
        $finding = $this->finding();
        foreach ([[], ['consent' => '1'], ['expected_domain' => 'example.org'],
            ['consent' => '0', 'expected_domain' => 'example.org'], ['consent' => ['1'], 'expected_domain' => 'example.org'],
            ['consent' => '1', 'expected_domain' => 'elsewhere.org'], ['consent' => '1', 'expected_domain' => ['example.org']]] as $input) {
            try { $this->contacts->discover($finding, 'hunter', $input); self::fail('Unacknowledged or mismatched lookup accepted.'); }
            catch (\LogicException) {}
        }
        self::assertSame(0, $this->http->getRequestsCount());
        self::assertSame([], $this->contacts->history($finding));
        $unconfigured = $this->service(new HunterContactProvider($this->http));
        try { $unconfigured->discover($finding, 'hunter', $this->consent()); self::fail('Unconfigured provider accepted.'); }
        catch (\LogicException) {}
        self::assertSame([], $unconfigured->history($finding));
        self::assertSame(0, $this->http->getRequestsCount());
    }

    public function testRequestReservationIsDurableBeforeHttpAndDoesNotChangeSelectedRouteOrCaseFacts(): void
    {
        $finding = $this->finding(); $this->selectManualRoute($finding);
        $route = $this->routes->state($finding); $facts = $this->facts();
        $this->duringRequest = function () use ($finding): void {
            self::assertFalse($this->db()->isTransactionActive(), 'A database transaction must not span the external call.');
            $reader = DriverManager::getConnection($this->db()->getParams());
            try {
                $rows = $reader->fetchAllAssociative('SELECT * FROM contact_discovery WHERE finding_id = ?', [$finding->getId()]);
                self::assertCount(1, $rows);
                self::assertSame('pending', json_decode($rows[0]['result'], true, 32, JSON_THROW_ON_ERROR)['status']);
                $ledger = $reader->fetchAllAssociative('SELECT * FROM hunter_domain_lookup');
                self::assertCount(1, $ledger);
                self::assertSame('example.org', $ledger[0]['domain']);
                self::assertSame('pending', $ledger[0]['state']);
            } finally { $reader->close(); }
        };
        $this->contacts->discover($finding, 'hunter', $this->consent());
        self::assertSame(1, $this->http->getRequestsCount());
        self::assertSame($route, $this->routes->state($finding));
        self::assertSame($facts, $this->facts());
        $result = $this->contacts->history($finding)[0]['result'];
        self::assertSame('found', $result['status']);
        self::assertSame('example.org', $result['queried_domain']);
        self::assertNotEmpty($result['case_binding']);
        self::assertTrue(ContactRouteService::suggestionCurrent($result));
        self::assertSame(['security@example.org'], array_column($result['contacts'], 'value'));
    }

    public function testMissingLedgerAndStaleDisplayedDomainNeverReachTheProvider(): void
    {
        $finding = $this->finding();
        $this->db()->update('domain', ['hostname' => 'changed.example.org'], ['id' => $finding->getDomain()->getId()]);
        try { $this->contacts->discover($finding, 'hunter', $this->consent()); self::fail('Old displayed domain was silently replaced.'); }
        catch (\LogicException) {}
        self::assertSame(0, $this->http->getRequestsCount());
        self::assertSame([], $this->contacts->history($finding));
        $this->db()->update('domain', ['hostname' => 'example.org'], ['id' => $finding->getDomain()->getId()]);
        $this->db()->executeStatement('DROP TABLE hunter_domain_lookup');
        try { $this->contacts->discover($finding, 'hunter', $this->consent()); self::fail('Missing durable reservation storage was bypassed.'); }
        catch (\LogicException) {}
        self::assertSame(0, $this->http->getRequestsCount());
    }

    public function testStaleManagedEntityCannotHideANewDatabaseDiscardOrDuplicateStatus(): void
    {
        $finding = $this->finding();
        foreach ([['manual_assessment' => 'discarded'], ['status' => 'duplicate']] as $change) {
            $this->db()->update('finding', array_replace(['status' => 'new', 'manual_assessment' => null], $change), ['id' => $finding->getId()]);
            try { $this->contacts->discover($finding, 'hunter', $this->consent()); self::fail('Stale in-memory case bypassed fresh status restriction.'); }
            catch (\LogicException) {}
            self::assertSame(0, $this->http->getRequestsCount());
            self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM hunter_domain_lookup'));
            self::assertSame([], $this->contacts->history($finding));
        }
    }

    public function testFreshDomainCacheIsReusedOnlyAfterExplicitActionAndDoesNotSelectAnyRoute(): void
    {
        $first = $this->finding(); $second = $this->finding('/second');
        $this->contacts->discover($first, 'hunter', $this->consent());
        self::assertSame([], $this->contacts->history($second), 'Opening another case must not implicitly copy contacts.');
        $initial = $this->contacts->history($first)[0]['result'];
        $this->contacts->discover($first, 'hunter', $this->consent());
        $this->contacts->discover($second, 'hunter', $this->consent());
        self::assertSame(1, $this->http->getRequestsCount());
        $secondResult = $this->contacts->history($second)[0]['result'];
        self::assertSame('found', $secondResult['status']);
        self::assertSame($initial['expires'], $secondResult['expires'], 'Cache reuse must not extend the source lifetime.');
        self::assertNotSame($initial['case_binding'], $secondResult['case_binding']);
        self::assertNull($this->routes->state($first)['selected']);
        self::assertNull($this->routes->state($second)['selected']);
    }

    public function testUnknownOutcomeKeepsTheDomainReservedAcrossCasesAndTime(): void
    {
        $first = $this->finding(); $second = $this->finding('/second');
        $this->responseStatus = 500;
        $this->contacts->discover($first, 'hunter', $this->consent());
        $result = $this->contacts->history($first)[0]['result'];
        self::assertSame('unknown', $result['metadata']['request_state']);
        $this->db()->executeStatement("UPDATE contact_discovery SET fetched_at = '2000-01-01'");
        foreach ([$first, $second] as $finding) {
            try { $this->contacts->discover($finding, 'hunter', $this->consent()); }
            catch (\LogicException) {}
        }
        self::assertSame(1, $this->http->getRequestsCount(), 'An ambiguous charged operation must not automatically run again.');
        self::assertNull($this->routes->state($first)['selected']);
        self::assertNull($this->routes->state($second)['selected']);
        self::getContainer()->get(FindingService::class)->deleteFinding($first);
        self::assertSame('unknown', $this->db()->fetchOne('SELECT state FROM hunter_domain_lookup WHERE domain = ?', ['example.org']));
        try { $this->contacts->discover($second, 'hunter', $this->consent()); }
        catch (\LogicException) {}
        self::assertSame(1, $this->http->getRequestsCount(), 'Deleting the source case must not remove the unknown-operation guard.');
    }

    public function testSuccessfulEmptyResultsAreAlsoCachedForAnExplicitSecondLookup(): void
    {
        $first = $this->finding(); $second = $this->finding('/second'); $this->emptyResult = true;
        $this->contacts->discover($first, 'hunter', $this->consent());
        $initial = $this->contacts->history($first)[0]['result'];
        self::assertSame('not_found', $initial['status']);
        self::assertNotNull($initial['expires']);
        $this->contacts->discover($second, 'hunter', $this->consent());
        self::assertSame(1, $this->http->getRequestsCount());
        $cached = $this->contacts->history($second)[0]['result'];
        self::assertSame('not_found', $cached['status']);
        self::assertSame($initial['expires'], $cached['expires']);
        self::assertFalse(ContactRouteService::suggestionCurrent($cached));
    }

    public function testConcurrentCaseLookupObservesPendingDomainReservationWithoutAnotherRequest(): void
    {
        $first = $this->finding(); $second = $this->finding('/second');
        $this->duringRequest = function () use ($second): void {
            // Separate connection, as another request/tab would use.
            $other = DriverManager::getConnection($this->db()->getParams());
            $provider = new HunterContactProvider(new MockHttpClient(static function (): void { self::fail('Concurrent request bypassed reservation.'); }), 'synthetic-other-key');
            try {
                $service = new ContactDiscoveryService($other, new FindingWorkPolicy($other), [$provider]);
                try { $service->discover($second, 'hunter', $this->consent()); }
                catch (\LogicException) {}
            } finally { $other->close(); }
        };
        $this->contacts->discover($first, 'hunter', $this->consent());
        self::assertSame(1, $this->http->getRequestsCount());
        self::assertSame('found', $this->contacts->history($first)[0]['result']['status']);
    }

    public function testDeletingTheOriginalCaseDoesNotEraseTheDomainReservationOrFreshCache(): void
    {
        $first = $this->finding(); $second = $this->finding('/surviving-case');
        $this->contacts->discover($first, 'hunter', $this->consent());
        self::getContainer()->get(FindingService::class)->deleteFinding($first);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM hunter_domain_lookup'));
        $this->contacts->discover($second, 'hunter', $this->consent());
        self::assertSame(1, $this->http->getRequestsCount());
        self::assertSame('found', $this->contacts->history($second)[0]['result']['status']);
    }

    public function testFourReservedDomainRequestsCannotBeBypassedByANewCase(): void
    {
        $first = $this->finding(); $second = $this->finding('/second');
        for ($i = 0; $i < 4; ++$i) {
            $this->contacts->discover($first, 'hunter', $this->consent());
            foreach ($this->db()->fetchAllAssociative('SELECT id, result FROM hunter_domain_lookup') as $row) {
                $result = json_decode($row['result'], true, 32, JSON_THROW_ON_ERROR);
                $result['expires'] = '2000-01-01T00:00:00Z';
                $this->db()->update('hunter_domain_lookup', ['result' => json_encode($result, JSON_THROW_ON_ERROR), 'fetched_at' => '2000-01-01 00:00:00'], ['id' => $row['id']]);
            }
            $this->db()->executeStatement("UPDATE contact_discovery SET fetched_at = '2000-01-01 00:00:00'");
        }
        self::assertSame(4, $this->http->getRequestsCount());
        try { $this->contacts->discover($second, 'hunter', $this->consent()); }
        catch (\LogicException) {}
        self::assertSame(4, $this->http->getRequestsCount());
        self::assertSame(4, (int) $this->db()->fetchOne('SELECT SUM(reserved_credits) FROM hunter_domain_lookup WHERE domain = ?', ['example.org']));
    }

    public function testChangedDomainDuringHttpMakesTheResponseUnusableAndPreservesSelectedContact(): void
    {
        $finding = $this->finding(); $this->selectManualRoute($finding); $route = $this->routes->state($finding);
        $this->duringRequest = function () use ($finding): void {
            $this->db()->update('domain', ['hostname' => 'changed.example.org'], ['id' => $finding->getDomain()->getId()]);
        };
        $this->contacts->discover($finding, 'hunter', $this->consent());
        $attempt = $this->contacts->history($finding)[0];
        self::assertFalse(ContactRouteService::suggestionCurrent($attempt['result']));
        self::assertSame('example.org', $attempt['result']['queried_domain']);
        self::assertSame($route, $this->routes->state($finding));
        try { $this->choose($finding, $attempt['id']); self::fail('Stale domain result became a selected recipient.'); }
        catch (\LogicException) {}
        self::assertSame($route, $this->routes->state($finding));
    }

    public function testRestrictionChangedDuringHttpPreventsFreshContactSuggestion(): void
    {
        $finding = $this->finding();
        $this->duringRequest = function () use ($finding): void {
            self::getContainer()->get(FollowUpService::class)->save($finding, ['scope' => 'domain', 'contact_blocked' => '1']);
        };
        $this->contacts->discover($finding, 'hunter', $this->consent());
        self::assertFalse(ContactRouteService::suggestionCurrent($this->contacts->history($finding)[0]['result']));
        self::assertNull($this->routes->state($finding)['selected']);
    }

    public function testChangingSourceAfterLookupCannotSelectAnOldSuggestionUsingAStaleEntity(): void
    {
        $finding = $this->finding(); $this->selectManualRoute($finding);
        $this->contacts->discover($finding, 'hunter', $this->consent());
        $attempt = $this->contacts->history($finding)[0]; $route = $this->routes->state($finding);
        $this->db()->executeStatement('UPDATE finding SET poc_revision = poc_revision + 1 WHERE id = ?', [$finding->getId()]);
        try { $this->choose($finding, $attempt['id']); self::fail('Changed source accepted an old Hunter suggestion.'); }
        catch (\LogicException) {}
        self::assertSame($route, $this->routes->state($finding));
    }

    public function testNativeDiscoveryActionNeedsCsrfAndExplicitConfirmationAndNeverAutoSelects(): void
    {
        $finding = $this->finding(); $path = '/findings/'.$finding->getId();
        $response = $this->request($path);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->http->getRequestsCount());
        self::assertSame(405, $this->request($path.'/contact-discovery')->getStatusCode());
        self::assertSame(403, $this->request($path.'/contact-discovery', 'POST', ['provider' => 'hunter', '_token' => 'wrong'] + $this->consent())->getStatusCode());
        $doc = new \DOMDocument(); @$doc->loadHTML($response->getContent()); $xp = new \DOMXPath($doc);
        $token = $xp->evaluate('string(//form[contains(@action,"/contact-discovery")]//input[@name="_token"]/@value)');
        self::assertNotSame('', $token);
        $post = ['_token' => $token, 'provider' => 'hunter'];
        self::assertSame(303, $this->request($path.'/contact-discovery', 'POST', $post)->getStatusCode());
        self::assertSame(0, $this->http->getRequestsCount());
        $response = $this->request($path.'/contact-discovery', 'POST', $post + $this->consent());
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame(1, $this->http->getRequestsCount());
        self::assertNull($this->routes->state($finding)['selected']);
        $html = $this->request($path)->getContent();
        self::assertStringContainsString('&lt;script&gt;synthetic-position&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>synthetic-position</script>', $html);
        $attempt = $this->contacts->history($finding)[0];
        $this->choose($finding, $attempt['id']);
        self::assertSame('security@example.org', $this->routes->state($finding)['selected']['destination']);
        self::assertSame('hunter', $this->routes->state($finding)['selected']['provenance']['provider']);
        self::assertTrue($this->routes->state($finding)['selected']['provenance']['source_current']);
        $this->db()->executeStatement('UPDATE finding SET poc_revision = poc_revision + 1 WHERE id = ?', [$finding->getId()]);
        self::assertFalse($this->routes->state($finding)['selected']['provenance']['source_current']);
    }

    private function service(HunterContactProvider $provider): ContactDiscoveryService
    { return new ContactDiscoveryService($this->db(), self::getContainer()->get(FindingWorkPolicy::class), [$provider]); }

    private function consent(): array { return ['consent' => '1', 'expected_domain' => 'example.org']; }
    private function db(): Connection { return $this->entityManager->getConnection(); }
    private function finding(string $path = '/synthetic-hunter-case'): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'example.org']);
        if (!$domain) { $domain = (new Domain())->setHostname('example.org'); $this->entityManager->persist($domain); }
        $finding = (new Finding())->setDomain($domain)->setType('stored-case')->setTitle('Synthetic Hunter fixture')->setUrl('https://example.org'.$path);
        $this->entityManager->persist($finding); $this->entityManager->flush(); return $finding;
    }
    private function selectManualRoute(Finding $finding): void
    { $this->routes->save($finding, ['mode' => 'manual', 'revision' => $this->routes->state($finding)['revision'], 'channel' => 'email', 'destination' => 'existing@example.org']); }
    private function choose(Finding $finding, string $id): void
    { $this->routes->save($finding, ['mode' => 'suggestion', 'revision' => $this->routes->state($finding)['revision'], 'attempt_id' => $id, 'contact_index' => '0']); }
    private function facts(): array
    {
        $facts = [];
        foreach (['finding', 'domain', 'finding_assessment', 'screenshot_job', 'evidence', 'retest_run'] as $table) $facts[$table] = $this->db()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        return $facts;
    }
    private function request(string $path, string $method = 'GET', array $input = []): Response
    {
        $request = Request::create($path, $method, $input); $request->setSession($this->session);
        $response = self::$kernel->handle($request); self::$kernel->terminate($request, $response); return $response;
    }
}
