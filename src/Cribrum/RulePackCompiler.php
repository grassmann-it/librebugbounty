<?php

namespace App\Cribrum;

final class RulePackCompiler
{
    private const MAX_PACKS = 64;
    private const MAX_RULES_PER_PACK = 512;
    private const MAX_TOTAL_RULES = 2048;
    private const ALLOWED_CONTEXT = ['payloadPresent', 'payloadInText', 'payloadLooksUrl', 'payloadPathLike', 'fileError'];

    public function compile(array $manifest, ?string $expectedId = null): RulePack
    {
        if (count($manifest) > 4096 || !in_array($manifest['schema'] ?? null, [1, 2], true)) {
            throw new \InvalidArgumentException('Rule pack manifest is invalid.');
        }
        $id = $manifest['id'] ?? null;
        $version = $manifest['version'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/D', $id) !== 1
            || ($expectedId !== null && $id !== $expectedId)
            || !is_string($version) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $version) !== 1) {
            throw new \InvalidArgumentException('Rule pack identity is invalid.');
        }
        $rawRules = $manifest['rules'] ?? [];
        if (!is_array($rawRules) || count($rawRules) === 0 || count($rawRules) > self::MAX_RULES_PER_PACK) {
            throw new \InvalidArgumentException('Rule pack rule count is invalid.');
        }
        $rules = [];
        $ruleIds = [];
        foreach ($rawRules as $raw) {
            if (!is_array($raw)) throw new \InvalidArgumentException('Rule is invalid.');
            $rule = Rule::fromArray($raw, $manifest['schema']);
            if (isset($ruleIds[$rule->id])) throw new \InvalidArgumentException('Duplicate rule id.');
            $ruleIds[$rule->id] = true;
            if ($rule->detector !== null) { $rules[] = $rule; continue; }
            foreach ($rule->context as $context) {
                if (!in_array($context, self::ALLOWED_CONTEXT, true)) throw new \InvalidArgumentException('Unknown rule context.');
            }
            if (($rule->any === [] && $rule->all === []) || ($rule->any !== [] && $rule->all !== [])) {
                throw new \InvalidArgumentException('Rule must use exactly one of any/all.');
            }
            if (count($rule->any) > 32 || count($rule->all) > 32 || count($rule->excludes) > 32 || count($rule->context) > 4) {
                throw new \InvalidArgumentException('Rule pattern/context count is invalid.');
            }
            $rules[] = $rule;
        }
        usort($rules, static fn (Rule $a, Rule $b): int => [$a->id, $a->claim, $a->state] <=> [$b->id, $b->claim, $b->state]);

        return new RulePack($id, $version, $rules);
    }

    public static function packLimit(): int { return self::MAX_PACKS; }
}
