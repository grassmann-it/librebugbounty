<?php

namespace App\Tests;

use App\Command\CribrumClassifyCommand;
use App\Cribrum\CribrumClassifier;
use App\Service\CribrumProbeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CribrumClassifyCommandTest extends TestCase
{
    private const URL = 'https://fixture.test/search?q=%3COPENBUG%3E';
    private const BODY = '<html>You have an error in your SQL syntax &lt;OPENBUG&gt;</html>';

    public function testOfflineHttpResponseUsesRealClassifierWithContextAndExplanations(): void
    {
        $file = $this->file("HTTP/1.1 500 Error\r\nContent-Type: text/html\r\nSet-Cookie: private=secret\r\n\r\n".self::BODY);
        $hash = hash_file('sha256', $file);
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['url' => self::URL, '--response' => $file, '--marker' => '<OPENBUG>', '--format' => 'json', '--explain' => true]));
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('file', $report['source']['mode']);
        self::assertSame(500, $report['source']['httpStatus']);
        self::assertSame('text/html', $report['source']['contentType']);
        $claims = array_column($report['classification']['suggestions'], 'state', 'claim');
        self::assertSame('observed', $claims['information_disclosure']);
        self::assertSame('suspected', $claims['sql_injection']);
        self::assertArrayNotHasKey('xss', $claims);
        self::assertNotEmpty($report['rules']);
        self::assertNotEmpty(array_filter($report['rules'], static fn (array $rule): bool => $rule['outcome'] === 'excluded' && $rule['exclusion'] !== null));
        self::assertNotEmpty($report['classification']['packs']['core']);
        self::assertStringNotContainsString('Set-Cookie', $tester->getDisplay());
        self::assertStringNotContainsString('private=secret', $tester->getDisplay());
        self::assertSame($hash, hash_file('sha256', $file));
    }

    public function testBodyOverridesAndDomTextUseTheSameClassifierWithoutInventingExecution(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['url' => self::URL, '--response' => $this->file(self::BODY),
            '--dom' => $this->file('You have an error in your SQL syntax <OPENBUG>'), '--status' => '500',
            '--content-type' => 'text/html', '--marker' => '<OPENBUG>', '--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(500, $report['source']['httpStatus']);
        self::assertGreaterThan(0, $report['source']['domBytes']);
        self::assertArrayNotHasKey('xss', array_column($report['classification']['suggestions'], 'state', 'claim'));
        self::assertArrayNotHasKey('rules', $report);
        self::assertSame([], $report['classification']['diagnostics']);
    }

    public function testBodyOnlyWithoutStatusIsExplicitAboutLimitedContext(): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--response' => $this->file(self::BODY), '--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($report['source']['httpStatus']);
        // A concrete diagnostic is observable without a status or URL; neither
        // SQL injection nor XSS execution can be inferred from this body alone.
        self::assertSame(['error_disclosure' => 'observed'],
            array_column($report['classification']['suggestions'], 'state', 'claim'));
        self::assertStringContainsString('No URL context supplied', implode(' ', $report['warnings']));
        self::assertStringContainsString('HTTP status is unknown', implode(' ', $report['warnings']));
    }

    public function testStdinAcceptsACompleteHttpResponse(): void
    {
        $tester = $this->tester();
        $tester->setInputs(["HTTP/2 500\nContent-Type: text/html\n\n".self::BODY]);
        self::assertSame(0, $tester->execute(['url' => self::URL, '--response' => '-', '--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('stdin', $report['source']['mode']);
        self::assertContains('sql_injection', array_column($report['classification']['suggestions'], 'claim'));
    }

    public function testUrlModeFetchesOnceAndNeverPromotesReflectionToObservedXss(): void
    {
        $client = new MockHttpClient(new MockResponse('<p>OPENBUG</p>', ['http_code' => 200, 'response_headers' => ['Content-Type: text/html']]));
        $tester = $this->tester($client);
        self::assertSame(0, $tester->execute(['url' => 'https://fixture.test/', '--marker' => 'OPENBUG', '--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('url', $report['source']['mode']);
        self::assertSame(['xss' => 'suspected'], array_column($report['classification']['suggestions'], 'state', 'claim'));
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testNoHitAndRedirectAreSuccessfulEvaluationsWithClearLimits(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://other.invalid/']]));
        $tester = $this->tester($client);
        self::assertSame(0, $tester->execute(['url' => self::URL, '--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $report['classification']['suggestions']);
        self::assertStringContainsString('redirects are not followed', implode(' ', $report['warnings']));
        self::assertSame(1, $client->getRequestsCount());
    }

    public static function invalidOptions(): iterable
    {
        yield 'no source' => [[]];
        yield 'non-http' => [['url' => 'file:///etc/passwd']];
        yield 'status' => [['url' => self::URL, '--status' => 'abc']];
        yield 'status range' => [['url' => self::URL, '--status' => '600']];
        yield 'type' => [['url' => self::URL, '--content-type' => "text/html\r\nInjected: yes"]];
        yield 'two stdin sources' => [['--response' => '-', '--dom' => '-']];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsReturnJsonAndNeverFetch(array $options): void
    {
        $tester = $this->tester();
        self::assertSame(2, $tester->execute($options + ['--format' => 'json']));
        self::assertNotEmpty(json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['error']);
    }

    public function testUnreadableOrOversizeLocalInputsDoNotFetchOrSilentlyTruncate(): void
    {
        $tester = $this->tester();
        self::assertSame(1, $tester->execute(['url' => self::URL, '--dom' => APP_TEST_ROOT.'/does-not-exist', '--format' => 'json']));
        self::assertSame(2, $tester->execute(['url' => self::URL, '--dom' => $this->file(str_repeat('a', CribrumProbeSource::DOM_LIMIT + 1)), '--format' => 'json']));
        self::assertSame(2, $tester->execute(['url' => self::URL, '--response' => $this->file(str_repeat('a', CribrumProbeSource::RAW_LIMIT + CribrumProbeSource::HEADER_LIMIT + 1)), '--format' => 'json']));
        self::assertSame(1, $tester->execute(['url' => self::URL, '--response' => 'https://fixture.test/not-a-local-file', '--format' => 'json']));
    }

    public function testLiteralExcerptsCannotInjectConsoleMarkupOrTerminalControls(): void
    {
        $body = "You have an error in your SQL syntax &lt;info&gt;OPENBUG&lt;/info&gt; \x1b[31m";
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['url' => 'https://fixture.test/?q=OPENBUG', '--response' => $this->file($body), '--status' => '500']));
        $display = $tester->getDisplay();
        self::assertStringContainsString('<info>OPENBUG</info>', $display);
        self::assertStringNotContainsString("\x1b", $display);
        self::assertStringContainsString('sql_injection', $display);
        self::assertStringContainsString('suspected', $display);
        self::assertStringContainsString('Classifier:', $display);
    }

    public static function filePayloads(): iterable
    {
        yield 'local' => ['../../etc/passwd', 'local_file_inclusion'];
        yield 'remote' => ['https://payload.invalid/example.txt', 'remote_file_inclusion'];
    }

    #[DataProvider('filePayloads')]
    public function testExplicitPayloadSuppliesFileInclusionContextButNeverConfirmsIt(string $payload, string $claim): void
    {
        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--response' => $this->file('Warning: include('.$payload.'): failed to open stream'),
            '--status' => '500', '--payload' => $payload, '--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('suspected', array_column($report['classification']['suggestions'], 'state', 'claim')[$claim]);
    }

    public function testDomCanReadStdinAlongsideAnOfflineBodyFile(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['You have an error in your SQL syntax OPENBUG']);
        self::assertSame(0, $tester->execute(['url' => 'https://fixture.test/?q=OPENBUG', '--response' => $this->file(''),
            '--dom' => '-', '--status' => '500', '--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('sql_injection', array_column($report['classification']['suggestions'], 'claim'));
    }

    public function testTransportErrorsAreMachineReadableAndDoNotLeakTransportDetails(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('private transport details');
        });
        $tester = $this->tester($client);
        self::assertSame(1, $tester->execute(['url' => self::URL, '--format' => 'json']));
        self::assertNotEmpty(json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['error']);
        self::assertStringNotContainsString('private transport details', $tester->getDisplay());
    }

    public function testUnknownOutputFormatFailsBeforeFetching(): void
    {
        $tester = $this->tester();
        self::assertSame(2, $tester->execute(['url' => self::URL, '--format' => 'yaml']));
        self::assertStringContainsString('--format=text or --format=json', $tester->getDisplay());
    }

    public function testInvalidPrivatePackErrorsAreNotReportedAsAnUnqualifiedSuccess(): void
    {
        $directory = APP_TEST_ROOT.'/bad-probe-packs';
        mkdir($directory);
        file_put_contents($directory.'/broken.json', '{broken');
        $tester = new CommandTester(new CribrumClassifyCommand(new CribrumClassifier($directory), new CribrumProbeSource(new MockHttpClient())));
        self::assertSame(1, $tester->execute(['--response' => $this->file('No findings'), '--format' => 'json']));
        self::assertNotEmpty(json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['classification']['diagnostics']);
    }

    public function testCandidateLimitReturnsFullJsonAndTextWarningWithSuccessfulInvocation(): void
    {
        $file = $this->file(str_repeat("Failed opening required '/var/www/private/view.php' (include_path='.:/usr/lib/php')\n", 13));
        $options = ['--response' => $file, '--candidate-packs' => dirname(__DIR__).'/resources/cribrum/candidates', '--candidate-mode' => 'active', '--capture-complete' => true];
        $tester = $this->tester();
        self::assertSame(0, $tester->execute($options + ['--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('partial', $report['classification']['coverage']['status']);
        self::assertSame([], $report['classification']['diagnostics']);
        self::assertNotEmpty($report['classification']['signals']);
        self::assertStringContainsString('match_budget', json_encode($report['classification']['coverage']['evaluation']));
        self::assertSame(0, $tester->execute($options));
        self::assertStringContainsString('Incomplete evaluation', $tester->getDisplay());
        self::assertStringContainsString('Coverage: partial', $tester->getDisplay());
    }

    public function testCandidateRegexErrorReturnsStructuredPartialResultAndFailureExit(): void
    {
        $module = "Module example.app, line 1, in foo\n  Module example.next, line 2, in bar";
        $file = $this->file($module."\nFailed opening required '/var/www/private/view.php' (include_path='.:/usr/lib/php')".str_repeat(' ', 500).'x Cloudflare HTTP 500');
        $previous = ini_get('pcre.backtrack_limit');
        try {
            ini_set('pcre.backtrack_limit', '30');
            $tester = $this->tester();
            self::assertSame(1, $tester->execute(['--response' => $file, '--candidate-packs' => dirname(__DIR__).'/resources/cribrum/candidates', '--capture-complete' => true, '--format' => 'json']));
            $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
        self::assertSame('partial', $report['classification']['coverage']['status']);
        self::assertNotEmpty($report['classification']['diagnostics']);
        self::assertNotEmpty($report['classification']['signals']);
        self::assertStringContainsString('regex_error', json_encode($report['classification']['coverage']['evaluation']));
        self::assertStringNotContainsString('/var/www/private/view.php', implode(' ', $report['classification']['diagnostics']));
    }

    private function tester(?MockHttpClient $client = null): CommandTester
    {
        return new CommandTester(new CribrumClassifyCommand(new CribrumClassifier(APP_TEST_ROOT.'/unused-probe-packs'),
            new CribrumProbeSource($client ?? new MockHttpClient(static fn () => self::fail('Unexpected target request.')))));
    }

    private function file(string $contents): string
    {
        $path = APP_TEST_ROOT.'/probe-'.bin2hex(random_bytes(6));
        file_put_contents($path, $contents);
        return $path;
    }
}
