<?php

namespace App\Tests;

use App\Command\FindingListCommand;
use App\Dto\FindingReadFilter;
use App\Entity\{Domain, Finding, FindingClaim};
use App\Repository\FindingReadRepository;
use App\Service\{FindingListService, InventoryViewService};
use Symfony\Component\Console\Tester\CommandTester;

final class CribrumSelectionTest extends DatabaseTestCase
{
    public function testOrWithinCodesAndAcrossFacetsSameClaimStateAndGlobalPagination(): void
    {
        $plain = [];
        for ($i = 0; $i < 13; ++$i) $plain[] = $this->finding('plain-'.$i, '2026-10-20');
        $sql = $this->finding('sql', '2026-01-01');
        $include = $this->finding('include', '2026-01-01');
        $rejected = $this->finding('rejected', '2026-10-21');
        $this->claim($sql, 'sql_injection', 'suspected');
        $this->claim($sql, 'xss', 'confirmed');
        $this->claim($include, 'local_file_inclusion', 'suspected');
        $this->claim($rejected, 'sql_injection', 'rejected');
        $this->claim($rejected, 'xss', 'confirmed');
        $repository = self::getContainer()->get(FindingReadRepository::class);
        $or = new FindingReadFilter(cribrumCodes: ['sql_injection', 'local_file_inclusion']);
        self::assertSame(2, $repository->count($or));
        $and = new FindingReadFilter(cribrumCodes: ['sql_injection', 'local_file_inclusion'], claim: 'xss', claimState: 'confirmed');
        self::assertSame([$sql->getId()], array_column($repository->findPage($and), 'id'));
        self::assertSame(0, $repository->count(new FindingReadFilter(claim: 'sql_injection', claimState: 'confirmed')));
        self::assertSame(3, $repository->count(new FindingReadFilter(cribrumCodes: ['sql_injection', 'local_file_inclusion'], cribrumHistory: true)));
        $sorted = new FindingReadFilter(sort: 'additional_first');
        $first = $repository->findPage($sorted, 2);
        self::assertEqualsCanonicalizing([$sql->getId(), $include->getId()], array_column($first, 'id'));
        $ids = [];
        for ($offset = 0; $offset < $repository->count($sorted); $offset += 3) $ids = [...$ids, ...array_column($repository->findPage($sorted, 3, $offset), 'id')];
        self::assertCount(16, array_unique($ids));
        self::assertSame($ids, array_column($repository->findPage($sorted, 100), 'id'));
        self::assertCount(2, $repository->findPage(new FindingReadFilter(extraFindings: 'yes')));
        self::assertCount(14, $repository->findPage(new FindingReadFilter(extraFindings: 'no')));
        self::assertCount(16, $repository->findPage(new FindingReadFilter(cribrumFreshness: 'missing')));
    }

    public function testCurrentAndHistoricalProjectionCountsDeduplicateSemanticAliases(): void
    {
        $db = $this->entityManager->getConnection();
        $db->executeStatement('CREATE TABLE cb_selection_fixture (finding_id TEXT, is_current INTEGER, snapshot_json TEXT)');
        $db->executeStatement('CREATE VIEW finding_report_current AS SELECT * FROM cb_selection_fixture');
        try {
            $current = $this->finding('current', '2026-01-01');
            $stale = $this->finding('stale', '2026-01-01');
            $this->finding('missing', '2026-01-01');
            $this->claim($current, 'sql_injection', 'suspected');
            $snapshot = ['components' => [['code' => 'sqli_suspected', 'certainty' => 'suspected']], 'tags' => ['sql_input_structure_indication', 'sqli_suspected', 'sql_error_observation', 'framework_laravel'], 'caseSummary' => ['hints' => ['sql_error_observation']]];
            foreach ([[$current, 1], [$stale, 0]] as [$finding, $fresh]) $db->insert('cb_selection_fixture', ['finding_id' => $finding->getId(), 'is_current' => $fresh, 'snapshot_json' => json_encode($snapshot)]);
            $repo = self::getContainer()->get(FindingReadRepository::class);
            self::assertSame(1, $repo->count(new FindingReadFilter(cribrumCodes: ['sql_error_observed'])));
            self::assertSame(2, $repo->count(new FindingReadFilter(cribrumCodes: ['sql_error_observed'], cribrumHistory: true)));
            self::assertSame([$stale->getId()], array_column($repo->findPage(new FindingReadFilter(cribrumFreshness: 'stale')), 'id'));
            self::assertSame($current->getId(), $repo->findPage(new FindingReadFilter(sort: 'additional_count'), 1)[0]->id);
            $count = \App\Service\CribrumFindingQuery::additionalCount();
            self::assertSame(2, (int) $db->fetchOne('SELECT '.$count.' FROM finding f WHERE f.id = ?', [$current->getId()]));
            $row = $repo->findPage(new FindingReadFilter(cribrumFreshness: 'stale'))[0];
            self::assertFalse($row->reportProjectionCurrent);
            self::assertNotNull($row->reportProjection);
        } finally {
            $db->executeStatement('DROP VIEW finding_report_current');
            $db->executeStatement('DROP TABLE cb_selection_fixture');
        }
    }

