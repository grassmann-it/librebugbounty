<?php

namespace App\Cribrum;

final class RulePackRepository
{
    /** @var array{packs:list<RulePack>,diagnostics:list<string>}|null */
    private ?array $cache = null;
    private ?RulePack $shippedCore = null;

    public function __construct(private readonly string $privateDirectory, private readonly ?string $candidateDirectory = null, private readonly ?array $candidatePacks = null)
    {
    }

    /** Internal provenance check: IDs and identical injected bytes are insufficient. */
    public function isShippedCore(RulePack $pack): bool
    {
        return $this->shippedCore === $pack;
    }

    /** @return array{packs:list<RulePack>,diagnostics:list<string>} */
    public function load(): array
    {
        if ($this->cache !== null) return $this->cache;

        $defaultDirectory = dirname(__DIR__, 2).'/resources/cribrum/packs';
        $packs = $this->candidatePacks ?? [];
        foreach ($packs as $pack) if (!$pack instanceof RulePack) throw new \InvalidArgumentException('Injected candidates must be compiled RulePacks.');
        $diagnostics = [];
        $seen = [];
        foreach ($packs as $pack) $seen[$pack->id] = true;
        foreach (array_filter([$defaultDirectory, $this->privateDirectory, $this->candidateDirectory]) as $directory) {
            if (!is_dir($directory)) continue;
            $files = glob(rtrim($directory, '/').'/*.json') ?: [];
            sort($files, SORT_STRING);
            if (count($files) > RulePackCompiler::packLimit()) {
                $diagnostics[] = 'Rule pack directory exceeds the pack limit.';
                break;
            }
            foreach ($files as $file) {
                $id = basename($file, '.json');
                try {
                    if (!is_file($file) || filesize($file) > 1048576) throw new \InvalidArgumentException('Rule pack file is too large.');
                    $manifest = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($manifest)) throw new \InvalidArgumentException('Manifest is not an object.');
                    $pack = (new RulePackCompiler())->compile($manifest, $id);
                    if (($manifest['schema'] ?? 1) === 2 && $directory !== $this->candidateDirectory) throw new \InvalidArgumentException('Schema 2 packs require explicit candidate selection.');
                    if (isset($seen[$pack->id])) throw new \InvalidArgumentException('Duplicate rule pack.');
                    $seen[$pack->id] = true;
                    $packs[] = $pack;
                    if ($file === $defaultDirectory.'/core.json') $this->shippedCore = $pack;
                } catch (\Throwable $error) {
                    $diagnostics[] = 'Rule pack '.$id.' skipped: '.$error->getMessage();
                }
            }
        }
        usort($packs, static fn (RulePack $a, RulePack $b): int => $a->id <=> $b->id);
        sort($diagnostics, SORT_STRING);
        if (count($packs) > RulePackCompiler::packLimit()) {
            $diagnostics[] = 'Global rule pack limit exceeded.';
            $packs = array_slice($packs, 0, RulePackCompiler::packLimit());
        }
        $ruleCount = array_sum(array_map(static fn (RulePack $pack): int => count($pack->rules), $packs));
        if ($ruleCount > 2048) {
            $diagnostics[] = 'Global Cribrum rule limit exceeded.';
        }

        return $this->cache = ['packs' => $packs, 'diagnostics' => $diagnostics];
    }
}
