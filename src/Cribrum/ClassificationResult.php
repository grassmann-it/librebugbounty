<?php

namespace App\Cribrum;

final readonly class ClassificationResult
{
    /**
     * @param list<ClaimSuggestion> $suggestions
     * @param list<array<string,mixed>> $signals
     * @param array<string,string> $packs
     * @param list<string> $diagnostics
     */
    public function __construct(
        public array $suggestions,
        public array $signals,
        public array $packs,
        public array $diagnostics,
        public string $classifierVersion,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $rawSuggestions = $data['suggestions'] ?? null;
        if (!is_array($rawSuggestions)) throw new \InvalidArgumentException('suggestions must be an array.');
        $suggestions = [];
        foreach ($rawSuggestions as $suggestion) {
            if (!is_array($suggestion)) throw new \InvalidArgumentException('Invalid classification suggestion.');
            $suggestions[] = ClaimSuggestion::fromArray($suggestion);
        }
        $rawSignals = $data['signals'] ?? null;
        if (!is_array($rawSignals)) throw new \InvalidArgumentException('signals must be an array.');
        $signals = [];
        foreach ($rawSignals as $signal) {
            if (!is_array($signal)) throw new \InvalidArgumentException('Invalid classification signal.');
            $signals[] = $signal;
        }
        $rawPacks = $data['packs'] ?? null;
        if (!is_array($rawPacks)) throw new \InvalidArgumentException('packs must be an array.');
        $packs = [];
        foreach ($rawPacks as $id => $version) {
            if (!is_string($id) || !is_string($version)) throw new \InvalidArgumentException('Invalid classification pack.');
            $packs[$id] = $version;
        }
        $rawDiagnostics = $data['diagnostics'] ?? null;
        if (!is_array($rawDiagnostics)) throw new \InvalidArgumentException('diagnostics must be an array.');
        $diagnostics = [];
        foreach ($rawDiagnostics as $diagnostic) {
            if (!is_string($diagnostic)) throw new \InvalidArgumentException('Invalid classification diagnostic.');
            $diagnostics[] = $diagnostic;
        }
        $version = $data['classifierVersion'] ?? null;
        if (!is_string($version) || $version === '' || strlen($version) > 128) throw new \InvalidArgumentException('classifierVersion is invalid.');

        usort($suggestions, static function (ClaimSuggestion $a, ClaimSuggestion $b): int {
            return [$a->claim, $a->state, $b->score, implode("\n", $a->ruleIds)] <=> [$b->claim, $b->state, $a->score, implode("\n", $b->ruleIds)];
        });
        usort($signals, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
        ksort($packs);
        $diagnostics = array_values(array_unique($diagnostics));
        sort($diagnostics, SORT_STRING);

        return new self($suggestions, $signals, $packs, $diagnostics, $version);
    }

    public function toArray(): array
    {
        return [
            'suggestions' => array_map(static fn (ClaimSuggestion $suggestion): array => $suggestion->toArray(), $this->suggestions),
            'signals' => $this->signals,
            'packs' => $this->packs,
            'diagnostics' => $this->diagnostics,
            'classifierVersion' => $this->classifierVersion,
        ];
    }
}
