<?php

use App\Service\StudioExportProfileService;
use App\Value\FindingReadLabels;

/** @var \App\Dto\FindingDetailView $view */
/** @var callable $escape */
/** @var callable $csrfField */
/** @var ?string $message */
/** @var ?string $error */
/** @var ?string $returnPath */
$finding = $view->finding;
$assessment = $finding->getManualAssessment();
$state = $view->assessmentState;
$latestRun = $state->latestRun;
$latestJob = $view->screenshotJobs[0] ?? null;
// New P7 labels stay local; P6 exclusively owns translation catalogs.
$captureLabels = $locale === 'de' ? [
    'initialUrl' => 'Start-URL', 'finalUrl' => 'Endgültige URL',
    'triggerJobId' => 'Auslösender Screenshot-Auftrag', 'triggerEvidenceId' => 'Auslösender Beleg',
    'initialHttpStatus' => 'HTTP-Status beim Start', 'currentHttpStatus' => 'HTTP-Status bei Aufnahme',
    'captureMethod' => 'Aufnahmemethode',
] : [
    'initialUrl' => 'Initial URL', 'finalUrl' => 'Final URL',
    'triggerJobId' => 'Trigger screenshot job', 'triggerEvidenceId' => 'Trigger evidence',
    'initialHttpStatus' => 'Initial HTTP status', 'currentHttpStatus' => 'Capture HTTP status',
    'captureMethod' => 'Capture method',
];
$renderProvenance = static function (array $provenance, bool $captured = true, bool $details = true) use ($escape, $locale, $captureLabels, $t): void {
    ?>
    <div data-capture-provenance>
      <p class="studio-challenge-notice"><?= $escape(StudioExportProfileService::captureDescription($provenance, $locale, $captured)) ?></p>
      <?php if ($details): ?>
      <dl class="studio-record-data">
        <?php foreach ($captureLabels as $key => $label): ?>
          <dt><?= $escape($label) ?></dt><dd><code><?= $escape($provenance[$key] ?? $t('unbekannt')) ?></code></dd>
        <?php endforeach; ?>
      </dl>
      <?php endif; ?>
    </div>
    <?php
};
$reviewResetById = [];
foreach ($view->reviewResets as $reset) {
    $reviewResetById[$reset->getId()] = $reset;
}
$findingPath = '/findings/'.$finding->getId();
$returnPath ??= null;
$returnField = $returnPath !== null
    ? '<input type="hidden" name="return_to" value="'.$escape($returnPath).'">'
    : '';
$jobLabel = static fn (string $status): string => $t(match ($status) {
    'queued' => 'Vorgemerkt',
    'running' => 'Aufnahme läuft',
    'available' => 'Aufnahme abgeschlossen',
    'failed' => 'Aufnahme fehlgeschlagen',
    default => $status,
});
$jobTone = static fn (string $status): string => match ($status) {
    'queued', 'running' => 'pending',
    'available' => 'success',
    'failed' => 'error',
    default => 'neutral',
};
$storedTime = static function (?string $value) use ($formatTime, $t): string {
    if ($value === null || $value === '') {
        return $t('Zeitpunkt unbekannt');
    }
    try {
        return $formatTime(new \DateTimeImmutable($value));
    } catch (\Throwable) {
        return $value;
    }
};
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($finding->getDomain()->getHostname()) ?> · <?= $escape($t('Fall')) ?> · <?= $escape(\App\AppInfo::NAME.' '.\App\AppInfo::RELEASE_NAME) ?></title>
  <?php require __DIR__.'/styles.php'; ?>
  <link rel="stylesheet" href="/css/studio-detail.css?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/css/studio-detail.css'), 0, 16) ?>">
  <link rel="stylesheet" href="/css/studio-history.css?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/css/studio-history.css'), 0, 16) ?>">
  <link rel="stylesheet" href="/css/studio-follow-up.css?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/css/studio-follow-up.css'), 0, 16) ?>">
  <link rel="stylesheet" href="/css/studio-recovery.css?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/css/studio-recovery.css'), 0, 16) ?>">
  <script src="/js/i18n.js" defer></script>
  <script src="/js/studio-detail.js" defer></script>
