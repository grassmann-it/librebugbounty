<?php

namespace App\Cribrum\Detector;

/** Carries engine metadata only: never a pattern, subject or reflected input. */
final class RegexFailure extends \RuntimeException
{
    public function __construct(public readonly string $operation, public readonly int $pcreCode)
    {
        parent::__construct('Checked regex '.$operation.' failed (PCRE '.$pcreCode.').');
    }
}
