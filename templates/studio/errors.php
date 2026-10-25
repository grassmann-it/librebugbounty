<?php
/** @var array<string, mixed> $problems */
$labels = ['all' => 'Alle Fehler', 'screenshot' => 'Aufnahmefehler', 'missing' => 'Bilddateien', 'technical' => 'Technische Fehler'];
$listQuery = array_intersect_key($problems, array_flip(['kind', 'q', 'page', 'pageSize']));
$returnPath = '/errors?'.http_build_query($listQuery, '', '&', PHP_QUERY_RFC3986);
$csrfToken = $this->csrf->getToken('missing_image_recovery')->getValue();
$recoveryFields = static function () use ($listQuery, $csrfToken, $escape): void {
    foreach ($listQuery + ['_token' => $csrfToken] as $name => $value) {
        echo '<input type="hidden" name="'.$escape($name).'" value="'.$escape($value).'">';
    }
};
$pageUrl = static fn (int $page): string => '/errors?'.http_build_query(array_replace($listQuery, ['page' => $page]), '', '&', PHP_QUERY_RFC3986);
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title><?= $escape($t('Fehlerübersicht')) ?> · <?= $escape(\App\AppInfo::NAME) ?></title>
  <?php require __DIR__.'/styles.php'; ?>
  <link rel="stylesheet" href="/css/studio-inventory.css">
  <link rel="stylesheet" href="/css/studio-errors.css">
