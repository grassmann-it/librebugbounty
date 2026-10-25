<?php

namespace App\Cribrum\Detector;

/** Invalid partial work must never become qualified evidence. */
final class InvalidEvidenceCandidate extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Evidence candidate does not match the input artifact.');
    }
}
