<?php
/** @var \App\Dto\FindingDetailView $view */
$sourceReview = $view->sourceReview;
$sourceReviewStatus = in_array($sourceReview['status'] ?? '', ['unavailable', 'open', 'pending', 'current', 'stale'], true)
    ? $sourceReview['status'] : 'unavailable';
$sourceReviewCopy = $locale === 'de' ? [
    'unavailable' => 'KI-Sichtung noch nicht verfügbar',
    'open' => 'KI-Sichtung offen',
    'pending' => 'KI-Sichtung läuft',
    'current' => 'Quelle von KI gesichtet',
    'stale' => 'Neue Quelle noch nicht von KI gesichtet',
    'no_new_hint' => 'Keine zusätzlichen Hinweise',
    'potential_gap' => 'Möglicherweise übersehener Hinweis',
    'corrected_gap' => 'Erkennungslücke korrigiert',
    'context_required' => 'Weitere Einordnung nötig',
    'reviewer' => 'Gesichtet von', 'reviewedAt' => 'Zeitpunkt',
    'outcome' => 'Ergebnis der Sichtung', 'source' => 'Gespeicherte Quelle',
    'pendingNote' => 'Diese gespeicherte Quelle wird bereits von einem Worker gesichtet.',
    'openNote' => 'Die aktuelle gespeicherte Quelle wurde noch nicht von KI gesichtet.',
    'staleNote' => 'Die frühere KI-Sichtung gilt für eine andere Quelle oder einen früheren Anfragekontext.',
    'unavailableNote' => 'Die KI-Sichtung ist für diese Quelle derzeit nicht verfügbar.',
    'certaintyNote' => 'Eine KI-Sichtung bestätigt keine Schwachstelle.',
] : [
    'unavailable' => 'AI source review not yet available',
    'open' => 'AI source review open',
    'pending' => 'AI source review in progress',
    'current' => 'Source reviewed by AI',
    'stale' => 'New source awaits AI review',
    'no_new_hint' => 'No additional hints',
    'potential_gap' => 'Potentially missed hint',
    'corrected_gap' => 'Detection gap corrected',
    'context_required' => 'More context needed',
    'reviewer' => 'Reviewed by', 'reviewedAt' => 'Reviewed at',
    'outcome' => 'Review outcome', 'source' => 'Retained source',
    'pendingNote' => 'A worker is already reviewing this retained source.',
    'openNote' => 'The current retained source has not yet been reviewed by AI.',
    'staleNote' => 'The earlier AI review applies to another source or an earlier request context.',
    'unavailableNote' => 'AI source review is currently unavailable for this case.',
    'certaintyNote' => 'An AI review does not confirm a vulnerability.',
];
$sourceReviewTime = null;
if (is_string($sourceReview['reviewedAt'] ?? null) && $sourceReview['reviewedAt'] !== '') {
    try { $sourceReviewTime = $formatTime(new \DateTimeImmutable($sourceReview['reviewedAt'])); }
    catch (\Exception) { /* An invalid stored date is not displayed as a review time. */ }
}
?>
<details class="studio-detail-fold" data-source-review="<?= $escape($sourceReviewStatus) ?>">
  <summary><?= $escape($sourceReviewCopy[$sourceReviewStatus]) ?><?php if ($sourceReviewStatus === 'current' && $sourceReviewTime !== null): ?> <span><?= $escape($sourceReviewTime) ?></span><?php endif; ?></summary>
  <?php if ($sourceReviewStatus === 'current'): ?>
    <dl class="studio-record-data">
      <dt><?= $escape($sourceReviewCopy['reviewer']) ?></dt><dd><?= $escape($sourceReview['reviewer'] ?? '') ?></dd>
      <?php if ($sourceReviewTime !== null): ?><dt><?= $escape($sourceReviewCopy['reviewedAt']) ?></dt><dd><?= $escape($sourceReviewTime) ?></dd><?php endif; ?>
      <dt><?= $escape($sourceReviewCopy['outcome']) ?></dt><dd><?= $escape($sourceReviewCopy[$sourceReview['outcome'] ?? ''] ?? $sourceReviewCopy['context_required']) ?></dd>
    </dl>
    <?php if (is_string($sourceReview['summary'] ?? null) && $sourceReview['summary'] !== ''): ?><p class="studio-detail-hint" data-source-review-summary><?= $escape($sourceReview['summary']) ?></p><?php endif; ?>
  <?php else: ?>
    <p class="studio-detail-hint"><?= $escape($sourceReviewCopy[$sourceReviewStatus.'Note']) ?></p>
    <?php if ($sourceReviewStatus === 'pending' && is_string($sourceReview['reviewer'] ?? null)): ?><p class="studio-detail-hint"><?= $escape($sourceReview['reviewer']) ?></p><?php endif; ?>
  <?php endif; ?>
  <?php if (is_string($sourceReview['artifactSha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $sourceReview['artifactSha256'])): ?>
    <p class="studio-detail-hint"><?= $escape($sourceReviewCopy['source']) ?>: <code title="<?= $escape($sourceReview['artifactSha256']) ?>"><?= $escape(substr($sourceReview['artifactSha256'], 0, 12)) ?></code></p>
  <?php endif; ?>
  <p class="studio-detail-hint"><?= $escape($sourceReviewCopy['certaintyNote']) ?></p>
</details>
