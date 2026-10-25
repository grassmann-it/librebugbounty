<?php

namespace App\Value;

/** Display vocabulary only. Classification remains in the shared report policy. */
final class ReportKindLabels
{
    public const KIND_LABELS = ['pure_information_disclosure' => 'Only Information Disclosure'];

    public const CHOICES = [
        'pure_xss' => 'Only XSS',
        'only_information_disclosure' => 'Only Information Disclosure',
        'mixed' => 'Gemischt',
        'unknown' => 'Unbekannt / offener Prüfbedarf',
        'mixed_or_unknown' => 'Gemischt / weiterer Prüfbedarf',
        'xss_containing' => 'Mit XSS (einschließlich Kombinationen)',
    ];
}
