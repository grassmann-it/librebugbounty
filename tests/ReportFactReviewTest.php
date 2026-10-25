<?php

namespace App\Tests;

use App\Cribrum\EvidenceSignal;
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

final class ReportFactReviewTest extends DatabaseTestCase
{
    private function context(): array
    {
        $url = 'https://case.example.test/?q=OPENBUG';
        $poc = ['effectiveUrl' => $url, 'revision' => 3, 'sha256' => hash('sha256', $url)];
        return ['findingId' => 'case-a', 'sourceInstanceId' => 'source-a', 'basisRevision' => 2, 'decisionRevision' => 1,
            'poc' => $poc, 'requestContextSha256' => hash('sha256', 'request-a'), 'facts' => [], 'selection' => [],
            'claims' => [], 'reportSelection' => ['mode' => 'auto'], 'intake' => ['payloadKind' => 'xss']];
    }

    private function proof(array $c): array
    {
        return ['proofId' => 'proof-a', 'sourceKind' => 'screenshot_job', 'sourceId' => 'job-a',
            'capturedAt' => '2026-10-25T10:00:00Z', 'sourcePageUrl' => $c['poc']['effectiveUrl'],
            'findingId' => $c['findingId'], 'sourceInstanceId' => $c['sourceInstanceId'],
            'pocRevision' => $c['poc']['revision'], 'pocSha256' => $c['poc']['sha256'],
            'requestContextSha256' => $c['requestContextSha256'], 'expectedMarker' => 'OPENBUG',
            'dialogText' => 'observed OPENBUG', 'dialogOrigin' => 'native', 'attribution' => 'matched',
            'matchPolicy' => 'native_marker_cs_substring_v1', 'contentSha256' => hash('sha256', 'proof')];
    }

    private function fact(array $c, string $id, string $code, string $observationId = 'obs-a', array $attributes = []): array
    {
        $roles = ['diagnostic_block', 'error', 'input', 'query'];
        $spans = array_map(static fn(string $role, int $n): array => EvidenceSignal::span('diagnostic', 0, 10) + ['role' => $role, 'id' => 'span-'.$n], $roles, array_keys($roles));
        return ['kind' => 'behavior', 'schemaVersion' => 1, 'mode' => 'active', 'factId' => $id, 'code' => $code,
            'findingId' => $c['findingId'], 'sourceInstanceId' => $c['sourceInstanceId'],
            'pocRevision' => $c['poc']['revision'], 'pocSha256' => $c['poc']['sha256'],
            'requestContextSha256' => $c['requestContextSha256'], 'sourceRef' => ['kind' => 'http_observation', 'id' => $observationId],
            'producer' => 'test-v1', 'capturedAt' => '2026-10-25T10:00:00Z',
            'contentSha256' => hash('sha256', $id), 'attributes' => $attributes, 'spans' => $spans,
            'relations' => [['type' => 'input_at_sql_structure_error', 'from' => 'span-2', 'to' => 'span-3']]];
    }

    private function rejection(array $c, array $facts, string $code = 'sql_error_observed'): array
    {
        return ['kind' => 'fact_review', 'decision' => 'not_applicable', 'code' => $code,
            'observationId' => 'obs-a', 'pocRevision' => $c['poc']['revision'],
            'requestContextSha256' => $c['requestContextSha256'],
            'factRefs' => array_map(static fn(array $fact): array => ['id' => $fact['factId'], 'contentSha256' => $fact['contentSha256']], $facts),
            'actor' => 'manual', 'reason' => 'Explicit Review deselection', 'reviewedAt' => '2026-10-25T11:00:00Z'];
    }

    public function testExactSqlErrorRejectionLeavesValidXssAndAuditableReference(): void
    {
        $c = $this->context(); $c['workerProof'] = $this->proof($c);
        $error = $this->fact($c, 'error-a', 'sql_error_observed');
        $c['facts'] = [$error];
        self::assertSame('xss_with_sql_error', (new ReportPolicy())->evaluate($c)['lane']);
        $c['selection'] = [$this->rejection($c, [$error])];
        $snapshot = (new ReportPolicy())->evaluate($c);
        self::assertSame('eligible', $snapshot['gate']);
        self::assertSame('xss', $snapshot['lane']);
        self::assertNotContains('sql_error_observed', $snapshot['tags']);
        self::assertSame([], $snapshot['factIds']);
        self::assertSame([['id' => 'error-a', 'contentSha256' => $error['contentSha256']]], $snapshot['reviewedFactExclusions'][0]['factRefs']);
        self::assertSame('behavior_fact', $snapshot['reviewedFactExclusions'][0]['evidenceRefs'][0]['kind']);
        self::assertArrayNotHasKey('spans', $snapshot['reviewedFactExclusions'][0]);
    }

