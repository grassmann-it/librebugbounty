<?php

namespace App\Tests;

use App\Cribrum\CribrumClassifier;
use App\Entity\HttpObservation;
use App\Service\CribrumClaimService;
use App\Service\CribrumObservationStore;
use App\Service\CribrumProcessingService;
use App\Service\CribrumReclassifyService;
use App\Service\CribrumStatusService;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use Symfony\Component\Filesystem\Filesystem;

final class CribrumReclassifyTest extends DatabaseTestCase
{
    private string $packs;
    private CribrumObservationStore $store;
    private CribrumClaimService $claims;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packs = sys_get_temp_dir().'/cribrum-reclassify-'.bin2hex(random_bytes(6));
        mkdir($this->packs, 0700);
        $this->store = new CribrumObservationStore($this->entityManager, self::getContainer()->get(EvidenceStorageInterface::class));
        $this->claims = new CribrumClaimService($this->entityManager);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->packs);
        parent::tearDown();
    }

    public function testChangedPackCreatesImmutableRevisionAndRepeatIsNoop(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://fixture.test/reclassify');
        $processor = new CribrumProcessingService($this->entityManager, new CribrumClassifier($this->packs), $this->store, $this->claims);
        $original = $processor->process($finding, $this->transport(), requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($finding));
        $originalResult = $original->getClassification();
        $this->pack('v1');
        $summary = $this->service()->reclassify($finding->getId(), true);
        self::assertSame([], $summary['errors']);
        self::assertSame(1, $this->rowCount('http_observation'));
        $this->service()->reclassify($finding->getId());
        $this->entityManager->refresh($original);
        self::assertSame($originalResult, $original->getClassification());
        self::assertSame(2, $this->rowCount('http_observation'));
        $history = $this->rowCount('finding_claim_revision');
        $this->service()->reclassify($finding->getId());
        self::assertSame(2, $this->rowCount('http_observation'));
        self::assertSame($history, $this->rowCount('finding_claim_revision'));
        $this->pack('v2');
        $this->service()->reclassify($finding->getId());
        self::assertSame(3, $this->rowCount('http_observation'));
        self::assertGreaterThan($history, $this->rowCount('finding_claim_revision'));
        $snapshot = (new CribrumStatusService($this->entityManager->getConnection()))->snapshot();
        self::assertSame(3, $snapshot['observations']['total']);
        self::assertSame(3, $snapshot['observations']['withArtifact']);
        self::assertNotNull($snapshot['observations']['lastClassifiedAt']);
    }

    public function testManualClaimsIncludingSameStateRemainUntouched(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://fixture.test/manual');
        $observation = $this->store->store($finding, $this->transport(), requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($finding));
        $manual = $this->claims->apply($finding, 'information_disclosure', 'observed', false, 'manual', [], 'Reviewed', $observation->getId());
        $this->claims->apply($finding, 'sql_injection', 'rejected', false, 'manual');
        $this->pack('v1');
        $this->service()->reclassify($finding->getId());
        self::assertSame('manual', $manual->source);
        self::assertSame(1, $manual->revision);
        self::assertSame($observation->getId(), $manual->observationId);
        self::assertSame('rejected', $this->entityManager->getConnection()->fetchOne("SELECT state FROM finding_claim WHERE claim = 'sql_injection'"));
    }

    public function testUnreadableArtifactDoesNotStopOtherCasesAndHistoricalPocIsSkipped(): void
    {
        $findingService = self::getContainer()->get(FindingService::class);
        $broken = $findingService->createFinding('https://fixture.test/broken');
        $observation = $this->store->store($broken, $this->transport(), requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($broken));
        self::getContainer()->get(EvidenceStorageInterface::class)->deleteFile($observation->getArtifactPath());
        $good = $findingService->createFinding('https://fixture.test/good');
        $this->store->store($good, $this->transport(), requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($good));
        $old = $findingService->createFinding('https://fixture.test/old');
        $this->store->store($old, $this->transport(), pocRevision: $old->getPocRevision() + 1, requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($old));
        $summary = $this->service()->reclassify();
        self::assertSame(1, $summary['processed']);
        self::assertSame(1, $summary['skipped']);
        self::assertCount(1, $summary['errors']);
        self::assertStringContainsString($observation->getId(), $summary['errors'][0]);
    }

    public function testSinceLimitRestrictsTheBackfillToRecentFindings(): void
    {
        $findingService = self::getContainer()->get(FindingService::class);
        $old = $findingService->createFinding('https://fixture.test/since-old');
        $recent = $findingService->createFinding('https://fixture.test/since-recent');
        $this->store->store($old, $this->transport('OLD'));
        $this->store->store($recent, $this->transport('RECENT'));
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE finding SET created_at = datetime('now', '-2 days') WHERE id = ?",
            [$old->getId()],
        );
        $this->pack('v1');

        $summary = $this->service()->reclassify(since: new \DateTimeImmutable('1 day ago'));

        self::assertSame(1, $summary['processed']);
        self::assertSame(0, $summary['skipped']);
        self::assertSame([], $summary['errors']);
    }

    public function testOnlyLatestCaptureAffectsClaimsAndDialogContextIsRetained(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://fixture.test/latest');
        $finding->setExpectedEvidence('CRIBRUM');
        $this->entityManager->flush();
        $processor = new CribrumProcessingService($this->entityManager, new CribrumClassifier($this->packs), $this->store, $this->claims);
        $processor->process($finding, $this->transport('You have an error in your SQL syntax CRIBRUM'), requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($finding));
        $processor->process($finding, $this->transport(), dialogSeen: true, dialogText: str_repeat('x', 9000).'CRIBRUM', requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($finding));
        $this->pack('v1');
        self::assertSame(1, $this->service()->reclassify($finding->getId())['processed']);
        $states = $this->entityManager->getConnection()->fetchAllKeyValue('SELECT claim, state FROM finding_claim');
        self::assertSame('stale', $states['sql_injection']);
        self::assertSame('observed', $states['xss']);
        $history = $this->rowCount('finding_claim_revision');
        $this->service()->reclassify($finding->getId());
        self::assertSame($history, $this->rowCount('finding_claim_revision'));
    }

    public function testLateObsoleteCaptureDoesNotHideActiveCapture(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://fixture.test/late');
        $finding->setPocUrl($finding->getPocUrl(), 1);
        $this->entityManager->flush();
        $current = $this->store->store($finding, $this->transport(), requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($finding));
        $this->store->store($finding, $this->transport('Old error'), pocRevision: 0, requestContextSha256: \App\Service\WorkerProofResolver::requestContextSha256($finding));
        $this->pack('v1');
        $summary = $this->service()->reclassify($finding->getId());
        self::assertSame(1, $summary['processed']);
        self::assertSame([], $summary['errors']);
        $latest = $this->entityManager->getConnection()->fetchAssociative('SELECT metadata FROM http_observation ORDER BY created_at DESC, id DESC LIMIT 1');
        self::assertSame($current->getId(), json_decode($latest['metadata'], true)['reclassifiedFrom']);
        $history = $this->rowCount('finding_claim_revision');
        $this->service()->reclassify($finding->getId());
        self::assertSame($history, $this->rowCount('finding_claim_revision'));
    }

    public function testCompetingManualRevisionIsRefreshedUnderWriteLock(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://fixture.test/race');
        $claim = $this->claims->apply($finding, 'sql_injection', 'suspected', false, 'classifier');
        $connection = $this->entityManager->getConnection();
        // Represents a competing commit after this EntityManager cached revision 1.
        $connection->executeStatement("UPDATE finding_claim SET revision = 2, source = 'manual', state = 'rejected' WHERE finding_id = ? AND claim = 'sql_injection'", [$finding->getId()]);
        try {
            $this->claims->apply($finding, 'sql_injection', 'suspected', false, 'classifier', expectedRevision: 1);
            self::fail('A stale managed claim must not overwrite the committed manual decision.');
        } catch (\UnexpectedValueException) {
            self::assertSame('manual', $claim->source);
            self::assertSame('rejected', $claim->state);
            self::assertSame(2, $claim->revision);
            self::assertSame('rejected', $connection->fetchOne("SELECT state FROM finding_claim WHERE finding_id = ? AND claim = 'sql_injection'", [$finding->getId()]));
        }
    }

    public function testHistoryOnlyReplayShadowsEvaluationRunsAndPreservesQualifiedEvidence(): void
    {
        $classifier = new CribrumClassifier($this->packs, dirname(__DIR__).'/resources/cribrum/candidates', 'active');
        $service = new CribrumReclassifyService($this->entityManager, $classifier, $this->store, $this->claims);
        foreach (['mismatched', 'unpinned'] as $binding) {
            $finding = self::getContainer()->get(FindingService::class)->createFinding('https://fixture.test/history-'.$binding);
            $finding->setPayload('CRIBRUM');
            $this->entityManager->flush();
            $context = ['url' => $finding->getPocUrl(), 'payload' => 'CRIBRUM', 'expectedEvidence' => null, 'dialogSeen' => false, 'dialogText' => null];
            $transport = $this->transport(str_repeat('CRIBRUM ', 25).'Fatal error: broken in /var/www/private/index.php on line 42');
            $observation = $this->store->store($finding, $transport, classificationContext: $context,
                requestContextSha256: $binding === 'unpinned' ? null : \App\Service\WorkerProofResolver::requestContextSha256($finding));
            $input = \App\Cribrum\ObservationInput::fromArray([
                ...$context, ...$this->store->load($observation), 'contentType' => 'text/html', 'httpStatus' => 500,
                'finalUrl' => $transport['finalUrl'], 'coverage' => ['status' => 'complete', 'truncated' => false],
            ]);
            $original = $classifier->classify($input)->toArray();
            $this->claims->attachClassification($observation, $original);
            self::assertSame('partial', $original['coverage']['status']);
            self::assertNotEmpty($original['signals']);
            self::assertCount(4, $original['coverage']['evaluation']['runs']);
            if ($binding === 'mismatched') {
                $finding->setPayload('different-current-payload');
                $this->entityManager->flush();
            }
            self::assertFalse($this->store->matchesCurrentRequest($finding, $observation));
            $connection = $this->entityManager->getConnection();
            $historicalJson = $connection->fetchOne('SELECT classification FROM http_observation WHERE id = ?', [$observation->getId()]);
            $claimsBefore = $connection->fetchAllAssociative('SELECT * FROM finding_claim WHERE finding_id = ?', [$finding->getId()]);
            $summary = $service->reclassify($finding->getId());
            self::assertSame([], $summary['errors']);
            self::assertSame(1, $summary['processed']);
            $this->entityManager->refresh($observation);
            self::assertSame($original, $observation->getClassification());
            self::assertSame($historicalJson, $connection->fetchOne('SELECT classification FROM http_observation WHERE id = ?', [$observation->getId()]));
            self::assertSame($claimsBefore, $connection->fetchAllAssociative('SELECT * FROM finding_claim WHERE finding_id = ?', [$finding->getId()]));
            $revisions = $this->entityManager->getRepository(HttpObservation::class)->findBy(['finding' => $finding]);
            self::assertCount(2, $revisions);
            $revision = array_values(array_filter($revisions, static fn (HttpObservation $candidate): bool => $candidate->getId() !== $observation->getId()))[0];
            self::assertSame($observation->getId(), $revision->getMetadata()['reclassifiedFrom']);
            self::assertSame($observation->getArtifactSha256(), $revision->getArtifactSha256());
            $replayed = $revision->getClassification();
            self::assertSame('shadow', $replayed['coverage']['mode']);
            self::assertSame('unknown', $replayed['coverage']['status']);
            self::assertContains('capture_context_changed_or_unpinned_history_only', $replayed['diagnostics']);
            self::assertSame(1, $replayed['coverage']['evaluation']['schemaVersion']);
            foreach ($original['coverage']['evaluation']['runs'] as $index => $originalRun) {
                self::assertSame('active', $originalRun['mode']);
                $originalRun['mode'] = 'shadow';
                // Includes every family outcome, issue, counter, pack/rule and output selection.
                self::assertSame($originalRun, $replayed['coverage']['evaluation']['runs'][$index]);
            }
            self::assertCount(count($original['signals']), $replayed['signals']);
            foreach ($original['signals'] as $index => $signal) {
                if (($signal['kind'] ?? null) !== 'observation_evidence') {
                    self::assertSame($signal, $replayed['signals'][$index]);
                    continue;
                }
                self::assertSame('active', $signal['mode']);
                $shadow = \App\Cribrum\EvidenceSignal::make($signal['code'], $input, $signal['spans'], $signal['attributes'], $signal['relations'], $signal['producer'], 'shadow');
                self::assertSame($shadow, $replayed['signals'][$index]);
                \App\Cribrum\EvidenceSignal::validateAgainstInput($replayed['signals'][$index], $input);
            }
        }
    }

    public function testStatusWorksWithoutCribrumSchema(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DROP TABLE http_observation');
        $snapshot = (new CribrumStatusService($connection))->snapshot();
        self::assertFalse($snapshot['available']);
        self::assertNull($snapshot['observations']);
        self::assertSame('core', $snapshot['packs'][0]['id']);
    }

    private function service(): CribrumReclassifyService
    {
        return new CribrumReclassifyService($this->entityManager, new CribrumClassifier($this->packs), $this->store, $this->claims);
    }

    private function pack(string $version): void
    {
        file_put_contents($this->packs.'/custom.json', json_encode([
            'schema' => 1, 'id' => 'custom', 'version' => $version,
            'rules' => [['id' => 'marker', 'representation' => 'text', 'any' => ['CRIBRUM'], 'claim' => 'information_disclosure', 'state' => 'observed', 'score' => 90, 'reason' => 'Synthetic marker']],
        ], JSON_THROW_ON_ERROR));
    }

    private function rowCount(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function transport(string $text = 'CRIBRUM'): array
    {
        return ['schema' => 1, 'contentType' => 'text/html', 'httpStatus' => 500,
            'finalUrl' => 'https://fixture.test/response', 'redirectChain' => [], 'headers' => [],
            'rawHtml' => '<p>'.$text.'</p>', 'rawHtmlBytes' => strlen($text) + 7, 'rawHtmlTruncated' => false,
            'domText' => $text, 'domTextBytes' => strlen($text), 'domTextTruncated' => false];
    }
}