    public function testSemanticChangeSortIgnoresRefreshAndPlacesUnknownTimeLast(): void
    {
        $db = $this->entityManager->getConnection();
        $db->executeStatement('CREATE TABLE cb_changes_fixture (finding_id TEXT, is_current INTEGER, snapshot_json TEXT)');
        $db->executeStatement('CREATE VIEW finding_report_current AS SELECT * FROM cb_changes_fixture');
        $db->executeStatement('CREATE TABLE finding_report_revision (finding_id TEXT, revision INTEGER, snapshot_json TEXT)');
        try {
            $unknown = $this->finding('unknown-time', '2026-10-23');
            $older = $this->finding('older-change', '2026-01-01');
            $newer = $this->finding('newer-change', '2026-01-01');
            foreach ([[$unknown, null], [$older, '2026-10-01T12:00:00Z'], [$newer, '2026-10-02T12:00:00Z']] as [$finding, $changed]) {
                $first = ['tags' => ['xss_confirmed'], 'components' => [], 'decidedAt' => '2026-09-01T00:00:00Z'];
                $latest = $changed === null ? $first : ['tags' => ['xss_confirmed', 'sqli_suspected'], 'components' => [], 'decidedAt' => $changed];
                $db->insert('finding_report_revision', ['finding_id' => $finding->getId(), 'revision' => 1, 'snapshot_json' => json_encode($first)]);
                if ($changed !== null) $db->insert('finding_report_revision', ['finding_id' => $finding->getId(), 'revision' => 2, 'snapshot_json' => json_encode($latest)]);
                $db->insert('cb_changes_fixture', ['finding_id' => $finding->getId(), 'is_current' => 1, 'snapshot_json' => json_encode($latest)]);
            }
            $repo = self::getContainer()->get(FindingReadRepository::class);
            $filter = new FindingReadFilter(sort: 'cribrum_changed');
            self::assertSame([$newer->getId(), $older->getId(), $unknown->getId()], array_column($repo->findPage($filter), 'id'));
            $refresh = ['tags' => ['sqli_suspected', 'xss_confirmed', 'sqli_suspected'], 'components' => [], 'decidedAt' => '2026-10-24T12:00:00Z', 'packVersion' => 'new-version'];
            $db->insert('finding_report_revision', ['finding_id' => $older->getId(), 'revision' => 3, 'snapshot_json' => json_encode($refresh)]);
            $db->update('cb_changes_fixture', ['snapshot_json' => json_encode($refresh)], ['finding_id' => $older->getId()]);
            self::assertSame([$newer->getId(), $older->getId(), $unknown->getId()], array_column($repo->findPage($filter), 'id'));
        } finally {
            $db->executeStatement('DROP VIEW finding_report_current');
            $db->executeStatement('DROP TABLE cb_changes_fixture');
            $db->executeStatement('DROP TABLE finding_report_revision');
        }
    }

    public function testSharedNewFindingsSqlMatchesAuthoritativeReviewNoticesAndAcknowledgement(): void
    {
        $db = $this->entityManager->getConnection();
        $db->executeStatement('CREATE TABLE cb_notice_fixture (finding_id TEXT, is_current INTEGER, snapshot_json TEXT)');
        $db->executeStatement('CREATE VIEW finding_report_current AS SELECT * FROM cb_notice_fixture');
        $db->executeStatement('CREATE TABLE finding_report_revision (finding_id TEXT, revision INTEGER, snapshot_json TEXT)');
        try {
            $changed = $this->finding('semantic-change', '2026-01-01');
            $unchanged = $this->finding('semantic-refresh', '2026-01-01');
            $waf = $this->finding('waf-review', '2026-01-01');
            $first = ['decisionRevision' => 1, 'decidedAt' => '2026-10-01T00:00:00Z', 'legacyBasis' => ['verified' => true], 'components' => [['code' => 'xss_confirmed']], 'tags' => [], 'caseSummary' => ['hints' => []]];
            foreach ([$changed, $unchanged, $waf] as $finding) {
                $db->insert('finding_report_revision', ['finding_id' => $finding->getId(), 'revision' => 1, 'snapshot_json' => json_encode($first)]);
                $current = $first; $current['decisionRevision'] = 2; $current['decidedAt'] = '2026-10-02T00:00:00Z';
                if ($finding === $changed) $current['tags'] = ['sqli_suspected'];
                if ($finding === $waf) { $current['reasonCodes'] = ['new_waf_block_unreviewed']; $current['wafReview']['pending'] = ['fingerprint' => ['observedAt' => '2026-10-02T00:00:00Z']]; }
                $db->insert('cb_notice_fixture', ['finding_id' => $finding->getId(), 'is_current' => 1, 'snapshot_json' => json_encode($current)]);
                $db->insert('finding_report_revision', ['finding_id' => $finding->getId(), 'revision' => 2, 'snapshot_json' => json_encode($current)]);
            }
            $repo = self::getContainer()->get(FindingReadRepository::class);
            $notices = self::getContainer()->get(\App\Service\ReviewNoticeService::class);
            $expected = [];
            foreach ($notices->forFindings() as $id => $notice) if (array_intersect(['classification_changed', 'waf_block'], array_column($notice->observations, 'reason')) !== []) $expected[] = $id;
            self::assertEqualsCanonicalizing([$changed->getId(), $waf->getId()], $expected);
            self::assertEqualsCanonicalizing($expected, array_column($repo->findPage(new FindingReadFilter(newFindings: 'yes')), 'id'));
            $ack = new \App\Entity\FindingReviewAcknowledgement($changed, 'semantic:'.$changed->getId(), 'confirmed', $notices->states($changed->getId()), []);
            $this->entityManager->persist($ack); $this->entityManager->flush();
            self::assertSame([$waf->getId()], array_column($repo->findPage(new FindingReadFilter(newFindings: 'yes')), 'id'));
            self::assertEqualsCanonicalizing([$changed->getId(), $unchanged->getId()], array_column($repo->findPage(new FindingReadFilter(newFindings: 'no')), 'id'));
        } finally {
            $db->executeStatement('DROP VIEW finding_report_current');
            $db->executeStatement('DROP TABLE cb_notice_fixture');
            $db->executeStatement('DROP TABLE finding_report_revision');
        }
    }

