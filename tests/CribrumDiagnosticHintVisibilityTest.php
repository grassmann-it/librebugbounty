<?php

declare(strict_types=1);

namespace App\Tests;

use App\Dto\FindingReadFilter;
use App\Entity\HttpObservation;
use App\Repository\FindingReadRepository;
use App\Service\CribrumClaimExport;
use App\Service\CribrumClaimService;
use App\Service\CribrumFindingSummary;
use App\Service\FindingService;
use App\Service\StudioExportService;
use Symfony\Component\HttpFoundation\Request;

final class CribrumDiagnosticHintVisibilityTest extends DatabaseTestCase
{
    public function testNativeSqlAndDatabaseRuntimeDiagnosticsReachBadgesFiltersAndExportsWithoutCreatingSqli(): void
    {
        $db = $this->entityManager->getConnection();
        $db->executeStatement('CREATE TABLE cb_diagnostic_fixture (finding_id TEXT, is_current INTEGER, snapshot_json TEXT)');
        $db->executeStatement('CREATE VIEW finding_report_current AS SELECT * FROM cb_diagnostic_fixture');
        try {
            $findings = self::getContainer()->get(FindingService::class);
            $claims = new CribrumClaimService($this->entityManager);
            $ids = [];
            foreach (['sql_error_observed' => 'builtin/sql_observation', 'database_runtime_error' => 'builtin/database-result-runtime'] as $code => $rule) {
                $finding = $findings->createFinding('https://diagnostic-visibility.test/'.$code);
                $ids[$code] = $finding->getId();
                $pin = str_repeat('a', 64);
                $hash = str_repeat('b', 64);
                $observation = new HttpObservation($finding, $finding->getPocRevision(), 500, $finding->getPocUrl(), 'text/html', 0, false, 0, false, [
                    'requestUrl' => $finding->getPocUrl(), 'requestContextSha256' => $pin, 'private' => 'PRIVATE_SOURCE_METADATA',
                ]);
                $observation->setArtifact(null, $hash);
                $this->entityManager->persist($observation);
                $this->entityManager->flush();
                $claims->apply($finding, 'error_disclosure', 'confirmed', false, 'classifier', [$rule], 'PRIVATE_REASON', $observation->getId());
                $snapshot = ['tags' => ['xss_confirmed'], 'components' => [], 'coverage' => ['requestContextSha256' => $pin, 'sourceRef' => ['artifactSha256' => $hash]]];
                $db->insert('cb_diagnostic_fixture', ['finding_id' => $finding->getId(), 'is_current' => 1, 'snapshot_json' => json_encode($snapshot)]);
            }
            $repo = self::getContainer()->get(FindingReadRepository::class);
            foreach ($ids as $code => $id) {
                $filter = new FindingReadFilter(cribrumCodes: [$code]);
                self::assertSame(1, $repo->count($filter));
                $page = $repo->findPage($filter);
                self::assertSame([$id], array_column($page, 'id'));
                $summary = CribrumFindingSummary::fromReadView($page[0]);
                $badge = array_column($summary['badges'], null, 'code')[$code];
                self::assertSame('observed', $badge['state']);
                self::assertFalse($badge['reportSelected']);
                self::assertFalse($badge['qualifiedComponent']);
                self::assertArrayNotHasKey('sql_injection', array_column($summary['badges'], null, 'code'));
            }
            self::assertSame(0, $repo->count(new FindingReadFilter(cribrumCodes: ['sql_injection'])));
            $export = self::getContainer()->get(StudioExportService::class);
            $out = '';
            $export->writeDownload(new FindingReadFilter(cribrumCodes: ['sql_error_observed']), false, static function (string $chunk) use (&$out): void { $out .= $chunk; });
            $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([$ids['sql_error_observed']], array_column($data['findings'], 'id'));
            $exported = array_column($data['findings'][0]['claims'], null, 'claim')['error_disclosure'];
            self::assertSame(['sql_error_observed'], $exported['diagnosticHints']);
            self::assertTrue($exported['diagnosticCurrent']);
            self::assertStringNotContainsString('PRIVATE', $out);
            self::assertStringNotContainsString('builtin/sql_observation', $out);

            $request = Request::create('/findings/'.$ids['sql_error_observed']);
            $response = self::$kernel->handle($request);
            self::$kernel->terminate($request, $response);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('data-cribrum-code="sql_error_observed"', $response->getContent());
            self::assertStringContainsString('SQL-Fehler · beobachtet', $response->getContent());
            self::assertStringContainsString('Diagnose aus der Einordnung', $response->getContent());

            // A subsequent manual decision owns a new revision, without borrowing
            // the prior classifier's cause from the same observation.
            $finding = $findings->getFindingOrFail($ids['sql_error_observed']);
            $previous = $claims->readClaims($finding);
            $error = array_column($previous, null, 'claim')['error_disclosure'];
            $claims->apply($finding, 'error_disclosure', 'confirmed', false, 'manual', [], null, $error['observation']['id']);
            $current = CribrumClaimExport::forFindings($db, [$finding->getId()])[$finding->getId()];
            self::assertSame([], array_column($current, null, 'claim')['error_disclosure']['diagnosticHints']);
            self::assertSame(0, $repo->count(new FindingReadFilter(cribrumCodes: ['sql_error_observed'])));
        } finally {
            $db->executeStatement('DROP VIEW finding_report_current');
            $db->executeStatement('DROP TABLE cb_diagnostic_fixture');
        }
    }
}