</head>
<body data-studio data-studio-detail>
  <div class="studio-shell studio-detail-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div>
    </header>

    <main class="studio-detail-workspace" id="studio-detail-main">
      <div class="studio-case-heading">
        <div class="studio-case-title">
          <p class="studio-eyebrow"><?= $escape($t('Fall')) ?> · <?= $escape(substr($finding->getId(), 0, 8)) ?></p>
          <h1><?= $escape($finding->getDomain()->getHostname()) ?></h1>
          <p class="studio-case-subtitle"><?= $escape($finding->getTitle()) ?></p>
        </div>
        <a class="studio-detail-back" href="<?= $escape($returnPath ?? '/findings') ?>"><span aria-hidden="true">←</span> <?= $escape($t(str_starts_with($returnPath ?? '', '/errors') ? 'Zur Fehlerübersicht' : (str_starts_with($returnPath ?? '', '/review') ? 'Zur Review' : 'Zum Bestand'))) ?></a>
      </div>

      <?php $reportSummaryCompact = false; $reportSummaryDeferDetails = true; require __DIR__.'/report-summary.php'; ?>
      <?php if ($message !== null && $message !== ''): ?>
        <p class="studio-detail-feedback" data-tone="success" role="status"><?= $escape($message) ?></p>
      <?php endif; ?>
      <?php if ($error !== null && $error !== ''): ?>
        <p class="studio-detail-feedback" data-tone="error" role="alert"><?= $escape($error) ?></p>
      <?php endif; ?>

      <nav class="studio-detail-sections" aria-label="<?= $escape($t('Bereiche dieses Falls')) ?>">
        <a href="#beleg"><?= $escape($t('Beleg')) ?></a>
        <a href="#vergleich"><?= $escape($t('Bildvergleich')) ?></a>
        <a href="#nachverfolgung"><?= $escape($t('Nachverfolgung')) ?></a>
        <a href="#kontakte"><?= $escape($t('Kontaktvorschläge')) ?></a>
        <a href="#meldung"><?= $escape($t('Meldung & Verlauf')) ?></a>
        <a href="#entscheidung"><?= $escape($t('Entscheidung')) ?></a>
        <a href="#verlauf" data-open-history><?= $escape($t('Verlauf')) ?> <span><?= $escape($formatNumber(count($view->assessments) + count($view->screenshotJobs) + count($view->runs))) ?></span></a>
      </nav>

      <div class="studio-case-layout">
        <section class="studio-evidence-workspace" id="beleg" aria-labelledby="studio-evidence-title" tabindex="-1">
          <div class="studio-section-heading">
            <h2 id="studio-evidence-title"><?= $escape($t('Beleg')) ?></h2>
            <span class="studio-detail-muted"><?= $escape($t(count($view->screenshots) === 1 ? '{count} Bild' : '{count} Bilder', ['count' => $formatNumber(count($view->screenshots))])) ?></span>
          </div>

          <div class="studio-capture-state" data-tone="<?= $escape($latestJob !== null ? $jobTone($latestJob->getStatus()) : 'neutral') ?>">
            <span class="studio-status" data-tone="<?= $escape($latestJob !== null ? $jobTone($latestJob->getStatus()) : 'neutral') ?>"><?= $escape($latestJob !== null ? $t('Letzter Screenshot · {status}', ['status' => $jobLabel($latestJob->getStatus())]) : $t('Noch kein Screenshot-Auftrag')) ?></span>
            <?php if ($latestJob !== null): ?>
              <span class="studio-detail-muted"><?= $escape($formatTime($latestJob->getRequestedAt())) ?></span>
              <?php if ($latestJob->getStatus() === 'failed'): ?>
                <p><?= $escape($latestJob->getErrorMessage() ?? $t('Die Aufnahme konnte nicht abgeschlossen werden.')) ?></p>
              <?php elseif (in_array($latestJob->getStatus(), ['queued', 'running'], true)): ?>
                <p><?= $escape($t('Der Auftrag läuft separat. Vorhandene Bilder bleiben frühere Belege.')) ?></p>
              <?php endif; ?>
              <?php $latestMetadata = $latestJob->getCaptureMetadata() ?? []; ?>
              <?php $latestProvenance = StudioExportProfileService::captureProvenance($latestMetadata); ?>
              <?php if ($latestProvenance === null && ($latestMetadata['challengeDetected'] ?? false) === true): ?>
                <p class="studio-challenge-notice"><?= $escape($t(($latestMetadata['challengeCleared'] ?? false) === true ? 'Browser-Schutz erkannt und beendet; anschließend wurde die Zielseite beobachtet.' : 'Browser-Schutz blieb aktiv. Ein Bild kann die Schutzseite zeigen.')) ?></p>
              <?php endif; ?>
              <?php if ($latestProvenance !== null): ?>
                <?php $renderProvenance($latestProvenance, $latestJob->getStatus() === 'available' && $latestJob->getScreenshotPath() !== null, false); ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <?php if ($view->screenshots === []): ?>
            <div class="studio-evidence-empty">
              <svg width="42" height="42" viewBox="0 0 48 48" fill="none" aria-hidden="true"><rect x="7" y="8" width="34" height="31" rx="3" stroke="currentColor" stroke-width="1.4"/><circle cx="17" cy="18" r="3" stroke="currentColor" stroke-width="1.4"/><path d="m8 34 10-10 8 7 6-6 9 9" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
              <h3><?= $escape($t('Noch kein Bildbeleg')) ?></h3>
              <p><?= $escape($t($latestJob !== null && in_array($latestJob->getStatus(), ['queued', 'running'], true) ? 'Die Aufnahme entsteht im Hintergrund. Nach Abschluss kannst du die Seite aktualisieren.' : 'Aufnahmezustand und vorhandene technische Belege findest du im Verlauf.')) ?></p>
            </div>
          <?php else: ?>
            <?php if (count($view->screenshots) > 1): ?>
              <nav class="studio-shot-picker" aria-label="<?= $escape($t('Bildbeleg auswählen')) ?>">
                <?php foreach ($view->screenshots as $index => $shot): ?>
                  <?php $shotProvenance = StudioExportProfileService::captureProvenance($shot['job']?->getCaptureMetadata()); ?>
                  <a href="#bild-<?= $escape($shot['evidence']->getId()) ?>" data-shot-select="<?= $escape($shot['evidence']->getId()) ?>">
                    <span><?= $escape($t('Bild {number}', ['number' => $formatNumber($index + 1)])) ?></span>
                    <?php if ($shotProvenance !== null): ?><small data-interactive-capture><?= $escape($t('Interaktiv')) ?></small><?php endif; ?>
                    <small><?= $escape($shot['capturedAt'] !== null ? $formatTime($shot['capturedAt']) : $t('Aufnahmezeit unbekannt')) ?></small>
                    <?php if (!$shot['available']): ?><small class="studio-detail-error"><?= $escape($t('Datei nicht verfügbar')) ?></small><?php endif; ?>
                    <?php if ($shot['evidence']->getId() === $comparison->basisId()): ?><small data-assessment-basis><?= $escape($t('Beleg der Bewertung')) ?></small><?php endif; ?>
                  </a>
                <?php endforeach; ?>
              </nav>
            <?php endif; ?>
            <div class="studio-shot-stage" data-shot-stage>
              <?php foreach ($view->screenshots as $index => $shot): ?>
                <?php $shotProvenance = StudioExportProfileService::captureProvenance($shot['job']?->getCaptureMetadata()); ?>
                <?php $localImage = $shot['available'] && is_string($shot['url']) && str_starts_with($shot['url'], '/artifacts/'); ?>
                <figure class="studio-shot" id="bild-<?= $escape($shot['evidence']->getId()) ?>" data-shot-id="<?= $escape($shot['evidence']->getId()) ?>" tabindex="-1">
                  <?php if ($localImage): ?>
                    <a class="studio-shot-image-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= $escape($t('Bild {number} in Originalgröße öffnen', ['number' => $formatNumber($index + 1)])) ?>">
                      <img src="<?= $escape($shot['url']) ?>" alt="<?= $escape($t('Gespeicherter Screenshot {number} für {host}', ['number' => $formatNumber($index + 1), 'host' => $finding->getDomain()->getHostname()])) ?>" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>" decoding="async">
                    </a>
                  <?php else: ?>
                    <div class="studio-shot-missing">
                      <span aria-hidden="true">⊘</span>
                      <h3><?= $escape($t('Bilddatei nicht verfügbar')) ?></h3>
                      <p><?= $escape($t('Der Beleg ist aufgezeichnet. Die zugehörige Datei fehlt oder kann nicht gelesen werden.')) ?></p>
                    </div>
                  <?php endif; ?>
                  <?php if ($shotProvenance !== null): ?><?php $renderProvenance($shotProvenance, true, false); ?><?php endif; ?>
                  <figcaption>
                    <div>
                      <strong><?= $escape($t('Bild {number}', ['number' => $formatNumber($index + 1)])) ?></strong>
                      <?php if ($shotProvenance !== null): ?><strong data-interactive-capture><?= $escape($t('Interaktiv')) ?></strong><?php endif; ?>
                      <?php if ($shot['evidence']->getId() === $comparison->basisId()): ?><strong data-assessment-basis><?= $escape($t('Beleg der Bewertung')) ?></strong><?php endif; ?>
                      <span><?= $escape($t('Aufnahme')) ?>: <?= $escape($shot['capturedAt'] !== null ? $formatTime($shot['capturedAt']) : $t('unbekannt')) ?></span>
                      <span><?= $escape($t('Ablage')) ?>: <?= $escape($formatTime($shot['evidence']->getCreatedAt())) ?></span>
                    </div>
                    <?php if ($localImage): ?><a class="studio-detail-text-link" href="<?= $escape($shot['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($t('Original öffnen')) ?> <span aria-hidden="true">↗</span></a><?php endif; ?>
                  </figcaption>
                </figure>
              <?php endforeach; ?>
            </div>
            <p class="studio-detail-hint"><?= $escape($t('Ein Bildbeleg ist eine Aufnahme. Die manuelle Bewertung steht im Inspector.')) ?></p>
          <?php endif; ?>

          <div class="studio-case-source">
            <div class="studio-source-heading"><h3><?= $escape($t('Aktive PoC-URL')) ?></h3><button type="button" class="studio-copy-button" data-copy-target="studio-finding-url" hidden><?= $escape($t('URL kopieren')) ?></button></div>
            <code id="studio-finding-url"><?= $escape($finding->getPocUrl()) ?></code>
            <?php if ($finding->getPocRevision() > 0): ?><p><?= $escape($t('Ursprünglich gemeldete URL')) ?>: <code data-original-poc-url><?= $escape($finding->getUrl()) ?></code></p><?php endif; ?>
            <p class="studio-copy-status" role="status" aria-live="polite" data-copy-status></p>
            <?php require __DIR__.'/source-review.php'; ?>
            <?php if ($view->pocHistory !== []): ?>
              <details class="studio-detail-fold" data-poc-history><summary><?= $escape($t('PoC-URL-Verlauf')) ?></summary>
                <p class="studio-detail-hint"><?= $escape($t('Die aktive URL wird für künftige Prüfungen verwendet. Frühere Belege behalten ihre tatsächliche Aufnahme-URL.')) ?></p>
                <?php foreach ($view->pocHistory as $pocChange): ?>
                  <dl class="studio-record-data">
                    <dt><?= $escape($t('Geändert')) ?></dt><dd><?= $escape($storedTime($pocChange['changedAt'])) ?></dd>
                    <dt><?= $escape($t('Vorherige PoC-URL')) ?></dt><dd><code><?= $escape($pocChange['previousUrl']) ?></code></dd>
                    <dt><?= $escape($t('Neue PoC-URL')) ?></dt><dd><code><?= $escape($pocChange['url']) ?></code></dd>
                    <dt><?= $escape($t('Quelle')) ?></dt><dd><?= $escape($t($pocChange['source'] === 'manual' ? 'Manuelle URL-Angabe' : 'Im Browser beobachtet')) ?></dd>
                    <?php if (($pocChange['sourceUrl'] ?? null) !== null): ?><dt><?= $escape($t('Tatsächliche Aufnahme-URL')) ?></dt><dd><code><?= $escape($pocChange['sourceUrl']) ?></code></dd><?php endif; ?>
                    <?php if (($pocChange['capturedAt'] ?? null) !== null): ?><dt><?= $escape($t('Aufnahme')) ?></dt><dd><?= $escape($storedTime($pocChange['capturedAt'])) ?></dd><?php endif; ?>
                  </dl>
                <?php endforeach; ?>
              </details>
            <?php endif; ?>
            <details class="studio-detail-fold">
              <summary><?= $escape($t('Kennzeichen & Eingabedaten')) ?></summary>
              <dl class="studio-record-data">
                <dt><?= $escape($t('Kennzeichen')) ?></dt><dd><code><?= $escape($finding->getExpectedEvidence() ?? $t('Nicht aufgezeichnet')) ?></code></dd>
                <?php if ($finding->getPayload() !== null && $finding->getPayload() !== ''): ?><dt>Payload</dt><dd><code><?= $escape($finding->getPayload()) ?></code></dd><?php endif; ?>
                <dt><?= $escape($t('Eingetragen')) ?></dt><dd><?= $escape($formatTime($finding->getSubmittedAt())) ?></dd>
                <dt><?= $escape($t('Typ')) ?></dt><dd><?= $escape($finding->getType()) ?></dd>
                <dt><?= $escape($t('HTTP-Methode')) ?></dt><dd><?= $escape($finding->getMethod()) ?></dd>
                <?php if ($finding->getLastRetestedAt() !== null): ?><dt><?= $escape($t('Letzte Prüfung')) ?></dt><dd><?= $escape($formatTime($finding->getLastRetestedAt())) ?></dd><?php endif; ?>
              </dl>
              <?php if ($finding->getRequestParams() !== null): ?><pre><?= $escape(json_encode($finding->getRequestParams(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre><?php endif; ?>
            </details>
          </div>
        </section>

        <aside class="studio-inspector" id="entscheidung" aria-labelledby="studio-decision-title" tabindex="-1">
          <section class="studio-inspector-section">
            <p class="studio-eyebrow"><?= $escape($t('Inspector')) ?></p>
            <h2 id="studio-decision-title"><?= $escape($t('Bewertung')) ?></h2>
            <p class="studio-assessment-value" data-assessment="<?= $escape($assessment ?? 'unknown') ?>"><?= $escape($t(FindingReadLabels::assessment($assessment, $finding->getDiscardReason()))) ?></p>
            <?php if ($assessment !== null): ?>
              <p class="studio-detail-hint"><?= $escape($t('Manuell')) ?> · <?= $escape($formatTime($finding->getAssessedAt())) ?></p>
            <?php elseif ($finding->getStatus() !== 'new' || $finding->getReviewState() !== null): ?>
              <p class="studio-detail-hint"><?= $escape($t('Historischer Bestand · Herkunft und Entscheidungsgrundlage unbekannt. Status: {status} · Review: {review}.', ['status' => $finding->getStatus(), 'review' => $finding->getReviewState() ?? $t('unbekannt')])) ?></p>
            <?php endif; ?>
            <?php if ($finding->isDiscarded()): ?><p class="studio-detail-hint"><?= $escape($t('Im normalen Arbeiten ignoriert. Neue Beobachtungen reaktivieren diesen Fall nicht.')) ?></p><?php endif; ?>

            <?php
            $recoveryFindingId = $finding->getId(); $recoveryContextToken = $reviewContextToken;
            $recoveryDisposition = $view->reviewDisposition; $recoveryTrailId = null;
            require __DIR__.'/review-recovery.php';
            ?>

            <div class="studio-observation" id="review-notice">
              <h3><?= $escape($t('Technische Beobachtung')) ?></h3>
              <p><?= $escape($t(FindingReadLabels::observation($latestRun?->getResult()))) ?></p>
              <?php if ($latestRun !== null): ?><span class="studio-detail-muted"><?= $escape($formatTime($state->latestAt)) ?> · <?= $escape($latestRun->getMode()) ?></span><?php endif; ?>
              <?php if ($state->newerObservation): ?><p class="studio-observation-callout" data-review-detail-notice><?= $escape($t('Neue Widersprüche, Unklarheiten oder Fehler sind noch nicht gesichtet. Die Bewertung bleibt erhalten.')) ?> <a href="/review?kind=changed&amp;images=all"><?= $escape($t('Neue Hinweise im Review prüfen ↗')) ?></a></p><?php endif; ?>
              <?php if ($state->needsConfirmation && !$state->newerObservation): ?><p class="studio-observation-callout"><?= $escape($t('Manuelle Beurteilung erforderlich. Ein uneindeutiges Ergebnis bedeutet keine Behebung.')) ?></p><?php endif; ?>
            </div>

            <form method="post" action="<?= $escape($findingPath) ?>/assessment" class="studio-assessment-form" id="assessment-form">
              <?= $csrfField('finding_assessment_'.$finding->getId()) ?>
              <?= $returnField ?>
              <details class="studio-detail-fold studio-basis">
                <summary><?= $escape($t('Bewertungsgrundlage')) ?> <span><?= $escape($t('Optional')) ?></span></summary>
                <p class="studio-detail-hint"><?= $escape($t('Nur auswählen, wenn du diesen Beleg oder diese Beobachtung beurteilt hast. Ohne Auswahl bleibt die Grundlage unbekannt.')) ?></p>
                <label for="studio-observation-basis"><?= $escape($t('Beurteilte Beobachtung')) ?></label>
                <select name="observation_id" id="studio-observation-basis">
                  <option value=""><?= $escape($t('Unbekannt / keine konkrete Beobachtung')) ?></option>
                  <?php foreach ($view->runs as $run): ?><option value="<?= $escape($run->getId()) ?>"><?= $escape($formatTime($run->getFinishedAt() ?? $run->getStartedAt()).' · '.$t(FindingReadLabels::observation($run->getResult())).' · '.substr($run->getId(), 0, 8)) ?></option><?php endforeach; ?>
                </select>
                <label for="studio-evidence-basis"><?= $escape($t('Beurteilter Beleg')) ?></label>
                <select name="evidence_id" id="studio-evidence-basis">
                  <option value=""><?= $escape($t('Unbekannt / kein konkreter Beleg')) ?></option>
                  <?php foreach ($view->evidence as $item): ?><option value="<?= $escape($item->getId()) ?>"><?= $escape($formatTime($item->getCreatedAt()).' · '.$item->getKind().' · '.substr($item->getId(), 0, 8)) ?></option><?php endforeach; ?>
                </select>
              </details>
              <div class="studio-assessment-actions">
                <?php if ($state->canConfirm): ?><button class="studio-detail-button studio-detail-button-primary" type="submit" name="assessment" value="confirmed"><?= $escape($t('Bestätigen')) ?></button><?php endif; ?>
                <?php if ($state->canMarkFixed): ?><button class="studio-detail-button" type="submit" name="assessment" value="fixed"><?= $escape($t('Behoben')) ?></button><?php endif; ?>
              </div>
              <?php if ($state->canDiscard): ?>
                <details class="studio-detail-fold studio-discard">
                  <summary><?= $escape($t('Verwerfen')) ?></summary>
                  <label for="studio-discard-reason"><?= $escape($t('Grund')) ?></label>
                  <select name="discard_reason" id="studio-discard-reason"><option value=""><?= $escape($t('Ohne besonderen Grund')) ?></option><option value="duplicate"><?= $escape($t('Duplikat')) ?></option></select>
                  <p class="studio-detail-hint"><?= $escape($t('Der Fall bleibt erhalten und wird im normalen Arbeiten ignoriert.')) ?></p>
                  <button class="studio-detail-button studio-detail-button-discard" type="submit" name="assessment" value="discarded"><?= $escape($t('Verwerfen speichern')) ?></button>
                </details>
              <?php endif; ?>
            </form>

            <div class="studio-case-proof" data-cribrum-panel-host><?php require __DIR__.'/report-summary-details.php'; ?></div>
      <?php if (count($reportHistory ?? []) > 1): ?>
      <details data-report-history><summary><?= $escape($locale === 'de' ? 'Klassifikationsverlauf · vorher / nachher' : 'Classification history · before / after') ?></summary>
        <?php foreach ($reportHistory as $revision): ?>
          <p><strong><?= $escape('#'.$revision['decisionRevision'].' · '.$revision['caseSummary']['kind']) ?></strong> · <?= $escape($revision['decidedAt']) ?> · <?= $escape($revision['gate']) ?></p>
          <p><code><?= $escape(implode(' · ', $revision['tags'])) ?></code></p>
          <p><?= $escape(implode(' · ', $revision['reasonCodes'])) ?></p>
        <?php endforeach; ?>
        <p><?= $escape($locale === 'de' ? 'Ein neuer Auswertungsstand ist kein neuer Zielbesuch. Aufnahmezeit und Auswertung bleiben über die Belegreferenzen getrennt.' : 'A new interpretation is not a new target visit. Capture time and evaluation remain separate through evidence references.') ?></p>
      </details>
      <?php endif; ?>
              <?php if (($reportProofCandidates ?? []) !== []): ?>
              <details id="worker-proof-review"><summary><?= $escape($locale === 'de' ? 'Worker-Beleg ausdrücklich zuordnen' : 'Explicitly attribute worker evidence') ?></summary>
                <p><?= $escape($locale === 'de' ? 'Ein vorhandener Dialog benötigt eine begründete Zuordnung. Die allgemeine manuelle Bewertung allein gibt keine neue XSS-Mail frei.' : 'An existing dialog needs a reasoned attribution. A general manual verdict alone does not release a new XSS email.') ?></p>
                <?php foreach ($reportProofCandidates as $proof): ?>
                <form method="post" action="/findings/<?= $escape($finding->getId()) ?>/report-proof-review">
                  <?= $csrfField('report_proof_review_'.$finding->getId()) ?>
                  <input type="hidden" name="proof_id" value="<?= $escape($proof['proofId']) ?>">
                  <input type="hidden" name="basis_revision" value="<?= $escape($reportProjection['basisRevision']) ?>">
                  <p><code><?= $escape($proof['sourceKind'].' · '.$proof['sourceId'].' · '.$proof['dialogOrigin']) ?></code></p>
                  <pre><?= $escape($proof['dialogText']) ?></pre>
                  <label><?= $escape($locale === 'de' ? 'Grund der Zuordnung zum aktuellen PoC' : 'Reason for attribution to the current PoC') ?><textarea name="reason" required maxlength="4000" rows="2"></textarea></label>
                  <button type="submit"><?= $escape($locale === 'de' ? 'Diesen Worker-Beleg als Ausführung bestätigen' : 'Confirm this worker event as execution evidence') ?></button>
                </form>
                <?php endforeach; ?>
              </details>
              <?php endif; ?>

          </section>

          <section class="studio-inspector-section" aria-labelledby="studio-note-title">
            <h2 id="studio-note-title"><?= $escape($t('Notiz')) ?></h2>
            <form method="post" action="<?= $escape($findingPath) ?>/notes" data-studio-notes>
              <?= $csrfField('finding_notes_'.$finding->getId()) ?>
              <?= $returnField ?>
              <label class="studio-sr-only" for="studio-case-notes"><?= $escape($t('Notiz zu diesem Fall')) ?></label>
              <textarea id="studio-case-notes" name="notes" rows="4" placeholder="<?= $escape($t('Deine Notiz zu diesem Fall')) ?>"><?= $escape($finding->getPrivateNotes() ?? '') ?></textarea>
              <div class="studio-note-actions"><span class="studio-detail-muted" data-note-state role="status"><?= $escape($t('Explizit speichern')) ?></span><button class="studio-detail-button" type="submit"><?= $escape($t('Notiz speichern')) ?></button></div>
            </form>
          </section>

          <section class="studio-inspector-section" aria-labelledby="studio-contact-title">
            <div class="studio-source-heading"><h2 id="studio-contact-title"><?= $escape($t('Kontakt')) ?></h2><span class="studio-status" data-tone="<?= $finding->getContactedAt() !== null ? 'success' : 'neutral' ?>"><?= $escape($t(FindingReadLabels::contact($finding->getContactedAt()))) ?></span></div>
            <?php if ($finding->getContactedAt() !== null): ?>
              <p class="studio-detail-hint"><?= $escape($formatTime($finding->getContactedAt())) ?></p>
            <?php else: ?>
              <p class="studio-detail-hint"><?= $escape($t('Den Zeitpunkt eines bereits erfolgten Kontakts festhalten.')) ?></p>
              <form method="post" action="<?= $escape($findingPath) ?>/mark-contacted">
                <?= $csrfField('finding_mark_contacted_'.$finding->getId()) ?>
                <?= $returnField ?>
                <button class="studio-detail-button" type="submit"><?= $escape($t('Als kontaktiert markieren')) ?></button>
              </form>
            <?php endif; ?>
          </section>
          <section class="studio-inspector-section" aria-labelledby="studio-sent-title">
            <div class="studio-source-heading"><h2 id="studio-sent-title"><?= $escape($t('Meldung versendet')) ?></h2><span class="studio-status" data-tone="<?= $finding->getNotifiedOwnerAt() !== null ? 'success' : 'neutral' ?>"><?= $escape($t($finding->getNotifiedOwnerAt() !== null ? 'Versendet' : 'Noch kein Versand erfasst')) ?></span></div>
            <?php if ($finding->getNotifiedOwnerAt() !== null): ?>
              <p class="studio-detail-hint"><time datetime="<?= $escape($finding->getNotifiedOwnerAt()->format(DATE_ATOM)) ?>"><?= $escape($formatTime($finding->getNotifiedOwnerAt())) ?></time></p>
            <?php else: ?>
              <p class="studio-detail-hint"><?= $escape($t('Eine bereits an den Betreiber verschickte Meldung festhalten.')) ?></p>
              <form method="post" action="<?= $escape($findingPath) ?>/mark-sent" data-studio-sent>
                <?= $csrfField('finding_mark_sent_'.$finding->getId()) ?>
                <?= $returnField ?>
                <button class="studio-detail-button" type="submit"><?= $escape($t('Als versendet markieren')) ?></button>
              </form>
            <?php endif; ?>
          </section>
          <?php if (!$finding->isDiscarded() && !$workState['checksBlocked']): ?>
            <section class="studio-inspector-section" aria-labelledby="studio-technical-actions-title">
              <h2 id="studio-technical-actions-title"><?= $escape($t('Technische Aktionen')) ?></h2>
              <div class="studio-technical-actions">
                <form method="post" action="<?= $escape($findingPath) ?>/screenshots" data-studio-screenshot-action aria-describedby="studio-screenshot-action-hint">
                  <?= $returnField ?>
                  <button class="studio-detail-button" type="submit"><?= $escape($t('Screenshot einreihen')) ?></button>
                  <p class="studio-detail-hint" id="studio-screenshot-action-hint"><?= $escape($t('Reiht eine neue Aufnahme im Hintergrund ein. Die Bewertung bleibt unverändert.')) ?></p>
                </form>
                <form method="post" action="<?= $escape($findingPath) ?>/retest" data-studio-retest-action aria-describedby="studio-retest-action-hint">
                  <?= $returnField ?>
                  <button class="studio-detail-button studio-detail-button-primary" type="submit"><?= $escape($t('Ziel technisch erneut prüfen')) ?></button>
                  <p class="studio-detail-hint" id="studio-retest-action-hint"><?= $escape($t('Ruft die gemeldete URL jetzt aktiv im Browser auf und speichert das technische Ergebnis. Dabei entsteht kein Screenshot.')) ?></p>
                </form>
              </div>
            </section>
          <?php endif; ?>
          <section class="studio-inspector-section" aria-labelledby="studio-maintenance-title">
            <h2 id="studio-maintenance-title"><?= $escape($t('Fallverwaltung')) ?></h2>
            <details class="studio-detail-fold studio-delete" data-studio-delete-section>
              <summary><?= $escape($t('Fall endgültig löschen')) ?></summary>
              <p class="studio-detail-hint"><?= $escape($t('Domainsperren und das Sperrprotokoll bleiben auch nach dem Löschen erhalten.')) ?></p>
              <p class="studio-detail-hint"><?= $escape($t('Dieser Fall, seine Bewertungen, technischen Beobachtungen, Screenshot-Aufträge und Belege werden dauerhaft entfernt.')) ?></p>
              <form method="post" action="<?= $escape($findingPath) ?>/delete" data-studio-delete>
                <?= $returnField ?>
                <label class="studio-delete-confirmation" for="studio-delete-confirmation">
                  <input id="studio-delete-confirmation" type="checkbox" name="confirm_delete" value="1" required>
                  <span><?= $escape($t('Diesen Fall und sämtliche Belege endgültig löschen.')) ?></span>
                </label>
                <button class="studio-detail-button studio-detail-button-danger" type="submit"><?= $escape($t('Endgültig löschen')) ?></button>
              </form>
            </details>
          </section>
        </aside>
      </div>

      <?php require __DIR__.'/follow-up.php'; ?>
      <?php require __DIR__.'/disclosure-record.php'; ?>
      <?php require __DIR__.'/comparison.php'; ?>

      <?php require __DIR__.'/finding-history.php'; ?>
    </main>

    <?php $activeWorkspace = 'finding'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
