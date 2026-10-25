<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Detector\FileTriageDetector;
use App\Cribrum\EvidenceSignal;
use App\Cribrum\ObservationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileTriageDetectorTest extends TestCase
{
    public function testTemplateCatalogsAreNotRuntimeDiagnostics(): void
    {
        $phrase = 'JSON data is malformed. Unable to load template.';
        $bodies = [
            '<script>window.labels={"template":"'.$phrase.'"};</script>',
            '<script type="application/json">{"template":"'.$phrase.'"}</script>',
            '<div class="documentation"><p>'.$phrase.'</p></div>',
            '<template><p>'.$phrase.'</p></template>',
            json_encode(['messages' => ['template' => $phrase], 'selectTemplate' => 'Select Template'], JSON_THROW_ON_ERROR),
            json_encode(['template' => $phrase, 'selectTemplate' => 'Select Template'], JSON_THROW_ON_ERROR),
        ];
        foreach ($bodies as $body) {
            $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?page=template', 'rawHtml' => $body, 'payload' => 'template']);
            self::assertSame([], array_column((new FileTriageDetector())->detect($input), 'code'), $body);
        }
    }

    public function testVisibleAndStructuredTemplateFailuresRemainDiagnosticOnly(): void
    {
        foreach (['<p>JSON data is malformed. Unable to load template.</p>',
            json_encode(['error' => ['message' => 'Unable to load template.']], JSON_THROW_ON_ERROR),
            json_encode(['message' => 'Unable to load template.'], JSON_THROW_ON_ERROR),
        ] as $body) {
            $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?page=template', 'rawHtml' => $body, 'payload' => 'template']);
            $signals = (new FileTriageDetector())->detect($input);
            self::assertSame(['file_include_diagnostic'], array_column($signals, 'code'));
            EvidenceSignal::validateAgainstInput($signals[0], $input);
        }
    }

    public function testJsonErrorDoesNotAuthorizeUnrelatedCatalogWarning(): void
    {
        $body = json_encode([
            'error' => ['message' => 'Unable to load template.'],
            'labels' => ['warning' => 'Warning: include(usercontrolled): failed to open stream: No such file or directory'],
        ], JSON_THROW_ON_ERROR);
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/?page=usercontrolled', 'rawHtml' => $body, 'payload' => 'usercontrolled']);
        $signals = (new FileTriageDetector())->detect($input);
        self::assertSame(['file_include_diagnostic'], array_column($signals, 'code'));
        self::assertStringContainsString('Unable to load template', $signals[0]['spans'][0]['excerpt']);
        EvidenceSignal::validateAgainstInput($signals[0], $input);

        $catalog = json_encode(['message' => 'Unable to load template.', 'selectTemplate' => 'Select Template'], JSON_THROW_ON_ERROR);
        self::assertSame([], (new FileTriageDetector())->detect(ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $catalog])));
    }
    public static function cases(): iterable
    {
        $cases = json_decode(file_get_contents(__DIR__.'/../Fixtures/cribrum/file-triage/cases.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) yield $case['id'] => [$case];
    }

    #[DataProvider('cases')]
    public function testSyntheticInputTargetRelations(array $case): void
    {
        $input = ObservationInput::fromArray([
            'url' => $case['url'] ?? 'https://fixture.test/', 'rawHtml' => $case['body'],
            'payload' => $case['payload'] ?? null, 'finalUrl' => $case['finalUrl'] ?? null,
            'httpStatus' => $case['httpStatus'] ?? null,
        ]);
        $signals = (new FileTriageDetector())->detect($input);
        self::assertSame($case['codes'], array_column($signals, 'code'));
        foreach ($signals as $signal) {
            EvidenceSignal::validateAgainstInput($signal, $input);
            self::assertSame('shadow', $signal['mode']);
            self::assertFalse($signal['attributes']['realPositiveCorpusValidated']);
            if (isset($case['target'])) self::assertSame($case['target'], $signal['attributes']['target']);
            if ($case['operationAbsent'] ?? false) {
                self::assertArrayNotHasKey('operation', $signal['attributes']);
                self::assertArrayNotHasKey('inputMapping', $signal['attributes']);
                self::assertSame([], $signal['relations']);
                self::assertCount(2, $signal['spans']);
                self::assertTrue($signal['attributes']['blockComplete']);
            }
            if (isset($case['diagnosticRaw'])) self::assertSame($case['diagnosticRaw'], $signal['spans'][0]['excerpt']);
            if (isset($case['targetRaw'])) self::assertSame($case['targetRaw'], $signal['spans'][1]['excerpt']);
            if (in_array($signal['code'], ['lfi_suspected', 'rfi_suspected'], true)) {
                self::assertTrue($signal['attributes']['attemptOnly']);
                self::assertFalse($signal['attributes']['fileReadProven']);
                self::assertFalse($signal['attributes']['codeExecutionProven']);
                self::assertNotEmpty($signal['relations']);
                if (isset($case['mappedParameter'])) {
                    self::assertSame($case['mappedParameter'], $signal['attributes']['inputMapping']['parameter']);
                    self::assertSame($case['mappedValue'], $signal['attributes']['inputMapping']['value']);
                    self::assertSame(['url_decode_once'], $signal['attributes']['inputMapping']['transformations']);
                }
            }
        }
        self::assertSame($signals, (new FileTriageDetector())->detect($input));
        self::assertSame($signals, (new FileTriageDetector())->evaluate($input)->signals);
    }

    public function testNoRawBodyDoesNotFabricateRawEvidenceFromDom(): void
    {
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/', 'domText' => 'Warning: include(../../etc/passwd): failed to open stream: No such file or directory', 'payload' => '../../etc/passwd']);
        self::assertSame([], (new FileTriageDetector())->detect($input));
    }

    public function testExplicitActiveModeAndContentCauseAreSeparate(): void
    {
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => "root:x:0:0:root:/root:/bin/bash\ndaemon:x:1:1:daemon:/usr/sbin:/bin/false\n"]);
        $signals = (new FileTriageDetector())->detect($input, 'active');
        self::assertSame('active', $signals[0]['mode']);
        self::assertFalse($signals[0]['attributes']['causeEstablished']);
    }
    public function testCompletedEmptyAndNegativeBodyDifferFromUnavailableRaw(): void
    {
        foreach (['', '<p>Ordinary response</p>'] as $body) {
            $evaluation = (new FileTriageDetector())->evaluate(ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $body]));
            self::assertSame('completed', $evaluation->status);
            self::assertSame([], $evaluation->signals);
        }
        $missing = (new FileTriageDetector())->evaluate(ObservationInput::fromArray(['url' => 'https://fixture.test/']));
        self::assertSame(['unavailable', 'unavailable'], array_column($missing->familyOutcomes, 'status'));
    }

    public function testInvalidRawUtf8IsUnavailableBeforeAnyRegexOrEvidence(): void
    {
        $input = ObservationInput::fromArray([
            'url' => 'https://fixture.test/', 'payload' => '../../etc/passwd',
            'rawHtml' => "Warning: include(../../etc/passwd): failed to open stream: No such file or directory\n\xff",
        ]);
        $evaluation = (new FileTriageDetector())->evaluate($input);
        self::assertSame('unavailable', $evaluation->status);
        self::assertSame([], $evaluation->signals);
        self::assertSame([], $evaluation->diagnostics);
        $expected = [];
        foreach (['local_include', 'remote_include'] as $family) {
            $expected[] = [
                'family' => $family, 'status' => 'unavailable',
                'stages' => [[
                    'stage' => 'raw_input', 'representation' => 'raw', 'status' => 'unavailable',
                    'issues' => [[
                        'code' => 'representation_invalid', 'detector' => 'file-triage-v1',
                        'family' => $family, 'stage' => 'raw_input', 'representation' => 'raw',
                    ]],
                ]],
                'counters' => ['candidatesConsidered' => 0, 'candidatesExcluded' => 0, 'signalsQualified' => 0],
            ];
        }
        self::assertSame($expected, $evaluation->familyOutcomes);
        self::assertSame([], (new FileTriageDetector())->detect($input));
    }

    public function testInvalidMappingInputRetainsIndependentDiagnosticAndLaterContent(): void
    {
        $body = "Warning: include(../../etc/passwd): failed to open stream: No such file or directory\n"
            ."root:x:0:0:root:/root:/bin/bash\ndaemon:x:1:1:daemon:/usr/sbin:/bin/false\n";
        $input = ObservationInput::fromArray(['url' => 'https://fixture.test/', 'rawHtml' => $body, 'payload' => "bad\xffinput"]);
        $evaluation = (new FileTriageDetector())->evaluate($input);
        self::assertSame('unavailable', $evaluation->status);
        self::assertSame(['file_include_diagnostic', 'sensitive_file_content_observed'], array_column($evaluation->signals, 'code'));
        $stage = array_values(array_filter($evaluation->familyOutcomes[0]['stages'], static fn (array $stage): bool => $stage['stage'] === 'mapping_input'))[0];
        self::assertSame('input_encoding_invalid', $stage['issues'][0]['code']);
        self::assertSame([], $evaluation->diagnostics);
        self::assertSame('raw', $stage['issues'][0]['candidate']['representation']);
        self::assertSame(hash('sha256', $body), $stage['issues'][0]['candidate']['representationSha256']);
        self::assertStringNotContainsString('bad', implode(' ', $evaluation->diagnostics));
        foreach ($evaluation->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
    }

    public function testMalformedQuerySourceDoesNotEraseValidPayloadRelation(): void
    {
        $input = ObservationInput::fromArray([
            'url' => 'https://fixture.test:99999/', 'payload' => '../../etc/passwd',
            'rawHtml' => 'Warning: include(../../etc/passwd): failed to open stream: No such file or directory',
        ]);
        $evaluation = (new FileTriageDetector())->evaluate($input);
        self::assertSame('failed', $evaluation->status);
        self::assertSame(['file_include_diagnostic', 'lfi_suspected'], array_column($evaluation->signals, 'code'));
        self::assertStringContainsString('parser_error', json_encode($evaluation->familyOutcomes, JSON_THROW_ON_ERROR));
    }

    public function testFailedNegativeRemoteGuardCannotQualifySuspectedRelation(): void
    {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, __DIR__.'/../Fixtures/cribrum/k1-file/guard-failure.php']);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('failed', $result['status']);
        self::assertSame(['file_include_diagnostic'], $result['codes']);
        self::assertSame(['completed', 'failed'], array_column($result['families'], 'status'));
        self::assertStringContainsString('regex_error', json_encode($result['families'], JSON_THROW_ON_ERROR));
        self::assertStringContainsString('candidate', json_encode($result['families'], JSON_THROW_ON_ERROR));
    }

    public function testLaterValidQueryMappingSurvivesInvalidPayloadMapping(): void
    {
        $input = ObservationInput::fromArray([
            'url' => 'https://fixture.test/?page=..%2F..%2Fetc%2Fpasswd', 'payload' => "bad\xffinput",
            'rawHtml' => 'Warning: include(../../etc/passwd): failed to open stream: No such file or directory',
        ]);
        $evaluation = (new FileTriageDetector())->evaluate($input);
        self::assertSame('unavailable', $evaluation->status);
        self::assertSame(['file_include_diagnostic', 'lfi_suspected'], array_column($evaluation->signals, 'code'));
        self::assertSame('query_parameter', $evaluation->signals[1]['attributes']['inputMapping']['source']);
    }

    public function testShortInvalidInputValuesAreReportedBeforeLengthFilter(): void
    {
        foreach ([['payload' => "\xff"], ['url' => 'https://fixture.test/?page=%FF'], ['payload' => '../../etc/passwd', 'url' => 'https://fixture.test/?page=%FF']] as $source) {
            $input = ObservationInput::fromArray(array_replace([
                'url' => 'https://fixture.test/',
                'rawHtml' => 'Warning: include(../../etc/passwd): failed to open stream: No such file or directory',
            ], $source));
            $evaluation = (new FileTriageDetector())->evaluate($input);
            self::assertSame('unavailable', $evaluation->status);
            self::assertSame([], $evaluation->diagnostics);
            self::assertStringContainsString('input_encoding_invalid', json_encode($evaluation->familyOutcomes, JSON_THROW_ON_ERROR));
            self::assertSame(isset($source['payload']) && $source['payload'] === '../../etc/passwd' ? ['file_include_diagnostic', 'lfi_suspected'] : ['file_include_diagnostic'], array_column($evaluation->signals, 'code'));
        }
    }

    public function testInvalidQueryParameterNameIsUnavailableAndValidMappingsSurvive(): void
    {
        foreach (['https://fixture.test/?%FF=marker', 'https://fixture.test/?%FF=x', 'https://fixture.test/?%FF=marker&page=marker'] as $url) {
            $input = ObservationInput::fromArray([
                'url' => $url, 'rawHtml' => 'Warning: include(marker): failed to open stream: No such file or directory',
            ]);
            $evaluation = (new FileTriageDetector())->evaluate($input);
            self::assertSame('unavailable', $evaluation->status);
            self::assertSame([], $evaluation->diagnostics);
            $expectedCodes = str_contains($url, '&page=') ? ['file_include_diagnostic', 'lfi_suspected'] : ['file_include_diagnostic'];
            self::assertSame($expectedCodes, array_column($evaluation->signals, 'code'));
            self::assertStringContainsString('input_encoding_invalid', json_encode($evaluation->familyOutcomes, JSON_THROW_ON_ERROR));
            if (count($evaluation->signals) === 2) self::assertSame('page', $evaluation->signals[1]['attributes']['inputMapping']['parameter']);
        }
    }

    public function testInvalidRequiredOriginHostWithholdsOnlyRemoteRelation(): void
    {
        $input = ObservationInput::fromArray([
            'url' => "https://bad\xff.test/?local=..%2F..%2Fetc%2Fpasswd", 'payload' => 'https://other.test/probe.php',
            'rawHtml' => "Warning: include(https://other.test/probe.php): failed to open stream: No such file or directory\n"
                .'Warning: include(../../etc/passwd): failed to open stream: No such file or directory',
        ]);
        $evaluation = (new FileTriageDetector())->evaluate($input);
        self::assertSame(['completed', 'unavailable'], array_column($evaluation->familyOutcomes, 'status'));
        self::assertSame(['file_include_diagnostic', 'file_include_diagnostic', 'lfi_suspected'], array_column($evaluation->signals, 'code'));
        self::assertSame([], $evaluation->diagnostics);
        $stage = array_values(array_filter($evaluation->familyOutcomes[1]['stages'], static fn (array $stage): bool => $stage['stage'] === 'origin_parse'))[0];
        self::assertSame(null, $stage['representation']);
        self::assertSame('input_encoding_invalid', $stage['issues'][0]['code']);
        self::assertSame('raw', $stage['issues'][0]['candidate']['representation']);
        foreach ($evaluation->signals as $signal) EvidenceSignal::validateAgainstInput($signal, $input);
    }

    public function testRemoteGuardUsesDeclaredLocalityAndLongBodyAloneIsComplete(): void
    {
        $warning = 'Warning: include(https://other.test/probe.php): failed to open stream: No such file or directory';
        // The guard intentionally inspects adjacent warnings; distant text is outside its rule scope.
        foreach ([0, 3000] as $padding) {
            $input = ObservationInput::fromArray([
                'url' => 'https://fixture.test/', 'payload' => 'https://other.test/probe.php',
                'rawHtml' => ($padding ? "allow_url_include = off\n" : '').str_repeat(' ', $padding).$warning,
            ]);
            $evaluation = (new FileTriageDetector())->evaluate($input);
            self::assertSame(['completed', 'completed'], array_column($evaluation->familyOutcomes, 'status'));
            self::assertSame(['file_include_diagnostic', 'rfi_suspected'], array_column($evaluation->signals, 'code'));
        }
        $input = ObservationInput::fromArray([
            'url' => 'https://fixture.test/', 'payload' => 'https://other.test/probe.php',
            'rawHtml' => str_repeat(' ', 3000)."allow_url_include = off\n".$warning,
        ]);
        $evaluation = (new FileTriageDetector())->evaluate($input);
        self::assertSame('completed', $evaluation->status);
        self::assertSame(['file_include_diagnostic'], array_column($evaluation->signals, 'code'));
    }

}
