<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Finding;
use App\Cribrum\ReportPolicy;
use App\Service\IntakeProfileResolver;
use App\Service\LegacyCohortService;
use App\Service\WorkerProofResolver;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class IntakeProofContractTest extends TestCase
{
    public function testIntakeNeverInfersXssFromTypePayloadOrMarker(): void
    {
        $finding = (new Finding())->setUrl('https://example.invalid/?x=%3Cscript%3E')->setType('reflected_xss')->setPayload('<script>abc</script>')->setExpectedEvidence('abc');
        $profile = (new IntakeProfileResolver())->resolve($finding);
        self::assertSame([], $profile['declaredClaims']);
        self::assertSame('unknown', $profile['payloadKind']);
        self::assertNull($profile['payloadSource']);
        self::assertNull($profile['legacyCohortId']);
    }

    public function testMarkerPolicyPreservesExactUnicodeAndSingleEventBoundaries(): void
    {
        self::assertTrue(WorkerProofResolver::markerMatches('aβc', 'x aβc y'));
        foreach ([['aaa', 'aaa'], ['ab', 'ab'], ['ABC', 'abc'], ['ébc', "e\u{0301}bc"], ['abc', 'ab']] as [$marker, $text]) {
            self::assertFalse(WorkerProofResolver::markerMatches($marker, $text));
        }
    }

    public function testIndependentProofRequiresCapturedContextAndNativeSingleEvent(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE retest_run (id TEXT, finding_id TEXT, finished_at TEXT, raw_result TEXT)');
        $finding = (new Finding())->setUrl("https://example.invalid/?x=%2f+%20\n")->setExpectedEvidence('abc');
        $resolver = new WorkerProofResolver($db);
        $raw = ['proofContext' => WorkerProofResolver::captureContext($finding), 'dialogEvents' => [['message' => 'ab'], ['message' => 'c']]];
        $write = function (array $raw) use ($db, $finding): void {
            $db->executeStatement('DELETE FROM retest_run');
            $db->insert('retest_run', ['id' => 'run', 'finding_id' => $finding->getId(), 'finished_at' => '2026-10-22T12:00:00Z', 'raw_result' => json_encode($raw, JSON_THROW_ON_ERROR)]);
        };
        $write($raw);
        self::assertFalse($resolver->resolve($finding, 'source')['verified']);
        $raw['dialogEvents'] = [['message' => 'abc']];
        $write($raw);
        $proof = $resolver->resolve($finding, 'source');
        self::assertTrue($proof['verified']);
        self::assertSame(hash('sha256', $finding->getPocUrl()), $proof['pocSha256']);
        self::assertSame($finding->getPocUrl(), $proof['sourcePageUrl']);
        $raw['hookedDialogEvents'] = $raw['dialogEvents'];
        unset($raw['dialogEvents']);
        $write($raw);
        self::assertFalse($resolver->resolve($finding, 'source')['verified']);
        $raw['dialogEvents'] = [['message' => 'abc']];
        unset($raw['proofContext']);
        $write($raw);
        self::assertFalse($resolver->resolve($finding, 'source')['verified']);
        $raw['proofContext'] = WorkerProofResolver::captureContext($finding);
        $finding->setExpectedEvidence('xyz');
        $write($raw);
        self::assertFalse($resolver->resolve($finding, 'source')['verified']);
    }

    public function testRequestContextChangesInvalidateNativeAndReviewedProofs(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE retest_run (id TEXT, finding_id TEXT, finished_at TEXT, raw_result TEXT)');
        $db->executeStatement('CREATE TABLE finding_report_context (finding_id TEXT, acknowledgements_json TEXT)');
        $finding = (new Finding())->setUrl('https://example.invalid/?x=%2F+')
            ->setMethod('POST')->setRequestParams(['body' => 'é/+'])
            ->setPayload('<script>abc</script>')->setExpectedEvidence('abc');
        $resolver = new WorkerProofResolver($db);
        $context = WorkerProofResolver::captureContext($finding);
        self::assertSame(hash('sha256', json_encode(['POST', ['body' => 'é/+'], '<script>abc</script>', 'abc'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $context['requestContextSha256']);
        foreach (['dialogEvents', 'hookedDialogEvents'] as $eventKey) {
            $db->executeStatement('DELETE FROM retest_run');
            $db->executeStatement('DELETE FROM finding_report_context');
            $raw = ['proofContext' => $context, $eventKey => [['message' => 'abc']]];
            $db->insert('retest_run', ['id' => 'run', 'finding_id' => $finding->getId(), 'finished_at' => '2026-10-23T12:00:00Z', 'raw_result' => json_encode($raw, JSON_THROW_ON_ERROR)]);
            $candidate = $resolver->candidates($finding, 'source')[0];
            $review = ['kind' => 'worker_proof', 'proofId' => $candidate['proofId'], 'contentSha256' => $candidate['contentSha256'],
                'actor' => 'reviewer', 'reason' => 'Reviewed captured event', 'sourceRef' => 'run', 'explicit' => true];
            $db->insert('finding_report_context', ['finding_id' => $finding->getId(), 'acknowledgements_json' => json_encode([$review], JSON_THROW_ON_ERROR)]);
            self::assertTrue($resolver->resolve($finding, 'source')['verified']);
            self::assertSame($context['requestContextSha256'], $resolver->resolve($finding, 'source')['requestContextSha256']);
            foreach (['method', 'body', 'payload'] as $change) {
                $changed = clone $finding;
                match ($change) {
                    'method' => $changed->setMethod('GET'),
                    'body' => $changed->setRequestParams(['body' => 'different']),
                    'payload' => $changed->setPayload('<img onerror=abc>'),
                };
                $proof = $resolver->resolve($changed, 'source');
                self::assertFalse($proof['verified'], $eventKey.' '.$change);
                self::assertSame('uncertain', $proof['attribution']);
                self::assertSame($context['requestContextSha256'], $proof['requestContextSha256']);
            }
            unset($raw['proofContext']['requestContextSha256']);
            $db->executeStatement('UPDATE retest_run SET raw_result=?', [json_encode($raw, JSON_THROW_ON_ERROR)]);
            self::assertFalse($resolver->resolve($finding, 'source')['verified']);
            self::assertNull($resolver->resolve($finding, 'source')['requestContextSha256']);
        }
    }

    public function testAuthorizedManifestHasExactFrozenMembershipAndRejectsChanges(): void
    {
        if (!is_file(dirname(__DIR__).'/.architecture-sparring/behavior-tags-legacy-cohort-v1.json')) self::markTestSkipped('Private authorized cohort manifest is not distributed.');
        $manifest = json_decode(file_get_contents(dirname(__DIR__).'/.architecture-sparring/behavior-tags-legacy-cohort-v1.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(6968, LegacyCohortService::validateManifest($manifest));
        $manifest['members'][0]['findingId'] = 'new-id';
        $this->expectException(\InvalidArgumentException::class);
        LegacyCohortService::validateManifest($manifest);
    }

    public function testExplicitImportPinsBaselineAndDoesNotEnrollAnotherId(): void
    {
        if (!is_file(dirname(__DIR__).'/.architecture-sparring/behavior-tags-legacy-cohort-v1.json')) self::markTestSkipped('Private authorized cohort manifest is not distributed.');
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE report_source_contract (source_instance_id TEXT, legacy_cohort_id TEXT, legacy_cohort_digest TEXT)');
        $db->insert('report_source_contract', ['source_instance_id' => 'test-source']);
        $db->executeStatement('CREATE TABLE finding (id TEXT, poc_revision INT, poc_url TEXT, url TEXT)');
        $db->executeStatement('CREATE TABLE finding_report_context (finding_id TEXT PRIMARY KEY, basis_revision INT, legacy_basis_json TEXT, legacy_basis_sha256 TEXT)');
        $finding = (new Finding())->setUrl('https://example.invalid');
        $db->insert('finding', ['id' => $finding->getId(), 'poc_revision' => 0, 'url' => $finding->getUrl()]);
        $db->insert('finding_report_context', ['finding_id' => $finding->getId(), 'basis_revision' => 1]);
        $service = new LegacyCohortService($db);
        self::assertNull($service->resolve($finding, 'test-source'));
        $result = $service->importManifest(dirname(__DIR__).'/.architecture-sparring/behavior-tags-legacy-cohort-v1.json', 'test-source');
        self::assertSame(0, $result['imported']);
        self::assertCount(6968, $result['missing']);
        self::assertNull($service->resolve($finding, 'test-source'));
        $manifest = json_decode(file_get_contents(dirname(__DIR__).'/.architecture-sparring/behavior-tags-legacy-cohort-v1.json'), true);
        $member = $manifest['members'][0];
        $known = (new Finding())->setUrl('https://changed.invalid/');
        (new \ReflectionProperty(Finding::class, 'id'))->setValue($known, $member['findingId']);
        $db->insert('finding', ['id' => $known->getId(), 'poc_revision' => 0, 'url' => $known->getUrl()]);
        $result = $service->importManifest(dirname(__DIR__).'/.architecture-sparring/behavior-tags-legacy-cohort-v1.json', 'test-source');
        self::assertSame(1, $result['imported']);
        self::assertSame([$known->getId()], $result['pocChanged']);
        $legacy = $service->resolve($known, 'test-source');
        self::assertTrue($legacy['verified']);
        self::assertFalse($legacy['pocMatches']);
        $policyContext = [
            'findingId' => $known->getId(), 'sourceInstanceId' => 'test-source',
            'basisRevision' => 1, 'decisionRevision' => 1,
            'poc' => ['intakeUrl' => $known->getUrl(), 'effectiveUrl' => $known->getPocUrl(),
                'revision' => $known->getPocRevision(), 'sha256' => hash('sha256', $known->getPocUrl())],
            'legacyBasis' => $legacy, 'claims' => [], 'facts' => [],
        ];
        $decision = (new ReportPolicy())->evaluate($policyContext);
        self::assertSame('hold', $decision['gate']);
        self::assertContains('poc_changed', $decision['reasonCodes']);
        self::assertNotContains('legacy_xss_basis', $decision['reasonCodes']);
        self::assertSame(LegacyCohortService::COHORT_ID, $db->fetchOne('SELECT legacy_cohort_id FROM report_source_contract'));
        self::assertSame(LegacyCohortService::DIGEST, $db->fetchOne('SELECT legacy_cohort_digest FROM report_source_contract'));
        self::assertSame($member['pocSha256'], $service->resolve($known, 'test-source')['baselinePocSha256']);
        self::assertNull($service->resolve($known, 'other-source'));
        $basis = $db->fetchOne('SELECT basis_revision FROM finding_report_context WHERE finding_id=?', [$known->getId()]);
        $service->importManifest(dirname(__DIR__).'/.architecture-sparring/behavior-tags-legacy-cohort-v1.json', 'test-source');
        self::assertSame($basis, $db->fetchOne('SELECT basis_revision FROM finding_report_context WHERE finding_id=?', [$known->getId()]));
    }
}
