<?php

namespace App\Tests\Cribrum;

use App\Cribrum\ClassificationResult;
use App\Cribrum\CribrumClassifier;
use App\Cribrum\ObservationInput;
use App\Cribrum\Taxonomy;
use PHPUnit\Framework\TestCase;

final class ClassifierTest extends TestCase
{
    public function testFrameworkErrorDoesNotInventSqlInjection(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/laravel?x=1',
            '<html><title>Whoops! There was an error</title></html>',
            'Whoops! There was an error',
            'text/html',
            500,
            'https://fixture.test/laravel?x=1',
            null,
            null,
            false,
            null,
        ));
        $this->assertClaims($result, [
            'error_disclosure' => 'observed',
            'information_disclosure' => 'observed',
        ]);
        $this->assertNotContains('sql_injection', array_column($result->toArray()['suggestions'], 'claim'));
    }

    public function testEncodedMarkerInDatabaseErrorStaysDisclosureAndSqlSuspicionWithoutXss(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/search?q=%3COPENBUG%3E',
            '<html>Warning: You have an error in your SQL syntax &lt;OPENBUG&gt;</html>',
            'Warning: You have an error in your SQL syntax <OPENBUG>',
            'text/html',
            500,
            'https://fixture.test/search?q=%3COPENBUG%3E',
            null,
            '<OPENBUG>',
            false,
            null,
        ));
        $this->assertClaims($result, [
            'error_disclosure' => 'observed',
            'information_disclosure' => 'observed',
            'sql_injection' => 'suspected',
        ]);
        $this->assertNotContains('xss', array_column($result->toArray()['suggestions'], 'claim'));
        $this->assertContains('reflection/entity-encoded', array_column($result->toArray()['signals'], 'rule'));
    }

    public function testNativeDialogWithMarkerObservesXss(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/xss?q=OPENBUG',
            '<html></html>', '', 'text/html', 200, null, 'OPENBUG', 'OPENBUG', true, 'OPENBUG alert',
        ));
        $this->assertClaims($result, ['xss' => 'observed']);
    }

    public function testPlainReflectionWithoutDialogIsOnlyXssSuspicion(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/xss?q=OPENBUG', '<div>OPENBUG</div>', 'OPENBUG', 'text/html', 200, null, null, 'OPENBUG', false, null,
        ));
        $this->assertClaims($result, ['xss' => 'suspected']);
    }

    public function testPathParameterInFileErrorCreatesLfiSuspicion(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/include?page=..%2F..%2Fetc%2Fpasswd',
            "<p>include(): Failed opening '../../etc/passwd' for inclusion</p>",
            "include(): Failed opening '../../etc/passwd' for inclusion",
            'text/html', 500, null, null, null, false, null,
        ));
        $this->assertClaims($result, [
            'error_disclosure' => 'observed',
            'local_file_inclusion' => 'suspected',
        ]);
        $this->assertNotContains('remote_file_inclusion', array_column($result->toArray()['suggestions'], 'claim'));
    }

    public function testUrlParameterInIncludeErrorCreatesRfiSuspicion(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/include?page=https%3A%2F%2Ffixture.test%2Fremote.php',
            'include(): Failed opening \'https://fixture.test/remote.php\' for inclusion',
            "include(): Failed opening 'https://fixture.test/remote.php' for inclusion",
            'text/html', 500, null, null, null, false, null,
        ));
        $this->assertClaims($result, [
            'error_disclosure' => 'observed',
            'remote_file_inclusion' => 'suspected',
        ]);
    }

    public function testUnrelatedUrlParameterCannotTurnALocalPathIntoRfi(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/include?page=..%2F..%2Fetc%2Fpasswd&returnTo=https%3A%2F%2Ffixture.test%2Fhome',
            "include(): Failed opening '../../etc/passwd' for inclusion",
            "include(): Failed opening '../../etc/passwd' for inclusion",
            'text/html', 500, null, null, null, false, null,
        ));
        $this->assertClaims($result, [
            'error_disclosure' => 'observed',
            'local_file_inclusion' => 'suspected',
        ]);
        self::assertNotContains('remote_file_inclusion', array_column($result->toArray()['suggestions'], 'claim'));
    }

    public function testDatabaseErrorWithoutReflectedInputDoesNotSuggestSqlInjection(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/support', '<p>You have an error in your SQL syntax; see our tutorial.</p>',
            'You have an error in your SQL syntax; see our tutorial.', 'text/html', 500, null, null, null, false, null,
        ));
        $this->assertClaims($result, [
            'error_disclosure' => 'observed',
            'information_disclosure' => 'observed',
        ]);
    }

    public function testVisibleLocalFileContentObservesLfi(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/file', null, "root:x:0:0:root:/root:/bin/bash\n", 'text/plain', 200, null, '../../etc/passwd', null, false, null,
        ));
        $this->assertClaims($result, ['local_file_inclusion' => 'observed']);
    }

    public function testDocumentationLikeSuccessResponseDoesNotClaimError(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/docs',
            '<article>You have an error in your SQL syntax when quoting is wrong.</article>',
            'You have an error in your SQL syntax when quoting is wrong.',
            'text/html', 200, null, null, null, false, null,
        ));
        $this->assertSame([], $result->suggestions);
    }

    public function testPrivatePackShadowAndBrokenPackDiagnostics(): void
    {
        $directory = sys_get_temp_dir().'/cribrum-packs-'.bin2hex(random_bytes(5));
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/custom.json', json_encode([
            'schema' => 1, 'id' => 'custom', 'version' => 'test',
            'rules' => [
                ['id' => 'custom/marker', 'representation' => 'text', 'any' => ['CIBRUM-CUSTOM'], 'claim' => 'information_disclosure', 'state' => 'observed', 'score' => 91, 'reason' => 'Custom marker matched.'],
                ['id' => 'custom/shadow', 'representation' => 'text', 'any' => ['SHADOW-SIGNAL'], 'claim' => 'information_disclosure', 'state' => 'observed', 'score' => 30, 'reason' => 'Shadow only.', 'shadow' => true],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/broken.json', '{');
        $input = new ObservationInput('https://fixture.test/custom', null, "CIBRUM-CUSTOM SHADOW-SIGNAL\n", 'text/plain', 200, null, null, null, false, null);
        $result = (new CribrumClassifier($directory))->classify($input);
        $this->assertClaims($result, ['information_disclosure' => 'observed']);
        $this->assertContains('custom/shadow', array_column($result->toArray()['signals'], 'rule'));
        $this->assertStringContainsString('Rule pack broken skipped', implode("\n", $result->diagnostics));
        (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
    }

    public function testSameInputIsByteIdenticalAndDtoRoundTrips(): void
    {
        $classifier = $this->classifier();
        $array = [
            'url' => 'https://fixture.test/sql?q=1',
            'rawHtml' => '<p>You have an error in your SQL syntax</p>',
            'domText' => 'You have an error in your SQL syntax',
            'contentType' => 'text/html',
            'httpStatus' => 500,
            'finalUrl' => 'https://fixture.test/final',
            'payload' => "1'",
            'expectedEvidence' => 'OPENBUG',
            'dialogSeen' => false,
            'dialogText' => null,
        ];
        $first = $classifier->classify(ObservationInput::fromArray($array));
        $second = $classifier->classify(ObservationInput::fromArray($array));
        self::assertSame($first->toArray(), $second->toArray());
        self::assertSame($array, ObservationInput::fromArray($array)->toArray());
    }

    public function testInputLimitsAndTaxonomy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObservationInput('https://fixture.test/a', str_repeat('a', 1048577), null, 'text/html', 200, null, null, null, false, null);
    }

    private function classifier(): CribrumClassifier
    {
        return new CribrumClassifier('/nonexistent-private-cribrum-packs');
    }

    /** @param array<string,string> $expected */
    private function assertClaims(ClassificationResult $result, array $expected): void
    {
        $actual = [];
        foreach ($result->suggestions as $suggestion) $actual[$suggestion->claim] = $suggestion->state;
        self::assertSame($expected, $actual);
        foreach ($actual as $claim => $state) {
            self::assertTrue(Taxonomy::isClaim($claim));
            self::assertTrue(Taxonomy::isState($state));
        }
    }
}
