<?php
/** @var array<string, mixed> $health */
/** @var string $utilityTitle */
/** @var bool $utilityWarning */
?>
<section class="studio-settings-panel studio-settings-health" id="health" aria-labelledby="settings-health-title" data-utility-content="status" data-utility-title="<?= $escape($utilityTitle) ?>" data-utility-warning="<?= $utilityWarning ? 'true' : 'false' ?>">
  <div class="studio-settings-panel-heading">
    <div><h2 id="settings-health-title"><?= $escape($t('Status & Gesundheit')) ?></h2></div>
    <a class="studio-health-refresh" href="/status"><?= $escape($t('Status aktualisieren')) ?></a>
  </div>
  <div class="studio-health-overview" data-service-warning="<?= $utilityWarning ? 'true' : 'false' ?>">
    <span class="studio-health-overview-icon" aria-hidden="true"><?= $utilityWarning ? '!' : '✓' ?></span>
    <div>
      <strong><?= $escape($t($utilityWarning ? 'Dienststatus prüfen' : 'Erwartete HTTP-Dienste und Worker-Signale erreichbar')) ?></strong>
      <p><?= $escape($t('Momentaufnahme, kein Live-Monitoring')) ?> · <?= $escape($t('Stand: {date}', ['date' => $formatTime($health['checkedAt'], true)])) ?></p>
    </div>
  </div>
  <p class="studio-health-boundary"><?= $escape($t('Warteschlangen, Worker-Signale und interaktive Verfügbarkeit sind getrennte Zustände. Eine leere Warteschlange bestätigt keinen gesunden Worker.')) ?></p>
  <div class="studio-settings-health-grid">
    <?php foreach (['recheck' => 'Recheck-Worker', 'screenshot' => 'Screenshot-Worker'] as $kind => $label):
        $group = $health[$kind];
        $workerSummary = $t('{active}/{expected} Worker aktiv', ['active' => $group['activeWorkers'], 'expected' => $group['expectedWorkers']]);
        $browserSummary = $t('{active}/{expected} Browserdienste erreichbar', ['active' => $group['reachableBrowsers'], 'expected' => $group['expectedBrowsers']]);
    ?>
    <article class="studio-settings-health-card" data-health-kind="<?= $escape($kind) ?>" data-health-state="<?= $escape($group['state']) ?>">
      <header>
        <span class="studio-settings-health-dot" data-state="<?= $escape($group['state']) ?>" aria-hidden="true"></span>
        <h3><?= $escape($t($label)) ?></h3>
        <span class="studio-health-group-state" data-state="<?= $escape($group['state']) ?>"><?= $escape($t(['ok' => 'Betriebssignale aktuell', 'degraded' => 'Eingeschränkt', 'stale' => 'Signale veraltet', 'unknown' => 'Keine Worker-Signale'][$group['state']])) ?></span>
      </header>
      <div class="studio-health-readiness">
        <div>
          <p class="studio-settings-health-state" data-health-summary><?= $escape($workerSummary) ?></p>
          <meter min="0" max="<?= max(1, $group['expectedWorkers']) ?>" value="<?= $group['activeWorkers'] ?>" aria-label="<?= $escape($workerSummary) ?>" data-health-meter="workers" data-state="<?= $group['activeWorkers'] === $group['expectedWorkers'] && $group['expectedWorkers'] > 0 ? 'ok' : $escape($group['state'] === 'ok' ? 'unknown' : $group['state']) ?>"></meter>
        </div>
        <div>
          <p class="studio-settings-health-state" data-health-browser-summary><?= $escape($browserSummary) ?></p>
          <meter min="0" max="<?= max(1, $group['expectedBrowsers']) ?>" value="<?= $group['reachableBrowsers'] ?>" aria-label="<?= $escape($browserSummary) ?>" data-health-meter="browsers" data-state="<?= $group['reachableBrowsers'] === $group['expectedBrowsers'] && $group['expectedBrowsers'] > 0 ? 'ok' : 'degraded' ?>"></meter>
        </div>
      </div>
      <section class="studio-health-work" aria-labelledby="health-work-<?= $escape($kind) ?>">
        <h4 id="health-work-<?= $escape($kind) ?>"><?= $escape($t('Warteschlange und Arbeit')) ?></h4>
        <dl class="studio-health-metrics">
          <?php if ($kind === 'recheck'): ?>
          <div data-tone="<?= $group['dueNow'] > 0 ? 'work' : 'neutral' ?>"><dt><?= $escape($t('Fällig jetzt')) ?></dt><dd><?= $escape($formatNumber($group['dueNow'])) ?></dd></div>
          <div><dt><?= $escape($t('Geplant')) ?></dt><dd><?= $escape($formatNumber($group['scheduled'])) ?></dd></div>
          <div><dt><?= $escape($t('Pausiert für Review')) ?></dt><dd><?= $escape($formatNumber($group['pausedManual'])) ?></dd></div>
          <?php else: ?>
          <div data-tone="<?= $group['queued'] > 0 ? 'work' : 'neutral' ?>"><dt><?= $escape($t('Wartend')) ?></dt><dd><?= $escape($formatNumber($group['queued'])) ?></dd></div>
          <div data-tone="<?= $group['running'] > 0 ? 'work' : 'neutral' ?>"><dt><?= $escape($t('In Arbeit')) ?></dt><dd><?= $escape($formatNumber($group['running'])) ?></dd></div>
          <?php endif; ?>
        </dl>
      </section>
      <dl class="studio-health-current-errors" data-has-errors="<?= $group['errorCases'] > 0 ? 'true' : 'false' ?>">
        <div>
          <dt><?= $escape($t($kind === 'recheck' ? 'Fälle mit technischem Fehler' : 'Fälle mit Aufnahmefehler')) ?><span><?= $escape($t('Aktueller Fallstatus, nicht Dienststatus')) ?></span></dt>
          <dd><a href="/errors?kind=<?= $kind === 'recheck' ? 'technical' : 'screenshot' ?>" data-health-errors="<?= $kind === 'recheck' ? 'technical' : 'screenshot' ?>" aria-label="<?= $escape($formatNumber($group['errorCases'])) ?> <?= $escape($t($kind === 'recheck' ? 'Fälle mit technischem Fehler' : 'Fälle mit Aufnahmefehler')) ?> · <?= $escape($t('Fehlerübersicht öffnen')) ?>"><?= $escape($formatNumber($group['errorCases'])) ?></a></dd>
        </div>
      </dl>
      <section class="studio-health-context" aria-labelledby="health-context-<?= $escape($kind) ?>">
        <h4 id="health-context-<?= $escape($kind) ?>"><?= $escape($t($kind === 'recheck' ? 'Zeitplan' : 'Auftragshistorie')) ?></h4>
        <dl>
          <?php if ($kind === 'recheck'): ?>
          <div><dt><?= $escape($t('Nächster Termin')) ?></dt><dd><?= $group['nextDueAt'] === null ? $escape($t('Kein Termin')) : $escape($formatTime($group['nextDueAt'])) ?></dd></div>
          <div><dt><?= $escape($t('Intervall')) ?></dt><dd><?= $escape($t('{days} Tage', ['days' => $group['intervalDays']])) ?></dd></div>
          <?php else: ?>
          <div><dt><?= $escape($t('Abgeschlossene Aufnahmen')) ?></dt><dd><?= $escape($formatNumber($group['available'])) ?></dd></div>
          <div><dt><?= $escape($t('Fehlgeschlagene Aufträge (Historie)')) ?></dt><dd><?= $escape($formatNumber($group['failed'])) ?></dd></div>
          <?php endif; ?>
        </dl>
      </section>
      <section class="studio-health-fleet" aria-labelledby="health-fleet-<?= $escape($kind) ?>">
        <h4 id="health-fleet-<?= $escape($kind) ?>"><?= $escape($t('Worker-Signale')) ?></h4>
        <ul class="studio-health-workers" aria-label="<?= $escape($t($label)) ?>">
          <?php foreach ($group['workers'] as $worker): ?>
          <li data-health-worker="<?= $escape($worker['id']) ?>" data-worker-state="<?= $escape($worker['state']) ?>" data-browser-state="<?= $worker['browserReachable'] ? 'ok' : 'unavailable' ?>">
            <div class="studio-health-worker-identity">
              <strong><?= $escape($worker['id']) ?></strong>
              <span class="studio-health-worker-seen"><?= $worker['lastSeen'] === null ? $escape($t('Zeitpunkt unbekannt')) : $escape($t('Letztes Signal: {date}', ['date' => $formatTime($worker['lastSeen'], true)])) ?></span>
            </div>
            <div class="studio-health-worker-signals">
              <span class="studio-health-worker-state" data-state="<?= $escape($worker['state']) ?>"><span aria-hidden="true"><?= ['ok' => '✓', 'stale' => '!', 'unknown' => '–'][$worker['state']] ?></span> <?= $escape($t(['ok' => 'Signal aktuell', 'stale' => 'Signal veraltet', 'unknown' => 'Noch kein Signal'][$worker['state']])) ?></span>
              <span class="studio-health-browser" data-state="<?= $worker['browserReachable'] ? 'ok' : 'unavailable' ?>"><span aria-hidden="true"><?= $worker['browserReachable'] ? '✓' : '!' ?></span> <?= $escape($t($worker['browserReachable'] ? 'Browserdienst erreichbar' : 'Browserdienst nicht erreichbar')) ?></span>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($group['workers'] === []): ?><p class="studio-settings-field-hint"><?= $escape($t('Keine Worker konfiguriert.')) ?></p><?php endif; ?>
      </section>
    </article>
    <?php endforeach; ?>
    <?php $interactive = $health['interactive']; ?>
    <article class="studio-settings-health-card" data-health-kind="interactive" data-health-state="<?= $escape($interactive['state']) ?>">
      <header>
        <span class="studio-settings-health-dot" data-state="<?= $escape($interactive['state']) ?>" aria-hidden="true"></span>
        <h3><?= $escape($t('Interaktiver Browser')) ?></h3>
        <span class="studio-health-group-state" data-state="<?= $escape($interactive['state']) ?>"><?= $escape($t($interactive['state'] === 'ok' ? 'Dienste erreichbar' : 'Dienststatus prüfen')) ?></span>
      </header>
      <section class="studio-health-fleet" aria-labelledby="health-interactive-services">
        <h4 id="health-interactive-services"><?= $escape($t('Technische Erreichbarkeit')) ?></h4>
        <ul class="studio-health-workers">
          <?php foreach (['worker' => 'Interaktiver Worker (HTTP)', 'gateway' => 'Gateway-Steuerung (HTTP)'] as $service => $label):
              $reachable = $interactive[$service.'Reachable'];
          ?>
          <li data-health-interactive-service="<?= $escape($service) ?>" data-service-state="<?= $reachable ? 'ok' : 'unavailable' ?>">
            <div class="studio-health-worker-identity"><strong><?= $escape($t($label)) ?></strong></div>
            <div class="studio-health-worker-signals"><span class="studio-health-browser" data-state="<?= $reachable ? 'ok' : 'unavailable' ?>"><?= $escape($t($service === 'gateway' && !$interactive['gatewayConfigured'] ? 'Zugang nicht konfiguriert' : ($reachable ? 'Erreichbar' : 'Nicht erreichbar'))) ?></span></div>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <section class="studio-health-context" aria-labelledby="health-interactive-availability">
        <h4 id="health-interactive-availability"><?= $escape($t('Gespeicherte Belegung')) ?></h4>
        <dl>
          <div><dt><?= $escape($t('Interaktiver Arbeitsplatz')) ?></dt><dd data-health-interactive-availability="<?= $escape($interactive['availability']) ?>"><?= $escape($t(['available' => 'Frei', 'busy' => 'Belegt', 'expired' => 'Sitzung abgelaufen', 'unknown' => 'Unbekannt'][$interactive['availability']])) ?></dd></div>
          <?php if ($interactive['expiresAt'] !== null): ?>
          <div><dt><?= $escape($t('Sitzungsende')) ?></dt><dd><?= $escape($formatTime($interactive['expiresAt'], true)) ?></dd></div>
          <?php endif; ?>
        </dl>
        <p class="studio-settings-field-hint"><?= $escape($t('Belegung aus dem gespeicherten Sitzungsstand; unabhängig von der Diensterreichbarkeit.')) ?></p>
      </section>
      <p class="studio-settings-field-hint"><?= $escape($t('HTTP-Antworten bestätigen die Dienste. Chromium, Bildschirmübertragung und WSS-Verbindung werden hier nicht geprüft.')) ?></p>
    </article>
    <article class="studio-settings-health-card" data-cribrum-status data-health-state="<?= $cribrum['available'] && $cribrum['packDiagnostics'] === [] && ($cribrum['observations']['attention'] ?? 0) === 0 ? 'ok' : 'degraded' ?>">
      <header>
        <span class="studio-settings-health-dot" data-state="<?= $cribrum['available'] && $cribrum['packDiagnostics'] === [] && ($cribrum['observations']['attention'] ?? 0) === 0 ? 'ok' : 'degraded' ?>" aria-hidden="true"></span>
        <h3>Cribrum</h3>
        <span class="studio-health-group-state"><?= $escape($t($cribrum['available'] ? 'Klassifikation aktiv' : 'Noch nicht eingerichtet')) ?></span>
      </header>
      <section class="studio-health-context" aria-labelledby="health-cribrum-packs">
        <h4 id="health-cribrum-packs"><?= $escape($t('Aktive Regelpakete')) ?></h4>
        <ul class="studio-health-workers">
          <?php foreach ($cribrum['packs'] as $pack): ?>
          <li data-cribrum-pack="<?= $escape($pack['id']) ?>"><div class="studio-health-worker-identity"><strong><?= $escape($pack['id']) ?></strong><span class="studio-health-worker-seen"><?= $escape($t('Version {version} · {rules} Regeln', ['version' => $pack['version'], 'rules' => $pack['rules']])) ?></span></div></li>
          <?php endforeach; ?>
        </ul>
        <?php foreach ($cribrum['packDiagnostics'] as $diagnostic): ?><p class="studio-settings-field-hint" data-cribrum-diagnostic><?= $escape($diagnostic) ?></p><?php endforeach; ?>
      </section>
      <?php if ($cribrum['observations'] !== null): ?>
      <dl class="studio-health-metrics">
        <div><dt><?= $escape($t('Gespeicherte Observations')) ?></dt><dd data-cribrum-observations="<?= $escape($cribrum['observations']['total']) ?>"><?= $escape($formatNumber($cribrum['observations']['total'])) ?></dd></div>
        <div><dt><?= $escape($t('Davon mit Artefakt')) ?></dt><dd><?= $escape($formatNumber($cribrum['observations']['withArtifact'])) ?></dd></div>
        <div data-tone="<?= $cribrum['observations']['attention'] > 0 ? 'work' : 'neutral' ?>"><dt><?= $escape($t('Klassifikation mit Fehler oder veralteter PoC')) ?></dt><dd data-cribrum-attention="<?= $escape($cribrum['observations']['attention']) ?>"><?= $escape($formatNumber($cribrum['observations']['attention'])) ?></dd></div>
        <?php if ($cribrum['observations']['lastClassifiedAt'] !== null): ?><div><dt><?= $escape($t('Letzte Klassifikation')) ?></dt><dd><?= $escape($formatTime($cribrum['observations']['lastClassifiedAt'], true)) ?></dd></div><?php endif; ?>
      </dl>
      <?php endif; ?>
    </article>
  </div>
  <div class="studio-health-explainer">
    <p><?= $escape($t('Grün bezieht sich auf aktuelle Worker-Signale und HTTP-Antworten der erwarteten Dienste. Chromium, Bildschirmübertragung und WSS sind damit nicht bestätigt. Die Statusabfrage startet keine Browseraufnahme.')) ?></p>
    <p><?= $escape($t('Signale gelten für Rechecks 10 Minuten und für Screenshots 5 Minuten. Auftragszahlen enthalten die Historie; abgeschlossene Aufnahmen bestätigen nicht die Lesbarkeit der Bilddateien.')) ?></p>
    <nav aria-label="<?= $escape($t('Fehlerdiagnose')) ?>"><a href="/errors"><?= $escape($t('Fehlerübersicht öffnen')) ?> <span aria-hidden="true">→</span></a><a href="/errors?kind=missing" data-health-errors="missing"><?= $escape($t('Gespeicherte Bilddateien prüfen')) ?> <span aria-hidden="true">→</span></a></nav>
  </div>
</section>
