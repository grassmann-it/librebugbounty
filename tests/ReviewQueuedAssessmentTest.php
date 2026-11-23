<?php

namespace App\Tests;

use App\Entity\Domain;
use App\Entity\Evidence;
use App\Entity\Finding;
use App\Service\BrowserRetestClientInterface;
use App\Service\BrowserScreenshotClientInterface;
use App\Service\CribrumClaimService;
use App\Service\EvidenceStorageInterface;
use App\Service\ReviewQueueService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

final class ReviewQueuedAssessmentTest extends DatabaseTestCase
{
    private Session $session;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
        $retest = $this->createMock(BrowserRetestClientInterface::class);
        $retest->expects(self::never())->method('retest');
        self::getContainer()->set(BrowserRetestClientInterface::class, $retest);
        $screenshots = $this->createMock(BrowserScreenshotClientInterface::class);
        $screenshots->expects(self::never())->method('capture');
        $screenshots->expects(self::never())->method('waitUntilReady');
        self::getContainer()->set(BrowserScreenshotClientInterface::class, $screenshots);
    }

    public function testPreviewIsReadOnlyAndReturnsFiveExactCardsWithoutMovingTheCurrentTrail(): void
    {
        $findings = [];
        for ($i = 0; $i < 7; ++$i) $findings[] = $this->finding('preview-'.$i);
        $html = $this->request('/review')->getContent();
        [, $form] = $this->fields($html);
        $trail = $this->session->get('studio_review_trails');
        $snapshot = $this->snapshot();
        $this->entityManager->getConnection()->executeStatement('PRAGMA query_only = ON');
        $response = $this->request('/review/preview?trail='.$form['trail_id'].'&after='.$findings[0]->getId().'&limit=5');
        $this->entityManager->getConnection()->executeStatement('PRAGMA query_only = OFF');
        self::assertSame(200, $response->getStatusCode());
        $preview = $this->json($response);
        self::assertSame(array_map(static fn (Finding $finding): string => $finding->getId(), array_slice($findings, 1, 5)), array_column($preview['cards'], 'id'));
        self::assertFalse($preview['exhausted']);
        self::assertSame($snapshot, $this->snapshot());
        self::assertSame($trail, $this->session->get('studio_review_trails'));
        foreach ($preview['cards'] as $index => $card) {
            [, $fields] = $this->fields($card['html']);
            self::assertSame($findings[$index]->getId(), $fields['after']);
            self::assertNotEmpty($fields['queued_token']);
            self::assertSame($form['trail_version'], $fields['trail_version']);
            self::assertStringNotContainsString('return=%2Freview%2Fpreview', $card['html'], 'Language switches must return to the displayed card, not the JSON endpoint.');
        }
        // The native form shown before prefetch remains valid.
        self::assertSame(303, $this->request('/review/'.$findings[0]->getId().'/assessment', 'POST', $form + ['assessment' => 'fixed'])->getStatusCode());
    }

    public function testQueuedCardsSaveWithOriginalVersionAndReplayCannotDuplicateOrUndoBack(): void
    {
        $a = $this->finding('a'); $b = $this->finding('b'); $c = $this->finding('c');
        [, $first] = $this->fields($this->request('/review')->getContent());
        $preview = $this->json($this->request('/review/preview?trail='.$first['trail_id'].'&after='.$a->getId()));
        [, $second] = $this->fields($preview['cards'][0]['html']);
        $first += ['assessment' => 'confirmed', 'operation_id' => Uuid::v7()->toRfc4122()];
        $second += ['assessment' => 'fixed', 'operation_id' => Uuid::v7()->toRfc4122()];
        self::assertSame(200, $this->queued($a, $first)->getStatusCode());
        self::assertSame(200, $this->queued($b, $second)->getStatusCode());
        self::assertSame('confirmed', $this->assessment($a));
        self::assertSame('fixed', $this->assessment($b));
        $replay = $this->json($this->queued($a, $first));
        self::assertTrue($replay['replayed']);
        self::assertCount(2, $this->rows('finding_assessment'));
        self::assertCount(2, $this->rows('review_queued_assessment_receipt'));
        $trail = $this->session->get('studio_review_trails')[$first['trail_id']];
        self::assertSame([$a->getId(), $b->getId()], array_column($trail['entries'], 'id'));
        self::assertSame(2, $trail['version']);

        $current = $this->request($preview['cards'][1]['url'])->getContent();
        [$backPath, $backFields] = $this->fields($current, 'review-back-form');
        $back = $this->request($backPath, 'POST', $backFields);
        self::assertSame(303, $back->getStatusCode());
        self::assertNull($this->assessment($b));
        self::assertNull($this->assessment($c));
        $trailAfterBack = $this->session->get('studio_review_trails');
        self::assertTrue($this->json($this->queued($b, $second))['replayed']);
        self::assertSame($trailAfterBack, $this->session->get('studio_review_trails'));
        self::assertNull($this->assessment($b));
        self::assertCount(2, $this->rows('finding_assessment'));
        self::assertCount(1, $this->rows('finding_assessment_cancellation'));
        self::assertSame(409, $this->queued($b, array_replace($second, ['assessment' => 'confirmed']))->getStatusCode());
    }

    public function testStaleFailureRemainsEligibleAndDoesNotPreventNextQueuedSave(): void
    {
        $a = $this->finding('changed'); $b = $this->finding('unchanged');
        [, $first] = $this->fields($this->request('/review')->getContent());
        $preview = $this->json($this->request('/review/preview?trail='.$first['trail_id'].'&after='.$a->getId()));
        [, $second] = $this->fields($preview['cards'][0]['html']);
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET private_notes = ? WHERE id = ?', ['concurrent edit', $a->getId()]);
        $failed = $this->queued($a, $first + ['assessment' => 'confirmed', 'operation_id' => Uuid::v7()->toRfc4122()]);
        self::assertSame(409, $failed->getStatusCode());
        self::assertSame('failed', $this->json($failed)['outcome']);
        self::assertNull($this->assessment($a));
        self::assertSame(200, $this->queued($b, $second + ['assessment' => 'fixed', 'operation_id' => Uuid::v7()->toRfc4122()])->getStatusCode());
        self::assertSame($a->getId(), self::getContainer()->get(ReviewQueueService::class)->get([])->detail->finding->getId());
        self::assertCount(1, $this->rows('review_queued_assessment_receipt'));
        $trail = $this->session->get('studio_review_trails')[$first['trail_id']];
        self::assertSame([$b->getId()], array_column($trail['entries'], 'id'));
    }

    public function testReceiptWriteFailureRollsBackAssessmentAndDoesNotLeakStorageError(): void
    {
        $finding = $this->finding('atomic');
        [, $fields] = $this->fields($this->request('/review')->getContent());
        $snapshot = $this->snapshot();
        $this->entityManager->getConnection()->executeStatement("CREATE TRIGGER queued_receipt_fixture_failure BEFORE INSERT ON review_queued_assessment_receipt BEGIN SELECT RAISE(ABORT, 'private fixture storage error'); END");
        try {
            $response = $this->queued($finding, $fields + ['assessment' => 'confirmed', 'operation_id' => Uuid::v7()->toRfc4122()]);
            self::assertSame(500, $response->getStatusCode());
            self::assertStringNotContainsString('private fixture', $response->getContent());
            self::assertSame($snapshot, $this->snapshot());
            self::assertNull($this->assessment($finding));
        } finally {
            $this->entityManager->getConnection()->executeStatement('DROP TRIGGER queued_receipt_fixture_failure');
        }
    }

    public function testSignedContextForeignEvidenceAndNativeNavigationBoundaries(): void
    {
        $a = $this->finding('a'); $b = $this->finding('b');
        [, $fields] = $this->fields($this->request('/review')->getContent());
        $base = $fields + ['assessment' => 'fixed', 'operation_id' => Uuid::v7()->toRfc4122()];
        self::assertSame(403, $this->queued($a, array_replace($base, ['queued_card' => $fields['queued_card'].'a']))->getStatusCode());
        $foreign = $this->entityManager->getConnection()->fetchOne('SELECT id FROM evidence WHERE finding_id = ?', [$b->getId()]);
        self::assertSame(400, $this->queued($a, array_replace($base, ['displayed_evidence_id' => $foreign]))->getStatusCode());
        $preview = $this->json($this->request('/review/preview?trail='.$fields['trail_id'].'&after='.$a->getId()));
        [, $second] = $this->fields($preview['cards'][0]['html']);
        self::assertSame(303, $this->request('/review/'.$a->getId().'/assessment', 'POST', $base)->getStatusCode());
        self::assertSame(409, $this->queued($b, $second + ['assessment' => 'fixed', 'operation_id' => Uuid::v7()->toRfc4122()])->getStatusCode());
        self::assertNull($this->assessment($b));
        self::assertCount(0, $this->rows('review_queued_assessment_receipt'));
    }

    public function testMissingReceiptMigrationKeepsNativeFormsAvailable(): void
    {
        $finding = $this->finding('native fallback');
        $this->entityManager->getConnection()->executeStatement('DROP TABLE review_queued_assessment_receipt');
        [, $fields] = $this->fields($this->request('/review')->getContent());
        self::assertArrayNotHasKey('queued_card', $fields);
        self::assertSame(503, $this->request('/review/preview?trail='.$fields['trail_id'])->getStatusCode());
        self::assertSame(303, $this->request('/review/'.$finding->getId().'/assessment', 'POST', $fields + ['assessment' => 'fixed'])->getStatusCode());
    }

    public function testReloadInvalidatesPrefetchedCardsEvenWhenTrailVersionHasNotAdvanced(): void
    {
        $a = $this->finding('same version a'); $b = $this->finding('same version b');
        [, $initial] = $this->fields($this->request('/review')->getContent());
        $preview = $this->json($this->request('/review/preview?trail='.$initial['trail_id'].'&after='.$a->getId()));
        [, $cached] = $this->fields($preview['cards'][0]['html']);
        [, $reloaded] = $this->fields($this->request('/review?trail='.$initial['trail_id'])->getContent());
        self::assertSame($initial['trail_version'], $reloaded['trail_version']);
        self::assertSame(409, $this->queued($b, $cached + ['assessment' => 'fixed', 'operation_id' => Uuid::v7()->toRfc4122()])->getStatusCode());
        self::assertNull($this->assessment($b));
        self::assertSame(200, $this->queued($a, $reloaded + ['assessment' => 'fixed', 'operation_id' => Uuid::v7()->toRfc4122()])->getStatusCode());
    }

    public function testQueuedClaimSelectionPreservesCertaintyAndImmutableHistoryOnReceiptReplay(): void
    {
        $finding = $this->finding('queued selected claims');
        $claims = self::getContainer()->get(CribrumClaimService::class);
        $deselected = $claims->apply($finding, 'local_file_inclusion', 'suspected', true, 'manual', ['fixture/manual']);
        $selected = $claims->apply($finding, 'information_disclosure', 'observed', false, 'manual');
        $unselected = $claims->apply($finding, 'error_disclosure', 'observed', false, 'manual');
        $classifier = $claims->apply($finding, 'sql_injection', 'suspected', false, 'classifier');
        [, $fields] = $this->fields($this->request('/review')->getContent());
        $beforeRevisions = $this->rows('finding_claim_revision');
        $beforeClaims = array_column($this->rows('finding_claim'), null, 'claim');
        $fields += ['assessment' => 'confirmed', 'operation_id' => Uuid::v7()->toRfc4122(), 'claim_selection' => [
            'local_file_inclusion' => ['revision' => (string) $deselected->revision],
            'information_disclosure' => ['revision' => (string) $selected->revision, 'selected' => '1'],
            'error_disclosure' => ['revision' => (string) $unselected->revision],
            'sql_injection' => ['revision' => (string) $classifier->revision],
        ]];
        $response = $this->queued($finding, $fields);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $savedClaims = array_column($this->rows('finding_claim'), null, 'claim');
        self::assertSame('confirmed', $this->assessment($finding));
        self::assertSame('rejected', $savedClaims['local_file_inclusion']['state']);
        self::assertSame(0, $savedClaims['local_file_inclusion']['report_selected']);
        self::assertSame(2, $savedClaims['local_file_inclusion']['revision']);
        self::assertSame('observed', $savedClaims['information_disclosure']['state'], 'Selecting a claim must not silently promote its certainty.');
        self::assertSame(1, $savedClaims['information_disclosure']['report_selected']);
        self::assertSame('manual', $savedClaims['information_disclosure']['source']);
        self::assertSame($beforeClaims['error_disclosure'], $savedClaims['error_disclosure']);
        self::assertSame($beforeClaims['sql_injection'], $savedClaims['sql_injection'], 'A case verdict must not adopt an unchanged classifier claim.');
        $savedRevisions = $this->rows('finding_claim_revision');
        self::assertCount(count($beforeRevisions) + 2, $savedRevisions);
        self::assertSame($beforeRevisions, array_slice($savedRevisions, 0, count($beforeRevisions)), 'Earlier decision revisions must remain byte-for-byte unchanged.');
        $snapshot = $this->snapshot();
        self::assertTrue($this->json($this->queued($finding, $fields))['replayed']);
        self::assertSame($snapshot, $this->snapshot(), 'Receipt replay must not append another claim revision or assessment.');
        self::assertSame($savedRevisions, $this->rows('finding_claim_revision'));
        self::assertCount(1, $this->rows('review_queued_assessment_receipt'));
    }

    public function testQueuedStaleClaimRevisionRejectsAssessmentAndReceiptAtomically(): void
    {
        $finding = $this->finding('queued stale revision');
        $claims = self::getContainer()->get(CribrumClaimService::class);
        $claim = $claims->apply($finding, 'information_disclosure', 'observed', false, 'manual');
        [, $fields] = $this->fields($this->request('/review')->getContent());
        $before = $this->snapshot();
        $trail = $this->session->get('studio_review_trails');
        $fields += ['assessment' => 'confirmed', 'operation_id' => Uuid::v7()->toRfc4122(), 'claim_selection' => [
            'information_disclosure' => ['revision' => (string) ($claim->revision + 1), 'selected' => '1'],
        ]];
        // The case fingerprint is still current: this reaches the independent
        // claim-revision validation rather than failing only its outer fence.
        $response = $this->queued($finding, $fields);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Schwachstellen-Revision', $this->json($response)['message']);
        self::assertSame('failed', $this->json($response)['outcome']);
        self::assertSame($before, $this->snapshot());
        self::assertSame($trail, $this->session->get('studio_review_trails'));
        self::assertNull($this->assessment($finding));
        self::assertCount(0, $this->rows('review_queued_assessment_receipt'));
        // A rejected operation has no receipt and may be corrected explicitly.
        $fields['claim_selection']['information_disclosure']['revision'] = (string) $claim->revision;
        self::assertSame(200, $this->queued($finding, $fields)->getStatusCode());
        self::assertCount(1, $this->rows('review_queued_assessment_receipt'));
        self::assertCount(2, $this->rows('finding_claim_revision'));
    }

    private function finding(string $label): Finding
    {
        $domain = $this->entityManager->getRepository(Domain::class)->findOneBy(['hostname' => 'localhost']);
        if (!$domain instanceof Domain) {
            $domain = (new Domain())->setHostname('localhost')->setScheme('http');
            $this->entityManager->persist($domain);
        }
        $finding = (new Finding())->setDomain($domain)->setTitle($label)->setType('other')->setSeverity('medium')
            ->setUrl('http://localhost/fixture/'.rawurlencode($label))->setMethod('GET')->setStatus('new');
        $this->entityManager->persist($finding);
        $this->entityManager->flush();
        $created = sprintf('2026-01-%02d 12:00:00', ++$this->sequence);
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET created_at = ? WHERE id = ?', [$created, $finding->getId()]);
        $this->entityManager->refresh($finding);
        $stored = self::getContainer()->get(EvidenceStorageInterface::class)->storeContents($finding, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG1sAAAAASUVORK5CYII=', true), 'fixture.png');
        $image = (new Evidence())->setFinding($finding)->setKind('screenshot')->setFilePath($stored->relativePath);
        $this->entityManager->persist($image);
        $this->entityManager->flush();
        return $finding;
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }

    private function fields(string $html, string $id = 'review-assessment-form'): array
    {
        $document = new \DOMDocument(); @$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $form = $xpath->query('//form[@id="'.$id.'"]')->item(0);
        self::assertNotNull($form);
        $fields = [];
        foreach ($xpath->query('.//input[@type="hidden"]', $form) as $field) $fields[$field->getAttribute('name')] = $field->getAttribute('value');
        return [$form->getAttribute('action'), $fields];
    }

    private function queued(Finding $finding, array $fields): Response { return $this->request('/review/'.$finding->getId().'/queued-assessment', 'POST', $fields); }
    private function json(Response $response): array { return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR); }
    private function assessment(Finding $finding): mixed { return $this->entityManager->getConnection()->fetchOne('SELECT manual_assessment FROM finding WHERE id = ?', [$finding->getId()]); }
    private function rows(string $table): array { return $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid'); }
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['finding', 'finding_assessment', 'finding_claim', 'finding_claim_revision', 'cribrum_review_selection', 'cribrum_review_selection_event', 'cribrum_rule_review_hint', 'evidence', 'retest_run', 'screenshot_job', 'review_queued_assessment_receipt'] as $table) $snapshot[$table] = $this->rows($table);
        return $snapshot;
    }
}
