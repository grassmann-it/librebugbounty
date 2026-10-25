<?php

namespace App\Tests;

use App\Entity\HttpObservation;
use App\Service\CribrumRuleReviewService;
use App\Service\FindingService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CribrumRuleReviewWebTest extends DatabaseTestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testQueueIsReadOnlyAndCompletionRequiresCsrfAndCurrentRevision(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://rule-review.example.test/?q=fixture');
        $observation = new HttpObservation($finding, $finding->getPocRevision(), 500, $finding->getPocUrl(), 'text/html', 0, false, 0, false);
        $this->entityManager->persist($observation);
        $this->entityManager->flush();
        $rules = self::getContainer()->get(CribrumRuleReviewService::class);
        $rules->raise($finding->getId(), 'component:sql_error_observation', 'fixture/sql-diagnostic', $observation->getId(), 'fixture-engine-1', 'fixture-pack-1');
        $row = $rules->list()['rows'][0];
        $db = $this->entityManager->getConnection();
        $before = $db->fetchAllAssociative('SELECT * FROM finding');
        $response = $this->request('/settings/cribrum/rule-review');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('fixture/sql-diagnostic', $response->getContent());
        self::assertStringContainsString('SQL-Fehler', $response->getContent());
        self::assertSame(1, $rules->list()['total']);
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM cribrum_rule_review_event'));
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $token = (new \DOMXPath($document))->evaluate('string(//input[@name="_token"]/@value)');
        self::assertNotEmpty($token);
        $path = '/settings/cribrum/rule-review/'.$row['id'];
        self::assertSame(403, $this->request($path, 'POST', ['_token' => 'invalid', 'revision' => '1', 'note' => ''])->getStatusCode());
        self::assertSame(409, $this->request($path, 'POST', ['_token' => $token, 'revision' => '2', 'note' => ''])->getStatusCode());
        self::assertSame('pending', $rules->get($row['id'])['status']);
        self::assertSame(303, $this->request($path, 'POST', ['_token' => $token, 'revision' => '1', 'note' => '<script>private-note</script>'])->getStatusCode());
        self::assertSame('reviewed', $rules->get($row['id'])['status']);
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM cribrum_rule_review_event'));
        self::assertSame(409, $this->request($path, 'POST', ['_token' => $token, 'revision' => '1', 'note' => 'replay'])->getStatusCode());
        $reviewed = $this->request('/settings/cribrum/rule-review?status=reviewed')->getContent();
        self::assertStringContainsString('&lt;script&gt;private-note&lt;/script&gt;', $reviewed);
        self::assertStringNotContainsString('<script>private-note</script>', $reviewed);
        self::assertSame($before, $db->fetchAllAssociative('SELECT * FROM finding'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM finding_claim'));
    }

    public function testMalformedQueueFiltersAreRejected(): void
    {
        foreach (['status[]=pending', 'status=arbitrary', 'page=0', 'page[]=1', 'page=1e3'] as $query) {
            self::assertSame(400, $this->request('/settings/cribrum/rule-review?'.$query)->getStatusCode(), $query);
        }
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }
}
