<?php

namespace App\Tests\Cribrum;

use App\Cribrum\RulePack;
use App\Cribrum\RulePackCompiler;
use App\Cribrum\RulePackRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CoreMatcherCacheTest extends TestCase
{
    public function testShippedCoreReducesActualMatcherCallsAndPreservesEveryRuleResultAcrossInputs(): void
    {
        $manifest = $this->core();
        $groups = [];
        foreach ($manifest['rules'] as $rule) {
            $key = serialize([$rule['representation'] ?? 'text', $rule['any'] ?? [], $rule['all'] ?? [], $rule['excludes'] ?? [], $rule['context'] ?? [], $rule['minStatus'] ?? null]);
            $groups[$key][] = $rule;
        }
        self::assertCount(60, $groups);
        self::assertCount(28, array_filter($groups, static fn ($rules) => count($rules) === 2));
        $inputs = [];
        // Every pair receives positive/negative and status-gated coverage in the real loop.
        foreach ($groups as $rules) {
            if (count($rules) !== 2) continue;
            $phrases = [...($rules[0]['any'] ?? []), ...($rules[0]['all'] ?? [])];
            $body = 'ä before '.implode(' ', $phrases).' between '.implode(' ', $phrases).' after';
            foreach ([200, 400, null] as $status) {
                $inputs[] = $this->input($body, $status);
                $inputs[] = $this->input('unrelated harmless response', $status);
            }
        }
        $inputs = [...$inputs,
            ['url' => 'https://fixture.test/', 'rawHtml' => null, 'domText' => null],
            ['url' => 'https://fixture.test/', 'rawHtml' => null, 'domText' => 'ä System.NullReferenceException', 'httpStatus' => 500],
            ['url' => 'https://fixture.test/', 'rawHtml' => '<b>raw absent phrase</b>', 'domText' => 'ä System.NullReferenceException', 'httpStatus' => 500],
        ];
        $cached = $this->probe(['inputs' => $inputs]);
        $uncached = $this->probe(['inputs' => $inputs, 'uncached' => true]);
        foreach ($cached as $i => $snapshot) {
            self::assertCount(60, $snapshot['calls']);
            self::assertCount(88, $uncached[$i]['calls']);
            self::assertCount(88, array_filter($snapshot['explain'], static fn ($row) => $row['pack'] === 'core'));
            self::assertSame($uncached[$i]['result'], $snapshot['result']);
            self::assertSame($uncached[$i]['explain'], $snapshot['explain']);
        }
        // The same engine is reused by the probe; each input still performs its own 60 searches.
        self::assertNotSame($cached[2]['explain'], $cached[3]['explain']);
    }

    public function testMatcherExceptionsAreRetriedForEveryOriginalRule(): void
    {
        $options = ['inputs' => [$this->input('anything', 500)], 'throwMatcher' => true];
        $cached = $this->probe($options)[0];
        $uncached = $this->probe([...$options, 'uncached' => true])[0];
        self::assertCount(88, $cached['calls']);
        self::assertSame($uncached, $cached);
        self::assertCount(88, array_filter($cached['explain'], static fn ($row) => $row['pack'] === 'core' && $row['outcome'] === 'error'));
        self::assertCount(88, $cached['result']['diagnostics']);
    }

    public function testInjectedIdenticalCoreIsNotEligibleForCaching(): void
    {
        $manifest = $this->core();
        $snapshots = $this->probe(['injectedPacks' => [$manifest], 'inputs' => [$this->input('System.NullReferenceException', 500)] ]);
        self::assertCount(88, $snapshots[0]['calls']);
        self::assertContains('Rule pack core skipped: Duplicate rule pack.', $snapshots[0]['result']['diagnostics']);
        $repository = new RulePackRepository('/tmp/cribrum-k2-no-private-packs');
        $loaded = $repository->load();
        $core = array_values(array_filter($loaded['packs'], static fn ($pack) => $pack->id === 'core'))[0];
        self::assertTrue($repository->isShippedCore($core));
        self::assertFalse($repository->isShippedCore(new RulePack($core->id, $core->version, $core->rules)));
        $injected = (new RulePackCompiler())->compile($manifest);
        $repository = new RulePackRepository('/tmp/cribrum-k2-no-private-packs', null, [$injected]);
        $repository->load();
        self::assertFalse($repository->isShippedCore($injected));
    }

    public function testPrivatePackWithDuplicateMatchersRunsEveryRule(): void
    {
        $rules = [$this->rule('one'), $this->rule('two'), $this->rule('three')];
        $directory = sys_get_temp_dir().'/cribrum-k2-private-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $file = $directory.'/private-pack.json';
        file_put_contents($file, json_encode(['schema' => 1, 'id' => 'private-pack', 'version' => '1', 'rules' => $rules], JSON_THROW_ON_ERROR));
        try {
            $snapshot = $this->probe(['privateDirectory' => $directory, 'inputs' => [$this->input('alpha', 500)]])[0];
            $repository = new RulePackRepository($directory);
            foreach ($repository->load()['packs'] as $pack) {
                self::assertSame($pack->id === 'core', $repository->isShippedCore($pack));
            }
        } finally {
            unlink($file);
            rmdir($directory);
        }
        self::assertCount(63, $snapshot['calls']);
        self::assertSame(['one', 'three', 'two'], array_values(array_filter($snapshot['calls'], static fn ($id) => in_array($id, ['one', 'two', 'three'], true))));
        self::assertCount(3, array_filter($snapshot['explain'], static fn ($row) => $row['pack'] === 'private-pack'));
    }

    public function testOrderedOperandsGuardsRepresentationsAndMergeTiesRemainDistinct(): void
    {
        $rules = [
            $this->rule('any-a', ['any' => ['alpha', 'beta']]),
            $this->rule('any-b', ['any' => ['beta', 'alpha']]),
            $this->rule('all-a', ['any' => null, 'all' => ['alpha', 'beta']]),
            $this->rule('all-b', ['any' => null, 'all' => ['beta', 'alpha']]),
            $this->rule('exclude-a', ['excludes' => ['banA', 'banB']]),
            $this->rule('exclude-b', ['excludes' => ['banB', 'banA']]),
            $this->rule('context-a', ['context' => ['payloadPresent', 'fileError']]),
            $this->rule('context-b', ['context' => ['fileError', 'payloadPresent']]),
            $this->rule('status-a', ['minStatus' => 200]),
            $this->rule('status-b', ['minStatus' => 400]),
            $this->rule('raw', ['representation' => 'raw']),
            $this->rule('text', ['representation' => 'text']),
            $this->rule('tie-a', ['reason' => 'First tie reason.']),
            $this->rule('tie-b', ['reason' => 'Second tie reason.']),
            $this->rule('shadow', ['shadow' => true]),
        ];
        $options = ['forcedShippedManifest' => ['schema' => 1, 'id' => 'core', 'version' => 'test', 'rules' => $rules], 'inputs' => [
            ['url' => 'https://fixture.test/', 'rawHtml' => 'ä RAW alpha beta banA banB', 'domText' => 'ä TEXT beta alpha banA banB', 'httpStatus' => 300],
            $this->input('unrelated harmless response', null),
        ]];
        $cached = $this->probe($options);
        $uncached = $this->probe([...$options, 'uncached' => true]);
        foreach ($cached as $i => $snapshot) {
            self::assertSame($uncached[$i]['result'], $snapshot['result']);
            self::assertSame($uncached[$i]['explain'], $snapshot['explain']);
            self::assertLessThan(count($uncached[$i]['calls']), count($snapshot['calls']));
        }
        $rows = array_column(array_filter($cached[0]['explain'], static fn ($row) => $row['pack'] === 'core'), null, 'rule');
        $signals = array_column(array_filter($cached[0]['result']['signals'], static fn ($signal) => $signal['pack'] === 'core'), null, 'rule');
        self::assertSame('alpha', $signals['any-a']['phrase']);
        self::assertSame('beta', $signals['any-b']['phrase']);
        self::assertSame('alpha', $signals['all-a']['phrase']);
        self::assertSame('beta', $signals['all-b']['phrase']);
        self::assertSame(mb_strpos($options['inputs'][0]['domText'], 'alpha'), $rows['any-a']['span']['start']);
        self::assertSame(mb_strpos($options['inputs'][0]['domText'], 'beta'), $rows['any-b']['span']['start']);
        self::assertSame('Excluded phrase: banA', $rows['exclude-a']['exclusion']);
        self::assertSame('Excluded phrase: banB', $rows['exclude-b']['exclusion']);
        self::assertSame('Missing context: payloadPresent', $rows['context-a']['exclusion']);
        self::assertSame('Missing context: fileError', $rows['context-b']['exclusion']);
        self::assertSame('hit', $rows['status-a']['outcome']);
        self::assertSame('excluded', $rows['status-b']['outcome']);
        $suggestion = $cached[0]['result']['suggestions'][0];
        self::assertContains('core/tie-a', $suggestion['ruleIds']);
        self::assertContains('core/tie-b', $suggestion['ruleIds']);
        self::assertContains('First tie reason.', $suggestion['reasons']);
        self::assertContains('Second tie reason.', $suggestion['reasons']);
        self::assertNotContains('core/shadow', $suggestion['ruleIds']);
    }

    private function probe(array $request): array
    {
        $root = dirname(__DIR__, 2);
        $hash = hash_file('sha256', $root.'/src/Cribrum/CribrumClassifier.php');
        $process = new Process([PHP_BINARY, $root.'/tests/Fixtures/cribrum/k2-match-probe.php'], $root);
        $process->setInput(json_encode(['sourceSha256' => $hash, ...$request], JSON_THROW_ON_ERROR));
        $process->mustRun();
        $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($hash, $output['sourceSha256']);
        return $output['snapshots'];
    }

    private function core(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/cribrum/packs/core.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function input(string $body, ?int $status): array
    {
        return ['url' => 'https://fixture.test/', 'rawHtml' => $body, 'httpStatus' => $status];
    }

    private function rule(string $id, array $extra = []): array
    {
        return ['id' => $id, 'representation' => 'text', 'any' => ['alpha'], 'claim' => 'information_disclosure', 'state' => 'observed', 'score' => 50, 'reason' => 'Same base reason.', ...$extra];
    }
}