    public function testSavedSelectionRoundTripAndCliUseTheSharedParser(): void
    {
        $lists = self::getContainer()->get(FindingListService::class);
        $query = ['cribrum' => ['sql_error_observed', 'sql_injection', 'sql_injection'], 'extra_findings' => 'yes', 'new_findings' => 'no', 'cribrum_freshness' => 'missing', 'cribrum_history' => '1', 'sort' => 'additional_count'];
        [$filter] = $lists->parse($query);
        self::assertSame(['sql_error_observed', 'sql_injection'], $filter->cribrumCodes);
        $views = self::getContainer()->get(InventoryViewService::class);
        $views->create('Cribrum selection', $query + ['page' => '9']);
        $saved = $views->all()[0];
        parse_str(parse_url($saved['url'], PHP_URL_QUERY), $decoded);
        self::assertSame($lists->filterQuery($filter), $decoded);
        self::assertStringContainsString('sql_injection', $saved['description']);
        $match = $this->finding('cli-match', '2026-01-01');
        $this->claim($match, 'sql_injection', 'suspected');
        $this->finding('cli-other', '2026-01-01');
        $tester = new CommandTester(self::getContainer()->get(FindingListCommand::class));
        self::assertSame(0, $tester->execute(['--cribrum' => ['sql_injection'], '--extra-findings' => 'yes', '--sort' => 'additional_count']));
        self::assertStringContainsString('cli-match', $tester->getDisplay());
        self::assertStringNotContainsString('cli-other', $tester->getDisplay());
        foreach ([['cribrum' => 'sql_injection'], ['cribrum' => [['sql_injection']]], ['cribrum_history' => 'true'], ['sort' => 'f.id DESC']] as $invalid) {
            try { $lists->parse($invalid); self::fail('Invalid selection accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testJsonAndDomainExportCliKeepSelectionAndBestMemberOrder(): void
    {
        $a = $this->finding('first-member', '2026-10-02');
        $b = $this->finding('second-member', '2026-10-01');
        $b->setDomain($a->getDomain());
        $c = $this->finding('other-group', '2026-09-01');
        $this->finding('excluded-group', '2026-10-24');
        foreach ([$a, $b, $c] as $finding) $this->claim($finding, 'sql_injection', 'suspected');
        $this->entityManager->flush();
        $domain = new CommandTester(self::getContainer()->get(\App\Command\DomainExportCommand::class));
        self::assertSame(0, $domain->execute(['--all' => true, '--format' => 'json', '--cribrum' => ['sql_injection'], '--sort' => 'additional_first']));
        $groups = json_decode($domain->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$a->getDomain()->getHostname(), $c->getDomain()->getHostname()], array_column($groups, 'hostname'));
        self::assertSame([$a->getId(), $b->getId()], $groups[0]['matchingFindingIds']);
        self::assertSame(2, $groups[0]['findings']);
        $json = new CommandTester(self::getContainer()->get(\App\Command\ExportJsonCommand::class));
        self::assertSame(0, $json->execute(['--cribrum' => ['sql_injection'], '--sort' => 'additional_first']));
        $data = json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$a->getId(), $b->getId(), $c->getId()], array_column($data['findings'], 'id'));
    }

    private function finding(string $name, string $date): Finding
    {
        $domain = (new Domain())->setHostname($name.'.example');
        $finding = (new Finding())->setDomain($domain)->setType('other')->setSeverity('low')->setTitle($name)->setUrl('https://'.$name.'.example/')->setSubmittedAt(new \DateTimeImmutable($date));
        $this->entityManager->persist($domain); $this->entityManager->persist($finding); $this->entityManager->flush();
        return $finding;
    }
    private function claim(Finding $finding, string $code, string $state): void
    {
        $claim = new FindingClaim(); $claim->finding = $finding; $claim->claim = $code; $claim->state = $state; $claim->source = 'manual';
        $this->entityManager->persist($claim); $this->entityManager->flush();
    }
}
