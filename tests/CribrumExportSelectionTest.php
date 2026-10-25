<?php

namespace App\Tests;

use App\Repository\FindingReadRepository;
use App\Service\CribrumClaimService;
use App\Service\FindingListService;
use App\Service\FindingService;
use App\Service\StatisticsService;
use App\Service\StudioExportProfileService;
use App\Service\StudioExportService;

final class CribrumExportSelectionTest extends DatabaseTestCase
{
    public function testSelectionAndOrderSurviveAllExportPagesAndProfiles(): void
    {
        $service = self::getContainer()->get(FindingService::class);
        $claims = new CribrumClaimService($this->entityManager);
        for ($i = 0; $i < 102; ++$i) {
            $finding = $service->createFinding('https://selection-export.test/'.$i);
            $claims->apply($finding, 'sql_injection', 'suspected', false, 'classifier');
            if ($i === 101) $claims->apply($finding, 'local_file_inclusion', 'suspected', false, 'classifier');
        }
        $service->createFinding('https://selection-export.test/unmatched');
        $query = ['cribrum' => ['sql_injection'], 'extra_findings' => 'yes', 'sort' => 'additional_count', 'page' => '2'];
        [$filter] = self::getContainer()->get(FindingListService::class)->parse($query);
        $expected = (new FindingReadRepository($this->entityManager->getConnection()))->findPage($filter, 200, 0);
        self::assertCount(102, $expected);
        $expectedIds = array_map(static fn ($view) => $view->id, $expected);
        $expectedUrls = array_map(static fn ($view) => $view->url, $expected);
        $profiles = self::getContainer()->get(StudioExportProfileService::class);
        $preview = $profiles->get($query);
        parse_str(parse_url($preview->downloadPath, PHP_URL_QUERY), $downloadQuery);
        self::assertSame(['sql_injection'], $downloadQuery['cribrum']);
        self::assertSame('additional_count', $downloadQuery['sort']);
        self::assertSame(102, $preview->findingCount);
        $out = '';
        $export = self::getContainer()->get(StudioExportService::class);
        $export->writeDownload($filter, false, static function ($part) use (&$out) { $out .= $part; });
        self::assertSame($expectedIds, array_column(json_decode($out, true, 512, JSON_THROW_ON_ERROR)['findings'], 'id'));
        foreach (['urls', 'state'] as $profile) {
            $out = '';
            $profiles->writeJson($profiles->parse($query + ['profile' => $profile]), static function ($part) use (&$out) { $out .= $part; });
            $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expectedUrls, array_column($profile === 'urls' ? $data : $data['findings'], 'url'));
        }
        $path = $profiles->buildReportArchive($profiles->parse($query + ['profile' => 'report', 'screenshots' => 'none']));
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $data = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $zip->close();
            self::assertSame($expectedUrls, array_column($data['findings'], 'url'));
            self::assertNotEmpty($data['findings'][0]['cribrum']['claims']);
            self::assertFalse($data['findings'][0]['cribrum']['includesHistory']);
            self::assertArrayNotHasKey('history', $data['findings'][0]['cribrum']);
        } finally { unlink($path); }
    }

    public function testStatisticsLinksCountCasesPerCodeAndExplainOverlap(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://selection-stats.test/a');
        $claims = new CribrumClaimService($this->entityManager);
        $claims->apply($finding, 'sql_injection', 'suspected', false, 'classifier');
        $claims->apply($finding, 'local_file_inclusion', 'suspected', false, 'classifier');
        $data = self::getContainer()->get(StatisticsService::class)->get([])['cribrum'];
        self::assertTrue($data['overlapping']);
        $items = array_column($data['items'], null, 'code');
        foreach (['sql_injection', 'local_file_inclusion'] as $code) {
            self::assertSame(1, $items[$code]['count']);
            parse_str(parse_url($items[$code]['url'], PHP_URL_QUERY), $query);
            [$filter] = self::getContainer()->get(FindingListService::class)->parse($query);
            self::assertSame(1, (new FindingReadRepository($this->entityManager->getConnection()))->count($filter));
        }
    }
}
