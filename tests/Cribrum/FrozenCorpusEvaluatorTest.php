<?php

namespace App\Tests\Cribrum;

use App\Cribrum\Evaluation\FrozenCorpusEvaluator;
use PHPUnit\Framework\TestCase;

final class FrozenCorpusEvaluatorTest extends TestCase
{
    private string $temporary;
    private const FIXTURES = __DIR__.'/../Fixtures/cribrum/offline-triage';

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/cribrum-frozen-'.bin2hex(random_bytes(8));
        mkdir($this->temporary, 0700);
        foreach (glob(self::FIXTURES.'/*') as $path) copy($path, $this->temporary.'/'.basename($path));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporary.'/*') as $path) unlink($path);
        rmdir($this->temporary);
    }

    public function testSameByteReplayPreservesShadowClaimsAndExposesLegacyCollisions(): void
    {
        $report = $this->evaluate();
        self::assertSame(0, $report['summary']['failedCases']);
        self::assertSame(8, $report['summary']['cases']);
        self::assertSame(4, $report['summary']['legacyCollisionCases']);
        self::assertSame($report, $this->evaluate(), 'Replay must be deterministic.');
        foreach ([
            'src/Cribrum/Detector/ReflectionTriageDetector.php',
            'src/Cribrum/Rule.php', 'src/Cribrum/RulePack.php',
            'src/Cribrum/RulePackCompiler.php', 'src/Cribrum/RulePackRepository.php',
            'src/Cribrum/ObservationInput.php', 'src/Cribrum/ClassificationResult.php',
            'src/Cribrum/ClaimSuggestion.php', 'src/Cribrum/Taxonomy.php',
            'src/Cribrum/Evaluation/FrozenCorpusEvaluator.php',
            'bin/cribrum-eval-frozen.php',
        ] as $dependency) {
            self::assertSame(hash_file('sha256', dirname(__DIR__, 2).'/'.$dependency), $report['implementationSha256'][$dependency] ?? null, 'Missing or stale implementation provenance: '.$dependency);
        }

        $records = array_column($report['records'], null, 'id');
        $manifest = json_decode(file_get_contents(self::FIXTURES.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['records'] as $case) self::assertSame($case['expectLegacyCollision'], $records[$case['id']]['legacyOverclaimCollisions'] !== []);
        foreach ($records as $record) {
            self::assertSame($record['baseline']['suggestions'], $record['candidateShadow']['suggestions']);
            self::assertSame([], $record['problems']);
        }
        foreach (['baseline-positive-sql', 'global-echo-collision', 'documentation-collision', 'bound-type-collision'] as $id) {
            self::assertContains('sql_injection', array_column($records[$id]['legacyOverclaimCollisions'], 'legacyClaim'));
            self::assertContains('sql_injection', $records[$id]['activeClaimDelta']['removed']);
        }
        self::assertContains('sql_input_structure_indication', $records['http200-access']['codes']);
        self::assertNotContains('input_in_sql_error', $records['incomplete-block']['codes']);
        self::assertSame('partial', $records['incomplete-block']['candidateShadow']['coverage']['status']);
        self::assertContains('lfi_suspected', $records['local-include-attempt']['codes']);
        self::assertContains('rfi_suspected', $records['remote-include-attempt']['codes']);
    }

    public function testChangedBodyIsRejectedBeforeClassification(): void
    {
        file_put_contents($this->temporary.'/http200-access.body', 'changed bytes', FILE_APPEND);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hash mismatch');
        $this->evaluate();
    }

    public function testMetadataHashIsAlsoFrozen(): void
    {
        file_put_contents($this->temporary.'/http200-access.json', "\n", FILE_APPEND);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hash mismatch');
        $this->evaluate();
    }

    public function testNonPassTwoCaptureIsRejectedEvenWithMatchingMetadataHash(): void
    {
        $metadataFile = $this->temporary.'/http200-access.json';
        $metadata = json_decode(file_get_contents($metadataFile), true, 512, JSON_THROW_ON_ERROR);
        $metadata['request_pass'] = 1;
        file_put_contents($metadataFile, json_encode($metadata, JSON_THROW_ON_ERROR));
        $manifestFile = $this->temporary.'/manifest.json';
        $manifest = json_decode(file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
        $manifest['records'][0]['metadataSha256'] = hash_file('sha256', $metadataFile);
        file_put_contents($manifestFile, json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('finished frozen pass-2');
        $this->evaluate();
    }

    public function testCorpusCannotReferenceAnExternalFile(): void
    {
        $manifestFile = $this->temporary.'/manifest.json';
        $manifest = json_decode(file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
        $manifest['records'][0]['body'] = '../outside.body';
        file_put_contents($manifestFile, json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('outside frozen corpus');
        $this->evaluate();
    }

    private function evaluate(): array
    {
        return (new FrozenCorpusEvaluator())->evaluate($this->temporary.'/manifest.json', dirname(__DIR__, 2).'/resources/cribrum/candidates');
    }
}
