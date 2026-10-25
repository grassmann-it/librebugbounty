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
        public ?string $claim,
        public ?string $state,
        public int $score,
        public string $reason,
        public bool $shadow = false,
        public ?int $minStatus = null,
        public ?string $detector = null,
        public array $outputs = [],
    ) {
    }

    /** Registered capabilities only; manifests cannot load arbitrary classes or code. */
    public static function detectorOutputs(): array
    {
        return [
            'reflection-triage-v1' => ['input_reflected'],
            'sql-triage-v1' => ['sql_error_observed', 'input_in_sql_error', 'sql_input_structure_indication'],
            'file-triage-v1' => ['file_include_diagnostic', 'lfi_suspected', 'rfi_suspected', 'sensitive_file_content_observed'],
            'context-triage-v1' => ['internal_information_exposed', 'error_response', 'coverage_limited', 'product_context'],
        ];
    }

    public static function fromArray(array $rule, int $schema = 1): self
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
        if ($schema === 2) {
            $allowed = ['id', 'detector', 'outputs', 'shadow'];
            if (array_diff(array_keys($rule), $allowed) !== []) throw new \InvalidArgumentException('Detector rules must be claim-free.');
            if (isset($rule['shadow']) && $rule['shadow'] !== true) throw new \InvalidArgumentException('A pack cannot activate detector evidence; select active mode explicitly.');
            $id = $rule['id'] ?? null;
            $detector = $rule['detector'] ?? null;
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_.:\/-]{1,128}$/D', $id) !== 1) throw new \InvalidArgumentException('id is invalid.');
            if (!is_string($detector) || !array_key_exists($detector, self::detectorOutputs())) throw new \InvalidArgumentException('Unknown registered detector.');
            $outputs = $strings($rule['outputs'] ?? null, 'outputs');
            if ($outputs === [] || count($outputs) > 32) throw new \InvalidArgumentException('Detector outputs are required.');
            foreach ($outputs as $code) if (!in_array($code, self::detectorOutputs()[$detector], true)) throw new \InvalidArgumentException('Invalid detector output.');
            return new self($id, 'raw', [], [], [], [], null, null, 0, '', true, null, $detector, $outputs);
        }
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
