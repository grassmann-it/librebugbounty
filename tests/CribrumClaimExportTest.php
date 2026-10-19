<?php

namespace App\Tests;

use App\Service\CribrumClaimService;
use App\Service\FindingService;
use App\Service\StudioExportProfileService;
use App\Service\StudioExportService;
use App\Entity\HttpObservation;

final class CribrumClaimExportTest extends DatabaseTestCase
{
    public function testEveryProfileExportsClaimsAndReportSeparatesSuspicionFromConfirmation(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://claim-export.test/a');
        $plain = self::getContainer()->get(FindingService::class)->createFinding('https://claim-export.test/legacy');
        // Simulate a retained historical case that has no claim ledger entries.
        $connection = $this->entityManager->getConnection();
        $connection->delete('finding_claim_revision', ['finding_id' => $plain->getId()]);
        $connection->delete('finding_claim', ['finding_id' => $plain->getId()]);

        $observation = new HttpObservation($finding, 0, 500, $finding->getUrl(), 'text/html', 0, false, 0, false, ['private' => 'SECRET_METADATA']);
        $observation->setClassification(['secret' => 'SECRET_CLASSIFICATION']);
        $this->entityManager->persist($observation);
        $this->entityManager->flush();
        $claims = new CribrumClaimService($this->entityManager);
        $claims->apply($finding, 'xss', 'confirmed', true, 'manual', [], 'SECRET_REASON', $observation->getId());
        $claims->apply($finding, 'information_disclosure', 'observed', true, 'manual', [], null, $observation->getId());
        $claims->apply($finding, 'sql_injection', 'suspected', true, 'manual', [], null, $observation->getId());
        $claims->apply($finding, 'local_file_inclusion', 'suspected', false, 'classifier');
        $claims->apply($finding, 'remote_file_inclusion', 'rejected', true, 'manual');
        $export = self::getContainer()->get(StudioExportService::class);
        [$filter] = $export->parse(['claim' => 'xss']);
        $state = '';
        $export->writeDownload($filter, false, static function (string $chunk) use (&$state): void { $state .= $chunk; });
        $data = json_decode($state, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $data['findings']);
        self::assertCount(5, $data['findings'][0]['claims']);
        self::assertSame($observation->getId(), $data['findings'][0]['claims'][0]['observation']['id']);
        self::assertStringNotContainsString('SECRET', $state);
        $profiles = self::getContainer()->get(StudioExportProfileService::class);
        foreach (['urls', 'state'] as $profile) {
            $out = '';
            $profiles->writeJson($profiles->parse(['profile' => $profile, 'claim' => 'xss']), static function (string $chunk) use (&$out): void { $out .= $chunk; });
            $json = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            self::assertCount(5, ($profile === 'urls' ? $json[0] : $json['findings'][0])['claims']);
            self::assertStringNotContainsString('SECRET', $out);
        }
        $path = $profiles->buildReportArchive($profiles->parse(['profile' => 'report', 'claim' => 'xss', 'screenshots' => 'none']));
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $manifest = $zip->getFromName('manifest.json');
            $markdown = $zip->getFromName('report.md');
            $zip->close();
            $data = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
            self::assertCount(3, $data['findings'][0]['claims']);
            self::assertStringContainsString('XSS: Bestätigt', $markdown);
            self::assertStringContainsString('Information Disclosure: Beobachtet', $markdown);
            self::assertStringContainsString('SQL\\-Injection: Verdacht', $markdown);
            self::assertStringNotContainsString('Local File Inclusion:', $markdown);
            self::assertStringContainsString('observation\\-1', $markdown);
            self::assertStringNotContainsString($observation->getId(), $manifest);
            self::assertStringNotContainsString('SECRET', $manifest.$markdown);
            self::assertStringNotContainsString('rawHtml', $manifest);
        } finally {
            unlink($path);
        }
        $out = '';
        [$filter] = $export->parse([]);
        $export->writeDownload($filter, false, static function (string $chunk) use (&$out): void { $out .= $chunk; });
        $all = json_decode($out, true, 512, JSON_THROW_ON_ERROR)['findings'];
        self::assertArrayNotHasKey('claims', array_values(array_filter($all, static fn (array $f): bool => $f['id'] === $plain->getId()))[0]);
    }
    public function testExportPreviewRetainsClaimFiltersWhenUpdatingOptions(): void
    {
        self::getContainer()->get(FindingService::class)->createFinding('https://claim-export-form.test/a');
        $request = \Symfony\Component\HttpFoundation\Request::create('/export?claim=xss&claim_state=observed');
        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode());
        $document = new \DOMDocument();
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//select[@name="claim"]/option[@value="xss"][@selected]')->length);
        self::assertSame(1, $xpath->query('//select[@name="claim_state"]/option[@value="observed"][@selected]')->length);
        self::assertSame(1, $xpath->query('//form[@id="export-filters"]//button[@formaction="/export/download"]')->length);
        $view = self::getContainer()->get(StudioExportProfileService::class)->get(['claim' => 'xss', 'claim_state' => 'observed']);
        self::assertStringContainsString('claim=xss', $view->downloadPath);
        self::assertStringContainsString('claim_state=observed', $view->downloadPath);
    }
    public function testHttpArtifactsRequireExplicitOptInAndOnlyFollowSelectedClaimReferences(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://private-http-export.test/a');
        $observation = new HttpObservation($finding, 0, 500, $finding->getUrl(), 'text/html', 16, false, 0, false);
        $storage = self::getContainer()->get(\App\Service\EvidenceStorageInterface::class);
        $raw = '<p>PRIVATE_RESPONSE</p>';
        $artifact = gzencode(json_encode([
            'schema' => 1, 'manifest' => ['rawSha256' => hash('sha256', $raw), 'domSha256' => null],
            'rawHtml' => $raw, 'domText' => null, 'privateMetadata' => 'NEVER_EXPORT',
        ], JSON_THROW_ON_ERROR));
        $stored = $storage->storeContents($finding, $artifact, 'observation.json.gz');
        $observation->setArtifact($stored->relativePath, $stored->sha256);
        $this->entityManager->persist($observation);
        $this->entityManager->flush();
        $claims = new CribrumClaimService($this->entityManager);
        $claims->apply($finding, 'xss', 'confirmed', true, 'manual', [], null, $observation->getId());
        $profiles = self::getContainer()->get(StudioExportProfileService::class);
        foreach ([false, true] as $optIn) {
            $path = $profiles->buildReportArchive($profiles->parse(['profile' => 'report', 'screenshots' => 'none', 'include_http_observations' => $optIn ? '1' : '0']));
            try {
                $zip = new \ZipArchive();
                self::assertTrue($zip->open($path));
                $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                if ($optIn) {
                    $item = $manifest['findings'][0]['httpObservations'][0];
                    self::assertTrue($item['available']);
                    $contents = $zip->getFromName($item['archivePath']);
                    self::assertSame(hash('sha256', $contents), $item['sha256']);
                    self::assertSame($raw, json_decode($contents, true, 512, JSON_THROW_ON_ERROR)['rawHtml']);
                    self::assertStringNotContainsString('NEVER_EXPORT', $contents);
                    self::assertStringNotContainsString($observation->getId(), json_encode($manifest));
                } else {
                    self::assertArrayNotHasKey('httpObservations', $manifest['findings'][0]);
                    self::assertSame(2, $zip->numFiles, 'Default report contains only manifest and Markdown.');
                }
                $zip->close();
            } finally { unlink($path); }
        }
        // Corrupt artifacts stay out of the ZIP, without exposing filesystem paths.
        $corrupt = $storage->storeContents($finding, 'corrupt', 'corrupt.json.gz');
        $observation->setArtifact($corrupt->relativePath, $stored->sha256);
        $this->entityManager->flush();
        $path = $profiles->buildReportArchive($profiles->parse(['profile' => 'report', 'screenshots' => 'none', 'include_http_observations' => '1']));
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($manifest['findings'][0]['httpObservations'][0]['available']);
            self::assertSame(2, $zip->numFiles);
            $zip->close();
        } finally { unlink($path); }
    }

    public function testInvalidOversizedAndForeignHttpArtifactsAreExcluded(): void
    {
        $findings = self::getContainer()->get(FindingService::class);
        $finding = $findings->createFinding('https://http-export-bounds.test/a');
        $other = $findings->createFinding('https://http-export-bounds.test/b');
        $observation = new HttpObservation($finding, 0, 500, $finding->getUrl(), 'text/html', 0, false, 0, false);
        $this->entityManager->persist($observation);
        $this->entityManager->flush();
        (new CribrumClaimService($this->entityManager))->apply($finding, 'xss', 'observed', true, 'manual', [], null, $observation->getId());
        $storage = self::getContainer()->get(\App\Service\EvidenceStorageInterface::class);
        $profiles = self::getContainer()->get(StudioExportProfileService::class);
        $valid = gzencode(json_encode(['schema' => 1, 'manifest' => ['rawSha256' => hash('sha256', 'private')], 'rawHtml' => 'private', 'domText' => null]));
        foreach ([
            [$finding, 'invalid gzip'],
            [$finding, str_repeat('x', 2097153)],
            [$finding, gzencode(str_repeat('x', 10 * 1024 * 1024 + 1))],
            [$finding, gzencode(json_encode(['schema' => 1, 'manifest' => [], 'rawHtml' => 'bad hash', 'domText' => null]))],
            [$other, $valid],
        ] as [$owner, $contents]) {
            $stored = $storage->storeContents($owner, $contents, 'bounded-observation.json.gz');
            $observation->setArtifact($stored->relativePath, $stored->sha256);
            $this->entityManager->flush();
            $path = $profiles->buildReportArchive($profiles->parse(['profile' => 'report', 'screenshots' => 'none', 'include_http_observations' => '1', 'domain' => 'http-export-bounds.test']));
            try {
                $zip = new \ZipArchive();
                self::assertTrue($zip->open($path));
                $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                foreach ($manifest['findings'] as $record) {
                    foreach ($record['httpObservations'] as $item) self::assertFalse($item['available']);
                }
                self::assertSame(2, $zip->numFiles);
                $zip->close();
            } finally { unlink($path); }
        }
    }

    public function testHttpOptInCannotBeEnabledForUrlExports(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get(StudioExportProfileService::class)->parse(['profile' => 'urls', 'include_http_observations' => '1']);
    }
}
