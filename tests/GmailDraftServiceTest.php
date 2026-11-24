<?php

namespace App\Tests;

use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Service\ContactRouteService;
use App\Service\EmailDraftClientInterface;
use App\Service\EmailPreparationException;
use App\Service\EmailPreparationService;
use App\Service\FindingService;
use App\Service\GmailDraftGatewayInterface;
use App\Service\GmailDraftService;
use App\Service\ReportProjectionService;
use App\Service\WorkerProofResolver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

final class GmailDraftServiceTest extends DatabaseTestCase
{
    private const ACCOUNT = 'reports@example.org';
    private const POC = 'https://gmail.fixture.invalid/?q=%22%3E%3Cscript%3Ealert(OPENBUG)%3C%2Fscript%3E&same=a+b&same=%2b#ExactCASE';
    private ReportProjectionService $reports;
    private ContactRouteService $contacts;
    private EmailPreparationService $emails;
    private GmailDraftTestGateway $gateway;
    private GmailDraftService $drafts;
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp(); $db = $this->entityManager->getConnection();
        $db->executeStatement('DROP TABLE IF EXISTS gmail_draft_attempt');
        foreach (['Version20261022000000', 'Version20261124000000'] as $version) {
            require_once dirname(__DIR__).'/migrations/'.$version.'.php';
            $class = 'DoctrineMigrations\\'.$version;
            $migration = new $class($db, new NullLogger()); $migration->up(new Schema());
            foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement());
        }
        $this->reports = self::getContainer()->get(ReportProjectionService::class);
        $this->contacts = self::getContainer()->get(ContactRouteService::class);
        $this->emails = new EmailPreparationService($db, $this->reports, $this->contacts, new GmailDraftTestEmailClient());
        $this->gateway = new GmailDraftTestGateway();
        $this->drafts = new GmailDraftService($db, $this->emails, $this->gateway, self::ACCOUNT);
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testReviewedDraftReservesDurablyBeforeTheGatewayAndDoesNotChangeCaseStatus(): void
    {
        $finding = $this->finding(); $input = $this->input($finding); $before = $this->sourceState();
        self::assertTrue($this->drafts->state($finding)['canCreate']);
        $this->gateway->duringSubmit = function (array $request): void {
            $db = $this->entityManager->getConnection();
            self::assertFalse($db->isTransactionActive(), 'Never retain a database transaction across Gmail I/O.');
            $reader = DriverManager::getConnection($db->getParams());
            try {
                $rows = $reader->fetchAllAssociative('SELECT * FROM gmail_draft_attempt');
                self::assertCount(1, $rows, 'The reservation must be committed and visible to another connection.');
                self::assertSame('pending', $rows[0]['state']);
            } finally { $reader->close(); }
            self::assertSame(1, $request['version']);
            self::assertSame('create', $request['operation']);
            self::assertSame(self::ACCOUNT, $request['account']);
            self::assertSame('security@example.org', $request['recipient']);
            self::assertSame(hash('sha256', self::ACCOUNT."\0".$request['recipient']."\0".$request['subject']."\0".$request['body']), $request['content_sha256']);
            self::assertStringContainsString(self::POC, $request['body']);
        };
        $receipt = $this->drafts->create($finding, $input);
        self::assertSame('verified', $receipt['state']);
        self::assertSame($input['attemptId'], $receipt['attemptId']);
        self::assertSame('r1234567890123456789', $receipt['draftId']);
        self::assertSame('18abcdef01234567', $receipt['messageId']);
        self::assertFalse($receipt['stale']);
        self::assertFalse($this->drafts->state($finding)['canCreate']);
        self::assertSame($before, $this->sourceState());
        self::assertCount(1, $this->gateway->submissions);
    }

    public function testExactReplayUsesTheDurableReceiptAndNewOrChangedAttemptsCannotCreateDuplicates(): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        $first = $this->drafts->create($finding, $input);
        $replay = $this->drafts->create($finding, $input);
        self::assertSame($first['draftId'], $replay['draftId']);
        self::assertCount(1, $this->gateway->submissions);
        foreach ([array_replace($input, ['attemptId' => Uuid::v7()->toRfc4122()]), array_replace($input, ['body' => $input['body']."\nChanged after review"])] as $changed) {
            try { $this->drafts->create($finding, $changed); self::fail('A second or changed attempt reached Gmail.'); }
            catch (EmailPreparationException $error) { self::assertSame(409, $error->httpStatus); }
        }
        self::assertCount(1, $this->gateway->submissions);
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM gmail_draft_attempt'));
    }

    public function testUnknownCreateOutcomeStaysReservedAndOnlyKnownAttemptReconciliationCanResolveIt(): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        $this->gateway->duringSubmit = static fn () => throw new \RuntimeException('PRIVATE_GATEWAY_TOKEN_OR_BODY');
        $receipt = $this->drafts->create($finding, $input);
        self::assertSame('uncertain', $receipt['state']);
        self::assertStringNotContainsString('PRIVATE_GATEWAY_TOKEN_OR_BODY', json_encode($receipt, JSON_THROW_ON_ERROR));
        self::assertFalse($this->drafts->state($finding)['canCreate']);
        self::assertSame('uncertain', $this->drafts->create($finding, $input)['state']);
        self::assertCount(1, $this->gateway->submissions);
        try { $this->drafts->create($finding, array_replace($input, ['attemptId' => Uuid::v7()->toRfc4122()])); self::fail('Unknown result allowed a new create attempt.'); }
        catch (EmailPreparationException $error) { self::assertSame(409, $error->httpStatus); }
        $resolved = $this->drafts->reconcile($finding, $input['attemptId']);
        self::assertSame('verified', $resolved['state']);
        self::assertCount(1, $this->gateway->submissions);
        self::assertCount(1, $this->gateway->reconciliations);
        $request = $this->gateway->reconciliations[0];
        self::assertSame('reconcile', $request['operation']);
        self::assertSame($input['attemptId'], $request['attempt_id']);
        foreach (['body', 'subject', 'recipient', 'fingerprint'] as $field) self::assertArrayNotHasKey($field, $request);
        try { $this->drafts->reconcile($finding, Uuid::v7()->toRfc4122()); self::fail('Unknown receipt reconciled.'); }
        catch (EmailPreparationException) { self::assertCount(1, $this->gateway->reconciliations); }
    }

    public function testKnownPreDispatchRejectionKeepsItsReceiptAndAllowsOnlyAnExplicitNewAttempt(): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        $this->gateway->replyOverrides = ['ok' => false, 'state' => 'rejected', 'safe_to_retry' => true,
            'draft_id' => null, 'message_id' => null, 'error_code' => 'profile_not_configured'];
        $rejected = $this->drafts->create($finding, $input);
        self::assertSame('rejected', $rejected['state']);
        self::assertTrue($this->drafts->state($finding)['canCreate']);
        self::assertSame('rejected', $this->drafts->create($finding, $input)['state']);
        self::assertCount(1, $this->gateway->submissions, 'Replaying the failed attempt must not dispatch again.');
        $this->gateway->replyOverrides = [];
        $replacement = $this->drafts->create($finding, array_replace($input, ['attemptId' => Uuid::v7()->toRfc4122()]));
        self::assertSame('verified', $replacement['state']);
        self::assertCount(2, $this->gateway->submissions);
        self::assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM gmail_draft_attempt'));
        self::assertFalse($this->drafts->state($finding)['canCreate']);
    }

    public function testSuccessfulCreationKeepsItsReceiptWhenTheCaseChangesDuringGatewayWork(): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        $this->gateway->duringSubmit = function () use ($finding): void {
            $this->entityManager->getConnection()->executeStatement('UPDATE finding SET expected_evidence=? WHERE id=?', ['changed-marker', $finding->getId()]);
        };
        $receipt = $this->drafts->create($finding, $input);
        self::assertSame('verified', $receipt['state']);
        self::assertSame('r1234567890123456789', $receipt['draftId']);
        self::assertTrue($receipt['stale']);
        self::assertFalse($this->drafts->state($finding)['canCreate']);
        self::assertCount(1, $this->gateway->submissions);
    }

    public function testChangedExpectedAccountCannotRetargetTheReviewedDraft(): void
    {
        $finding = $this->finding(); $input = array_replace($this->input($finding), ['expectedAccount' => 'other@example.org']);
        try { $this->drafts->create($finding, $input); self::fail('Changed Gmail account accepted.'); }
        catch (EmailPreparationException $error) {
            self::assertSame(409, $error->httpStatus);
            self::assertCount(0, $this->gateway->submissions);
            self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM gmail_draft_attempt'));
        }
    }

    public function testReconciliationCannotRetargetTheKnownGmailDraft(): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        $created = $this->drafts->create($finding, $input);
        $this->gateway->replyOverrides = ['draft_id' => 'r9999999999999999999', 'message_id' => '19abcdef01234567'];
        $receipt = $this->drafts->reconcile($finding, $input['attemptId']);
        self::assertNotSame('verified', $receipt['state']);
        self::assertSame($created['draftId'], $receipt['draftId']);
        self::assertSame($created['messageId'], $receipt['messageId']);
        self::assertFalse($receipt['canCreate']);
        self::assertCount(1, $this->gateway->submissions);
        self::assertCount(1, $this->gateway->reconciliations);
    }

    public function testVerifiedReadbackMayRefreshTheMessageInsideTheSameGmailDraft(): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        $created = $this->drafts->create($finding, $input);
        $this->gateway->replyOverrides = ['message_id' => '19abcdef01234567'];
        $verified = $this->drafts->reconcile($finding, $input['attemptId']);
        self::assertSame('verified', $verified['state']);
        self::assertSame($created['draftId'], $verified['draftId']);
        self::assertSame('19abcdef01234567', $verified['messageId']);
        $this->gateway->replyOverrides = ['ok' => false, 'state' => 'verification_failed', 'message_id' => '20abcdef01234567'];
        $failed = $this->drafts->reconcile($finding, $input['attemptId']);
        self::assertNotSame('verified', $failed['state']);
        self::assertSame($verified['draftId'], $failed['draftId']);
        self::assertSame($verified['messageId'], $failed['messageId'], 'Unverified readback cannot replace the last verified message identity.');
        self::assertCount(1, $this->gateway->submissions);
    }

    public function testLateUncertainReadbackCannotEraseIdsFromANewerVerificationFailure(): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        $this->gateway->replyOverrides = ['ok' => false, 'state' => 'pending', 'draft_id' => null, 'message_id' => null];
        self::assertSame('pending', $this->drafts->create($finding, $input)['state']);
        $this->gateway->duringReconcile = function () use ($finding, $input): void {
            $this->gateway->duringReconcile = null;
            $this->gateway->replyOverrides = ['ok' => false, 'state' => 'verification_failed'];
            $newer = $this->drafts->reconcile($finding, $input['attemptId']);
            self::assertSame('verification_failed', $newer['state']);
            self::assertSame('r1234567890123456789', $newer['draftId']);
            $this->gateway->replyOverrides = ['ok' => false, 'state' => 'uncertain', 'draft_id' => null, 'message_id' => null];
        };
        $older = $this->drafts->reconcile($finding, $input['attemptId']);
        self::assertSame('verification_failed', $older['state']);
        self::assertSame('r1234567890123456789', $older['draftId']);
        self::assertSame('18abcdef01234567', $older['messageId']);
        self::assertFalse($older['canCreate']);
        self::assertCount(1, $this->gateway->submissions);
        self::assertCount(2, $this->gateway->reconciliations);
    }

    public function testLateUncertainReadbackCannotDowngradeANewerSafeRejection(): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        $this->gateway->replyOverrides = ['ok' => false, 'state' => 'pending', 'draft_id' => null, 'message_id' => null];
        self::assertSame('pending', $this->drafts->create($finding, $input)['state']);
        $this->gateway->duringReconcile = function () use ($input): void {
            $this->entityManager->getConnection()->executeStatement("UPDATE gmail_draft_attempt SET state='rejected', safe_to_retry=1, error_code='profile_not_configured', receipt_revision=receipt_revision+1 WHERE attempt_id=?", [$input['attemptId']]);
            $this->gateway->replyOverrides = ['ok' => false, 'state' => 'uncertain', 'draft_id' => null, 'message_id' => null];
        };
        $receipt = $this->drafts->reconcile($finding, $input['attemptId']);
        self::assertSame('rejected', $receipt['state']);
        self::assertTrue($receipt['safeToRetry']);
        self::assertTrue($receipt['canCreate']);
        self::assertCount(1, $this->gateway->submissions);
    }

    public function testLateVerifiedReadbackCannotOverrideANewerVerificationFailure(): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        self::assertSame('verified', $this->drafts->create($finding, $input)['state']);
        $this->gateway->duringReconcile = function () use ($finding, $input): void {
            $this->gateway->duringReconcile = null;
            $this->gateway->replyOverrides = ['ok' => false, 'state' => 'verification_failed'];
            self::assertSame('verification_failed', $this->drafts->reconcile($finding, $input['attemptId'])['state']);
            $this->gateway->replyOverrides = [];
        };
        $receipt = $this->drafts->reconcile($finding, $input['attemptId']);
        self::assertSame('verification_failed', $receipt['state']);
        self::assertSame('r1234567890123456789', $receipt['draftId']);
        self::assertSame('18abcdef01234567', $receipt['messageId']);
        self::assertFalse($receipt['canCreate']);
        self::assertCount(1, $this->gateway->submissions);
        self::assertCount(2, $this->gateway->reconciliations);
    }

    public function testConcurrentConflictingDraftCannotAttachItsMessageToTheRecordedDraft(): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        $this->gateway->replyOverrides = ['ok' => false, 'state' => 'pending', 'draft_id' => null, 'message_id' => null];
        self::assertSame('pending', $this->drafts->create($finding, $input)['state']);
        $this->gateway->replyOverrides = [];
        $recordedDraft = 'r9876543210987654321';
        $this->gateway->duringReconcile = function () use ($input, $recordedDraft): void {
            $this->entityManager->getConnection()->executeStatement("UPDATE gmail_draft_attempt SET state='verification_failed', draft_id=?, message_id=NULL, receipt_revision=receipt_revision+1 WHERE attempt_id=?", [$recordedDraft, $input['attemptId']]);
        };
        $receipt = $this->drafts->reconcile($finding, $input['attemptId']);
        self::assertSame('verification_failed', $receipt['state']);
        self::assertSame($recordedDraft, $receipt['draftId']);
        self::assertNull($receipt['messageId'], 'An ID from a different draft must not be paired with the recorded draft.');
        self::assertFalse($receipt['canCreate']);
        self::assertCount(1, $this->gateway->submissions);
    }

    #[DataProvider('invalidDraftInputs')]
    public function testUnreviewedMalformedAndPocModifiedDraftsNeverReserveOrCallGmail(array $overrides): void
    {
        $finding = $this->finding(); $input = array_replace($this->input($finding), $overrides);
        try { $this->drafts->create($finding, $input); self::fail('Invalid draft accepted.'); }
        catch (EmailPreparationException) {
            self::assertCount(0, $this->gateway->submissions);
            self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM gmail_draft_attempt'));
        }
    }

    public static function invalidDraftInputs(): iterable
    {
        yield 'not reviewed' => [['reviewed' => false]];
        yield 'string reviewed' => [['reviewed' => 'true']];
        yield 'bad attempt ID' => [['attemptId' => 'not-a-uuid']];
        yield 'empty subject' => [['subject' => '']];
        yield 'header injection' => [['subject' => "Security report\r\nBcc: attacker@example.org"]];
        yield 'missing original PoC' => [['body' => 'No exact original PoC.']];
        yield 'normalized PoC' => [['body' => str_replace('%2b', '%2B', self::POC)]];
        yield 'empty fingerprint' => [['fingerprint' => '']];
    }

    public function testChangedContactBeforeCreateAndPortalRoutesNeverReachGmail(): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        $this->saveContact($finding, 'changed@example.org');
        try { $this->drafts->create($finding, $input); self::fail('Stale contact accepted.'); }
        catch (EmailPreparationException $error) { self::assertSame(409, $error->httpStatus); }
        $this->saveContact($finding, 'https://example.org/report', 'web');
        $portalInput = $this->input($finding);
        try { $this->drafts->create($finding, $portalInput); self::fail('Form route turned into an email recipient.'); }
        catch (EmailPreparationException) { self::assertCount(0, $this->gateway->submissions); }
    }

    #[DataProvider('unverifiedGatewayReplies')]
    public function testMismatchedOrUnverifiedGatewayReceiptsCannotBePromotedToVerified(array $overrides): void
    {
        $finding = $this->finding(); $input = $this->input($finding);
        $this->gateway->replyOverrides = $overrides;
        $receipt = $this->drafts->create($finding, $input);
        self::assertNotSame('verified', $receipt['state']);
        self::assertFalse($this->drafts->state($finding)['canCreate']);
        self::assertSame($receipt['state'], $this->drafts->create($finding, $input)['state']);
        self::assertCount(1, $this->gateway->submissions);
    }

    public static function unverifiedGatewayReplies(): iterable
    {
        yield 'wrong account' => [['account' => 'other@example.org']];
        yield 'wrong content hash' => [['content_sha256' => str_repeat('0', 64)]];
        yield 'wrong attempt' => [['attempt_id' => '01900000-0000-7000-8000-000000000000']];
        yield 'verification failed' => [['ok' => false, 'state' => 'verification_failed']];
        yield 'uncertain' => [['ok' => false, 'state' => 'uncertain']];
        yield 'rejected with known draft ID is not safe to retry' => [['ok' => false, 'state' => 'rejected', 'safe_to_retry' => true, 'message_id' => null]];
        yield 'rejected with known message ID is not safe to retry' => [['ok' => false, 'state' => 'rejected', 'safe_to_retry' => true, 'draft_id' => null]];
    }

    public function testMissingGatewayConfigurationIsVisibleAndCannotReserveAnAttempt(): void
    {
        $finding = $this->finding(); $input = $this->input($finding); $this->gateway->ready = false;
        self::assertFalse($this->drafts->state($finding)['configured']);
        try { $this->drafts->create($finding, $input); self::fail('Unconfigured gateway accepted.'); }
        catch (EmailPreparationException) {
            self::assertCount(0, $this->gateway->submissions);
            self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM gmail_draft_attempt'));
        }
    }

    public function testControllerGetIsReadOnlyAndDraftCreationNeedsCsrfReviewAndTheServerSelectedRecipient(): void
    {
        self::getContainer()->set(EmailPreparationService::class, $this->emails);
        self::getContainer()->set(GmailDraftService::class, $this->drafts);
        $finding = $this->finding(); $before = $this->sourceState();
        $base = '/findings/'.$finding->getId().'/prepare-email';
        $state = $this->request($base.'/gmail-draft');
        self::assertSame(200, $state->getStatusCode());
        self::assertStringContainsString('no-store', $state->headers->get('Cache-Control'));
        self::assertTrue(json_decode($state->getContent(), true, 512, JSON_THROW_ON_ERROR)['canCreate']);
        self::assertCount(0, $this->gateway->submissions);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM gmail_draft_attempt'));
        $page = $this->request($base);
        self::assertSame(200, $page->getStatusCode());
        $document = new \DOMDocument(); @$document->loadHTML($page->getContent());
        $script = (new \DOMXPath($document))->query('//script[@id="email-preparation-config"]')->item(0);
        self::assertNotNull($script);
        $token = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR)['csrfToken'];
        $input = $this->input($finding) + ['_token' => $token];
        self::assertSame(403, $this->request($base.'/gmail-draft', 'POST', array_replace($input, ['_token' => 'wrong']))->getStatusCode());
        self::assertSame(400, $this->request($base.'/gmail-draft', 'POST', $input + ['recipient' => 'attacker@example.org'])->getStatusCode());
        self::assertSame(400, $this->request($base.'/gmail-draft', 'POST', array_replace($input, ['reviewed' => false]))->getStatusCode());
        self::assertCount(0, $this->gateway->submissions);
        $created = $this->request($base.'/gmail-draft', 'POST', $input);
        self::assertSame(200, $created->getStatusCode(), $created->getContent());
        $receipt = json_decode($created->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($receipt['ok']);
        self::assertSame('verified', $receipt['state']);
        self::assertStringContainsString('no-store', $created->headers->get('Cache-Control'));
        self::assertSame(200, $this->request($base.'/gmail-draft', 'POST', $input)->getStatusCode());
        self::assertCount(1, $this->gateway->submissions);
        $reconciled = $this->request($base.'/gmail-draft/reconcile', 'POST', ['_token' => $token, 'attemptId' => $input['attemptId']]);
        self::assertSame(200, $reconciled->getStatusCode(), $reconciled->getContent());
        self::assertCount(1, $this->gateway->reconciliations);
        self::assertSame($before, $this->sourceState());
    }

    private function request(string $path, string $method = 'GET', ?array $input = null): Response
    {
        $request = Request::create($path, $method, [], [], [], $input === null ? [] : ['CONTENT_TYPE' => 'application/json'], $input === null ? null : json_encode($input, JSON_THROW_ON_ERROR));
        $request->setSession($this->session);
        $response = self::$kernel->handle($request); self::$kernel->terminate($request, $response);
        return $response;
    }

    private function finding(): Finding
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding(self::POC, type: 'unclassified', expectedEvidence: 'OPENBUG');
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('still_vulnerable')->setFinishedAt(new \DateTimeImmutable())
            ->setRawResult(['proofContext' => WorkerProofResolver::captureContext($finding), 'dialogEvents' => [['message' => 'OPENBUG PRIVATE_DIALOG_EVIDENCE']]]);
        $this->entityManager->persist($run); $this->entityManager->flush();
        self::assertSame('eligible', $this->reports->refresh($finding)['gate']);
        $this->saveContact($finding);
        return $finding;
    }

    private function saveContact(Finding $finding, string $destination = 'security@example.org', string $channel = 'email'): void
    {
        $this->contacts->save($finding, ['mode' => 'manual', 'revision' => $this->contacts->state($finding)['revision'], 'channel' => $channel,
            'destination' => $destination, 'source' => 'https://example.org/security']);
    }

    private function input(Finding $finding): array
    {
        $preview = $this->emails->preview($finding, 'en');
        $draft = $preview['manualDraft'];
        return ['language' => 'en', 'fingerprint' => $preview['fingerprint'], 'attemptId' => Uuid::v7()->toRfc4122(), 'expectedAccount' => self::ACCOUNT,
            'subject' => $draft['subject'], 'body' => $draft['body'], 'reviewed' => true];
    }

    private function sourceState(): array
    {
        $result = []; $db = $this->entityManager->getConnection();
        foreach (['finding', 'finding_contact_route', 'finding_assessment', 'screenshot_job', 'retest_run', 'disclosure_activity', 'finding_report_context', 'finding_report_revision'] as $table) {
            $result[$table] = $db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        }
        return $result;
    }
}