    public function testOrdinaryComponentExclusionStillHoldsCombinedFinding(): void
    {
        $c = $this->context(); $c['workerProof'] = $this->proof($c);
        $c['facts'] = [$this->fact($c, 'error-a', 'sql_error_observed')];
        $c['selection'] = [['component' => 'sql_error_observation', 'mode' => 'exclude']];
        self::assertContains('combined_component_excluded', (new ReportPolicy())->evaluate($c)['reasonCodes']);
    }

    public function testStaleTamperedAndCrossCaseReferencesDoNotSuppressCurrentFacts(): void
    {
        $c = $this->context(); $c['workerProof'] = $this->proof($c);
        $error = $this->fact($c, 'error-a', 'sql_error_observed');
        $c['facts'] = [$error]; $valid = $this->rejection($c, [$error]);
        foreach (['hash', 'observation', 'poc', 'request', 'code', 'actor', 'missing_ref'] as $case) {
            $decision = $valid;
            match ($case) {
                'hash' => $decision['factRefs'][0]['contentSha256'] = hash('sha256', 'tampered'),
                'observation' => $decision['observationId'] = 'obs-other',
                'poc' => $decision['pocRevision'] = 2,
                'request' => $decision['requestContextSha256'] = hash('sha256', 'other-request'),
                'code' => $decision['code'] = 'input_in_sql_error',
                'actor' => $decision['actor'] = '',
                'missing_ref' => $decision['factRefs'][] = ['id' => 'missing', 'contentSha256' => hash('sha256', 'missing')],
            };
            $c['selection'] = [$decision]; $snapshot = (new ReportPolicy())->evaluate($c);
            self::assertSame('xss_with_sql_error', $snapshot['lane'], $case);
            self::assertSame([], $snapshot['reviewedFactExclusions'], $case);
        }
        $other = $this->context(); $other['findingId'] = 'case-b'; $other['workerProof'] = $this->proof($other);
        $other['facts'] = [$this->fact($other, 'error-b', 'sql_error_observed')]; $other['selection'] = [$valid];
        self::assertSame('xss_with_sql_error', (new ReportPolicy())->evaluate($other)['lane']);
    }

    public function testNewObservationAndIndependentSqlSignalsRemainActive(): void
    {
        $c = $this->context(); $c['workerProof'] = $this->proof($c);
        $old = $this->fact($c, 'error-old', 'sql_error_observed');
        $new = $this->fact($c, 'error-new', 'sql_error_observed', 'obs-new');
        $input = $this->fact($c, 'input-a', 'input_in_sql_error');
        $structure = $this->fact($c, 'structure-a', 'sql_input_structure_indication', attributes: [
            'assessment' => 'suspected_only', 'uncertaintyChangesComposition' => true,
        ]);
        $c['facts'] = [$old, $new, $input, $structure]; $c['selection'] = [$this->rejection($c, [$old])];
        $snapshot = (new ReportPolicy())->evaluate($c);
        self::assertSame('xss_with_sqli_suspected', $snapshot['lane']);
        self::assertContains('sql_basis_incomplete', $snapshot['reasonCodes']);
        self::assertSame('suspected', array_column($snapshot['components'], 'certainty', 'code')['sqli_suspected']);
        self::assertContains('sql_error_observed', $snapshot['caseSummary']['hints']);
        self::assertContains('input_in_sql_error', $snapshot['caseSummary']['hints']);
        self::assertContains('sql_input_structure_indication', $snapshot['caseSummary']['hints']);
        self::assertContains('error-new', $snapshot['factIds']);
        self::assertNotContains('error-old', $snapshot['factIds']);
    }

    public function testFactReviewCannotRemoveWafBlockGate(): void
    {
        $c = $this->context(); $c['workerProof'] = $this->proof($c);
        $block = $this->fact($c, 'block-a', 'coverage_limited', attributes: [
            'wafBlock' => true, 'reason' => 'waf_block', 'vendor' => 'example',
            'blockEvidence' => 'access_denied', 'relevantRequestActuallyBlocked' => true,
        ]);
        $c['facts'] = [$block]; $c['selection'] = [$this->rejection($c, [$block], 'coverage_limited')];
        $snapshot = (new ReportPolicy())->evaluate($c);
        self::assertSame('hold', $snapshot['gate']);
        self::assertContains('new_waf_block_unreviewed', $snapshot['reasonCodes']);
        self::assertContains('block-a', $snapshot['factIds']);
        self::assertSame([], $snapshot['reviewedFactExclusions']);
    }

