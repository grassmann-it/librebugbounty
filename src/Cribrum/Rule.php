<?php

namespace App\Cribrum;

final readonly class Rule
{
    /**
     * @param list<string> $any
     * @param list<string> $all
     * @param list<string> $excludes
     * @param list<string> $context
     */
    public function __construct(
        public string $id,
        public string $representation,
        public array $any,
        public array $all,
        public array $excludes,
        public array $context,
        public string $claim,
        public string $state,
        public int $score,
        public string $reason,
        public bool $shadow = false,
        public ?int $minStatus = null,
    ) {
    }

    public static function fromArray(array $rule): self
    {
        $strings = static function (mixed $value, string $name): array {
            if ($value === null) return [];
            if (!is_array($value)) throw new \InvalidArgumentException($name.' must be an array.');
            $result = [];
            foreach ($value as $item) {
                if (!is_string($item) || $item === '' || strlen($item) > 512) throw new \InvalidArgumentException($name.' is invalid.');
                $result[] = $item;
            }
            return $result;
        };
        $representation = $rule['representation'] ?? 'text';
        if (!in_array($representation, ['raw', 'text'], true)) throw new \InvalidArgumentException('representation is invalid.');
        $claim = $rule['claim'] ?? null;
        $state = $rule['state'] ?? null;
        if (!is_string($claim) || !Taxonomy::isClaim($claim) || !is_string($state) || !Taxonomy::isState($state)) {
            throw new \InvalidArgumentException('claim/state is invalid.');
        }
        if (!in_array($state, [Taxonomy::OBSERVED, Taxonomy::SUSPECTED], true)) {
            throw new \InvalidArgumentException('Rule suggestions may only be observed or suspected.');
        }
        $score = $rule['score'] ?? null;
        if (!is_int($score) || $score < 0 || $score > 100) throw new \InvalidArgumentException('score is invalid.');

        return new self(
            is_string($rule['id'] ?? null) && preg_match('/^[A-Za-z0-9_.:\/-]{1,128}$/D', $rule['id']) === 1 ? $rule['id'] : throw new \InvalidArgumentException('id is invalid.'),
            $representation,
            $strings($rule['any'] ?? null, 'any'),
            $strings($rule['all'] ?? null, 'all'),
            $strings($rule['excludes'] ?? null, 'excludes'),
            $strings($rule['context'] ?? null, 'context'),
            $claim,
            $state,
            $score,
            is_string($rule['reason'] ?? null) && mb_strlen($rule['reason']) <= 500 ? $rule['reason'] : throw new \InvalidArgumentException('reason is invalid.'),
            ($rule['shadow'] ?? false) === true,
            ($rule['minStatus'] ?? null) === null ? null : (is_int($rule['minStatus']) && $rule['minStatus'] >= 100 && $rule['minStatus'] <= 599 ? $rule['minStatus'] : throw new \InvalidArgumentException('minStatus is invalid.')),
        );
    }
}