final class GmailDraftTestGateway implements GmailDraftGatewayInterface
{
    public bool $ready = true;
    public array $submissions = [];
    public array $reconciliations = [];
    public array $replyOverrides = [];
    public ?\Closure $duringSubmit = null;
    public ?\Closure $duringReconcile = null;
    public function configured(): bool { return $this->ready; }
    public function submit(array $request): array
    {
        $this->submissions[] = $request;
        ($this->duringSubmit ?? static fn () => null)($request);
        return $this->reply($request);
    }
    public function reconcile(array $request): array
    {
        $this->reconciliations[] = $request;
        ($this->duringReconcile ?? static fn () => null)($request);
        return $this->reply($request);
    }
    private function reply(array $request): array
    {
        return array_replace(['ok' => true, 'state' => 'verified', 'attempt_id' => $request['attempt_id'],
            'account' => $request['account'], 'content_sha256' => $request['content_sha256'],
            'draft_id' => 'r1234567890123456789', 'message_id' => '18abcdef01234567'], $this->replyOverrides);
    }
}

final class GmailDraftTestEmailClient implements EmailDraftClientInterface
{
    public function configured(): bool { return true; }
    public function model(): string { return 'gpt-6.2-sol'; }
    public function reasoning(): string { return 'medium'; }
    public function generate(array $request): array
    {
        throw new \LogicException('Gmail draft tests must use the local manual draft without any AI call.');
    }
}
