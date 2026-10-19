<?php

namespace App\Tests;

use App\Repository\FindingReadRepository;
use App\Service\CribrumClaimService;
use App\Service\FindingListService;
use App\Service\FindingService;
use App\Service\InventoryViewService;

final class CribrumClaimFilterTest extends DatabaseTestCase
{
    public function testClaimFilterStatsAndViewsAreClaimAware(): void
    {
        $findings = self::getContainer()->get(FindingService::class);
        $claims = new CribrumClaimService($this->entityManager);
        $withClaims = $findings->createFinding('https://claim-filter.example.test/a');
        $plain = $findings->createFinding('https://claim-filter-plain.example.test/a');
        // Simulate a retained historical case that has no claim ledger entries.
        $connection = $this->entityManager->getConnection();
        $connection->delete('finding_claim_revision', ['finding_id' => $plain->getId()]);
        $connection->delete('finding_claim', ['finding_id' => $plain->getId()]);

        $claims->apply($withClaims, 'sql_injection', 'suspected', false, 'classifier', ['r1']);
        $claims->apply($withClaims, 'xss', 'resolved', true, 'manual');

        $list = self::getContainer()->get(FindingListService::class);
        $repo = self::getContainer()->get(FindingReadRepository::class);

        [$filter] = $list->parse(['claim' => 'sql_injection', 'claim_state' => 'suspected']);
        self::assertSame(1, $repo->count($filter));
        self::assertSame(['scope' => 'active', 'claim' => 'sql_injection', 'claim_state' => 'suspected'], $list->filterQuery($filter) + ['scope' => 'active']);
        [$filter] = $list->parse(['claim' => 'sql_injection', 'claim_state' => 'confirmed']);
        self::assertSame(0, $repo->count($filter));
        [$filter] = $list->parse(['claim_state' => 'resolved']);
        self::assertSame($withClaims->getId(), $repo->findPage($filter)[0]->id);
        // Old cases without claims stay visible in the unfiltered list.
        [$filter] = $list->parse([]);
        self::assertSame(2, $repo->count($filter));

        $view = $list->get([], '/findings');
        self::assertSame(1, $view->stats['claim_sql_injection']['count']);
        self::assertSame(1, $view->stats['claim_xss']['count']);
        self::assertStringContainsString('claim=sql_injection', $view->stats['claim_sql_injection']['url']);

        $views = self::getContainer()->get(InventoryViewService::class);
        self::assertSame(['claim' => 'xss', 'claim_state' => 'resolved'], array_diff_key($views->normalize(['claim' => 'xss', 'claim_state' => 'resolved']), ['scope' => 1]));
        $id = $views->create('Gelöste XSS', ['claim' => 'xss', 'claim_state' => 'resolved']);
        self::assertStringContainsString('claim=xss', $views->all()[0]['url']);
        self::assertSame($id, $views->all()[0]['id']);

        $stats = self::getContainer()->get(\App\Service\StatisticsService::class)->get(['period' => 'all']);
        self::assertCount(2, $stats['claims']);
        foreach ($stats['claims'] as $row) {
            parse_str(parse_url($row['url'], PHP_URL_QUERY), $query);
            [$selection] = $list->parse($query);
            self::assertSame($row['count'], $repo->count($selection));
        }
        // A search must not alter the global claim statistics.
        self::assertSame($view->stats['claim_xss'], $list->get(['q' => 'absent'], '/findings')->stats['claim_xss']);
    }

    public function testInventoryFormRetainsClaimSelectionAndExportLink(): void
    {
        self::getContainer()->get(FindingService::class)->createFinding('https://claim-form.test/a');
        $request = \Symfony\Component\HttpFoundation\Request::create('/findings?claim=xss&claim_state=observed');
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode());
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//select[@name="claim"]/option[@value="xss"][@selected]')->length);
        self::assertSame(1, $xpath->query('//select[@name="claim_state"]/option[@value="observed"][@selected]')->length);
        self::assertGreaterThan(0, $xpath->query('//a[contains(@href,"/export?") and contains(@href,"claim=xss") and contains(@href,"claim_state=observed")]')->length);
    }

    public function testInvalidClaimFilterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(FindingListService::class)->parse(['claim' => 'nope']);
    }

    public function testLegacyDatabaseWithoutClaimTablesKeepsUnfilteredCases(): void
    {
        self::getContainer()->get(FindingService::class)->createFinding('https://legacy-claim.test/a');
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DROP TABLE finding_claim_revision');
        $connection->executeStatement('DROP TABLE finding_claim');
        $repo = self::getContainer()->get(FindingReadRepository::class);
        self::assertSame(1, $repo->count(new \App\Dto\FindingReadFilter()));
        self::assertSame(0, $repo->count(new \App\Dto\FindingReadFilter(claim: 'xss')));
        self::assertSame([], $repo->claimCounts());
        self::assertSame([], self::getContainer()->get(\App\Repository\StatisticsRepository::class)->claimCounts());
    }
}
