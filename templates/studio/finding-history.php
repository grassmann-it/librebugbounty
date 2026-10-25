<?php
use App\Service\StudioExportProfileService;
use App\Value\FindingReadLabels;
?>
      <section class="studio-technical-section" id="verlauf" aria-label="<?= $escape($t('Technik und Historie')) ?>" tabindex="-1">
        <details class="studio-technical-history" data-studio-history>
          <summary><span><?= $escape($t('Technik & Historie')) ?></span><span class="studio-detail-muted"><?= $escape($t('{count} Bewertungen', ['count' => $formatNumber(count($view->assessments))])) ?><?= ($view->reviewAcknowledgements ?? []) !== [] ? ' · '.$escape($t('{count} Sichtungen', ['count' => $formatNumber(count($view->reviewAcknowledgements))])) : '' ?> · <?= $escape($t('{count} Aufnahmen', ['count' => $formatNumber(count($view->screenshotJobs))])) ?> · <?= $escape($t('{count} Beobachtungen', ['count' => $formatNumber(count($view->runs))])) ?></span></summary>
          <div class="studio-history-sections">
            <p class="studio-history-orientation"><?= $escape($t('Hier stehen gespeicherte Bewertungen, Aufnahmen und Beobachtungen. Die Diagnose wertet vorhandene Artefakte lokal aus; sie startet keinen neuen Zielbesuch.')) ?></p>
            <section aria-labelledby="studio-assessment-history-title">
              <h2 id="studio-assessment-history-title"><?= $escape($t('Bewertungshistorie')) ?></h2>
              <?php if ($view->assessments === []): ?><p class="studio-detail-hint"><?= $escape($t('Noch keine Bewertungsänderung aufgezeichnet.')) ?></p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->assessments as $entry): ?>
                  <?php
                    $snapshot = $entry->getReferenceSnapshot() ?? [];
                    $resetId = $view->cancelledAssessmentIds[$entry->getId()] ?? null;
                    $reset = $resetId !== null ? ($reviewResetById[$resetId] ?? null) : null;
                  ?>
                  <li class="studio-record"<?= $resetId !== null ? ' data-cancelled-assessment="'.$escape($entry->getId()).'"' : '' ?>>
                    <div class="studio-record-heading"><strong><?= $escape($t(FindingReadLabels::assessment($entry->getAssessment(), $entry->getDiscardReason()))) ?></strong><span><?= $escape($formatTime($entry->getAssessedAt())) ?></span></div>
                    <?php if ($reset !== null): ?>
                      <p class="studio-detail-hint"><a href="#assessment-reset-<?= $escape($resetId) ?>"><?= $escape($t('Durch Zurück aufgehoben am {date}', ['date' => $formatTime($reset->getResetAt())])) ?></a> · <?= $escape($t('Nur Historie, keine wirksame Bewertung.')) ?></p>
                    <?php endif; ?>
                    <p class="studio-detail-hint"><?= $escape($t('Herkunft')) ?>: <?= $escape($entry->getSource() === 'manual' ? $t('Manuell') : $entry->getSource()) ?></p>
                    <dl class="studio-record-data">
                      <dt><?= $escape($t('Beobachtungsgrundlage')) ?></dt><dd><?= $entry->getObservationId() !== null ? '<code>'.$escape($entry->getObservationId()).'</code>' : $escape($t('Unbekannt / keine konkrete Beobachtung')) ?>
                        <?php if (isset($snapshot['observation'])): ?><br><?= $escape($storedTime($snapshot['observation']['finishedAt'] ?? $snapshot['observation']['startedAt'] ?? null).' · '.$t(FindingReadLabels::observation($snapshot['observation']['result'] ?? null)).' · '.($snapshot['observation']['mode'] ?? $t('Herkunft unbekannt'))) ?><?php endif; ?>
                      </dd>
                      <dt><?= $escape($t('Beleggrundlage')) ?></dt><dd><?= $entry->getEvidenceId() !== null ? '<code>'.$escape($entry->getEvidenceId()).'</code>' : $escape($t('Unbekannt / kein konkreter Beleg')) ?>
                        <?php if (isset($snapshot['evidence'])): ?><br><?= $escape($t('Ablage')) ?>: <?= $escape($storedTime($snapshot['evidence']['storedAt'] ?? null).' · '.($snapshot['evidence']['kind'] ?? $t('Art unbekannt'))) ?><?php endif; ?>
                      </dd>
                    </dl>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
            <?php if ($view->reviewResets !== []): ?>
              <section aria-labelledby="studio-reset-history-title">
                <h2 id="studio-reset-history-title"><?= $escape($t('Zurückgesetzte Bewertungen')) ?></h2>
                <p class="studio-detail-hint"><?= $escape($t('Zurück im Review setzt den Fall auf unbewertet. Frühere Bewertungen bleiben zur Nachvollziehbarkeit erhalten.')) ?></p>
                <ol class="studio-record-list">
                  <?php foreach ($view->reviewResets as $reset): ?>
                    <?php $previousState = $reset->getPreviousState(); ?>
                    <li class="studio-record" id="assessment-reset-<?= $escape($reset->getId()) ?>" data-review-reset="<?= $escape($reset->getId()) ?>">
                      <div class="studio-record-heading"><strong><?= $escape($t('Auf unbewertet zurückgesetzt')) ?></strong><span><?= $escape($formatTime($reset->getResetAt())) ?></span></div>
                      <p class="studio-detail-hint"><?= $escape($t('Vorher: {assessment}', ['assessment' => $t(FindingReadLabels::assessment($previousState['manual_assessment'] ?? null, $previousState['discard_reason'] ?? null))])) ?></p>
                    </li>
                  <?php endforeach; ?>
                </ol>
              </section>
            <?php endif; ?>
            <?php if (($view->reviewAcknowledgements ?? []) !== []): ?>
              <section aria-labelledby="studio-review-history-title">
                <h2 id="studio-review-history-title"><?= $escape($t('Gesichtete Hinweise')) ?></h2>
                <ol class="studio-record-list">
                  <?php foreach ($view->reviewAcknowledgements as $entry): ?>
                    <?php $snapshot = $entry->getReferenceSnapshot() ?? []; ?>
                    <li class="studio-record" data-review-acknowledgement="<?= $escape($entry->getId()) ?>">
                      <div class="studio-record-heading"><strong><?= $escape($t('Geprüft · Bewertung behalten')) ?></strong><span><?= $escape($formatTime($entry->getReviewedAt())) ?></span></div>
                      <p class="studio-detail-hint"><?= $escape($t('Beibehaltenes Urteil: {assessment}. Das Bewertungsdatum wurde nicht geändert.', ['assessment' => $t(FindingReadLabels::assessment($entry->getAssessment(), null))])) ?></p>
                      <dl class="studio-record-data">
                        <dt><?= $escape($t('Gesichtete Hinweise')) ?></dt><dd><?php foreach ($entry->getTriggeringObservationIds() as $triggerId): ?><code><?= $escape($triggerId) ?></code><br><?php endforeach; ?></dd>
                        <dt><?= $escape($t('Beobachtungsgrundlage')) ?></dt><dd><?= $entry->getObservationId() !== null ? '<code>'.$escape($entry->getObservationId()).'</code>' : $escape($t('Unbekannt / keine konkrete Beobachtung')) ?><?php if (isset($snapshot['observation'])): ?><br><?= $escape($storedTime($snapshot['observation']['finishedAt'] ?? $snapshot['observation']['startedAt'] ?? null).' · '.$t(FindingReadLabels::observation($snapshot['observation']['result'] ?? null)).' · '.($snapshot['observation']['mode'] ?? $t('Herkunft unbekannt'))) ?><?php endif; ?></dd>
                        <dt><?= $escape($t('Beleggrundlage')) ?></dt><dd><?= $entry->getEvidenceId() !== null ? '<code>'.$escape($entry->getEvidenceId()).'</code>' : $escape($t('Unbekannt / kein konkreter Beleg')) ?><?php if (isset($snapshot['evidence'])): ?><br><?= $escape($t('Ablage')) ?>: <?= $escape($storedTime($snapshot['evidence']['storedAt'] ?? null).' · '.($snapshot['evidence']['kind'] ?? $t('Art unbekannt'))) ?><?php endif; ?></dd>
                      </dl>
                    </li>
                  <?php endforeach; ?>
                </ol>
              </section>
            <?php endif; ?>
            <section aria-labelledby="studio-job-history-title">
              <h2 id="studio-job-history-title"><?= $escape($t('Screenshot-Aufträge')) ?></h2>
              <?php if ($view->screenshotJobs === []): ?><p class="studio-detail-hint"><?= $escape($t('Noch kein Auftrag aufgezeichnet.')) ?></p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->screenshotJobs as $job): ?>
                  <?php $metadata = $job->getCaptureMetadata() ?? []; $provenance = StudioExportProfileService::captureProvenance($metadata); ?>
                  <li class="studio-record">
                    <div class="studio-record-heading"><strong class="studio-status" data-tone="<?= $escape($jobTone($job->getStatus())) ?>"><?= $escape($jobLabel($job->getStatus())) ?></strong><code><?= $escape($job->getId()) ?></code></div>
                    <?php if ($provenance !== null): ?><strong data-interactive-capture><?= $escape($t('Interaktive Aufnahme')) ?></strong><?php endif; ?>
                    <dl class="studio-record-data">
                      <dt><?= $escape($t('Angefordert')) ?></dt><dd><?= $escape($formatTime($job->getRequestedAt())) ?></dd>
                      <dt><?= $escape($t('Begonnen')) ?></dt><dd><?= $escape($formatTime($job->getStartedAt())) ?></dd>
                      <dt><?= $escape($t('Aufgenommen')) ?></dt><dd><?= $escape($formatTime($job->getCapturedAt())) ?></dd>
                      <dt><?= $escape($t('Abgeschlossen')) ?></dt><dd><?= $escape($formatTime($job->getFinishedAt())) ?></dd>
                      <dt><?= $escape($t('Versuche')) ?></dt><dd><?= $escape($formatNumber($job->getAttempts())) ?></dd>
                      <?php if ($job->getScreenshotPath() !== null): ?><dt><?= $escape($t('Datei')) ?></dt><dd><code><?= $escape($job->getScreenshotPath()) ?></code></dd><?php endif; ?>
                    </dl>
                    <?php if ($job->getErrorMessage() !== null): ?><p class="studio-record-error"><?= $escape($job->getErrorMessage()) ?></p><?php endif; ?>
                    <?php if ($provenance === null && ($metadata['challengeDetected'] ?? false) === true): ?><p class="studio-challenge-notice"><?= $escape($t('Browser-Schutz erkannt · {state} · {seconds} Sekunden gewartet.', ['state' => $t(($metadata['challengeCleared'] ?? false) === true ? 'beendet' : 'weiterhin aktiv'), 'seconds' => $formatNumber(max(0, (int) ($metadata['challengeWaitedMs'] ?? 0)) / 1000, 1)])) ?></p><?php endif; ?>
                    <?php if ($provenance !== null): ?><?php $renderProvenance($provenance, $job->getStatus() === 'available' && $job->getScreenshotPath() !== null); ?><?php endif; ?>
                    <?php if ($metadata !== []): ?><details class="studio-detail-fold"><summary><?= $escape($t('Aufnahmedetails')) ?></summary><pre><?= $escape(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
            <section aria-labelledby="studio-evidence-history-title">
              <h2 id="studio-evidence-history-title"><?= $escape($t('Alle Belege')) ?></h2>
              <?php if ($view->evidence === []): ?><p class="studio-detail-hint"><?= $escape($t('Noch kein Beleg aufgezeichnet.')) ?></p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->evidence as $item): ?>
                  <li class="studio-record">
                    <div class="studio-record-heading"><strong><?= $escape($item->getKind()) ?></strong><span><?= $escape($t('Ablage')) ?>: <?= $escape($formatTime($item->getCreatedAt())) ?></span></div>
                    <p><code><?= $escape($item->getId()) ?></code></p>
                    <?php if ($item->getValue() !== null): ?><pre><?= $escape($item->getValue()) ?></pre><?php endif; ?>
                    <?php if ($item->getFilePath() !== null): ?><p class="studio-detail-hint"><?= $escape($t('Datei')) ?>: <code><?= $escape($item->getFilePath()) ?></code></p><?php endif; ?>
                    <?php foreach ($view->screenshots as $shot): ?><?php if ($shot['evidence']->getId() === $item->getId() && !$shot['available']): ?><p class="studio-record-error"><?= $escape($t('Datei fehlt oder ist nicht verfügbar; Beleg erhalten.')) ?></p><?php endif; ?><?php endforeach; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
            <section aria-labelledby="studio-observation-history-title">
              <h2 id="studio-observation-history-title"><?= $escape($t('Technische Beobachtungen')) ?></h2>
              <p class="studio-history-diagnostic-link"><a href="/findings/<?= $escape(rawurlencode($finding->getId())) ?>/diagnostics"><?= $escape($t('Observation-Diagnostik')) ?></a></p>
              <?php if ($view->runs === []): ?><p class="studio-detail-hint"><?= $escape($t('Noch keine technische Beobachtung aufgezeichnet.')) ?></p><?php endif; ?>
              <ol class="studio-record-list">
                <?php foreach ($view->runs as $run): ?>
                  <li class="studio-record">
                    <div class="studio-record-heading"><strong><?= $escape($t(FindingReadLabels::observation($run->getResult()))) ?></strong><code><?= $escape($run->getId()) ?></code></div>
                    <dl class="studio-record-data"><dt><?= $escape($t('Modus')) ?></dt><dd><?= $escape($run->getMode()) ?></dd><dt><?= $escape($t('Begonnen')) ?></dt><dd><?= $escape($formatTime($run->getStartedAt())) ?></dd><dt><?= $escape($t('Abgeschlossen')) ?></dt><dd><?= $escape($formatTime($run->getFinishedAt())) ?></dd><dt>HTTP</dt><dd><?= $escape($run->getHttpStatus() !== null ? (string) $run->getHttpStatus() : $t('Nicht aufgezeichnet')) ?></dd></dl>
                    <?php if ($run->getFinalUrl() !== null): ?><p class="studio-detail-hint"><?= $escape($t('Letzte URL')) ?>: <code><?= $escape($run->getFinalUrl()) ?></code></p><?php endif; ?>
                    <?php if ($run->getObservedEvidence() !== null): ?><pre><?= $escape($run->getObservedEvidence()) ?></pre><?php endif; ?>
                    <?php if ($run->getScreenshotPath() !== null): ?><p class="studio-detail-hint"><?= $escape($t('Aufnahmedatei')) ?>: <code><?= $escape($run->getScreenshotPath()) ?></code></p><?php endif; ?>
                    <?php if ($run->getErrorMessage() !== null): ?><p class="studio-record-error"><?= $escape($run->getErrorMessage()) ?></p><?php endif; ?>
                    <?php if ($run->getRawResult() !== null): ?><details class="studio-detail-fold"><summary><?= $escape($t('Laufdetails')) ?></summary><pre><?= $escape(json_encode($run->getRawResult(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
          </div>
        </details>
      </section>
