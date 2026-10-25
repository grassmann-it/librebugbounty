<?php

namespace App\Tests;

use App\Dto\FindingReadFilter;
use App\Entity\Domain;
use App\Entity\Finding;
use App\Repository\FindingReadRepository;
use App\Service\FindingListService;

final class ReportKindFilterTest extends DatabaseTestCase
{
    public function testCurrentProjectionControlsStrictAndBroadSelectionsAndCounts(): void
    {
        $db = $this->entityManager->getConnection();
        $db->executeStatement('DROP VIEW IF EXISTS finding_report_current');
        $db->executeStatement('CREATE TABLE ui_report_fixture (finding_id TEXT, case_kind TEXT, purity TEXT, is_current INTEGER, case_summary_json TEXT, snapshot_json TEXT)');
        $db->executeStatement('CREATE VIEW finding_report_current AS SELECT * FROM ui_report_fixture');
        try {
            foreach ([
                'pure' => ['pure_xss', 'pure', ['xss_confirmed'], 1],
                'mixed' => ['mixed', 'mixed', ['xss_confirmed', 'sql_error_observation'], 1],
                'disclosure' => ['pure_information_disclosure', 'pure', ['information_disclosure'], 1],
                'unknown' => ['xss_confirmed', 'unknown', ['xss_confirmed'], 1],
                'stale' => ['pure_xss', 'pure', ['xss_confirmed'], 0],
                'absent' => null,
            ] as $name => $data) {
                $finding = $this->finding($name);
                if ($data === null) continue;
                [$kind, $purity, $components, $current] = $data;
                $summary = ['kind' => $kind, 'purity' => $purity, 'components' => $components, 'hints' => []];
                $db->insert('ui_report_fixture', ['finding_id' => $finding->getId(), 'case_kind' => $kind, 'purity' => $purity, 'is_current' => $current, 'case_summary_json' => json_encode($summary), 'snapshot_json' => json_encode(['caseSummary' => $summary])]);
            }
            $repository = self::getContainer()->get(FindingReadRepository::class);
            foreach (['pure_xss' => ['pure'], 'only_information_disclosure' => ['disclosure'], 'mixed' => ['mixed'], 'unknown' => ['absent', 'stale', 'unknown'], 'mixed_or_unknown' => ['absent', 'mixed', 'stale', 'unknown'], 'xss_containing' => ['mixed', 'pure', 'unknown']] as $kind => $expected) {
                $filter = new FindingReadFilter(reportKind: $kind);
                $rows = $repository->findPage($filter);
                $actual = array_column($rows, 'title'); sort($actual); sort($expected);
                self::assertSame($expected, $actual, $kind);
                self::assertSame(count($expected), $repository->count($filter), $kind);
            }
            $list = self::getContainer()->get(FindingListService::class);
            [$filter] = $list->parse(['report_kind' => 'pure_xss']);
            self::assertSame('pure_xss', $filter->reportKind);
            self::assertSame('pure_xss', $list->filterQuery($filter)['report_kind']);
            self::assertNotNull($repository->findPage($filter)[0]->reportProjection);
        } finally {
            $db->executeStatement('DROP VIEW finding_report_current');
            $db->executeStatement('DROP TABLE ui_report_fixture');
        }
    }

    public function testMissingProjectionIsUnknownAndDoesNotInventPurity(): void
    {
        $db = $this->entityManager->getConnection();
        $db->executeStatement('DROP VIEW IF EXISTS finding_report_current');
        $this->finding('unclassified');
        $repository = self::getContainer()->get(FindingReadRepository::class);
        self::assertSame(0, $repository->count(new FindingReadFilter(reportKind: 'pure_xss')));
        self::assertSame(0, $repository->count(new FindingReadFilter(reportKind: 'only_information_disclosure')));
        self::assertSame(1, $repository->count(new FindingReadFilter(reportKind: 'unknown')));
        self::assertNull($repository->findPage(new FindingReadFilter())[0]->reportProjection);
    }

    private function finding(string $name): Finding
    {
        $domain = (new Domain())->setHostname($name.'.example');
        $finding = (new Finding())->setDomain($domain)->setType('other')->setSeverity('low')->setTitle($name)->setUrl('https://'.$name.'.example/?q=marker');
        $this->entityManager->persist($domain);
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        return $finding;
    }
}
