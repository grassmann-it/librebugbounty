<?php

namespace App\Tests;

use App\Cribrum\CribrumClassifier;
use App\Cribrum\Detector\SqlTriageDetector;
use App\Cribrum\ObservationInput;
use App\Cribrum\ReportPolicy;
use App\Entity\RetestRun;
use App\Service\CribrumClaimService;
use App\Service\CribrumObservationStore;
use App\Service\CribrumProcessingService;
use App\Service\FindingService;
use App\Service\ReportProjectionService;
use App\Service\ReviewQueueService;
use App\Service\WorkerProofResolver;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ReviewClaimSelectionTest extends DatabaseTestCase
{
    private ReviewQueueService $queue;
    private CribrumClaimService $claims;
    private CribrumProcessingService $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $request = Request::create('/review');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);
        $db = $this->entityManager->getConnection();
        require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
        $migration = new \DoctrineMigrations\Version20261022000000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        $this->queue = self::getContainer()->get(ReviewQueueService::class);
        $this->claims = self::getContainer()->get(CribrumClaimService::class);
        $store = self::getContainer()->get(CribrumObservationStore::class);
        $this->processor = new CribrumProcessingService($this->entityManager,
            new CribrumClassifier('/nonexistent-private-cribrum-packs', dirname(__DIR__).'/resources/cribrum/candidates', 'active'),
            $store, $this->claims, null, self::getContainer()->get(ReportProjectionService::class));
    }

    public function testConfirmedReviewRejectsOnlyUncheckedBoundProposalAndQueuesRuleFeedback(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://selection.example.test/?id=OPENBUG');
        $finding->setExpectedEvidence('OPENBUG');
        $this->entityManager->flush();
        $this->processor->process($finding, [
            'schema' => 1, 'contentType' => 'text/html', 'httpStatus' => 500,
            'finalUrl' => $finding->getPocUrl(), 'redirectChain' => [], 'headers' => [],
            'rawHtml' => "<pre>Microsoft JET Database Engine error: Syntax error (missing operator) in query expression 'id = OPENBUG'.</pre>",
            'rawHtmlBytes' => 115, 'rawHtmlTruncated' => false,
            'domText' => "Microsoft JET Database Engine error: Syntax error (missing operator) in query expression 'id = OPENBUG'.",
            'domTextBytes' => 104, 'domTextTruncated' => false,
        ], requestContextSha256: WorkerProofResolver::requestContextSha256($finding));
        $view = $this->queue->get(['images' => 'all'], $finding->getId(), true);
        $rows = array_column($view->claims, null, 'claim');
        self::assertArrayHasKey('observation:sql_input_structure_indication', $rows);
        self::assertArrayHasKey('component:sql_error_observation', $rows);
        self::assertArrayNotHasKey('sql_injection', $rows);
        self::assertSame('suspected', $rows['observation:sql_input_structure_indication']['selectionState']);
        self::assertSame('observed', $rows['component:sql_error_observation']['selectionState']);
        $payload = [];
        foreach ($view->claims as $row) {
            $payload[$row['claim']] = ['revision' => (string) $row['revision']];
            if ($row['selectionSeeded'] && !in_array($row['claim'], ['observation:sql_input_structure_indication', 'component:sql_error_observation'], true)) $payload[$row['claim']]['selected'] = '1';
        }
        $token = $this->queue->contextToken($finding->getId(), $view->stateFingerprint);
        $this->queue->assess($finding->getId(), 'confirmed', null, null, null, $token, true,
            claimSelection: ['version' => '1', 'observation' => $view->claimObservationId, 'rows' => $payload]);
        $current = array_column($this->claims->currentClaims($finding), null, 'claim');
        self::assertArrayNotHasKey('sql_injection', $current);
        $db = $this->entityManager->getConnection();
        self::assertGreaterThan(0, (int) $db->fetchOne('SELECT COUNT(*) FROM cribrum_rule_review_hint WHERE finding_id=?', [$finding->getId()]));
        self::assertGreaterThan(0, (int) $db->fetchOne('SELECT COUNT(*) FROM cribrum_review_selection_event'));
        $selection = json_decode((string) $db->fetchOne('SELECT selection_json FROM finding_report_context WHERE finding_id=?', [$finding->getId()]), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('sql_error_observed', array_column(array_filter($selection, static fn (array $entry): bool => ($entry['kind'] ?? null) === 'fact_review'), 'code'));
        $snapshot = self::getContainer()->get(ReportProjectionService::class)->current($finding);
        self::assertNotContains('sql_error_observation', array_column($snapshot['components'], 'code'));
    }

    public function testExplicitEmptySetIsIntentionalAndLegacyAssessmentStillWorks(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://selection.example.test/empty');
        $view = $this->queue->get(['images' => 'all'], $finding->getId(), true);
        self::assertSame([], $view->claims);
        $token = $this->queue->contextToken($finding->getId(), $view->stateFingerprint);
        $this->queue->assess($finding->getId(), 'confirmed', null, null, null, $token, true,
            claimSelection: ['version' => '1', 'observation' => '', 'rows' => []]);
        self::assertSame('confirmed', $this->entityManager->getConnection()->fetchOne('SELECT manual_assessment FROM finding WHERE id=?', [$finding->getId()]));
    }

    public function testManualSelectionKeepsCertaintyAndRejectsOnlyExplicitDeselection(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://selection.example.test/manual');
        $manual = $this->claims->apply($finding, 'local_file_inclusion', 'suspected', true, 'manual', ['fixture/manual'], 'Established by reviewer');
        $excluded = $this->claims->apply($finding, 'information_disclosure', 'observed', false, 'manual', [], 'Kept out of report');
        $untouched = $this->claims->apply($finding, 'error_disclosure', 'observed', false, 'manual', [], 'Initially excluded');
        $view = $this->queue->get(['images' => 'all'], $finding->getId(), true);
        $rows = array_column($view->claims, null, 'claim');
        self::assertTrue($rows['local_file_inclusion']['selectionSeeded']);
        self::assertFalse($rows['information_disclosure']['selectionSeeded']);
        self::assertFalse($rows['error_disclosure']['selectionSeeded']);
        $payload = [
            'local_file_inclusion' => ['revision' => (string) $manual->revision],
            'information_disclosure' => ['revision' => (string) $excluded->revision, 'selected' => '1'],
            'error_disclosure' => ['revision' => (string) $untouched->revision],
        ];
        $this->queue->assess($finding->getId(), 'confirmed', null, null, null,
            $this->queue->contextToken($finding->getId(), $view->stateFingerprint), true,
            claimSelection: ['version' => '1', 'observation' => '', 'rows' => $payload]);
        $claims = array_column($this->claims->currentClaims($finding), null, 'claim');
        self::assertSame('rejected', $claims['local_file_inclusion']->state);
        self::assertFalse($claims['local_file_inclusion']->reportSelected);
        self::assertSame('observed', $claims['information_disclosure']->state);
        self::assertTrue($claims['information_disclosure']->reportSelected);
        self::assertSame('manual', $claims['information_disclosure']->source);
        self::assertSame('observed', $claims['error_disclosure']->state);
        self::assertFalse($claims['error_disclosure']->reportSelected);
        self::assertSame(1, $claims['error_disclosure']->revision);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM cribrum_rule_review_hint'));
    }

    public function testStaleClaimRevisionRollsBackCaseAssessment(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://selection.example.test/conflict');
        $claim = $this->claims->apply($finding, 'information_disclosure', 'observed', false, 'manual');
        $view = $this->queue->get(['images' => 'all'], $finding->getId(), true);
        try {
            $this->queue->assess($finding->getId(), 'confirmed', null, null, null,
                $this->queue->contextToken($finding->getId(), $view->stateFingerprint), true,
                claimSelection: ['version' => '1', 'observation' => '', 'rows' => [
                    'information_disclosure' => ['revision' => (string) ($claim->revision + 1), 'selected' => '1'],
                ]]);
            self::fail('Stale claim revision was accepted.');
        } catch (\UnexpectedValueException) {}
        self::assertNull($this->entityManager->getConnection()->fetchOne('SELECT manual_assessment FROM finding WHERE id=?', [$finding->getId()]));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM cribrum_rule_review_hint'));
    }

    public function testProofBackedXssRemainsWhenSqlErrorIsRejectedForThisCase(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://selection.example.test/proof?q=OPENBUG', expectedEvidence: 'OPENBUG');
        $this->claims->apply($finding, 'xss', 'observed', true, 'migration');
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('still_vulnerable')
            ->setFinishedAt(new \DateTimeImmutable('-1 minute'))
            ->setRawResult(['proofContext' => WorkerProofResolver::captureContext($finding), 'dialogEvents' => [['message' => 'observed OPENBUG', 'type' => 'alert']]]);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $raw = '<pre>ERROR: invalid input syntax for type integer: OPENBUG</pre>';
        $signals = array_values(array_filter((new SqlTriageDetector())->detect(new ObservationInput(
            $finding->getPocUrl(), $raw, null, 'text/html', 500, $finding->getPocUrl(), null, 'OPENBUG', false, null,
        ), 'active'), static fn (array $signal): bool => ($signal['code'] ?? null) === 'sql_error_observed'));
        self::assertNotEmpty($signals);
        $observation = self::getContainer()->get(CribrumObservationStore::class)->store($finding, [
            'schema' => 1, 'contentType' => 'text/html', 'httpStatus' => 500,
            'finalUrl' => $finding->getPocUrl(), 'redirectChain' => [], 'headers' => [],
            'rawHtml' => $raw, 'rawHtmlBytes' => strlen($raw), 'rawHtmlTruncated' => false,
            'domText' => null, 'domTextBytes' => null, 'domTextTruncated' => false,
        ], requestContextSha256: WorkerProofResolver::requestContextSha256($finding));
        $this->claims->attachClassification($observation, [
            'classifierVersion' => 'cribrum-1.3.1', 'signals' => $signals, 'suggestions' => [], 'packs' => [],
            'coverage' => ['profile' => 'behavior-triage-v1', 'version' => 1, 'status' => 'complete',
                'evaluatedFamilies' => ReportPolicy::COVERAGE_FAMILIES, 'pocRevision' => $finding->getPocRevision(),
                'pocSha256' => hash('sha256', $finding->getPocUrl()), 'artifactSha256' => hash('sha256', $raw),
                'resultVersion' => 'cribrum-1.3.1', 'inputUrlSha256' => hash('sha256', $finding->getPocUrl()), 'mode' => 'active'],
        ]);
        $projection = self::getContainer()->get(ReportProjectionService::class);
        $before = $projection->refresh($finding, $observation);
        self::assertSame('xss_with_sql_error', $before['lane']);
        $view = $this->queue->get(['images' => 'all'], $finding->getId(), true);
        $rows = array_column($view->claims, null, 'claim');
        self::assertArrayHasKey('component:xss_confirmed', $rows);
        self::assertTrue($rows['component:xss_confirmed']['selectionSeeded']);
        self::assertFalse($rows['component:xss_confirmed']['selectionSelectable']);
        self::assertArrayHasKey('component:sql_error_observation', $rows);
        $payload = [];
        foreach ($view->claims as $row) $payload[$row['claim']] = ['revision' => (string) $row['revision']];
        $this->queue->assess($finding->getId(), 'confirmed', null, null, null,
            $this->queue->contextToken($finding->getId(), $view->stateFingerprint), true,
            claimSelection: ['version' => '1', 'observation' => $view->claimObservationId, 'rows' => $payload]);
        $after = $projection->current($finding);
        self::assertSame('xss', $after['lane']);
        self::assertSame('eligible', $after['gate']);
        self::assertNotContains('sql_error_observation', array_column($after['components'], 'code'));
        self::assertGreaterThan(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM cribrum_rule_review_hint WHERE finding_id=? AND status=?', [$finding->getId(), 'pending']));
    }
}
