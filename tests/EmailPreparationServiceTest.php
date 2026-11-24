<?php

namespace App\Tests;

use App\Cribrum\CribrumClassifier;
use App\Entity\Finding;
use App\Entity\RetestRun;
use App\Service\ContactRouteService;
use App\Service\CribrumClaimService;
use App\Service\CribrumObservationStore;
use App\Service\CribrumProcessingService;
use App\Service\EmailDraftClientInterface;
use App\Service\EmailPreparationException;
use App\Service\EmailPreparationService;
use App\Service\EmailWritingProfileService;
use App\Service\FindingService;
use App\Service\FollowUpService;
use App\Service\ReportProjectionService;
use App\Service\WorkerProofResolver;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class EmailPreparationServiceTest extends DatabaseTestCase
{
    private ReportProjectionService $reports;
    private ContactRouteService $contacts;
    private EmailPreparationTestClient $client;
    private EmailPreparationService $preparation;
    private Session $session;
    private const POC = 'https://email.fixture.invalid/?q=%22%3E%3Cscript%3Ealert(OPENBUG)%3C%2Fscript%3E&same=a+b&same=%2b#ExactCASE';

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__).'/migrations/Version20261022000000.php';
        $db = $this->entityManager->getConnection();
        $migration = new \DoctrineMigrations\Version20261022000000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement());
        $this->reports = self::getContainer()->get(ReportProjectionService::class);
        $this->contacts = self::getContainer()->get(ContactRouteService::class);
        $this->client = new EmailPreparationTestClient();
        $this->preparation = new EmailPreparationService($db, $this->reports, $this->contacts, $this->client);
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testPreviewIsLocalAndPayloadContainsOnlyTheExplicitRecipientFactAllowlist(): void
    {
        $finding = $this->finding(); $before = $this->facts();
        $preview = $this->preparation->preview($finding, 'de');
        self::assertSame(0, $this->client->calls);
        self::assertSame($before, $this->facts());
        self::assertSame('de', $preview['language']);
        self::assertSame('gpt-6.2-sol', $preview['model']);
        self::assertSame('security@example.org', $preview['contact']['destination']);
        self::assertSame('email', $preview['contact']['channel']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preview['fingerprint']);
        self::assertFalse($preview['request']['store']);
        self::assertSame('json_schema', $preview['request']['text']['format']['type']);
        self::assertTrue($preview['request']['text']['format']['strict']);
        $payload = json_encode($preview['request'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([self::POC, 'security@example.org', 'CONTACT_PRIVATE_NOTE', 'CONTACT_PRIVATE_PERSON', 'PRIVATE_DIALOG_EVIDENCE', $finding->getId(), 'sourceInstanceId', 'basisRevision', 'evidenceRefs'] as $private) {
            self::assertStringNotContainsString($private, $payload);
        }
        self::assertStringContainsString('email.fixture.invalid', $payload);
    }

    public function testGenerationPreservesOriginalPocAndDoesNotChangeAnySourceState(): void
    {
        $finding = $this->finding(); $preview = $this->preparation->preview($finding, 'en'); $before = $this->facts();
        $draft = $this->preparation->generate($finding, 'en', $preview['fingerprint']);
        self::assertSame(1, $this->client->calls);
        self::assertSame($preview['request'], $this->client->requests[0]);
        self::assertSame(1, substr_count($draft['body'], self::POC));
        self::assertStringContainsString('confirmed', strtolower($draft['body']));
        self::assertStringContainsString('JavaScript', $draft['body']);
        self::assertSame('security@example.org', $draft['recipient']);
        self::assertSame('email', $draft['channel']);
        self::assertSame($before, $this->facts());
    }

    public function testSourceChangeBeforeGenerationCannotUseAnOldPreview(): void
    {
        $finding = $this->finding(); $preview = $this->preparation->preview($finding, 'de');
        $this->entityManager->getConnection()->executeStatement('UPDATE finding SET payload=? WHERE id=?', ['new payload', $finding->getId()]);
        try { $this->preparation->generate($finding, 'de', $preview['fingerprint']); self::fail('Stale preview accepted.'); }
        catch (EmailPreparationException) { self::assertSame(0, $this->client->calls); }
    }

    public function testContactChangeBeforeGenerationCannotUseAnOldPreview(): void
    {
        $finding = $this->finding(); $preview = $this->preparation->preview($finding, 'de');
        $this->saveContact($finding, 'new-contact@example.org');
        try { $this->preparation->generate($finding, 'de', $preview['fingerprint']); self::fail('Stale contact accepted.'); }
        catch (EmailPreparationException) { self::assertSame(0, $this->client->calls); }
    }

    #[DataProvider('previewMismatches')]
    public function testLanguageAndFingerprintChangesRequireANewPreview(string $language, string $fingerprint): void
    {
        $finding = $this->finding(); $preview = $this->preparation->preview($finding, 'de');
        try { $this->preparation->generate($finding, $language, $fingerprint === 'original' ? $preview['fingerprint'] : $fingerprint); self::fail('Changed preview accepted.'); }
        catch (EmailPreparationException) { self::assertSame(0, $this->client->calls); }
    }

    public static function previewMismatches(): iterable
    {
        yield 'changed language' => ['en', 'original'];
        yield 'invalid language' => ['fr', 'original'];
        yield 'missing fingerprint' => ['de', ''];
        yield 'wrong fingerprint' => ['de', str_repeat('a', 64)];
    }

    public function testSourceChangeDuringGenerationDiscardsTheReturnedDraft(): void
    {
        $finding = $this->finding(); $preview = $this->preparation->preview($finding, 'de');
        $this->client->duringGenerate = function () use ($finding): void {
            $this->entityManager->getConnection()->executeStatement('UPDATE finding SET expected_evidence=? WHERE id=?', ['changed marker', $finding->getId()]);
        };
        try { $this->preparation->generate($finding, 'de', $preview['fingerprint']); self::fail('Draft from stale facts accepted.'); }
        catch (EmailPreparationException) { self::assertSame(1, $this->client->calls); }
    }

    public function testContactChangeDuringGenerationDiscardsTheReturnedDraft(): void
    {
        $finding = $this->finding(); $preview = $this->preparation->preview($finding, 'de');
        $this->client->duringGenerate = fn () => $this->saveContact($finding, 'new-contact@example.org');
        try { $this->preparation->generate($finding, 'de', $preview['fingerprint']); self::fail('Draft for stale contact accepted.'); }
        catch (EmailPreparationException) { self::assertSame(1, $this->client->calls); }
    }

    public function testMixedSelectedFindingsKeepConfirmedXssAndSuspectedSqlInjectionDistinct(): void
    {
        $poc = 'https://mixed-email.fixture.invalid/?id=OPENBUG&same=a+b&same=%2b#ExactCASE';
        $finding = $this->finding($poc);
        $raw = "<pre>Microsoft JET Database Engine error: Syntax error (missing operator) in query expression 'id = OPENBUG'.</pre><!-- PRIVATE_SQL_SNIPPET -->";
        $processor = new CribrumProcessingService($this->entityManager,
            new CribrumClassifier('/nonexistent-private-packs', dirname(__DIR__).'/resources/cribrum/candidates', 'active'),
            self::getContainer()->get(CribrumObservationStore::class), new CribrumClaimService($this->entityManager), new NullLogger(), $this->reports);
        $processor->process($finding, ['schema' => 1, 'contentType' => 'text/html', 'httpStatus' => 500,
            'finalUrl' => $poc, 'rawHtml' => $raw, 'rawHtmlBytes' => strlen($raw), 'rawHtmlTruncated' => false,
            'domText' => null, 'domTextBytes' => null, 'domTextTruncated' => false, 'redirectChain' => [], 'headers' => []],
            requestContextSha256: WorkerProofResolver::requestContextSha256($finding));
        $snapshot = $this->reports->current($finding);
        self::assertSame('eligible', $snapshot['gate']);
        self::assertContains('xss_confirmed', array_column($snapshot['components'], 'code'));
        self::assertContains('sqli_suspected', array_column($snapshot['components'], 'code'));
        $preview = $this->preparation->preview($finding, 'en');
        $outbound = json_decode($preview['request']['input'][1]['content'], true, 512, JSON_THROW_ON_ERROR);
        $certainty = array_column($outbound['selected_findings'], 'certainty', 'label');
        self::assertSame('confirmed', $certainty['Cross-Site Scripting']);
        self::assertSame('suspected', $certainty['SQL injection']);
        $requestJson = json_encode($preview['request'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PRIVATE_SQL_SNIPPET', $requestJson);
        self::assertStringNotContainsString('Microsoft JET', $requestJson);
        $before = $this->facts();
        $draft = $this->preparation->generate($finding, 'en', $preview['fingerprint']);
        self::assertStringContainsString('confirmed Cross-Site Scripting', $draft['body']);
        self::assertStringContainsString('concrete suspicion of SQL injection', $draft['body']);
        self::assertStringContainsString('Exploitable SQL injection is not confirmed', $draft['body']);
        self::assertStringNotContainsString('confirmed SQL injection', $draft['body']);
        self::assertSame(1, substr_count($draft['body'], $poc));
        self::assertSame($before, $this->facts());
    }

    public function testContactRestrictionAddedDuringGenerationDiscardsTheReturnedDraft(): void
    {
        $finding = $this->finding(); $preview = $this->preparation->preview($finding, 'de');
        $followUp = self::getContainer()->get(FollowUpService::class);
        $this->client->duringGenerate = function () use ($finding, $followUp): void {
            $followUp->save($finding, ['scope' => 'case', 'pursuit' => 'active', 'contact_blocked' => '1',
                'checks_blocked' => '0', 'revision' => $followUp->state($finding)['revisions']['case']]);
        };
        try { $this->preparation->generate($finding, 'de', $preview['fingerprint']); self::fail('Draft returned after contact was blocked.'); }
        catch (EmailPreparationException $error) {
            self::assertSame('stale_preview', $error->errorCode);
            self::assertSame(409, $error->httpStatus);
            self::assertTrue($followUp->state($finding)['contactBlocked']);
            self::assertSame(1, $this->client->calls);
        }
    }

    public function testContactSourceExpiryChangedDuringGenerationDiscardsTheReturnedDraft(): void
    {
        $finding = $this->finding(); $db = $this->entityManager->getConnection();
        $future = ['origin' => 'suggestion', 'expires' => '2099-01-01T00:00:00Z'];
        $db->update('finding_contact_route', ['provenance' => json_encode($future, JSON_THROW_ON_ERROR)], ['finding_id' => $finding->getId()]);
        $preview = $this->preparation->preview($finding, 'en');
        $this->client->duringGenerate = static function () use ($finding, $db, $future): void {
            $db->update('finding_contact_route', ['provenance' => json_encode(array_replace($future, ['expires' => '2000-01-01T00:00:00Z']), JSON_THROW_ON_ERROR)], ['finding_id' => $finding->getId()]);
        };
        try { $this->preparation->generate($finding, 'en', $preview['fingerprint']); self::fail('Draft returned after selected contact source expired.'); }
        catch (EmailPreparationException $error) {
            self::assertSame('stale_preview', $error->errorCode);
            self::assertSame(409, $error->httpStatus);
            self::assertSame(1, $this->client->calls);
        }
    }

    public function testUnqualifiedCaseCannotBeUsedToInventAnEmailFinding(): void
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding('https://unqualified.fixture.invalid/?q=original', type: 'reflected_xss');
        $this->reports->refresh($finding); $this->saveContact($finding); $before = $this->facts();
        self::assertFalse($this->preparation->initialState($finding, 'de')['eligible']);
        try { $this->preparation->preview($finding, 'de'); self::fail('Unqualified case accepted.'); }
        catch (EmailPreparationException) { self::assertSame(0, $this->client->calls); self::assertSame($before, $this->facts()); }
    }

    public function testFormRouteProducesSubmissionTextWithoutInventingAnEmailRecipient(): void
    {
        $finding = $this->finding(); $this->saveContact($finding, 'https://example.org/report', 'web');
        $preview = $this->preparation->preview($finding, 'en');
        $draft = $this->preparation->generate($finding, 'en', $preview['fingerprint']);
        self::assertSame('web', $draft['channel']);
        self::assertSame('https://example.org/report', $draft['recipient']);
    }

    public function testPreviewRemainsAvailableWithoutAnApiKey(): void
    {
        $finding = $this->finding(); $this->client->ready = false;
        self::assertFalse($this->preparation->initialState($finding, 'de')['configured']);
        $preview = $this->preparation->preview($finding, 'de');
        self::assertSame('gpt-6.2-sol', $preview['model']);
        self::assertSame(1, substr_count($preview['manualDraft']['body'], self::POC));
        self::assertFalse($preview['manualDraft']['reviewed']);
        self::assertSame($preview['fingerprint'], $preview['manualDraft']['fingerprint']);
        self::assertSame(0, $this->client->calls);
    }

    public function testOnlySelectedWritingProfileExamplesLeaveTheApplicationAndBuiltinBindingStaysCompatible(): void
    {
        $finding = $this->finding(); $legacy = $this->preparation->preview($finding, 'en');
        $profiles = $this->writingProfiles();
        $selected = $profiles->save($this->profileInput(['name' => 'Selected style', 'example_texts' => ['PRIVATE_SELECTED_STYLE: We confirmed SQL injection at other.example.org.']]));
        $other = $profiles->save($this->profileInput(['name' => 'Other style', 'example_texts' => ['PRIVATE_UNSELECTED_STYLE']]));
        $builtin = $this->preparation->preview($finding, 'en', 'builtin');
        self::assertSame($legacy['request'], $builtin['request']);
        self::assertSame($legacy['fingerprint'], $builtin['fingerprint']);
        $preview = $this->preparation->preview($finding, 'en', $selected['id']);
        $wire = json_encode($preview['request'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('PRIVATE_SELECTED_STYLE', $wire);
        self::assertStringNotContainsString('PRIVATE_UNSELECTED_STYLE', $wire);
        self::assertStringNotContainsString(self::POC, $wire);
        $originalFacts = json_decode($builtin['request']['input'][1]['content'], true, 512, JSON_THROW_ON_ERROR)['selected_findings'];
        $selectedFacts = json_decode($preview['request']['input'][1]['content'], true, 512, JSON_THROW_ON_ERROR)['selected_findings'];
        self::assertSame($originalFacts, $selectedFacts, 'A style example cannot add technical findings to this case.');
        $profiles->setDefault($other['id'], $other['revision'], $profiles->defaultSelection()['revision']);
        self::assertSame($preview['fingerprint'], $this->preparation->preview($finding, 'en', $selected['id'])['fingerprint']);
        self::assertSame($builtin['fingerprint'], $this->preparation->preview($finding, 'en')['fingerprint'], 'An omitted profile ID retains the historic built-in binding.');
        $draft = $this->preparation->generate($finding, 'en', $preview['fingerprint'], $selected['id']);
        self::assertSame(1, $this->client->calls);
        self::assertStringNotContainsString('PRIVATE_SELECTED_STYLE', $draft['body']);
        self::assertStringNotContainsString('SQL injection', $draft['body']);
        self::assertSame(1, substr_count($draft['body'], self::POC));
    }

    public function testWritingProfileChangesBeforeAndDuringGenerationInvalidateThePreview(): void
    {
        $finding = $this->finding(); $profiles = $this->writingProfiles();
        $profile = $profiles->save($this->profileInput());
        $preview = $this->preparation->preview($finding, 'en', $profile['id']);
        $current = $profiles->save($this->profileInput(['action' => 'update', 'profileId' => $profile['id'], 'revision' => $profile['revision'], 'instructions' => 'Updated writing preferences.']));
        try { $this->preparation->generate($finding, 'en', $preview['fingerprint'], $profile['id']); self::fail('Old writing profile revision accepted.'); }
        catch (EmailPreparationException $error) { self::assertSame(409, $error->httpStatus); self::assertSame(0, $this->client->calls); }
        $preview = $this->preparation->preview($finding, 'en', $profile['id']);
        $this->client->duringGenerate = static fn () => $profiles->archive($current['id'], $current['revision']);
        try { $this->preparation->generate($finding, 'en', $preview['fingerprint'], $profile['id']); self::fail('Archived writing profile accepted after generation.'); }
        catch (EmailPreparationException $error) { self::assertSame(409, $error->httpStatus); self::assertSame(1, $this->client->calls); }
    }

    private function writingProfiles(): EmailWritingProfileService
    {
        require_once dirname(__DIR__).'/migrations/Version20261124010000.php';
        $db = $this->entityManager->getConnection();
        $migration = new \DoctrineMigrations\Version20261124010000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement());
        $profiles = new EmailWritingProfileService($db);
        $this->preparation = new EmailPreparationService($db, $this->reports, $this->contacts, $this->client, $profiles);
        return $profiles;
    }

    private function profileInput(array $changes = []): array
    {
        return array_replace(['action' => 'create', 'name' => 'Local writing profile', 'instructions' => 'Be concise and calm.',
            'example_names' => ['Example'], 'example_texts' => ['PRIVATE_STYLE_SAMPLE']], $changes);
    }

    public function testAnAlreadyContactedCaseIsNotANewDisclosureEvenWhenItsEvidenceIsEligible(): void
    {
        $finding = $this->finding();
        $finding->setContactedAt(new \DateTimeImmutable('2026-01-01'));
        $this->entityManager->flush(); $this->reports->refresh($finding);
        self::assertSame('eligible', $this->reports->current($finding)['gate']);
        self::assertFalse($this->preparation->initialState($finding, 'en')['eligible']);
        try { $this->preparation->preview($finding, 'en'); self::fail('Previously contacted case accepted as a new disclosure.'); }
        catch (EmailPreparationException) { self::assertSame(0, $this->client->calls); }
    }

    public function testHistoricalReportedAtBlocksANewDisclosureIndependentlyOfCurrentStatus(): void
    {
        $finding = $this->finding();
        $finding->setReportedAt(new \DateTimeImmutable('2026-01-01'));
        $this->entityManager->flush(); $this->reports->refresh($finding);
        self::assertNotSame('reported', $finding->getStatus());
        self::assertSame('eligible', $this->reports->current($finding)['gate']);
        self::assertFalse($this->preparation->initialState($finding, 'en')['eligible']);
        try { $this->preparation->preview($finding, 'en'); self::fail('Historically reported case accepted as a new disclosure.'); }
        catch (EmailPreparationException $error) {
            self::assertSame('workflow_blocked', $error->errorCode);
            self::assertSame(0, $this->client->calls);
        }
    }

    public function testPageAndPreviewDoNotGenerateAndControllerRequiresExplicitCsrfProtectedJson(): void
    {
        self::getContainer()->set(EmailPreparationService::class, $this->preparation);
        $finding = $this->finding(); $before = $this->facts();
        $path = '/findings/'.$finding->getId().'/prepare-email';
        $page = $this->request($path);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        self::assertStringNotContainsString(self::POC, $page->getContent());
        self::assertSame($before, $this->facts());
        self::assertSame(0, $this->client->calls);
        $input = ['_token' => $this->pageConfig($page)['csrfToken'], 'language' => 'de'];
        $previewResponse = $this->request($path.'/preview', 'POST', $input);
        self::assertSame(200, $previewResponse->getStatusCode(), $previewResponse->getContent());
        $preview = json_decode($previewResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($preview['ok']);
        self::assertSame(0, $this->client->calls);
        self::assertSame(403, $this->request($path.'/generate', 'POST', array_replace($input, ['_token' => 'invalid', 'fingerprint' => $preview['fingerprint']]))->getStatusCode());
        self::assertSame(400, $this->request($path.'/generate', 'POST', $input)->getStatusCode());
        self::assertSame(405, $this->request($path.'/generate')->getStatusCode());
        self::assertSame(405, $this->request($path.'/preview')->getStatusCode());
        self::assertSame(404, $this->request('/findings/not-a-uuid/prepare-email')->getStatusCode());
        self::assertSame(0, $this->client->calls);
        $generated = $this->request($path.'/generate', 'POST', $input + ['fingerprint' => $preview['fingerprint']]);
        self::assertSame(200, $generated->getStatusCode(), $generated->getContent());
        self::assertStringContainsString('no-store', $generated->headers->get('Cache-Control'));
        $draft = json_decode($generated->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($draft['ok']);
        self::assertFalse($draft['reviewed']);
        self::assertStringContainsString(self::POC, $draft['body']);
        self::assertSame(1, $this->client->calls);
        self::assertSame($before, $this->facts());
    }

    public function testControllerKeepsStalePreviewFailuresSeparateFromProviderCalls(): void
    {
        self::getContainer()->set(EmailPreparationService::class, $this->preparation);
        $finding = $this->finding(); $path = '/findings/'.$finding->getId().'/prepare-email';
        $input = ['_token' => $this->pageConfig($this->request($path))['csrfToken'], 'language' => 'de'];
        $preview = json_decode($this->request($path.'/preview', 'POST', $input)->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->saveContact($finding, 'changed@example.org');
        $response = $this->request($path.'/generate', 'POST', $input + ['fingerprint' => $preview['fingerprint']]);
        self::assertSame(409, $response->getStatusCode());
        $error = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($error['ok']);
        self::assertSame('stale_preview', $error['code']);
        self::assertSame(0, $this->client->calls);
    }

    private function request(string $path, string $method = 'GET', ?array $input = null): Response
    {
        $request = Request::create($path, $method, [], [], [], $input === null ? [] : ['CONTENT_TYPE' => 'application/json'], $input === null ? null : json_encode($input, JSON_THROW_ON_ERROR));
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }

    private function pageConfig(Response $page): array
    {
        $document = new \DOMDocument(); @$document->loadHTML($page->getContent());
        $scripts = (new \DOMXPath($document))->query('//script[@id="email-preparation-config"]');
        self::assertSame(1, $scripts->length);
        return json_decode($scripts->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
    }

    private function finding(string $poc = self::POC): Finding
    {
        $finding = self::getContainer()->get(FindingService::class)->createFinding($poc, type: 'unclassified', expectedEvidence: 'OPENBUG');
        $run = (new RetestRun())->setFinding($finding)->setMode('browser')->setResult('still_vulnerable')->setFinishedAt(new \DateTimeImmutable())->setRawResult([
            'proofContext' => WorkerProofResolver::captureContext($finding), 'dialogEvents' => [['message' => 'OPENBUG PRIVATE_DIALOG_EVIDENCE']],
        ]);
        $this->entityManager->persist($run); $this->entityManager->flush();
        self::assertSame('eligible', $this->reports->refresh($finding)['gate']);
        $this->saveContact($finding);
        return $finding;
    }

    private function saveContact(Finding $finding, string $destination = 'security@example.org', string $channel = 'email'): void
    {
        $this->contacts->save($finding, ['mode' => 'manual', 'revision' => $this->contacts->state($finding)['revision'], 'channel' => $channel,
            'destination' => $destination, 'person' => 'CONTACT_PRIVATE_PERSON', 'source' => 'https://example.org/security', 'notes' => 'CONTACT_PRIVATE_NOTE']);
    }

    private function facts(): array
    {
        $db = $this->entityManager->getConnection(); $state = [];
        foreach (['finding', 'finding_assessment', 'screenshot_job', 'evidence', 'retest_run', 'finding_claim', 'finding_report_revision', 'finding_report_context', 'finding_behavior_fact', 'finding_contact_route', 'disclosure_activity', 'disclosure_reminder'] as $table) {
            $state[$table] = $db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        }
        return $state;
    }
}

final class EmailPreparationTestClient implements EmailDraftClientInterface
{
    public bool $ready = true;
    public int $calls = 0;
    public array $requests = [];
    public ?\Closure $duringGenerate = null;
    public function configured(): bool { return $this->ready; }
    public function model(): string { return 'gpt-6.2-sol'; }
    public function reasoning(): string { return 'medium'; }
    public function generate(array $request): array
    {
        ++$this->calls; $this->requests[] = $request;
        ($this->duringGenerate ?? static fn () => null)();
        return ['subject' => 'Security report', 'opening' => 'Hello, I would like to report a security issue.', 'closing' => 'Thank you for reviewing this report.', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150]];
    }
}
