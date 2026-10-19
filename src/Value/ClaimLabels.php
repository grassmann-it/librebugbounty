<?php

namespace App\Value;

final class ClaimLabels
{
    public const CLAIMS = [
        'xss' => 'XSS', 'sql_injection' => 'SQL-Injection', 'local_file_inclusion' => 'Local File Inclusion',
        'remote_file_inclusion' => 'Remote File Inclusion', 'information_disclosure' => 'Information Disclosure',
        'error_disclosure' => 'Fehlermeldung offengelegt', 'stack_trace' => 'Stacktrace',
    ];

    public const STATES = [
        'observed' => 'Beobachtet', 'suspected' => 'Verdacht', 'confirmed' => 'Bestätigt',
        'rejected' => 'Abgelehnt', 'resolved' => 'Behoben', 'stale' => 'Aktuell nicht belegt',
    ];
}
