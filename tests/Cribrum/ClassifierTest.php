<?php

namespace App\Tests\Cribrum;

use App\Cribrum\ClassificationResult;
use App\Cribrum\CribrumClassifier;
use App\Cribrum\ObservationInput;
use App\Cribrum\Taxonomy;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Cribrum\RulePackRepository;

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

    public function testNativeDialogWithExactShortMarkerObservesXss(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/xss?q=%3Cscript%3Ealert%283%29%3C%2Fscript%3E',
            '<input value="<script>alert(3)</script>">',
            'Search',
            'text/html',
            200,
            null,
            null,
            '3',
            true,
            '3',
        ));
        $this->assertClaims($result, ['xss' => 'observed']);
    }

    public function testShortMarkerSubstringInUnrelatedDialogDoesNotObserveXss(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/xss?q=%3Cscript%3Ealert%283%29%3C%2Fscript%3E',
            '<p>Search failed with error 5003</p>',
            'Search failed with error 5003',
            'text/html',
            200,
            null,
            null,
            '3',
            true,
            'Search failed with error 5003',
        ));
        $this->assertSame([], $result->suggestions);
    }

    public function testRequestSpecificSqlQueryEchoIsDisclosureWithoutSqlInjectionClaim(): void
    {
        $query = "SELECT id, name FROM items WHERE name LIKE '%<script>alert(3)</script>%' AND active = 1";
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/search?s=%3Cscript%3Ealert%283%29%3C%2Fscript%3E',
            '<p>'.$query.'</p>',
            "SELECT id, name FROM items WHERE name LIKE '%alert(3)%' AND active = 1",
            'text/html',
            200,
            null,
            null,
            '3',
            false,
            null,
        ));
        $this->assertClaims($result, ['information_disclosure' => 'observed']);
        $this->assertContains('sql-query-echo', array_column($result->toArray()['signals'], 'rule'));
        $this->assertNotContains('sql_injection', array_column($result->toArray()['suggestions'], 'claim'));
    }

    public function testRequestValueInSqlSyntaxErrorAtHttpStatus200IsSqlSuspicion(): void
    {
        $payload = '-->\'"><\/title><\/iframe><\/script><img src=0 onerror=alert(\'OPENBUGBOUNTY\')>';
        $body = "SELECT * FROM STD_PAGES WHERE REC_STATE='CURRENT' AND NAME='{$payload}' AND FK_LANGUAGE_KEY = 3 "
            .":: You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version "
            ."for the right syntax to use near '\"><\/title><\/iframe><\/script><img src=0 onerror=alert('OPENBUGBOUNTY')>' AND F...' at line 1";
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/?page='.rawurlencode($payload),
            $body,
            $body,
            'text/html',
            200,
            null,
            $payload,
            'OPENBUGBOUNTY',
            false,
            null,
        ));
        $this->assertClaims($result, [
            'error_disclosure' => 'observed',
            'information_disclosure' => 'observed',
            'sql_injection' => 'suspected',
            'xss' => 'suspected',
        ]);
        $this->assertContains('sql-error-request-value', array_column($result->toArray()['signals'], 'rule'));
    }

    public function testReflectedNavigationWithOraInPlaceNameIsNotAnOracleDiagnostic(): void
    {
        $payload = '\'--><img src=0 onerror=alert(123)>';
        $body = '<a href="hedemora-dack/tires?vehicle_type_id='.$payload.'">Tires</a>';
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/webshop?vehicle_type_id='.rawurlencode($payload),
            $body, 'Tires', 'text/html', 200, null, $payload, null, false, null,
        ));
        self::assertNotContains('sql_injection', array_column($result->toArray()['suggestions'], 'claim'));
        self::assertNotContains('sql-error-request-value', array_column($result->toArray()['signals'], 'rule'));
    }

    public function testNumberedOracleErrorStillRetainsRequestBoundSuspicion(): void
    {
        $body = "SELECT * FROM items WHERE id='OPENBUG' ORA-00933: SQL command not properly ended";
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/?id=OPENBUG', $body, $body, 'text/html', 200, null, 'OPENBUG', null, false, null,
        ));
        self::assertContains('sql_injection', array_column($result->toArray()['suggestions'], 'claim'));
    }

    public function testSqlDocumentationWithSeparateReflectionDoesNotBecomeSqlSuspicion(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/docs?q=OPENBUG',
            "Example: SELECT id FROM items WHERE name = 'example'\nSearch: OPENBUG\nDatabase error: You have an error in your SQL syntax",
            "Example: SELECT id FROM items WHERE name = 'example' Search: OPENBUG Database error: You have an error in your SQL syntax",
            'text/html',
            200,
            null,
            null,
            'OPENBUG',
            false,
            null,
        ));
        $this->assertNotContains('sql_injection', array_column($result->toArray()['suggestions'], 'claim'));
    }

    public function testShortSqlQueryWithRequestValueIsDisclosure(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/search?q=OPENBUG',
            "<p>SELECT id FROM items WHERE name = 'OPENBUG'</p>",
            "SELECT id FROM items WHERE name = 'OPENBUG'",
            'text/html',
            200,
            null,
            null,
            null,
            false,
            null,
        ));
        $this->assertClaims($result, ['information_disclosure' => 'observed']);
    }

    public function testGenericSqlDocumentationDoesNotBecomeDisclosure(): void
    {
        $result = $this->classifier()->classify(new ObservationInput(
            'https://fixture.test/docs?q=OPENBUG',
            '<p>Example: SELECT id FROM items WHERE name = \'example\'</p>',
            "Example: SELECT id FROM items WHERE name = 'example'",
            'text/html',
            200,
            null,
            null,
            'OPENBUG',
            false,
            null,
        ));
        $this->assertSame([], $result->suggestions);
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

    public static function corpus(): iterable
    {
        $corpus = self::loadCorpus();
        foreach ($corpus['cases'] as $case) {
            yield $corpus['version'].' pack/core@'.$corpus['packs']['core'].' '.$corpus['classifierVersion'].' '.$case['id'] => [$case];
        }
    }

    #[DataProvider('corpus')]
    public function testVersionedCorpus(array $case): void
    {
        $trace = [];
        $result = $this->classifier()->classify(ObservationInput::fromArray($case['input']), $trace);
        $this->assertClaims($result, $case['claims']);
        foreach ($case['excludedClaims'] ?? [] as $claim) self::assertNotContains($claim, array_column($result->toArray()['suggestions'], 'claim'));
        $hits = array_column($result->signals, 'rule');
        foreach ($case['positiveRules'] ?? [] as $rule) self::assertContains($rule, $hits);
        foreach ($case['negativeRules'] ?? [] as $rule) self::assertNotContains($rule, $hits);
        foreach ($trace as $evaluation) {
            if ($evaluation['pack'] === 'builtin') continue;
            self::assertSame('core', $evaluation['pack']);
            self::assertSame(self::loadCorpus()['packs']['core'], $evaluation['version']);
            if ($evaluation['outcome'] === 'hit') self::assertNotNull($evaluation['span']);
            else self::assertNotEmpty($evaluation['exclusion']);
        }
        self::assertSame($result->toArray(), $this->classifier()->classify(ObservationInput::fromArray($case['input']))->toArray());
    }

    public function testCorpusCoversEveryDefaultRuleAndReportsVersions(): void
    {
        $corpus = self::loadCorpus();
        $result = $this->classifier()->classify(ObservationInput::fromArray(['url' => 'https://fixture.test/empty']));
        self::assertSame($corpus['packs'], $result->packs);
        self::assertSame($corpus['classifierVersion'], $result->classifierVersion);
        fwrite(STDERR, "\nC4 corpus=".$corpus['version'].' pack/core='.$result->packs['core'].' classifier/core='.$result->classifierVersion."\n");
        $positives = $negatives = [];
        foreach ($corpus['cases'] as $case) {
            $positives = [...$positives, ...($case['positiveRules'] ?? [])];
            $negatives = [...$negatives, ...($case['negativeRules'] ?? [])];
        }
        foreach ((new RulePackRepository('/nonexistent-private-cribrum-packs'))->load()['packs'] as $pack) {
            foreach ($pack->rules as $rule) {
                self::assertContains($rule->id, $positives, 'Missing positive: '.$rule->id);
                self::assertContains($rule->id, $negatives, 'Missing relevant negative: '.$rule->id);
            }
        }
    }

    public static function responseLimits(): iterable
    {
        yield 'raw at limit' => ['rawHtml', 1048576, false];
        yield 'raw oversize' => ['rawHtml', 1048577, true];
        yield 'DOM at limit' => ['domText', 524288, false];
        yield 'DOM oversize' => ['domText', 524289, true];
    }

    #[DataProvider('responseLimits')]
    public function testResponseLimits(string $field, int $bytes, bool $rejected): void
    {
        if ($rejected) $this->expectException(\InvalidArgumentException::class);
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/size', $field => str_repeat('a', $bytes)]);
        self::assertSame([], $this->classifier()->classify($input)->suggestions);
    }

    private static function loadCorpus(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/cribrum/corpus-v1.json'), true, 512, JSON_THROW_ON_ERROR);
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