</head>
<body data-studio data-studio-errors>
  <div class="studio-shell studio-list-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div>
    </header>
    <main class="studio-list-main">
      <div class="studio-list-heading"><div><p class="studio-eyebrow"><?= $escape($t('Betriebsübersicht')) ?></p><h1><?= $escape($t('Fehlerübersicht')) ?></h1></div><a class="studio-list-button" href="/settings#health"><?= $escape($t('Status & Gesundheit')) ?></a></div>
      <p class="studio-list-hint"><?= $escape($t('Aufnahme-, Bilddatei- und technische Fehler je Fall, einschließlich Archiv. Fehler sind keine Schwachstellenbewertung; ältere Fehler bleiben im Verlauf.')) ?></p>
      <p class="studio-list-hint"><?= $escape($t('Bilddateien werden beim Öffnen dieser Übersicht auf Lesbarkeit und unterstützte Bildheader geprüft, nicht vollständig dekodiert. Es werden keine Aufträge eingereiht oder Zielseiten aufgerufen.')) ?></p>
      <p class="studio-list-hint"><?= $escape($t('Stand: {date}', ['date' => $formatTime($problems['checkedAt'], true)])) ?></p>
      <p class="studio-list-hint"><?= $escape($t('Ein lesbares Bild zur aktiven PoC erledigt das Bildproblem. Historische Dateien und Bewertungen bleiben erhalten. Zurückgestellte, verworfene oder gesperrte Fälle werden übersprungen.')) ?></p>
      <?php foreach ($request->getSession()->getFlashBag()->get('missing_image_recovery') as $result): ?>
      <p role="status" data-recovery-result><?= $escape($t('Neu eingereiht: {queued}. Bereits aktiv: {active}. Übersprungen: {skipped}. Ein Auftrag ist noch kein wiederhergestelltes Bild.', ['queued' => $formatNumber($result['queued']), 'active' => $formatNumber($result['alreadyActive']), 'skipped' => $formatNumber($result['skipped'])])) ?></p>
      <?php endforeach; ?>
      <section class="studio-list-tools" aria-label="<?= $escape($t('Suche und Filter')) ?>">
        <nav class="studio-list-scopes" aria-label="<?= $escape($t('Fehlerarten')) ?>">
          <?php foreach ($labels as $kind => $label): ?>
          <a href="<?= $escape('/errors?'.http_build_query(['kind' => $kind, 'pageSize' => $problems['pageSize']])) ?>" data-error-filter="<?= $escape($kind) ?>"<?= $problems['kind'] === $kind ? ' aria-current="page"' : '' ?>><?= $escape($t($label)) ?> <span data-error-count="<?= $escape($kind) ?>"><?= $escape($formatNumber($problems['counts'][$kind])) ?></span></a>
          <?php endforeach; ?>
        </nav>
        <p class="studio-list-hint"><?= $escape($t('Zähler: betroffene Fälle insgesamt, unabhängig von der Suche. Ein Fall kann mehrere Fehlerarten haben.')) ?></p>
        <form method="get" action="/errors" class="studio-error-filters">
          <input type="hidden" name="kind" value="<?= $escape($problems['kind']) ?>">
          <label><?= $escape($t('Suche')) ?><input name="q" value="<?= $escape($problems['q']) ?>" placeholder="<?= $escape($t('Domain, URL oder Titel')) ?>"></label>
          <label><?= $escape($t('Zeilen pro Seite')) ?><select name="pageSize"><?php foreach ([10, 25, 50, 100] as $size): ?><option value="<?= $size ?>"<?= $problems['pageSize'] === $size ? ' selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></label>
          <button class="studio-list-button" type="submit"><?= $escape($t('Anwenden')) ?></button>
        </form>
      </section>
      <section class="studio-error-results" aria-label="<?= $escape($t('Betroffene Fälle')) ?>">
        <p class="studio-error-summary" data-error-total><?= $escape($t('{count} betroffene Fälle', ['count' => $formatNumber($problems['total'])])) ?></p>
        <?php if ($problems['missingTotal'] > 0): ?>
        <form method="post" action="/errors/missing-images/recover" data-recovery-filtered>
          <?php $recoveryFields(); ?>
          <button class="studio-list-button" type="submit"><?= $escape($t('Fehlende Bilder der gefilterten Fälle neu aufnehmen')) ?></button>
          <p class="studio-list-hint"><?= $escape($t('Gilt für alle Ergebnisse des aktuellen Filters, über alle Seiten. Die Aufnahme erfolgt über die vorhandene Queue.')) ?></p>
        </form>
        <?php endif; ?>
        <?php if ($problems['rows'] === []): ?><div class="studio-error-empty" data-error-empty><strong><?= $escape($t('Keine betroffenen Fälle')) ?></strong><p><?= $escape($t('Keine betroffenen Fälle für diese Auswahl. Das Öffnen dieser Übersicht startet keine Aufnahme.')) ?></p></div><?php endif; ?>
        <?php foreach ($problems['rows'] as $row): $detailPath = '/findings/'.$row['id'].'?'.http_build_query(['return_to' => $returnPath], '', '&', PHP_QUERY_RFC3986); ?>
        <article class="studio-error-case" data-error-case="<?= $escape($row['id']) ?>">
          <header><a href="<?= $escape($detailPath) ?>" data-error-detail><?= $escape($row['hostname']) ?> · <?= $escape($row['title']) ?></a><span><?= $escape($t(\App\Value\FindingReadLabels::assessment($row['manual_assessment'], null))) ?> · <?= $escape($row['status']) ?></span></header>
          <code><?= $escape($row['url']) ?></code>
          <ul>
            <?php foreach ($row['problems'] as $kind => $problem): ?>
            <li data-problem-kind="<?= $escape($kind) ?>"><strong><?= $escape($t($labels[$kind])) ?></strong> · <?= $escape($kind === 'missing' ? $t('Ablage') : $t('Zeitpunkt')) ?>: <?= $escape($formatTime($problem['at'] !== null ? new \DateTimeImmutable($problem['at']) : null)) ?>
              <?php if ($kind === 'missing'): ?>
              <p><?= $escape($t('Für die aktive PoC liegt kein lesbares Bild vor.')) ?> <?php if ($problem['count'] > 0): ?><?= $escape($t(['missing' => 'Bilddatei fehlt oder ist nicht lesbar.', 'unreadable' => 'Bilddatei ist nicht lesbar.', 'invalid' => 'Bilddatei hat keinen unterstützten Bildheader.', 'too_large' => 'Bilddatei zu groß für diese Prüfung (maximal 25 MiB).'][$problem['reason']])) ?> <?= $escape($t('{count} betroffene Bildbelege', ['count' => $formatNumber($problem['count'])])) ?><?php endif; ?></p>
              <?php if ($problem['evidenceId'] !== null): ?><a href="<?= $escape($detailPath.'#bild-'.$problem['evidenceId']) ?>"><?= $escape($t('Betroffenen Beleg öffnen')) ?></a><?php endif; ?>
              <form method="post" action="/errors/missing-images/recover" data-recovery-one>
                <?php $recoveryFields(); ?>
                <input type="hidden" name="finding" value="<?= $escape($row['id']) ?>">
                <input type="hidden" name="pocUrl" value="<?= $escape($row['url']) ?>">
                <input type="hidden" name="pocRevision" value="<?= $escape($row['poc_revision']) ?>">
                <button class="studio-list-button" type="submit"<?= !$row['recovery_allowed'] || in_array($row['job_status'], ['queued', 'running'], true) ? ' disabled' : '' ?>><?= $escape($t(!$row['recovery_allowed'] ? 'Aufnahme gesperrt oder zurückgestellt.' : (in_array($row['job_status'], ['queued', 'running'], true) ? 'Aufnahme bereits eingereiht.' : 'Fehlendes Bild neu aufnehmen'))) ?></button>
              </form>
              <?php else: ?><p><?= $escape($problem['message'] ?: $t('Keine Fehlermeldung aufgezeichnet.')) ?></p><a href="<?= $escape($detailPath.'#verlauf') ?>"><?= $escape($t('Verlauf öffnen')) ?></a><?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
        </article>
        <?php endforeach; ?>
        <nav class="studio-list-pages" aria-label="<?= $escape($t('Ergebnisseiten')) ?>"><span><?= $escape($t('Seite {page} von {pages}', ['page' => $formatNumber($problems['page']), 'pages' => $formatNumber($problems['pages'])])) ?></span>
          <?php if ($problems['page'] > 1): ?><a class="studio-list-button" href="<?= $escape($pageUrl($problems['page'] - 1)) ?>" data-page="previous"><?= $escape($t('Zurück')) ?></a><?php endif; ?>
          <?php if ($problems['page'] < $problems['pages']): ?><a class="studio-list-button" href="<?= $escape($pageUrl($problems['page'] + 1)) ?>" data-page="next"><?= $escape($t('Weiter')) ?></a><?php endif; ?>
        </nav>
      </section>
    </main>
    <?php $activeWorkspace = 'inventory'; require __DIR__.'/navigation.php'; ?>
  </div>
</body>
</html>
