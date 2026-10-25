<?php

namespace App\Tests\Cribrum;

use App\Cribrum\ClassificationResult;
use App\Cribrum\CribrumClassifier;
use App\Cribrum\ObservationInput;
use App\Cribrum\RulePackCompiler;
use App\Cribrum\RulePackRepository;
use PHPUnit\Framework\TestCase;

final class CandidateEngineTest extends TestCase
{
    private function input(array $coverage = []): ObservationInput
    {
        return new ObservationInput('https://fixture.test/?q=ALPHA', '<pre>You have an error in your SQL syntax ALPHA</pre>', null, 'text/html', 500, null, null, null, false, null, $coverage);
    }

    private function directory(): string { return dirname(__DIR__, 2).'/resources/cribrum/candidates'; }
    private function candidate(string $mode = 'shadow'): CribrumClassifier { return new CribrumClassifier('/nonexistent', $this->directory(), $mode); }

    public function testDefaultConfigurationDoesNotLoadCandidates(): void
    {
        self::assertArrayNotHasKey('behavior-triage', (new CribrumClassifier('/nonexistent'))->configuration()['packs']);
        self::assertSame([], (new CribrumClassifier('/nonexistent'))->classify($this->input())->coverage);
    }

    public static function publicDetectorCases(): iterable
    {
        $matrix = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/cribrum/public-regression-v2.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($matrix['detectorCases'] as $case) yield $case['id'] => [$case];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicDetectorCases')]
    public function testPublicMatrixRunsThroughRegisteredCandidate(array $case): void
    {
        $input = ObservationInput::fromArray($case['input']);
        $baseline = (new CribrumClassifier('/nonexistent'))->classify($input)->toArray();
        foreach (['shadow', 'active'] as $mode) {
            $result = $this->candidate($mode)->classify($input)->toArray();
            if ($mode === 'shadow') self::assertSame($baseline['suggestions'], $result['suggestions']);
            $signals = array_values(array_filter($result['signals'], static fn ($s) => ($s['kind'] ?? null) === 'observation_evidence'));
            $codes = array_column($signals, 'code');
            foreach ($case['expectedPresent'] ?? [] as $code) self::assertContains($code, $codes, $case['reason']);
            foreach ($case['expectedAbsent'] ?? [] as $code) self::assertNotContains($code, $codes, $case['reason']);
            foreach ($case['expectedAttributes'] ?? [] as $code => $attributes) {
                self::assertNotEmpty(array_filter($signals, static function ($s) use ($code, $attributes): bool {
                    if ($s['code'] !== $code) return false;
                    foreach ($attributes as $key => $value) if (($s['attributes'][$key] ?? null) !== $value) return false;
                    return true;
                }), $case['reason']);
            }
        }
    }

    public function testShadowAddsValidatedClaimFreeEvidenceAndPreservesBaselineSuggestions(): void
    {
        $baseline = (new CribrumClassifier('/nonexistent'))->classify($this->input())->toArray();
        $explain = [];
        $candidate = $this->candidate()->classify($this->input(), $explain);
        self::assertSame($baseline['suggestions'], $candidate->toArray()['suggestions']);
        $evidence = array_values(array_filter($candidate->signals, static fn (array $s): bool => ($s['kind'] ?? null) === 'observation_evidence'));
        self::assertNotEmpty($evidence);
        foreach ($evidence as $signal) {
            self::assertTrue($signal['shadow']);
            self::assertSame('shadow', $signal['mode']);
            self::assertArrayNotHasKey('claim', $signal);
            self::assertSame('behavior-triage', $signal['attributes']['rulePack']);
        }
        self::assertCount(4, array_filter($explain, static fn (array $row): bool => isset($row['detector'])));
        self::assertSame($candidate->toArray(), ClassificationResult::fromArray($candidate->toArray())->toArray());
    }

    public function testActiveIsExplicitAndSupersedesBroadLegacySqlSuspicion(): void
    {
        $baseline = (new CribrumClassifier('/nonexistent'))->classify($this->input());
        self::assertContains('sql_injection', array_column($baseline->toArray()['suggestions'], 'claim'));
        $active = $this->candidate('active')->classify($this->input(['status' => 'complete']));
        self::assertNotContains('sql_injection', array_column($active->toArray()['suggestions'], 'claim'));
        self::assertSame('complete', $active->coverage['status']);
        foreach ($active->signals as $signal) if (($signal['kind'] ?? null) === 'observation_evidence') self::assertSame('active', $signal['mode']);
    }

    public function testActiveDoesNotPromoteLegacyFileSuggestions(): void
    {
        $input = new ObservationInput('https://fixture.test/?f=https%3A%2F%2Fexample.invalid%2Finput.txt',
            '<p>https://example.invalid/input.txt</p><pre>require(): Failed opening fixed-template.php: failed to open stream</pre>',
            null, 'text/html', 500, null, null, null, false, null);
        $baseline = (new CribrumClassifier('/nonexistent'))->classify($input)->toArray();
        self::assertContains('remote_file_inclusion', array_column($baseline['suggestions'], 'claim'));
        $active = $this->candidate('active')->classify($input)->toArray();
        self::assertNotContains('remote_file_inclusion', array_column($active['suggestions'], 'claim'));
        self::assertNotContains('local_file_inclusion', array_column($active['suggestions'], 'claim'));
    }

    public function testCoverageRequiresCompleteCaptureDeclarationAndHashBinding(): void
    {
        self::assertSame('partial', $this->candidate()->classify($this->input())->coverage['status']);
        self::assertSame('partial', $this->candidate()->classify($this->input(['status' => 'complete', 'truncated' => true]))->coverage['status']);
        $input = $this->input(['status' => 'complete']);
        $coverage = $this->candidate()->classify($input)->coverage;
        self::assertSame('complete', $coverage['status']);
        self::assertSame(hash('sha256', $input->rawHtml), $coverage['artifactSha256']);
        self::assertSame(hash('sha256', $input->url), $coverage['inputUrlSha256']);
        self::assertSame([], array_diff($coverage['requiredFamilies'], $coverage['evaluatedFamilies']));
    }

    public function testPartialPackCannotClaimCompleteFamilyCoverage(): void
    {
        $pack = (new RulePackCompiler())->compile(['schema' => 2, 'id' => 'sql-only', 'version' => '1', 'rules' => [
            ['id' => 'sql', 'detector' => 'sql-triage-v1', 'outputs' => ['sql_error_observed']],
        ]]);
        $result = (new CribrumClassifier('/nonexistent', null, 'shadow', [$pack]))->classify($this->input(['status' => 'complete']));
        self::assertSame('partial', $result->coverage['status']);
        self::assertNotContains('sql', $result->coverage['evaluatedFamilies']);
        $this->expectException(\InvalidArgumentException::class);
        (new CribrumClassifier('/nonexistent', null, 'active', [$pack]))->classify($this->input());
    }

    public function testThreeDetectorPackCannotClaimReflectionOrActivate(): void
    {
        $manifest = json_decode(file_get_contents($this->directory().'/behavior-triage.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['rules'] = array_values(array_filter($manifest['rules'], static fn (array $rule): bool => $rule['detector'] !== 'reflection-triage-v1'));
        $pack = (new RulePackCompiler())->compile($manifest);
        $input = $this->input(['status' => 'complete']);
        $shadow = (new CribrumClassifier('/nonexistent', null, 'shadow', [$pack]))->classify($input);
        self::assertNotContains('reflection', $shadow->coverage['evaluatedFamilies']);
        self::assertSame('partial', $shadow->coverage['status']);
        $this->expectException(\InvalidArgumentException::class);
        (new CribrumClassifier('/nonexistent', null, 'active', [$pack]))->classify($input);
    }

    public function testRegisteredReflectionProducesClaimFreeEvidenceInBothModes(): void
    {
        $input = new ObservationInput('https://fixture.test/?q=ALPHA', '<p>ALPHA</p>', null, 'text/html', 200, null, null, null, false, null, ['status' => 'complete']);
        $baseline = (new CribrumClassifier('/nonexistent'))->classify($input)->toArray()['suggestions'];
        foreach (['shadow', 'active'] as $mode) {
            $result = $this->candidate($mode)->classify($input);
            self::assertSame($baseline, $result->toArray()['suggestions']);
            $reflections = array_values(array_filter($result->signals, static fn (array $signal): bool => ($signal['code'] ?? null) === 'input_reflected'));
            self::assertCount(1, $reflections);
            self::assertSame($mode, $reflections[0]['mode']);
            self::assertSame('reflection-triage-v1', $reflections[0]['producer']);
            self::assertFalse($reflections[0]['attributes']['executionProven']);
            self::assertContains('reflection', $result->coverage['evaluatedFamilies']);
            self::assertSame('complete', $result->coverage['status']);
        }
    }

    public function testSchemaTwoCannotEnterProductionDirectories(): void
    {
        $loaded = (new RulePackRepository($this->directory()))->load();
        self::assertNotEmpty($loaded['diagnostics']);
        self::assertNotContains('behavior-triage', array_map(static fn ($pack): string => $pack->id, $loaded['packs']));
    }

    public function testSchemaTwoRejectsClaimsAndArbitraryClassNames(): void
    {
        foreach ([['claim' => 'sql_injection'], ['detector' => '\\Some\\ArbitraryClass'], ['outputs' => ['invented_code']], ['shadow' => false]] as $bad) {
            try {
                (new RulePackCompiler())->compile(['schema' => 2, 'id' => 'invalid-pack', 'version' => '1', 'rules' => [
                    array_replace(['id' => 'sql', 'detector' => 'sql-triage-v1', 'outputs' => ['sql_error_observed']], $bad),
                ]]);
                self::fail('Invalid detector rule accepted');
            } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testTamperedTypedEvidenceCannotRoundTrip(): void
    {
        $data = $this->candidate()->classify($this->input())->toArray();
        foreach ($data['signals'] as &$signal) if (($signal['kind'] ?? null) === 'observation_evidence') { $signal['spans'][0]['excerpt'] = 'tampered'; break; }
        unset($signal);
        $this->expectException(\InvalidArgumentException::class);
        ClassificationResult::fromArray($data);
    }

    public function testOfflineCliExposesCandidatesAndCaptureCoverage(): void
    {
        $client = new \Symfony\Component\HttpClient\MockHttpClient();
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \App\Command\CribrumClassifyCommand(
            new CribrumClassifier('/nonexistent'), new \App\Service\CribrumProbeSource($client)));
        $file = tempnam(sys_get_temp_dir(), 'cribrum-candidate-');
        file_put_contents($file, $this->input()->rawHtml);
        try {
            self::assertSame(0, $tester->execute(['--response' => $file, '--candidate-packs' => $this->directory(), '--capture-complete' => true, '--format' => 'json', '--explain' => true]));
            $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('complete', $report['classification']['coverage']['status']);
            self::assertSame('shadow', $report['classification']['coverage']['mode']);
            self::assertArrayHasKey('behavior-triage', $report['classification']['packs']);
            self::assertSame(0, $client->getRequestsCount());
        } finally { unlink($file); }
    }

    public function testCandidateCliRefusesNetworkModeBeforeAnyRequest(): void
    {
        $client = new \Symfony\Component\HttpClient\MockHttpClient();
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \App\Command\CribrumClassifyCommand(
            new CribrumClassifier('/nonexistent'), new \App\Service\CribrumProbeSource($client)));
        self::assertSame(2, $tester->execute(['url' => 'https://fixture.test/', '--candidate-packs' => $this->directory(), '--format' => 'json']));
        self::assertStringContainsString('offline', $tester->getDisplay());
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testLegacyInputRoundTripRemainsByteCompatible(): void
    {
        $data = $this->input()->toArray();
        self::assertArrayNotHasKey('coverage', $data);
        self::assertSame($data, ObservationInput::fromArray($data)->toArray());
        $covered = $this->input(['status' => 'complete'])->toArray();
        self::assertSame($covered, ObservationInput::fromArray($covered)->toArray());
    }
}
