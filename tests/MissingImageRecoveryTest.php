<?php

namespace App\Tests;

use App\Dto\BrowserScreenshotResult;
use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Entity\ScreenshotJob;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingProblemService;
use App\Service\MissingImageRecoveryService;
use App\Service\ScreenshotQueueService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class MissingImageRecoveryTest extends DatabaseTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=';

    public function testEnqueueTwiceDoesNotResolveMissingUntilCurrentCaptureIsReadableAndPreservesHistory(): void
    {
        $finding = $this->finding();
        $connection = $this->entityManager->getConnection();
        $at = new \DateTimeImmutable('2026-10-01');
        $finding->setManualAssessment('confirmed', null, $at);
        $this->entityManager->persist(new \App\Entity\FindingAssessment($finding, 'confirmed', null, $at));
        $this->entityManager->flush();
        $assessments = $connection->fetchAllAssociative('SELECT * FROM finding_assessment');
        $history = $connection->fetchAllAssociative('SELECT * FROM evidence');
        $case = $connection->fetchAssociative('SELECT * FROM finding');
        $client = $this->createMock(BrowserScreenshotClientInterface::class);
        $client->expects(self::once())->method('capture')->willReturn(new BrowserScreenshotResult(
            screenshotBase64: self::PNG, capturedAt: new \DateTimeImmutable(), finalUrl: $finding->getPocUrl(),
        ));
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $client);
        $recovery = self::getContainer()->get(MissingImageRecoveryService::class);
        self::assertSame(['queued' => 1, 'alreadyActive' => 0, 'skipped' => 0], $recovery->recover([]));
        self::assertSame(['queued' => 0, 'alreadyActive' => 1, 'skipped' => 0], $recovery->recover([]));
        self::assertSame(1, $this->missingCount());
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
        self::assertSame('available', self::getContainer()->get(ScreenshotQueueService::class)->processNext()?->getStatus());
        self::assertSame(0, $this->missingCount());
        self::assertSame($history, $connection->fetchAllAssociative('SELECT * FROM evidence WHERE file_path = ?', ['storage/artifacts/lost.png']));
        self::assertSame($case, $connection->fetchAssociative('SELECT * FROM finding'));
        self::assertSame($assessments, $connection->fetchAllAssociative('SELECT * FROM finding_assessment'));
    }

    public function testStaleImageOrAvailableStatusCannotResolveCurrentProblemButCurrentRetestImageCan(): void
    {
        $finding = $this->finding();
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, base64_decode(self::PNG), 'old.png');
        $image = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath);
        $job = (new ScreenshotJob())->setFinding($finding)->setUrl($finding->getUrl())->setStatus('available')->setScreenshotPath($stored->relativePath);
        $this->entityManager->persist($image);
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        self::assertSame(0, $this->missingCount());
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('UPDATE finding SET poc_revision = 1, poc_url = ? WHERE id = ?', ['https://recovery.test/new', $finding->getId()]);
        self::assertSame(1, $this->missingCount());
        // A stale form must not silently queue the new PoC.
        self::assertSame(1, self::getContainer()->get(MissingImageRecoveryService::class)->recover([], $finding->getId(), $finding->getUrl(), 0)['skipped']);
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
        $job->setUrl('https://recovery.test/new')->setScreenshotPath('storage/artifacts/nonexistent.png')->setCaptureMetadata(['queuedPocRevision' => 1]);
        $this->entityManager->flush();
        self::assertSame(1, $this->missingCount());
        $run = (new \App\Entity\RetestRun())->setFinding($finding)->setResult('still_vulnerable')->setScreenshotPath($stored->relativePath)
            ->setRawResult(['pocRevision' => 0, 'pocUrl' => $finding->getUrl()]);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        self::assertSame(1, $this->missingCount());
        $run->setRawResult(['pocRevision' => 1, 'pocUrl' => 'https://recovery.test/new']);
        $this->entityManager->flush();
        self::assertSame(0, $this->missingCount());
    }

    public function testRecoveryReplacesStaleActiveJobWithOneBoundToCurrentPoc(): void
    {
        $finding = $this->finding();
        $queue = self::getContainer()->get(ScreenshotQueueService::class);
        $old = $queue->enqueue($finding)->job;
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET poc_url = ?, poc_revision = 1 WHERE id = ?', ['https://recovery.test/current', $finding->getId()]);
        $recovery = self::getContainer()->get(MissingImageRecoveryService::class);
        self::assertSame(1, $recovery->recover([])['queued']);
        self::assertSame(1, $recovery->recover([])['alreadyActive']);
        $this->entityManager->refresh($old);
        self::assertSame('failed', $old->getStatus());
        self::assertSame($finding->getUrl(), $old->getUrl());
        $current = $this->entityManager->getConnection()->fetchAssociative('SELECT url, capture_metadata FROM screenshot_job WHERE active_key = ?', [$finding->getId()]);
        self::assertSame('https://recovery.test/current', $current['url']);
        self::assertSame(1, json_decode($current['capture_metadata'], true)['queuedPocRevision']);
        self::assertSame(1, $this->missingCount());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedWork')]
    public function testAdditionalWorkRestrictionsAreRespected(string $restriction): void
    {
        $finding = $this->finding();
        $connection = $this->entityManager->getConnection();
        if ($restriction === 'domain') {
            $connection->executeStatement("INSERT INTO domain_work_restriction (hostname, checks_blocked, contact_blocked, changed_at) VALUES ('recovery.test', 1, 0, '2026-10-18')");
        } elseif ($restriction === 'closed') {
            $connection->executeStatement("INSERT INTO finding_follow_up (finding_id, pursuit, contact_blocked, checks_blocked, changed_at) VALUES (?, 'closed', 0, 0, '2026-10-18')", [$finding->getId()]);
        } else {
            $finding->setStatus($restriction);
            $this->entityManager->flush();
        }
        self::assertSame(['queued' => 0, 'alreadyActive' => 0, 'skipped' => 1], self::getContainer()->get(MissingImageRecoveryService::class)->recover([]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
    }

    public static function blockedWork(): array
    {
        return [['domain'], ['closed'], ['discarded'], ['duplicate']];
    }

    public function testFilteredRecoverySpansPagesAndSkipsDeferralDiscardAndWorkPolicy(): void
    {
        $findings = [];
        foreach (range(1, 13) as $i) $findings[] = $this->finding('selected '.$i);
        $this->finding('outside filter');
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("INSERT INTO finding_review_disposition (finding_id, revision, state, reason, changed_at, retry_state) VALUES (?, 1, 'deferred', 'unreachable', '2026-10-18', 'none')", [$findings[0]->getId()]);
        $findings[1]->setManualAssessment('discarded', null, new \DateTimeImmutable());
        $this->entityManager->flush();
        $connection->executeStatement("INSERT INTO finding_follow_up (finding_id, pursuit, contact_blocked, checks_blocked, changed_at) VALUES (?, 'active', 0, 1, '2026-10-18')", [$findings[2]->getId()]);
        $before = $connection->fetchAllAssociative('SELECT * FROM finding_review_disposition');
        self::assertSame(['queued' => 10, 'alreadyActive' => 0, 'skipped' => 3], self::getContainer()->get(MissingImageRecoveryService::class)->recover(['kind' => 'missing', 'q' => 'selected', 'pageSize' => '10', 'page' => '2']));
        self::assertSame(10, $connection->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM finding_review_disposition'));
        self::assertSame(14, $this->missingCount());
    }

    public function testPostRequiresCsrfAndPreservesFiltersAndGetNeverQueues(): void
    {
        $finding = $this->finding();
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/errors?kind=missing&q=recovery&pageSize=10');
        $request->setSession($session);
        $response = self::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-recovery-one', $response->getContent());
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
        $endpoint = '/errors/missing-images/recover';
        self::assertSame(405, self::$kernel->handle(Request::create($endpoint))->getStatusCode());
        foreach ([[], ['_token' => 'bad'], ['_token' => ['bad']]] as $input) {
            self::assertSame(403, self::$kernel->handle(Request::create($endpoint, 'POST', $input))->getStatusCode());
        }
        preg_match('/name="_token" value="([^"]+)"/', $response->getContent(), $match);
        $input = ['_token' => html_entity_decode($match[1]), 'kind' => 'missing', 'q' => 'recovery', 'pageSize' => '10', 'page' => '1', 'finding' => $finding->getId(), 'pocUrl' => $finding->getUrl(), 'pocRevision' => '0'];
        $post = Request::create($endpoint, 'POST', $input);
        $post->setSession($session);
        $response = self::$kernel->handle($post);
        self::assertSame(303, $response->getStatusCode(), $response->getContent());
        self::assertSame('/errors?kind=missing&q=recovery&page=1&pageSize=10', $response->headers->get('Location'));
        self::assertSame(1, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM screenshot_job'));
        $post = Request::create($endpoint, 'POST', array_replace($input, ['kind' => ['missing']]));
        $post->setSession($session);
        self::assertSame(400, self::$kernel->handle($post)->getStatusCode());
    }

    private function missingCount(): int
    {
        return self::getContainer()->get(FindingProblemService::class)->get(['kind' => 'missing'])['total'];
    }

    private function finding(string $title = 'recovery'): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'recovery.test']);
        if (!$domain) { $domain = (new Domain())->setHostname('recovery.test')->setScheme('https'); $this->entityManager->persist($domain); }
        $finding = (new Finding())->setDomain($domain)->setTitle($title)->setType('xss')->setUrl('https://recovery.test/'.rawurlencode($title));
        $this->entityManager->persist($finding);
        $this->entityManager->persist((new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath('storage/artifacts/lost.png'));
        $this->entityManager->flush();
        return $finding;
    }
}
