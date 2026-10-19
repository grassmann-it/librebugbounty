<?php

namespace App\Cribrum;

final readonly class ClaimSuggestion
{
    /**
     * @param list<string> $ruleIds
     * @param list<string> $reasons
     * @param list<array{representation:string,start:int,length:int,excerpt:string}> $spans
     */
    public function __construct(
        public string $claim,
        public string $state,
        public int $score,
        public array $ruleIds,
        public array $reasons,
        public array $spans,
    ) {
        if (!Taxonomy::isClaim($claim) || !Taxonomy::isState($state) || $score < 0 || $score > 100) {
            throw new \InvalidArgumentException('Invalid claim suggestion.');
        }
    }

    public static function fromArray(array $data): self
    {
        $strings = static function (mixed $value, string $name): array {
            if (!is_array($value)) throw new \InvalidArgumentException($name.' must be an array.');
            $result = [];
            foreach ($value as $item) {
                if (!is_string($item) || $item === '') throw new \InvalidArgumentException($name.' contains an invalid value.');
                $result[] = $item;
            }
            return $result;
        };
        $spans = [];
        foreach (is_array($data['spans'] ?? null) ? $data['spans'] : [] as $span) {
            if (!is_array($span) || !in_array($span['representation'] ?? null, ['raw', 'text'], true)
                || !is_int($span['start'] ?? null) || $span['start'] < 0
                || !is_int($span['length'] ?? null) || $span['length'] <= 0
                || !is_string($span['excerpt'] ?? null)) {
                throw new \InvalidArgumentException('Invalid claim suggestion span.');
            }
            $spans[] = ['representation' => $span['representation'], 'start' => $span['start'], 'length' => $span['length'], 'excerpt' => $span['excerpt']];
        }

        return new self(
            is_string($data['claim'] ?? null) ? $data['claim'] : throw new \InvalidArgumentException('claim is required.'),
            is_string($data['state'] ?? null) ? $data['state'] : throw new \InvalidArgumentException('state is required.'),
            is_int($data['score'] ?? null) ? $data['score'] : throw new \InvalidArgumentException('score is required.'),
            $strings($data['ruleIds'] ?? [], 'ruleIds'),
            $strings($data['reasons'] ?? [], 'reasons'),
            $spans,
        );
    }

    public function toArray(): array
    {
        return [
            'claim' => $this->claim,
            'state' => $this->state,
            'score' => $this->score,
            'ruleIds' => $this->ruleIds,
            'reasons' => $this->reasons,
            'spans' => $this->spans,
        ];
    }
}
