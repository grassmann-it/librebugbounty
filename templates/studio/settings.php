<?php
/** @var string $defaultPayload */
/** @var string $reviewTimeout */
/** @var string $reviewDecisionDelay */
/** @var string $reviewPreloadCount */
/** @var string $inventoryPageSize */
/** @var string $exportProfile */
/** @var string $exportScreenshotMode */
/** @var string $recheckIntervalDays */
/** @var string $recheckErrorBackoffDays */
/** @var array<string, string> $errors */
/** @var ?string $message */
/** @var callable $escape */
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($t('Einstellungen')) ?> · <?= $escape(\App\AppInfo::NAME.' '.\App\AppInfo::RELEASE_NAME) ?></title>
  <?php require __DIR__.'/styles.php'; ?>
  <link rel="stylesheet" href="/css/studio-settings.css">
  <link rel="stylesheet" href="/css/studio-about.css">
  <link rel="stylesheet" href="/css/studio-follow-up.css">
  <script src="/js/i18n.js" defer></script>
</head>
<body data-studio data-studio-settings>
  <div class="studio-shell studio-settings-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div>
    </header>

    <main class="studio-settings-main">
      <div class="studio-settings-content">
        <header class="studio-settings-heading">
          <div>
            <p class="studio-eyebrow"><?= $escape($t('Arbeitsumgebung')) ?></p>
            <h1><?= $escape($t('Einstellungen')) ?></h1>
            <p><?= $escape($t('Standardwerte für künftige Aktionen. Sie ändern keine bestehende Fallentscheidung.')) ?></p>
          </div>
        </header>

        <?php if ($message !== null && $message !== ''): ?>
          <p class="studio-settings-feedback" data-tone="success" role="status"><?= $escape($message) ?></p>
        <?php endif; ?>
        <?php if ($errors !== []): ?>
          <div class="studio-settings-feedback" data-tone="error" role="alert">
            <strong><?= $escape($t('Die Einstellungen wurden nicht gespeichert.')) ?></strong>
            <span><?= $escape($t('Bitte korrigiere die markierten Felder. Deine Eingaben bleiben erhalten.')) ?></span>
          </div>
        <?php endif; ?>

        <div class="studio-settings-layout">
          <section class="studio-settings-panel" aria-labelledby="settings-title">
            <div class="studio-settings-panel-heading">
              <div><h2 id="settings-title"><?= $escape($t('Vorgaben')) ?></h2></div>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9 3-1 3-3 1-2 3 2 2v3l3 1 1 3h4l1-3 3-1v-3l2-2-2-3-3-1-1-3H9Z" transform="translate(1 1)" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.4"/></svg>
            </div>
            <form method="post" action="/settings" class="studio-settings-form">
              <?= $csrfField('settings_preferences') ?>
              <p class="studio-settings-field-hint"><?= $escape($t('Diese Vorgaben gelten für die lokale Installation. Deine Auswahl auf den Seiten hat Vorrang.')) ?></p>
              <fieldset class="studio-settings-group">
              <legend><?= $escape($t('Allgemein')) ?></legend>
              <label for="settings-default-payload"><?= $escape($t('Standardkennzeichen')) ?>
                <span><?= $escape($t('Wird beim Erfassen neuer URLs vorausgefüllt.')) ?></span>
              </label>
              <input id="settings-default-payload" name="default_payload" value="<?= $escape($defaultPayload) ?>" autocomplete="off" spellcheck="false"<?= isset($errors['default_payload']) ? ' aria-invalid="true" aria-describedby="settings-default-payload-error"' : '' ?>>
              <?php if (isset($errors['default_payload'])): ?><p class="studio-settings-field-error" id="settings-default-payload-error"><?= $escape($errors['default_payload']) ?></p><?php endif; ?>

              <input type="hidden" name="paste_submit_present" value="1">
              <label class="studio-paste-setting"><input type="checkbox" name="paste_submit" value="1"<?= $pasteSubmitEnabled ? ' checked' : '' ?>> <?= $escape($t('Eingefügte URL sofort erfassen')) ?></label>
              <p class="studio-settings-field-hint"><?= $escape($t('Nur im Eingang: URL-ähnlicher Text im URL-Feld oder außerhalb anderer Eingabefelder wird direkt erfasst.')) ?></p>

              <label for="settings-review-timeout"><?= $escape($t('Browser-Zeitlimit')) ?>
                <span><?= $escape($t('Gilt pro Screenshot-Aufnahme oder technischer Browserprüfung. Zulässig sind 1000 bis 120000 Millisekunden.')) ?></span>
              </label>
              <div class="studio-settings-timeout">
                <input id="settings-review-timeout" name="review_timeout_ms" value="<?= $escape($reviewTimeout) ?>" inputmode="numeric" pattern="[0-9]+" min="1000" max="120000" aria-describedby="settings-review-timeout-hint<?= isset($errors['review_timeout_ms']) ? ' settings-review-timeout-error' : '' ?>"<?= isset($errors['review_timeout_ms']) ? ' aria-invalid="true"' : '' ?>>
                <span aria-hidden="true">ms</span>
              </div>
              <p class="studio-settings-field-hint" id="settings-review-timeout-hint"><?= $escape($t('Ein höherer Wert hilft langsamen Zielseiten, bindet die jeweilige Browserprüfung aber länger.')) ?></p>
              <?php if (isset($errors['review_timeout_ms'])): ?><p class="studio-settings-field-error" id="settings-review-timeout-error"><?= $escape($errors['review_timeout_ms']) ?></p><?php endif; ?>
              </fieldset>

              <fieldset class="studio-settings-group">
              <legend><?= $escape($t('Review')) ?></legend>
              <label for="settings-review-decision-delay"><?= $escape($t('Entscheidungspause im Review')) ?></label>
              <select id="settings-review-decision-delay" name="review_decision_delay_seconds" aria-describedby="settings-review-decision-delay-hint<?= isset($errors['review_decision_delay_seconds']) ? ' settings-review-decision-delay-error' : '' ?>"<?= isset($errors['review_decision_delay_seconds']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (!in_array($reviewDecisionDelay, ['0', '3', '5'], true)): ?><option value="<?= $escape($reviewDecisionDelay) ?>" selected><?= $escape($t('Ungültige Auswahl')) ?></option><?php endif; ?>
                <?php foreach (['0' => 'Aus', '3' => '3 Sekunden', '5' => '5 Sekunden'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $reviewDecisionDelay === (string) $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?>
              </select>
              <p class="studio-settings-field-hint" id="settings-review-decision-delay-hint"><?= $escape($t('Neue Fälle können erst nach Ablauf der Pause bewertet werden.')) ?></p>
              <?php if (isset($errors['review_decision_delay_seconds'])): ?><p class="studio-settings-field-error" id="settings-review-decision-delay-error"><?= $escape($errors['review_decision_delay_seconds']) ?></p><?php endif; ?>
              <label for="settings-review-preload-count"><?= $escape($t('Fälle vorladen')) ?></label>
              <input id="settings-review-preload-count" name="review_preload_count" value="<?= $escape($reviewPreloadCount) ?>" inputmode="numeric" pattern="[0-9]+" min="0" max="50" aria-describedby="settings-review-preload-count-hint<?= isset($errors['review_preload_count']) ? ' settings-review-preload-count-error' : '' ?>"<?= isset($errors['review_preload_count']) ? ' aria-invalid="true"' : '' ?>>
              <p class="studio-settings-field-hint" id="settings-review-preload-count-hint"><?= $escape($t('Lädt die nächsten Fälle und vorhandenen Screenshots vor und füllt nach jeder Bewertung nach. 0 schaltet das Vorladen aus; maximal 50. Es werden keine neuen Aufnahmen gestartet.')) ?></p>
              <?php if (isset($errors['review_preload_count'])): ?><p class="studio-settings-field-error" id="settings-review-preload-count-error"><?= $escape($errors['review_preload_count']) ?></p><?php endif; ?>
              </fieldset>

              <fieldset class="studio-settings-group">
              <legend><?= $escape($t('Bestand')) ?></legend>
              <label for="settings-inventory-page-size"><?= $escape($t('Fälle pro Seite')) ?></label>
              <select id="settings-inventory-page-size" name="inventory_page_size" aria-describedby="settings-inventory-page-size-hint<?= isset($errors['inventory_page_size']) ? ' settings-inventory-page-size-error' : '' ?>"<?= isset($errors['inventory_page_size']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (!in_array($inventoryPageSize, ['10', '25', '50', '100'], true)): ?><option value="<?= $escape($inventoryPageSize) ?>" selected><?= $escape($t('Ungültige Auswahl')) ?></option><?php endif; ?>
                <?php foreach (['10', '25', '50', '100'] as $value): ?><option value="<?= $escape($value) ?>"<?= $inventoryPageSize === $value ? ' selected' : '' ?>><?= $escape($value) ?></option><?php endforeach; ?>
              </select>
              <p class="studio-settings-field-hint" id="settings-inventory-page-size-hint"><?= $escape($t('Startwert für den Bestand. Alle Fälle kannst du weiterhin direkt auf der Seite wählen.')) ?></p>
              <?php if (isset($errors['inventory_page_size'])): ?><p class="studio-settings-field-error" id="settings-inventory-page-size-error"><?= $escape($errors['inventory_page_size']) ?></p><?php endif; ?>
              </fieldset>

              <fieldset class="studio-settings-group">
              <legend><?= $escape($t('Automatische Rechecks')) ?></legend>
              <label for="settings-recheck-interval"><?= $escape($t('Recheck-Intervall')) ?>
                <span><?= $escape($t('Tage nach einem abgeschlossenen Check. Verkürzen zieht bereits geplante Checks vor; Fehler-Backoffs bleiben unverändert.')) ?></span>
              </label>
              <div class="studio-settings-unit"><input id="settings-recheck-interval" name="recheck_interval_days" value="<?= $escape($recheckIntervalDays) ?>" inputmode="numeric" pattern="[0-9]+" min="1" max="90" aria-describedby="settings-recheck-interval-hint<?= isset($errors['recheck_interval_days']) ? ' settings-recheck-interval-error' : '' ?>"<?= isset($errors['recheck_interval_days']) ? ' aria-invalid="true"' : '' ?>><span aria-hidden="true"><?= $escape($t('Tage')) ?></span></div>
              <p class="studio-settings-field-hint" id="settings-recheck-interval-hint"><?= $escape($t('Standard sind 14 Tage. Zulässig sind 1 bis 90 Tage.')) ?></p>
              <?php if (isset($errors['recheck_interval_days'])): ?><p class="studio-settings-field-error" id="settings-recheck-interval-error"><?= $escape($errors['recheck_interval_days']) ?></p><?php endif; ?>

              <label for="settings-recheck-error-backoff"><?= $escape($t('Wiederholung nach Fehlern')) ?>
                <span><?= $escape($t('Tage, bevor ein Fehlerergebnis erneut automatisch geprüft wird.')) ?></span>
              </label>
              <div class="studio-settings-unit"><input id="settings-recheck-error-backoff" name="recheck_error_backoff_days" value="<?= $escape($recheckErrorBackoffDays) ?>" inputmode="numeric" pattern="[0-9]+" min="1" max="30" aria-describedby="settings-recheck-error-backoff-hint<?= isset($errors['recheck_error_backoff_days']) ? ' settings-recheck-error-backoff-error' : '' ?>"<?= isset($errors['recheck_error_backoff_days']) ? ' aria-invalid="true"' : '' ?>><span aria-hidden="true"><?= $escape($t('Tage')) ?></span></div>
              <p class="studio-settings-field-hint" id="settings-recheck-error-backoff-hint"><?= $escape($t('Standard sind 3 Tage. Zulässig sind 1 bis 30 Tage.')) ?></p>
              <?php if (isset($errors['recheck_error_backoff_days'])): ?><p class="studio-settings-field-error" id="settings-recheck-error-backoff-error"><?= $escape($errors['recheck_error_backoff_days']) ?></p><?php endif; ?>
              </fieldset>

              <fieldset class="studio-settings-group">
              <legend><?= $escape($t('Export')) ?></legend>
              <label for="settings-export-profile"><?= $escape($t('Bevorzugte Exportvorlage')) ?></label>
              <select id="settings-export-profile" name="export_profile" aria-describedby="settings-export-profile-hint<?= isset($errors['export_profile']) ? ' settings-export-profile-error' : '' ?>"<?= isset($errors['export_profile']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (!in_array($exportProfile, ['urls', 'state', 'report'], true)): ?><option value="<?= $escape($exportProfile) ?>" selected><?= $escape($t('Ungültige Auswahl')) ?></option><?php endif; ?>
                <?php foreach (['report' => ['Meldung mit Belegen', 'ZIP'], 'state' => ['Aktueller Fallstand', 'JSON'], 'urls' => ['URL-Liste', 'JSON']] as $value => [$label, $format]): ?><option value="<?= $escape($value) ?>"<?= $exportProfile === $value ? ' selected' : '' ?>><?= $escape($t($label).' ('.$format.')') ?></option><?php endforeach; ?>
              </select>
              <p class="studio-settings-field-hint" id="settings-export-profile-hint"><?= $escape($t('Öffnet Export mit dieser Vorlage. Inhalte und private Notizen wählst du bei jedem Export selbst.')) ?></p>
              <?php if (isset($errors['export_profile'])): ?><p class="studio-settings-field-error" id="settings-export-profile-error"><?= $escape($errors['export_profile']) ?></p><?php endif; ?>

              <label for="settings-export-screenshots"><?= $escape($t('Screenshots im Meldungspaket')) ?></label>
              <select id="settings-export-screenshots" name="export_screenshot_mode" aria-describedby="settings-export-screenshots-hint<?= isset($errors['export_screenshot_mode']) ? ' settings-export-screenshots-error' : '' ?>"<?= isset($errors['export_screenshot_mode']) ? ' aria-invalid="true"' : '' ?>>
                <?php if (!in_array($exportScreenshotMode, ['basis', 'latest', 'all', 'none'], true)): ?><option value="<?= $escape($exportScreenshotMode) ?>" selected><?= $escape($t('Ungültige Auswahl')) ?></option><?php endif; ?>
                <?php foreach (['latest' => 'Neuester gespeicherter Bildbeleg', 'basis' => 'Beleg meiner Bewertung', 'all' => 'Alle gespeicherten Bildbelege', 'none' => 'Keine Bilddateien'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $exportScreenshotMode === $value ? ' selected' : '' ?>><?= $escape($t($label)) ?></option><?php endforeach; ?>
              </select>
              <p class="studio-settings-field-hint" id="settings-export-screenshots-hint"><?= $escape($t('Startwert nur für ZIP-Pakete. Der neueste Screenshot ist nicht automatisch der Beleg deiner Bewertung.')) ?></p>
              <?php if (isset($errors['export_screenshot_mode'])): ?><p class="studio-settings-field-error" id="settings-export-screenshots-error"><?= $escape($errors['export_screenshot_mode']) ?></p><?php endif; ?>
              </fieldset>

              <details class="studio-shortcut-editor"<?= isset($errors['shortcut_bindings']) ? ' open' : '' ?>>
                <summary><?= $escape($t('Tastenkürzel konfigurieren')) ?></summary>
                <p class="studio-settings-field-hint"><?= $escape($t('Bildbewertung und Seitenwechsel. Leer lassen deaktiviert ein Kürzel. Seitenwechsel brauchen Ctrl, Alt oder Meta; bekannte Browserkombinationen und AltGr bleiben reserviert.')) ?></p>
                <p class="studio-settings-field-hint"><?= $escape($t('Beispiel: Alt+Shift+B. Die bisherige Reviewbelegung bleibt der Standard; Seitenwechsel sind zunächst unbelegt.')) ?></p>
                <?php if (isset($errors['shortcut_bindings'])): ?><p class="studio-settings-field-error" id="settings-shortcut-error" role="alert"><?= $escape($errors['shortcut_bindings']) ?></p><?php endif; ?>
                <?php foreach (\App\Service\ShortcutSettings::LABELS as $action => $label): $shortcutId = 'shortcut-'.str_replace('.', '-', $action); ?>
                  <div class="studio-shortcut-row">
                    <label for="<?= $escape($shortcutId) ?>"><?= $escape($t($label)) ?></label>
                    <div><input id="<?= $escape($shortcutId) ?>" name="shortcut_bindings[<?= $escape($action) ?>]" value="<?= $escape($shortcutBindings[$action]) ?>" data-shortcut-default="<?= $escape(\App\Service\ShortcutSettings::DEFAULTS[$action]) ?>" autocomplete="off" spellcheck="false"<?= isset($errors['shortcut_bindings']) ? ' aria-invalid="true" aria-describedby="settings-shortcut-error"' : '' ?>><button type="button" hidden data-shortcut-record="<?= $escape($shortcutId) ?>"><?= $escape($t('Taste aufnehmen')) ?></button></div>
                  </div>
                <?php endforeach; ?>
                <button type="button" hidden data-shortcut-reset><?= $escape($t('Standardbelegung wiederherstellen')) ?></button>
              </details>

              <div class="studio-settings-actions">
                <button type="submit"><?= $escape($t('Einstellungen speichern')) ?></button>
                <a href="/"><?= $escape($t('Zum Eingang')) ?></a>
              </div>
            </form>

          </section>

          <section class="studio-settings-panel" aria-labelledby="settings-cribrum-title">
            <div class="studio-settings-panel-heading">
              <div><h2 id="settings-cribrum-title"><?= $escape($t('Cribrum-Klassifikator')) ?></h2><span class="studio-settings-readonly"><?= $escape($t('Nur lesend')) ?></span></div>
            </div>
            <p class="studio-settings-field-hint"><?= $escape($t('Schwerpunkt von {version} „{release}“: nachvollziehbare Befundhinweise aus gespeicherten Browser-Beobachtungen.', ['version' => \App\AppInfo::VERSION, 'release' => \App\AppInfo::RELEASE_NAME])) ?> <a href="/about#release-notes-title"><?= $escape($t('Neu in {version}', ['version' => \App\AppInfo::VERSION])) ?> →</a></p>
            <p class="studio-settings-field-hint"><?= $escape($t('Lokale, versionierte Klassifikation der gespeicherten Browser-Observations. Vorschläge ersetzen keine manuelle Bewertung; private Pakete liegen unter {directory}.', ['directory' => $cribrum['privateDirectory']])) ?></p>
            <p class="studio-settings-field-hint"><a href="/settings/cribrum/rule-review"><?= $escape($t('Regelprüfliste öffnen')) ?> →</a> <?= $escape($t('Im Review abgewählte Vorschläge mit ihren auslösenden Regeln prüfen.')) ?></p>
            <dl class="studio-health-metrics">
              <div><dt><?= $escape($t('Speicherlimit je Observation')) ?></dt><dd><?= $escape($formatNumber($cribrum['limits']['compressedBytes'] / 1048576, 0)) ?> MiB</dd></div>
              <div><dt><?= $escape($t('Aufbewahrte Ausschnitte bei Überschreitung')) ?></dt><dd><?= $escape($formatNumber($cribrum['limits']['retainedRawBytes'] / 1024, 0)) ?> / <?= $escape($formatNumber($cribrum['limits']['retainedDomBytes'] / 1024, 0)) ?> KiB</dd></div>
            </dl>
            <ul class="studio-health-workers">
              <?php foreach ($cribrum['packs'] as $pack): ?>
              <li data-cribrum-pack="<?= $escape($pack['id']) ?>"><div class="studio-health-worker-identity"><strong><?= $escape($pack['id']) ?></strong><span class="studio-health-worker-seen"><?= $escape($t('Version {version} · {rules} Regeln', ['version' => $pack['version'], 'rules' => $pack['rules']])) ?></span></div></li>
              <?php endforeach; ?>
            </ul>
            <?php foreach ($cribrum['packDiagnostics'] as $diagnostic): ?><p class="studio-settings-field-hint" data-cribrum-diagnostic><?= $escape($diagnostic) ?></p><?php endforeach; ?>
            <p class="studio-settings-field-hint"><?= $escape($t('Neubewertung gespeicherter Observations nach Paketänderungen: php bin/console app:cribrum:reclassify (--dry-run zur Vorschau). Manuelle Claim-Entscheidungen bleiben erhalten.')) ?></p>
          </section>

          <?php require __DIR__.'/restrictions.php'; ?>

        </div>
      </div>
    </main>

    <?php $activeWorkspace = 'settings'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
