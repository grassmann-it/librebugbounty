<?php

namespace App\Tests;

use App\Cribrum\Detector\SqlTriageDetector;
use App\Cribrum\ObservationInput;
use App\Cribrum\ReportPolicy;
use App\Entity\RetestRun;
use App\Service\CribrumClaimService;
use App\Service\CribrumObservationStore;
use App\Service\EvidenceStorageInterface;
use App\Service\FindingService;
use App\Service\IntakeProfileResolver;
use App\Service\LegacyCohortService;
use App\Service\ReportProjectionService;
use App\Service\WorkerProofResolver;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/** Cross-language acceptance for an exact negative Review decision. */
final class ReportFactReviewConsumerTest extends DatabaseTestCase
{
    public function testRejectedSqlErrorLeavesPureXssReadableWithoutRecipientLeak(): void
    {
        $rgen = getenv('RGEN_BEHAVIOR_PATH');
        if (!is_string($rgen) || !is_file($rgen.'/src/report_gen/handoff.py')) {
            self::markTestSkipped('Set RGEN_BEHAVIOR_PATH to run the private reader acceptance test.');
        }
        $db = $this->entityManager->getConnection();
        require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
        $migration = new \DoctrineMigrations\Version20261022000000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());

        $finding = self::getContainer()->get(FindingService::class)->createFinding(
            'https://review-consumer.fixture.invalid/check?q=OPENBUG', type: 'unclassified', expectedEvidence: 'OPENBUG');
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('still_vulnerable')
            ->setFinishedAt(new \DateTimeImmutable('-1 minute'))
            ->setRawResult(['proofContext' => WorkerProofResolver::captureContext($finding),
                'dialogEvents' => [['message' => 'observed OPENBUG', 'type' => 'alert']]]);
        $this->entityManager->persist($run); $this->entityManager->flush();

        $raw = '<pre>Microsoft JET Database Engine error: Syntax error (missing operator) in query expression id = PRIVATE_SQL_REJECTED_CANARY.</pre>';
        $input = new ObservationInput($finding->getPocUrl(), $raw, null, 'text/html', 500,
            $finding->getPocUrl(), null, 'OPENBUG', false, null);
        $signals = (new SqlTriageDetector())->detect($input, 'active');
        self::assertSame(['sql_error_observed'], array_column($signals, 'code'));
        $observations = new CribrumObservationStore($this->entityManager, self::getContainer()->get(EvidenceStorageInterface::class));
        $observation = $observations->store($finding, ['schema' => 1, 'contentType' => 'text/html', 'httpStatus' => 500,
            'finalUrl' => $finding->getPocUrl(), 'rawHtml' => $raw, 'rawHtmlBytes' => strlen($raw),
            'rawHtmlTruncated' => false, 'domText' => null, 'domTextBytes' => null, 'domTextTruncated' => false,
            'redirectChain' => [], 'headers' => []], requestContextSha256: WorkerProofResolver::requestContextSha256($finding));
        $coverage = ['profile' => 'behavior-triage-v1', 'version' => 1, 'status' => 'complete',
            'evaluatedFamilies' => ReportPolicy::COVERAGE_FAMILIES, 'pocRevision' => $finding->getPocRevision(),
            'pocSha256' => hash('sha256', $finding->getPocUrl()), 'artifactSha256' => hash('sha256', $raw),
            'resultVersion' => 'review-consumer-v1', 'inputUrlSha256' => hash('sha256', $finding->getPocUrl()), 'mode' => 'active'];
        (new CribrumClaimService($this->entityManager))->attachClassification($observation,
            ['classifierVersion' => 'review-consumer-v1', 'signals' => $signals, 'suggestions' => [], 'coverage' => $coverage]);
        $projection = new ReportProjectionService($db, new ReportPolicy(), new IntakeProfileResolver(),
            new LegacyCohortService($db), new WorkerProofResolver($db), $observations);
        $before = $projection->refresh($finding, $observation);
        self::assertSame('xss_with_sql_error', $before['lane']);
        $fact = $db->fetchAssociative('SELECT fact_id,fact_json,fact_sha256 FROM finding_behavior_fact WHERE finding_id=? AND code=?',
            [$finding->getId(), 'sql_error_observed']);
        self::assertIsArray($fact);
        $selection = [[
            'kind' => 'fact_review', 'decision' => 'not_applicable', 'code' => 'sql_error_observed',
            'observationId' => $observation->getId(), 'pocRevision' => $finding->getPocRevision(),
            'requestContextSha256' => WorkerProofResolver::requestContextSha256($finding),
            'factRefs' => [['id' => $fact['fact_id'], 'contentSha256' => $fact['fact_sha256']]],
            'actor' => 'manual', 'reason' => 'Explicit Review deselection', 'reviewedAt' => '2026-10-25T11:00:00Z',
        ]];
        $db->executeStatement('UPDATE finding_report_context SET selection_json=?,basis_revision=basis_revision+1 WHERE finding_id=?',
            [json_encode($selection, JSON_THROW_ON_ERROR), $finding->getId()]);
        $after = $projection->refresh($finding, $observation);
        self::assertSame('eligible', $after['gate']);
        self::assertSame('pure_xss', $after['caseSummary']['kind']);
        self::assertSame(['xss_confirmed'], array_column($after['components'], 'code'));
        self::assertNotContains($fact['fact_id'], $after['factIds']);
        self::assertSame($fact['fact_id'], $after['reviewedFactExclusions'][0]['factRefs'][0]['id']);

        $read = $this->consumer($rgen, $finding->getId(), 'read');
        self::assertArrayNotHasKey('error', $read, json_encode($read));
        self::assertSame('pure_xss', $read['caseSummary']['kind']);
        self::assertSame($after['components'], $read['components']);
        $recipient = $this->consumer($rgen, $finding->getId(), 'source_canary');
        self::assertArrayNotHasKey('error', $recipient, json_encode($recipient));
        self::assertSame([], $recipient['safe']);
        self::assertStringContainsString('bestätigte XSS', $recipient['paragraphs']);
        foreach (['PRIVATE_SQL_REJECTED_CANARY', 'Microsoft JET', 'sql_error_observed', 'sql_error_observation'] as $secret) {
            self::assertStringNotContainsString($secret, $recipient['paragraphs']);
            self::assertStringNotContainsString($secret, $recipient['mail']);
            self::assertStringNotContainsString($secret, json_encode($recipient['recipientContext'], JSON_THROW_ON_ERROR));
        }
        self::assertSame($raw, $observations->load($observation)['rawHtml']);
        self::assertSame($fact['fact_json'], $db->fetchOne('SELECT fact_json FROM finding_behavior_fact WHERE fact_id=?', [$fact['fact_id']]));
        self::assertContains($fact['fact_id'], array_column($projection->evidence($finding), 'factId'));
    }

    private function consumer(string $rgen, string $findingId, string $action): array
    {
        $process = new Process(['python3', __DIR__.'/Support/behavior_pipeline_consumer.py', APP_TEST_ROOT.'/database.sqlite',
            $findingId, $action, 'pure_xss'], $rgen, ['PYTHONPATH' => $rgen.'/src', 'PYTHONDONTWRITEBYTECODE' => '1']);
        $process->mustRun();
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
