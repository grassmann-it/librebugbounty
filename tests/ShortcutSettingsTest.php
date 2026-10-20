<?php

namespace App\Tests;

use App\Service\SettingsService;
use App\Service\ShortcutSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ShortcutSettingsTest extends DatabaseTestCase
{
    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testDefaultsAndLegacySubmissionsPreserveBindingsAndPasteSetting(): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        $shortcuts = self::getContainer()->get(ShortcutSettings::class);
        self::assertFalse($settings->isPasteSubmitEnabled());
        self::assertSame(ShortcutSettings::DEFAULTS, $shortcuts->bindings());
        $bindings = ShortcutSettings::validate(['review.fixed' => 'A', 'nav.inventory' => 'Alt+Shift+B']);
        $settings->save(['ui.shortcuts' => json_encode($bindings), 'intake.paste_submit' => '1']);
        self::assertSame(302, $this->request('/settings', 'POST', ['default_payload' => 'MARKER', 'review_timeout_ms' => '45000'])->getStatusCode());
        self::assertSame($bindings, $shortcuts->bindings());
        self::assertTrue($settings->isPasteSubmitEnabled());
        $html = $this->request('/')->getContent();
        self::assertStringContainsString('data-intake-paste-submit="1"', $html);
        self::assertStringContainsString('"nav.inventory":"Alt+Shift+B"', $html);
        self::assertStringContainsString('"review.fixed":"A"', $html);
        $doc = new \DOMDocument();
        @$doc->loadHTML($this->request('/review')->getContent());
        $hints = (new \DOMXPath($doc))->query('//*[@data-shortcut-hint and not(ancestor::template)]');
        self::assertGreaterThan(0, $hints->length);
        foreach ($hints as $hint) self::assertTrue($hint->hasAttribute('hidden'), 'No-JS Review must not advertise inactive keyboard handlers.');
    }

    public function testPreferenceSaveUsesCsrfAndAtomicallyValidatesAllBindings(): void
    {
        $parameters = ['default_payload' => 'MARKER', 'review_timeout_ms' => '45000', 'paste_submit_present' => '1', 'paste_submit' => '1', 'shortcut_bindings' => ['review.fixed' => 'A', 'nav.inventory' => 'Alt+Shift+B']];
        self::assertSame(403, $this->request('/settings', 'POST', $parameters)->getStatusCode());
        $parameters['_token'] = $this->token();
        self::assertSame(302, $this->request('/settings', 'POST', $parameters)->getStatusCode());
        $settings = self::getContainer()->get(SettingsService::class);
        self::assertTrue($settings->isPasteSubmitEnabled());
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM setting ORDER BY id');
        $parameters['default_payload'] = 'SHOULD-NOT-SAVE';
        $parameters['shortcut_bindings']['review.confirmed'] = 'A';
        $response = $this->request('/settings', 'POST', $parameters);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Jedes Tastenkürzel darf nur einmal vergeben werden.', $response->getContent());
        self::assertStringContainsString('value="SHOULD-NOT-SAVE"', $response->getContent());
        self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM setting ORDER BY id'));
        unset($parameters['paste_submit']);
        $parameters['shortcut_bindings'] = ShortcutSettings::DEFAULTS;
        self::assertSame(302, $this->request('/settings', 'POST', $parameters)->getStatusCode());
        self::assertFalse($settings->isPasteSubmitEnabled());
        self::assertSame(ShortcutSettings::DEFAULTS, self::getContainer()->get(ShortcutSettings::class)->bindings());
    }

    public static function invalidBindings(): iterable
    {
        yield 'duplicate' => [['review.fixed' => 'Enter']];
        yield 'context collision' => [['review.fixed' => 'Alt+Shift+B', 'nav.inventory' => 'Alt+Shift+B']];
        yield 'browser paste' => [['review.fixed' => 'Ctrl+V']];
        yield 'browser tab' => [['nav.inventory' => 'Ctrl+Shift+T']];
        yield 'browser responsive design' => [['nav.inventory' => 'Ctrl+Shift+M']];
        yield 'altgr' => [['nav.inventory' => 'Ctrl+Alt+B']];
        yield 'plain nav' => [['nav.inventory' => 'B']];
        yield 'shift only nav' => [['nav.inventory' => 'Shift+B']];
        yield 'sequence' => [['nav.inventory' => 'G B']];
        yield 'duplicate modifier' => [['nav.inventory' => 'Alt+Alt+B']];
        yield 'unknown action' => [['arbitrary' => 'B']];
        yield 'nested value' => [['review.fixed' => ['A']]];
        yield 'explicit null' => [['review.fixed' => null]];
        yield 'not array' => ['invalid'];
    }

    #[DataProvider('invalidBindings')]
    public function testRejectsInvalidConflictingAndReservedBindings(mixed $bindings): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ShortcutSettings::validate($bindings);
    }

    public function testDisabledKeysAndCanonicalModifierOrderAndCorruptSettings(): void
    {
        $bindings = ShortcutSettings::validate(['review.fixed' => '', 'nav.inventory' => 'Shift+Alt+b']);
        self::assertSame('', $bindings['review.fixed']);
        self::assertSame('Alt+Shift+B', $bindings['nav.inventory']);
        foreach (['{"review.fixed":null}', '{broken', '42', '{"nav.inventory":"B"}'] as $corrupt) {
            self::getContainer()->get(SettingsService::class)->save(['ui.shortcuts' => $corrupt]);
            self::assertSame(ShortcutSettings::DEFAULTS, self::getContainer()->get(ShortcutSettings::class)->bindings());
        }
    }

    private function token(): string
    {
        $html = $this->request('/settings')->getContent();
        $doc = new \DOMDocument();
        @$doc->loadHTML($html);
        return (new \DOMXPath($doc))->evaluate('string(//form[@action="/settings"]//input[@name="_token"]/@value)');
    }

    private function request(string $path, string $method = 'GET', array $parameters = []): Response
    {
        $request = Request::create($path, $method, $parameters);
        $request->setSession($this->session);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }
}
