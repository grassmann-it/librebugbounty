<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\ContextTriageDetector;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\TestCase;

final class RuntimeDiagnosticsBridgeTest extends TestCase
{
    private function input(string $body, int $status = 200): ObservationInput
    {
        return ObservationInput::fromArray(['url' => 'https://example.test/item', 'rawHtml' => $body, 'httpStatus' => $status]);
    }

    public function testRenderedNoticeAtSuccessStatusHasBoundedRuntimeAndPathEvidence(): void
    {
        $body = 'Ä<br /><b>Notice</b>: Undefined index: syntheticKey in <b>/container/demo/controllers/ExampleController.php</b> on line <b>27</b><!doctype html>';
        $evaluation = (new ContextTriageDetector())->evaluateDiagnostics($this->input($body));
        self::assertSame('completed', $evaluation->status);
        $codes = array_column($evaluation->signals, 'code');
        self::assertContains('internal_information_exposed', $codes);
        self::assertContains('error_response', $codes);
        foreach ($evaluation->signals as $signal) {
            foreach ($signal['spans'] as $span) {
                self::assertSame(mb_substr($body, $span['start'], $span['length'], 'UTF-8'), $span['excerpt']);
                self::assertSame(hash('sha256', $body), $span['representationSha256']);
            }
        }
        self::assertSame(['disclosure', 'error_response'], array_column($evaluation->familyOutcomes, 'family'));
    }

    public function testJsonCatalogAndExampleProseAreNotRuntimeEvidence(): void
    {
        $notice = 'Notice: Undefined index: sample in /srv/app/example.php on line 42';
        $catalog = json_encode(['labels' => ['sample' => $notice]], JSON_THROW_ON_ERROR);
        $mixed = json_encode(['error' => ['status' => 500, 'title' => 'Internal Server Error', 'detail' => 'Call to undefined function demo()'], 'labels' => ['sample' => $notice]], JSON_THROW_ON_ERROR);
        foreach ([$catalog, $mixed, '<p>Example: "'.$notice.'"</p>'] as $body) {
            $signals = (new ContextTriageDetector())->evaluateDiagnostics($this->input($body))->signals;
            foreach ($signals as $signal) self::assertStringNotContainsString($notice, $signal['spans'][0]['excerpt']);
            if ($body !== $mixed) self::assertSame([], $signals);
            $fullInternals = array_values(array_filter((new ContextTriageDetector())->evaluate($this->input($body))->signals, static fn (array $signal): bool => $signal['code'] === 'internal_information_exposed'));
            self::assertSame([], $fullInternals);
        }
        $actual = '<p><b>Notice</b>: Undefined index: syntheticKey in <b>/container/demo/controllers/ExampleController.php</b> on line <b>27</b></p>';
        self::assertNotEmpty((new ContextTriageDetector())->evaluateDiagnostics($this->input($actual))->signals);
    }

    public function testOnlyBoundWordPressErrorObjectCanDiscloseJsonPath(): void
    {
        $error = ['type' => 8, 'message' => 'Undefined index: syntheticKey', 'file' => '/srv/app/example.php', 'line' => 27];
        $catalog = json_encode(['labels' => ['error' => $error]], JSON_THROW_ON_ERROR);
        $actual = json_encode(['code' => 'internal_server_error', 'data' => ['status' => 500, 'error' => $error]], JSON_THROW_ON_ERROR);
        $mixed = json_encode(['code' => 'internal_server_error', 'labels' => ['error' => $error], 'data' => ['status' => 500, 'error' => $error]], JSON_THROW_ON_ERROR);
        self::assertSame([], (new ContextTriageDetector())->evaluateDiagnostics($this->input($catalog))->signals);
        self::assertSame([], array_values(array_filter((new ContextTriageDetector())->evaluate($this->input($catalog))->signals, static fn (array $signal): bool => $signal['code'] === 'internal_information_exposed')));
        foreach ([$actual, $mixed] as $body) {
            $signals = (new ContextTriageDetector())->evaluateDiagnostics($this->input($body))->signals;
            $internals = array_values(array_filter($signals, static fn (array $signal): bool => $signal['code'] === 'internal_information_exposed'));
            self::assertCount(1, $internals);
            $fullInternals = array_values(array_filter((new ContextTriageDetector())->evaluate($this->input($body))->signals, static fn (array $signal): bool => $signal['code'] === 'internal_information_exposed'));
            self::assertCount(1, $fullInternals);
            self::assertStringContainsString('example.php', $internals[0]['spans'][0]['excerpt']);
            self::assertSame(hash('sha256', $body), $internals[0]['spans'][0]['representationSha256']);
        }
    }

    public function testStructuredScriptFramesBindOnlyToRootFields(): void
    {
        $frames = ["vendor/demo/adapter.rb:41:in `execute'", "vendor/demo/adapter.rb:42:in `block in query'"];
        $ruby = ['error' => ['status_code' => 500, 'details' => $frames]];
        $luaTrace = "\nstack traceback:\n\t./lib/demo.lua:41: in function 'read'\n\t./app.lua:82: in function 'handle'";
        $lua = ['traceback' => $luaTrace];
        foreach ([$ruby, $lua] as $structured) {
            foreach ([$structured, ['labels' => $structured], $structured + ['labels' => $structured]] as $index => $object) {
                $body = json_encode($object, JSON_THROW_ON_ERROR);
                foreach (['evaluate', 'evaluateDiagnostics'] as $method) {
                    $signals = (new ContextTriageDetector())->$method($this->input($body))->signals;
                    $stacks = array_values(array_filter($signals, static fn (array $signal): bool => $signal['code'] === 'internal_information_exposed' && ($signal['attributes']['detailKind'] ?? '') === 'stack_trace'));
                    self::assertCount($index === 1 ? 0 : 1, $stacks);
                }
            }
        }
    }

    public function testDiagnosticEntryPointExcludesCatalogsAndGenericTitles(): void
    {
        $notice = '<b>Notice</b>: Undefined index: syntheticKey in <b>/container/demo/controllers/ExampleController.php</b> on line <b>27</b>';
        foreach (['<script>var i18n = '.json_encode([$notice]).';</script>', '<section class="documentation">'.$notice.'</section>', '<code>'.$notice.'</code>', '<h1>Not Found</h1>', '<h1>Internal Server Error</h1>'] as $body) {
            self::assertSame([], (new ContextTriageDetector())->evaluateDiagnostics($this->input($body, 500))->signals, $body);
        }
    }
}
