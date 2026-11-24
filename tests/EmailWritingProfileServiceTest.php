<?php

namespace App\Tests;

use App\Service\EmailPreparationException;
use App\Service\EmailDraftClientInterface;
use App\Service\EmailWritingProfileService;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class EmailWritingProfileServiceTest extends DatabaseTestCase
{
    private EmailWritingProfileService $profiles;
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->profiles = new EmailWritingProfileService($this->entityManager->getConnection());
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testBuiltinProfileWorksReadOnlyBeforeTheProfileMigration(): void
    {
        self::assertFalse($this->profiles->available());
        $builtin = $this->profiles->get('builtin');
        self::assertSame('builtin', $builtin['id']);
        self::assertSame(1, $builtin['revision']);
        self::assertTrue($builtin['builtin']);
        self::assertSame('builtin', $this->profiles->defaultSelection()['id']);
        self::assertSame(['builtin'], array_column($this->profiles->all(), 'id'));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'email_writing_profile%'"));
    }

    public function testUpdatesAppendImmutableRevisionsAndReadsDoNotWrite(): void
    {
        $first = $this->create();
        self::assertSame(1, $first['revision']);
        self::assertSame([['name' => 'Tone example', 'text' => 'PRIVATE_STYLE_EXAMPLE_A']], $first['examples']);
        self::assertSame(1, $first['exampleCount']);
        $second = $this->profiles->save($this->input(['action' => 'update', 'profileId' => $first['id'], 'revision' => $first['revision'], 'instructions' => 'Be brief and courteous.']));
        self::assertSame($first['id'], $second['id']);
        self::assertSame(2, $second['revision']);
        self::assertNotSame($first['payloadHash'], $second['payloadHash']);
        self::assertSame($second['payloadHash'], $this->profiles->get($first['id'])['payloadHash']);
        $before = $this->rows();
        self::assertCount(2, $before['email_writing_profile_revision']);
        $this->profiles->all(); $this->profiles->get($first['id']); $this->profiles->defaultSelection();
        self::assertSame($before, $this->rows());
        foreach (['UPDATE email_writing_profile_revision SET revision=revision', 'DELETE FROM email_writing_profile_revision'] as $sql) {
            try { $this->entityManager->getConnection()->executeStatement($sql); self::fail('Historical profile revision was mutable.'); }
            catch (DbalException) { self::assertSame($before, $this->rows()); }
        }
    }

    public function testStaleEditsArchiveAndDefaultSelectionCannotOverwriteNewerWork(): void
    {
        $first = $this->create();
        $current = $this->profiles->save($this->input(['action' => 'update', 'profileId' => $first['id'], 'revision' => $first['revision'], 'name' => 'Updated profile']));
        $before = $this->rows();
        $actions = [
            fn () => $this->profiles->save($this->input(['action' => 'update', 'profileId' => $first['id'], 'revision' => $first['revision']])),
            fn () => $this->profiles->archive($first['id'], $first['revision']),
            fn () => $this->profiles->setDefault($first['id'], $first['revision'], $this->profiles->defaultSelection()['revision']),
        ];
        foreach ($actions as $action) {
            try { $action(); self::fail('Stale profile revision accepted.'); }
            catch (EmailPreparationException $error) { self::assertSame(409, $error->httpStatus); self::assertSame($before, $this->rows()); }
        }
        $oldDefault = $this->profiles->defaultSelection();
        $this->profiles->setDefault($current['id'], $current['revision'], $oldDefault['revision']);
        $newDefault = $this->profiles->defaultSelection();
        self::assertSame($current['id'], $newDefault['id']);
        self::assertGreaterThan($oldDefault['revision'], $newDefault['revision']);
        try { $this->profiles->setDefault('builtin', 1, $oldDefault['revision']); self::fail('Stale default selection accepted.'); }
        catch (EmailPreparationException $error) { self::assertSame(409, $error->httpStatus); self::assertSame($newDefault, $this->profiles->defaultSelection()); }
    }

    public function testArchiveRequiresChangingTheDefaultFirstAndDoesNotDeleteHistory(): void
    {
        $profile = $this->create();
        $this->profiles->setDefault($profile['id'], $profile['revision'], $this->profiles->defaultSelection()['revision']);
        try { $this->profiles->archive($profile['id'], $profile['revision']); self::fail('Selected default was silently archived.'); }
        catch (EmailPreparationException) { self::assertFalse($this->profiles->get($profile['id'])['archived']); }
        $this->profiles->setDefault('builtin', 1, $this->profiles->defaultSelection()['revision']);
        $history = $this->rows()['email_writing_profile_revision'];
        $this->profiles->archive($profile['id'], $profile['revision']);
        self::assertTrue($this->profiles->get($profile['id'], true)['archived']);
        self::assertNotContains($profile['id'], array_column($this->profiles->all(false), 'id'));
        self::assertContains($profile['id'], array_column($this->profiles->all(true), 'id'));
        $archivedHistory = $this->rows()['email_writing_profile_revision'];
        self::assertCount(count($history) + 1, $archivedHistory);
        self::assertSame($history, array_slice($archivedHistory, 0, count($history)));
        self::assertTrue($this->profiles->history($profile['id'])[0]['archived']);
        try { $this->profiles->get($profile['id']); self::fail('Archived profile remained selectable.'); }
        catch (EmailPreparationException) {}
        try { $this->profiles->setDefault($profile['id'], $profile['revision'], $this->profiles->defaultSelection()['revision']); self::fail('Archived profile selected as default.'); }
        catch (EmailPreparationException) { self::assertSame('builtin', $this->profiles->defaultSelection()['id']); }
    }

    public function testBuiltinCannotBeEditedOrArchived(): void
    {
        $this->migrate(); $before = $this->rows();
        foreach ([fn () => $this->profiles->save($this->input(['action' => 'update', 'profileId' => 'builtin', 'revision' => 1])), fn () => $this->profiles->archive('builtin', 1)] as $action) {
            try { $action(); self::fail('Built-in profile changed.'); }
            catch (EmailPreparationException) { self::assertSame($before, $this->rows()); }
        }
    }

    public function testInvalidProfileFieldsAndOversizedExamplesNeverPartiallyWrite(): void
    {
        $this->migrate(); $before = $this->rows();
        $invalid = [
            ['name' => ''], ['name' => str_repeat('n', 81)], ['name' => []], ['instructions' => str_repeat('x', 6001)],
            ['instructions' => "bad\0instruction"], ['instructions' => "\xff"],
            ['example_names' => ['one', 'two', 'three', 'four'], 'example_texts' => ['a', 'b', 'c', 'd']],
            ['example_names' => [str_repeat('n', 81)], 'example_texts' => ['text']],
            ['example_names' => ['one'], 'example_texts' => [str_repeat('x', 10001)]],
            ['example_names' => ['one'], 'example_texts' => [[]]], ['example_names' => 'not-array'],
            ['instructions' => str_repeat('😀', 6000), 'example_names' => ['one', 'two', 'three'], 'example_texts' => array_fill(0, 3, str_repeat('😀', 10000))],
        ];
        foreach ($invalid as $change) {
            try { $this->profiles->save($this->input($change)); self::fail('Invalid profile accepted.'); }
            catch (EmailPreparationException) { self::assertSame($before, $this->rows()); }
        }
    }

    public function testProfileFormsProtectWritesRetainInvalidInputAndSupportRevisionAwareLifecycle(): void
    {
        $this->migrate();
        $client = $this->createMock(EmailDraftClientInterface::class);
        $client->method('configured')->willReturn(false);
        $client->method('model')->willReturn('gpt-6.2-sol');
        $client->method('reasoning')->willReturn('medium');
        $client->expects(self::never())->method('generate');
        self::getContainer()->set(EmailDraftClientInterface::class, $client);
        $before = $this->rows();
        $page = $this->request('/settings/ai?new=1');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        self::assertSame($before, $this->rows());
        $form = $this->form($page, 'create');
        $typed = $this->input(['name' => '<script>Profile</script>', 'instructions' => "PRIVATE_RETAINED_INSTRUCTIONS\nSecond line.", 'example_texts' => ["PRIVATE_RETAINED_EXAMPLE\nAnother line."]]);
        $rejected = $this->request('/settings/ai', 'POST', array_replace($form, $typed, ['_token' => 'wrong']));
        self::assertSame(403, $rejected->getStatusCode());
        self::assertStringContainsString('&lt;script&gt;Profile&lt;/script&gt;', $rejected->getContent());
        self::assertStringNotContainsString('<script>Profile</script>', $rejected->getContent());
        self::assertSame($typed['instructions'], $this->xpath($rejected)->evaluate('string(//textarea[@name="instructions"])'));
        self::assertSame($typed['example_texts'][0], $this->xpath($rejected)->evaluate('string(//textarea[@name="example_texts[]"][1])'));
        $invalid = $this->request('/settings/ai', 'POST', array_replace($form, $typed, ['name' => '']));
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame('true', $this->xpath($invalid)->evaluate('string(//input[@name="name"]/@aria-invalid)'));
        self::assertSame($typed['instructions'], $this->xpath($invalid)->evaluate('string(//textarea[@name="instructions"])'));
        self::assertSame($before, $this->rows());
        $saved = $this->request('/settings/ai', 'POST', array_replace($form, $typed));
        self::assertSame(303, $saved->getStatusCode());
        parse_str(parse_url($saved->headers->get('Location'), PHP_URL_QUERY), $query);
        $id = $query['profile'];
        self::assertSame($typed['name'], $this->profiles->get($id)['name']);
        $edit = $this->form($this->request('/settings/ai?profile='.$id), 'update');
        $updated = $this->request('/settings/ai', 'POST', array_replace($edit, $typed, ['action' => 'update', 'instructions' => 'New revision.']));
        self::assertSame(303, $updated->getStatusCode());
        self::assertSame(2, $this->profiles->get($id)['revision']);
        $stale = $this->request('/settings/ai', 'POST', array_replace($edit, $typed, ['action' => 'update', 'instructions' => 'UNSAVED_STALE_FORM_INPUT']));
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('UNSAVED_STALE_FORM_INPUT', $this->xpath($stale)->evaluate('string(//textarea[@name="instructions"])'));
        self::assertSame('New revision.', $this->profiles->get($id)['instructions']);
        $default = $this->form($this->request('/settings/ai?profile='.$id), 'default');
        self::assertSame(303, $this->request('/settings/ai', 'POST', $default)->getStatusCode());
        self::assertSame($id, $this->profiles->defaultSelection()['id']);
        $staleDefault = $this->request('/settings/ai', 'POST', $default);
        self::assertSame(409, $staleDefault->getStatusCode());
        self::assertSame($typed['name'], $this->xpath($staleDefault)->evaluate('string(//input[@name="name"]/@value)'));
        self::assertSame('New revision.', $this->xpath($staleDefault)->evaluate('string(//textarea[@name="instructions"])'));
        self::assertSame($typed['example_texts'][0], $this->xpath($staleDefault)->evaluate('string(//textarea[@name="example_texts[]"][1])'));
        $archive = $this->form($this->request('/settings/ai?profile='.$id), 'archive');
        $blockedArchive = $this->request('/settings/ai', 'POST', $archive);
        self::assertSame(422, $blockedArchive->getStatusCode());
        self::assertSame($typed['name'], $this->xpath($blockedArchive)->evaluate('string(//input[@name="name"]/@value)'));
        self::assertSame('New revision.', $this->xpath($blockedArchive)->evaluate('string(//textarea[@name="instructions"])'));
        self::assertSame($typed['example_texts'][0], $this->xpath($blockedArchive)->evaluate('string(//textarea[@name="example_texts[]"][1])'));
        self::assertSame(303, $this->request('/settings/ai', 'POST', $this->form($this->request('/settings/ai?profile=builtin'), 'default'))->getStatusCode());
        self::assertSame(303, $this->request('/settings/ai', 'POST', $archive)->getStatusCode());
        self::assertTrue($this->profiles->get($id, true)['archived']);
        self::assertSame(3, count($this->profiles->history($id)));
        $afterArchive = $this->request('/settings/ai', 'POST', array_replace($edit, $typed, ['action' => 'update',
            'instructions' => 'UNSAVED_AFTER_ANOTHER_TAB_ARCHIVED', 'example_texts' => ['UNSAVED_ARCHIVED_EXAMPLE']]));
        self::assertSame(409, $afterArchive->getStatusCode());
        self::assertSame($typed['name'], $this->xpath($afterArchive)->evaluate('string(//input[@name="name"]/@value)'));
        self::assertSame('UNSAVED_AFTER_ANOTHER_TAB_ARCHIVED', $this->xpath($afterArchive)->evaluate('string(//textarea[@name="instructions"])'));
        self::assertSame('UNSAVED_ARCHIVED_EXAMPLE', $this->xpath($afterArchive)->evaluate('string(//textarea[@name="example_texts[]"][1])'));
        self::assertSame('New revision.', $this->profiles->get($id, true)['instructions']);
    }

    private function request(string $path, string $method = 'GET', array $input = []): Response
    {
        $request = Request::create($path, $method, $input); $request->setSession($this->session);
        $response = self::$kernel->handle($request); self::$kernel->terminate($request, $response);
        return $response;
    }

    private function xpath(Response $response): \DOMXPath
    {
        $document = new \DOMDocument(); @$document->loadHTML($response->getContent());
        return new \DOMXPath($document);
    }

    private function form(Response $response, string $action): array
    {
        $xpath = $this->xpath($response); $query = '//form[input[@name="action" and @value="'.$action.'"]]';
        self::assertSame(1, $xpath->query($query)->length);
        $input = [];
        foreach ($xpath->query($query.'//input[@type="hidden"]') as $field) $input[$field->getAttribute('name')] = $field->getAttribute('value');
        return $input;
    }

    private function create(array $overrides = []): array
    {
        if (!$this->profiles->available()) $this->migrate();
        return $this->profiles->save($this->input($overrides));
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['action' => 'create', 'name' => 'Clear disclosure', 'instructions' => 'Use a calm, concise tone.',
            'example_names' => ['Tone example'], 'example_texts' => ['PRIVATE_STYLE_EXAMPLE_A']], $overrides);
    }

    private function migrate(): void
    {
        require_once dirname(__DIR__).'/migrations/Version20261124010000.php';
        $migration = new \DoctrineMigrations\Version20261124010000($this->entityManager->getConnection(), new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) $this->entityManager->getConnection()->executeStatement($sql->getStatement());
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['email_writing_profile', 'email_writing_profile_revision', 'email_writing_profile_settings'] as $table) {
            $rows[$table] = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY rowid');
        }
        return $rows;
    }
}
