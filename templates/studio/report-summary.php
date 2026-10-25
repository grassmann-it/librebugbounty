<?php
/** Shared read-only badges: presentation never acknowledges a review or changes mail selection. */
$reportProjection ??= null;
$reportSummaryCompact ??= false;
$summary = is_array($reportProjection['caseSummary'] ?? null) ? $reportProjection['caseSummary'] : [];
$kind = $summary['kind'] ?? 'unknown';
$purity = $summary['purity'] ?? 'unknown';
$reportText = static fn (mixed $value): string => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
$badgeSummary = ($finding ?? null) instanceof \App\Dto\FindingReadView
    ? \App\Service\CribrumFindingSummary::fromReadView($finding, $locale)
    : \App\Service\CribrumFindingSummary::derive($reportProjection, $cribrumClaims ?? (is_array($view->claims ?? null) ? $view->claims : []), $reportProjectionCurrent ?? null, $locale, $reportEvidence ?? []);
if (!(($finding ?? null) instanceof \App\Dto\FindingReadView)) {
    $badgeReviewReasons = array_column($view->triggeringObservations ?? $view->notice?->observations ?? [], 'reason');
    if (array_intersect(['classification_changed', 'waf_block'], $badgeReviewReasons) !== []) {
        $badgeSummary['new'] = true;
    }
}
$badgeFindingId = ($finding ?? null) instanceof \App\Dto\FindingReadView ? $finding->id : (($finding ?? null)?->getId() ?? '');
$badgeDetailBase = $reportSummaryCompact ? '/findings/'.rawurlencode($badgeFindingId).'?' . http_build_query(['return_to' => $returnPath ?? '/findings'], '', '&', PHP_QUERY_RFC3986) : '';
$badgeFilterQuery = $view->filterQuery ?? [];
if ($badgeFilterQuery === [] && str_starts_with($returnPath ?? '', '/findings?')) {
    parse_str(parse_url($returnPath, PHP_URL_QUERY) ?? '', $badgeFilterQuery);
}
$badgeCopy = $locale === 'de' ? [
 'title'=>'Erkenntnisse zu diesem Fall', 'filter'=>'Fälle filtern: ', 'proof'=>'Beleg ansehen', 'new'=>'Neu seit letzter Sichtung', 'caseNew'=>'Neue Erkenntnisse seit letzter Sichtung',
 'conflict'=>'Widerspruch · Sichtung erforderlich', 'missing'=>'Auswertung fehlt · weitere Befunde unbekannt', 'stale'=>'Gespeicherte Auswertung passt nicht zum aktuellen Fallstand · weitere Befunde unbekannt',
 'selected'=>'Zur Meldung ausgewählt', 'unselected'=>'Nicht zur Meldung ausgewählt', 'history'=>'Frühere Erkenntnisse', 'shadow'=>'Shadow / Testauswertung', 'diagnosis'=>'Technische Diagnose',
 'observed'=>'Beobachtet', 'recognized'=>'Erkannt', 'source'=>'Herkunft', 'unknown'=>'Nicht dokumentiert', 'review'=>'Neue Erkenntnisse sichten',
] : [
 'title'=>'Findings for this case', 'filter'=>'Filter cases: ', 'proof'=>'View evidence', 'new'=>'New since last review', 'caseNew'=>'New findings since last review',
 'conflict'=>'Conflict · review required', 'missing'=>'Evaluation missing · additional findings unknown', 'stale'=>'Saved evaluation does not match the current case state · additional findings unknown',
 'selected'=>'Selected for report', 'unselected'=>'Not selected for report', 'history'=>'Previous findings', 'shadow'=>'Shadow / test evaluation', 'diagnosis'=>'Technical diagnostics',
 'observed'=>'Observed', 'recognized'=>'Recognized', 'source'=>'Source', 'unknown'=>'Not recorded', 'review'=>'Review new findings',
];
$badgeOriginLabels = $locale === 'de' ? ['report_component'=>'Belegte Meldekomponente', 'report_observation'=>'Beobachtung aus der Auswertung', 'behavior_fact'=>'Gespeicherter technischer Beleg', 'claim:manual'=>'Manuelle Einordnung', 'claim:cribrum'=>'Cribrum-Auswertung', 'waf_review'=>'WAF-Sichtung'] : ['report_component'=>'Evidenced report component', 'report_observation'=>'Evaluation observation', 'behavior_fact'=>'Stored technical evidence', 'claim:manual'=>'Manual assessment', 'claim:cribrum'=>'Cribrum evaluation', 'waf_review'=>'WAF review'];
?>
<section class="cribrum-summary" data-cribrum-layout="<?= $reportSummaryCompact ? 'compact' : 'c1' ?>" data-report-summary data-report-kind="<?= $escape($kind) ?>" data-report-purity="<?= $escape($purity) ?>" aria-label="<?= $escape($badgeCopy['title']) ?>">
  <?php if (!$reportSummaryCompact && $badgeSummary['badges'] !== []): ?>
  <header class="cribrum-summary-heading"><h2><?= $escape($badgeCopy['title']) ?></h2><span><?= count($badgeSummary['badges']) ?> <?= $escape($locale === 'de' ? 'Erkenntnisse' : 'findings') ?></span></header>
  <p class="cribrum-action-help"><?= $escape($locale === 'de' ? 'Bubbles filtern Fälle. „Beleg ansehen“ öffnet den zugehörigen Beleg.' : 'Badges filter cases. “View evidence” opens the corresponding evidence.') ?></p>
  <?php if (count($badgeSummary['badges']) > 8): ?><p class="cribrum-scroll-cue"><?= $escape($locale === 'de' ? 'Weitere Erkenntnisse im Bereich scrollen oder mit Tab erreichen.' : 'Scroll this region or use Tab to reach more findings.') ?></p><?php endif; ?>
  <?php endif; ?>
  <ul class="cribrum-badges" aria-label="<?= $escape($badgeCopy['title']) ?>">
    <?php foreach ($badgeSummary['badges'] as $badge):
      $badgeState = \App\Value\CribrumBadgeCatalog::stateLabel($badge['state'], $locale);
      $badgeVisibleLabel = $badge['known'] ? $badge['label'] : ($locale === 'de' ? 'Nicht eingeordneter Hinweis' : 'Unclassified hint');
      $badgeLabel = $badgeVisibleLabel.($badgeState !== '' ? ' · '.$badgeState : '');
      $badgeQuery = array_replace($badgeFilterQuery, ['cribrum' => [$badge['code']]]); unset($badgeQuery['page']);
      $badgeFilterUrl = '/findings?'.http_build_query($badgeQuery, '', '&', PHP_QUERY_RFC3986);
      $badgeAnchor = $badge['detailAnchor'];
    ?>
    <li class="cribrum-badge-item" data-cribrum-code="<?= $escape($badge['code']) ?>">
      <<?= $badge['filterable'] ? 'a' : 'span' ?> class="cribrum-badge" data-certainty="<?= $escape($badge['state']) ?>" data-additional="<?= $badge['additional'] ? 'true' : 'false' ?>" <?php if ($badge['filterable']): ?>href="<?= $escape($badgeFilterUrl) ?>"<?php endif; ?> aria-label="<?= $escape(($badge['filterable'] ? $badgeCopy['filter'] : '').$badgeLabel) ?>"><span aria-hidden="true"><?= $badge['state'] === 'confirmed' ? '✓' : ($badge['state'] === 'suspected' ? '?' : '●') ?></span> <?= $escape($badgeLabel) ?></<?= $badge['filterable'] ? 'a' : 'span' ?>>
      <?php if ($badge['new']): ?><a class="cribrum-new" href="<?= $escape('/findings/'.rawurlencode($badgeFindingId).'#review-notice') ?>"><?= $escape($badgeCopy['new']) ?></a><?php endif; ?>
      <?php if ($badge['conflict']): ?><strong class="cribrum-conflict"><?= $escape($badgeCopy['conflict']) ?></strong><?php endif; ?>
      <a class="cribrum-proof-link" href="<?= $escape($badgeDetailBase.'#'.$badgeAnchor) ?>"><?= $escape($badgeCopy['proof']) ?><span class="cribrum-sr-only">: <?= $escape($badgeLabel) ?></span></a>
      <?php if ($badge['origin'] === 'claim:manual'): ?><span class="cribrum-claim-source"><?= $escape($locale === 'de' ? 'Manuell gespeichert' : 'Manually recorded') ?></span><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($badgeSummary['new']): ?><p class="cribrum-case-new" data-cribrum-case-new><a class="cribrum-new" href="<?= $escape('/findings/'.rawurlencode($badgeFindingId).'#review-notice') ?>"><?= $escape($badgeCopy['caseNew']) ?></a></p><?php endif; ?>
  <?php if ($badgeSummary['projectionStatus'] !== 'current'): ?><p class="cribrum-freshness" role="status" data-cribrum-freshness="<?= $escape($badgeSummary['projectionStatus']) ?>"><span aria-hidden="true">ⓘ</span> <?= $escape($badgeCopy[$badgeSummary['projectionStatus'] === 'missing' ? 'missing' : 'stale']) ?></p><?php endif; ?>
  <?php if (!$reportSummaryCompact): ?>
    <?php if (!($reportSummaryDeferDetails ?? false)) require __DIR__.'/report-summary-details.php'; ?>
  <?php endif; ?>
</section>
