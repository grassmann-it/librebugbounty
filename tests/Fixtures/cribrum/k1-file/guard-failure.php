<?php

// Isolated test process only: force the remote negative guard to report a PCRE error.
namespace App\Cribrum\Detector {
    function preg_match(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int|false
    {
        if (str_contains($pattern, 'URL file-access is disabled')) {
            @\preg_match('~[~', '');
            return false;
        }
        return \preg_match($pattern, $subject, $matches, $flags, $offset);
    }
}

namespace {
    require dirname(__DIR__, 4).'/vendor/autoload.php';
    $input = \App\Cribrum\ObservationInput::fromArray([
        'url' => 'https://fixture.test/', 'payload' => 'https://other.test/probe.php',
        'rawHtml' => 'Warning: include(https://other.test/probe.php): failed to open stream: No such file or directory',
    ]);
    $evaluation = (new \App\Cribrum\Detector\FileTriageDetector())->evaluate($input);
    echo json_encode(['status' => $evaluation->status, 'codes' => array_column($evaluation->signals, 'code'), 'families' => $evaluation->familyOutcomes], JSON_THROW_ON_ERROR);
}
