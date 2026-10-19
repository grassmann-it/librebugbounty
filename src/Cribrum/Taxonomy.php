<?php

namespace App\Cribrum;

final class Taxonomy
{
    public const XSS = 'xss';
    public const SQL_INJECTION = 'sql_injection';
    public const LOCAL_FILE_INCLUSION = 'local_file_inclusion';
    public const REMOTE_FILE_INCLUSION = 'remote_file_inclusion';
    public const INFORMATION_DISCLOSURE = 'information_disclosure';
    public const ERROR_DISCLOSURE = 'error_disclosure';
    public const STACK_TRACE = 'stack_trace';

    public const OBSERVED = 'observed';
    public const SUSPECTED = 'suspected';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';
    public const STALE = 'stale';
    public const RESOLVED = 'resolved';

    public const CLAIMS = [
        self::XSS,
        self::SQL_INJECTION,
        self::LOCAL_FILE_INCLUSION,
        self::REMOTE_FILE_INCLUSION,
        self::INFORMATION_DISCLOSURE,
        self::ERROR_DISCLOSURE,
        self::STACK_TRACE,
    ];

    public const STATES = [
        self::OBSERVED,
        self::SUSPECTED,
        self::CONFIRMED,
        self::REJECTED,
        self::STALE,
        self::RESOLVED,
    ];

    public static function claims(): array { return self::CLAIMS; }
    public static function states(): array { return self::STATES; }
    public static function isClaim(string $claim): bool { return in_array($claim, self::CLAIMS, true); }
    public static function isState(string $state): bool { return in_array($state, self::STATES, true); }
    private function __construct() {}
}
