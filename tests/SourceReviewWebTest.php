<?php

namespace App\Tests;

use App\Entity\Finding;
use App\Service\CribrumObservationStore;
use App\Service\FindingService;
use App\Service\SourceReviewService;
use App\Service\WorkerProofResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SourceReviewWebTest extends DatabaseTestCase
{
    private Session $session;

    protected function usesGermanCopy(): bool { return false; }

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
    }

    #[DataProvider('locales')]
    public function testReviewedSourceIsVisibleEscapedAndReadOnly(string $locale, string $label, string $outcome): void
    {
        $finding = $this->source('reviewed');
        $reviews = self::getContainer()->get(SourceReviewService::class);
        $claim = $reviews->claim('Codex <worker>', 1, $finding->getId())[0];
        $reviews->record($claim['leaseToken'], 'potential_gap', '<img id="untrusted-review">Check the native diagnostic.', 'cribrum-1.3.5');
        $before = $this->snapshot();

        $page = $this->request($finding, $locale);
        self::assertSame(200, $page->getStatusCode());
        $html = $page->getContent();
        self::assertStringContainsString('data-source-review="current"', $html);
        self::assertStringContainsString($label, $html);
        self::assertStringContainsString($outcome, $html);
        self::assertStringContainsString('Codex &lt;worker&gt;', $html);
        self::assertStringContainsString('&lt;img id=&quot;untrusted-review&quot;&gt;', $html);
        self::assertStringNotContainsString('<img id="untrusted-review">', $html);
        self::assertSame($before, $this->snapshot(), 'A source-sighting GET must not alter decisions, evidence or the ledger.');
        self::assertNull($finding->getManualAssessment());
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM finding_claim'));
    }

    public static function locales(): iterable
    {
        yield 'German' => ['de', 'Quelle von KI gesichtet', 'Möglicherweise übersehener Hinweis'];
        yield 'English' => ['en', 'Source reviewed by AI', 'Potentially missed hint'];
    }

    public function testOpenPendingAndNewSourceRemainSeparateFromCompletedSighting(): void
    {
        $finding = $this->source('changed');
        $reviews = self::getContainer()->get(SourceReviewService::class);
        $open = $this->request($finding)->getContent();
        self::assertStringContainsString('data-source-review="open"', $open);
        $claim = $reviews->claim('Codex source worker', 1, $finding->getId())[0];
        $pending = $this->request($finding)->getContent();
        self::assertStringContainsString('data-source-review="pending"', $pending);
        self::assertStringContainsString('KI-Sichtung läuft', $pending);
        self::assertStringContainsString('Codex source worker', $pending);
        self::assertStringNotContainsString('Quelle von KI gesichtet', $pending);
        $reviews->record($claim['leaseToken'], 'no_new_hint', 'The retained fixture source was inspected.', 'cribrum-1.3.5');
        $this->store($finding, 'Changed retained source');
        $before = $this->snapshot();

        $stale = $this->request($finding)->getContent();
        self::assertStringContainsString('data-source-review="stale"', $stale);
        self::assertStringContainsString('Neue Quelle noch nicht von KI gesichtet', $stale);
        self::assertStringNotContainsString('<summary>Quelle von KI gesichtet', $stale);
        self::assertSame($before, $this->snapshot());
    }

    public function testCaseWithoutRetainedSourceShowsUnavailableWithoutChangingTheFinding(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://source-review.test/no-source');
        $before = $this->snapshot();
        $response = $this->request($finding);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-source-review="unavailable"', $response->getContent());
        self::assertStringContainsString('KI-Sichtung noch nicht verfügbar', $response->getContent());
        self::assertSame($before, $this->snapshot());
    }

    private function source(string $path): Finding
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://source-review.test/'.$path);
        $this->store($finding, 'Retained fixture source');
        return $finding;
    }

    private function store(Finding $finding, string $text): void
    {
        $finding = $this->entityManager->find(Finding::class, $finding->getId());
        self::getContainer()->get(CribrumObservationStore::class)->store($finding, [
            'schema' => 1, 'contentType' => 'text/html', 'httpStatus' => 200,
            'finalUrl' => $finding->getPocUrl(), 'redirectChain' => [], 'headers' => [],
            'rawHtml' => '<p>'.$text.'</p>', 'rawHtmlBytes' => strlen($text) + 7, 'rawHtmlTruncated' => false,
            'domText' => $text, 'domTextBytes' => strlen($text), 'domTextTruncated' => false,
        ], requestContextSha256: WorkerProofResolver::requestContextSha256($finding));
    }

    private function request(Finding $finding, string $locale = 'de'): Response
    {
        $request = Request::create('/findings/'.$finding->getId(), 'GET', cookies: ['lbb_locale' => $locale]);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }

    private function snapshot(): array
    {
        $connection = $this->entityManager->getConnection();
        $snapshot = [];
        foreach ($connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $rows = $connection->fetchAllAssociative('SELECT * FROM '.$connection->quoteIdentifier($table));
            usort($rows, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
            $snapshot[$table] = $rows;
        }
        return $snapshot;
    }
}