    public function testMaterializedReviewSurvivesBodylessRefreshAndKeepsImmutableEvidence(): void
    {
        $db = $this->entityManager->getConnection();
        if (!in_array('report_source_contract', $db->createSchemaManager()->listTableNames(), true)) {
            require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
            $migration = new \DoctrineMigrations\Version20261022000000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://review.fixture.invalid/?q=OPENBUG', type: 'unclassified', expectedEvidence: 'OPENBUG');
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('still_vulnerable')
            ->setFinishedAt(new \DateTimeImmutable('-1 minute'))->setRawResult(['proofContext' => WorkerProofResolver::captureContext($finding),
                'dialogEvents' => [['message' => 'observed OPENBUG', 'type' => 'alert']]]);
        $this->entityManager->persist($run); $this->entityManager->flush();
        $raw = "<pre>Microsoft JET Database Engine error: Syntax error (missing operator) in query expression 'id = OPENBUG'.</pre>";
        $input = new ObservationInput($finding->getPocUrl(), $raw, null, 'text/html', 500, $finding->getPocUrl(), null, 'OPENBUG', false, null);
        $signals = array_values(array_filter((new SqlTriageDetector())->detect($input, 'active'), static fn(array $signal): bool => $signal['code'] === 'sql_error_observed'));
        self::assertNotEmpty($signals);
        $observations = new CribrumObservationStore($this->entityManager, self::getContainer()->get(EvidenceStorageInterface::class));
        $observation = $observations->store($finding, ['schema' => 1, 'contentType' => 'text/html', 'httpStatus' => 500,
            'finalUrl' => $finding->getPocUrl(), 'rawHtml' => $raw, 'rawHtmlBytes' => strlen($raw), 'rawHtmlTruncated' => false,
            'domText' => null, 'domTextBytes' => null, 'domTextTruncated' => false, 'redirectChain' => [], 'headers' => []],
            requestContextSha256: WorkerProofResolver::requestContextSha256($finding));
        (new CribrumClaimService($this->entityManager))->attachClassification($observation,
            ['classifierVersion' => 'test-v1', 'signals' => $signals, 'suggestions' => [], 'coverage' => []]);
        $projection = new ReportProjectionService($db, new ReportPolicy(), new IntakeProfileResolver(),
            new LegacyCohortService($db), new WorkerProofResolver($db), $observations);
        $before = (int)$db->fetchOne('SELECT COUNT(*) FROM finding_report_revision WHERE finding_id=?', [$finding->getId()]);
        $facts = $projection->materializeObservationFacts($finding, $observation);
        self::assertCount(1, $facts);
        self::assertSame($before, (int)$db->fetchOne('SELECT COUNT(*) FROM finding_report_revision WHERE finding_id=?', [$finding->getId()]));
        $storedJson = $db->fetchOne('SELECT fact_json FROM finding_behavior_fact WHERE fact_id=?', [$facts[0]['factId']]);
        $decision = $this->rejection(['poc' => ['revision' => $finding->getPocRevision()],
            'requestContextSha256' => WorkerProofResolver::requestContextSha256($finding)], $facts);
        $decision['observationId'] = $observation->getId();
        $db->executeStatement('INSERT OR IGNORE INTO finding_report_context(finding_id) VALUES (?)', [$finding->getId()]);
        $db->executeStatement('UPDATE finding_report_context SET selection_json=?,basis_revision=basis_revision+1 WHERE finding_id=?',
            [json_encode([$decision], JSON_THROW_ON_ERROR), $finding->getId()]);
        $first = $projection->refresh($finding, $observation);
        self::assertSame('xss', $first['lane']);
        self::assertSame('eligible', $first['gate']);
        self::assertCount(1, $first['reviewedFactExclusions']);
        $projection->invalidate($finding, 'bodyless_review_check');
        $second = $projection->refresh($finding);
        self::assertSame('xss', $second['lane']);
        self::assertSame($first['reviewedFactExclusions'][0]['factRefs'], $second['reviewedFactExclusions'][0]['factRefs']);
        self::assertContains($facts[0]['factId'], array_column($projection->evidence($finding), 'factId'));
        self::assertSame($storedJson, $db->fetchOne('SELECT fact_json FROM finding_behavior_fact WHERE fact_id=?', [$facts[0]['factId']]));
    }
}
