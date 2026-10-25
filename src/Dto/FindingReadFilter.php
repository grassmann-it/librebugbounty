<?php

namespace App\Dto;

use App\Value\FindingSeverity;
use App\Value\FindingStatus;
use App\Value\ManualAssessment;
use App\Value\RetestResult;

final readonly class FindingReadFilter
{
    public function __construct(
        public string $domain = '',
        public string $assessment = '',
        public string $observation = '',
        public string $contact = '',
        public string $scope = 'active',
        public string $legacyStatus = '',
        public string $legacyBucket = '',
        public string $type = '',
        public string $severity = '',
        public bool $exactDomain = false,
        public string $q = '',
        public string $event = '',
        public string $from = '',
        public string $to = '',
        public string $tld = '',
        public string $sent = '',
        public string $legacyReview = '',
        public string $pursuit = '',
        public string $closureReason = '',
        public string $contactWork = '',
        public string $reminder = '',
        public string $view = '',
        public string $review = '',
        public string $claim = '',
        public string $claimState = '',
        public string $reportKind = '',
        public array $cribrumCodes = [],
        public string $extraFindings = '',
        public string $newFindings = '',
        public string $cribrumFreshness = '',
        public bool $cribrumHistory = false,
        public string $sort = 'default',
    ) {
        self::validate('additional findings', $extraFindings, ['', 'yes', 'no']);
        self::validate('new findings', $newFindings, ['', 'yes', 'no']);
        self::validate('Cribrum freshness', $cribrumFreshness, ['', 'current', 'missing', 'stale']);
        self::validate('sort', $sort, ['default', 'additional_first', 'cribrum_changed', 'additional_count']);
        if (!array_is_list($cribrumCodes) || count($cribrumCodes) > 100) {
            throw new \InvalidArgumentException('Ungültige Cribrum-Auswahl.');
        }
        foreach ($cribrumCodes as $code) {
            if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $code)) {
                throw new \InvalidArgumentException('Ungültiger Cribrum-Code.');
            }
        }
        self::validate('report kind', $reportKind, ['', 'pure_xss', 'only_information_disclosure', 'pure_information_disclosure', 'mixed', 'unknown', 'mixed_or_unknown', 'xss_containing']);
        self::validate('claim', $claim, ['', ...\App\Entity\FindingClaim::CLAIMS]);
        self::validate('claim state', $claimState, ['', ...\App\Entity\FindingClaim::STATES]);
        self::validate('review', $review, ['', 'all', 'open', 'deferred']);
        self::validate('view', $view, ['', 'workbasket']);
        self::validate('reminder', $reminder, ['', 'today', 'overdue', 'open']);
        self::validate('contact work', $contactWork, ['', 'allowed', 'blocked']);
        self::validate('pursuit', $pursuit, ['', 'active', 'closed']);
        self::validate('closure reason', $closureReason, ['', ...array_keys(\App\Value\PursuitStatus::REASONS)]);
        self::validate('assessment', $assessment, ['', 'unknown', ...ManualAssessment::values()]);
        self::validate('observation', $observation, ['', 'none', ...RetestResult::values()]);
        self::validate('contact', $contact, ['', 'yes', 'no']);
        self::validate('sent', $sent, ['', 'yes', 'no']);
        self::validate('scope', $scope, ['active', 'discarded', 'duplicates', 'all']);
        self::validate('legacy status', $legacyStatus, ['', ...FindingStatus::values()]);
        self::validate('legacy bucket', $legacyBucket, ['', 'open', 'fixed', 'manual_review', 'unchecked']);
        self::validate('legacy review', $legacyReview, ['', 'confirmed_fixed', 'manually_checked']);
        self::validate('severity', $severity, ['', ...FindingSeverity::values()]);
        self::validate('event', $event, ['', 'reported', 'sent', 'contacted', 'confirmed', 'fixed']);
        foreach ([$from, $to] as $date) {
            if ($date !== '') {
                self::date($date);
            }
        }
        if (($from !== '' || $to !== '') && $event === '') {
            throw new \InvalidArgumentException('Ein Datumsfilter benötigt ein Ereignis.');
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new \InvalidArgumentException('Der Beginn liegt nach dem Ende des Zeitraums.');
        }
        if ($tld !== '' && !in_array($tld, ['ip', 'local'], true) && !preg_match('/^\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $tld)) {
            throw new \InvalidArgumentException('Ungültige TLD.');
        }
    }

    public static function date(string $value): \DateTimeImmutable
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) || (int) substr($value, 0, 4) < 1 || (int) substr($value, 0, 4) > 9998) {
            throw new \InvalidArgumentException('Datumsangaben müssen gültige Tage im Format JJJJ-MM-TT sein.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Berlin'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Datumsangaben müssen gültige Tage im Format JJJJ-MM-TT sein.');
        }

        return $date;
    }

    /** @param list<string> $allowed */
    private static function validate(string $dimension, string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException('Ungültiger Filterwert.');
        }
    }
}
