<?php

namespace App\Cribrum;

final readonly class RulePack
{
    /** @param list<Rule> $rules */
    public function __construct(public string $id, public string $version, public array $rules)
    {
    }
}
