<?php
$hunterDomain = strtolower(rtrim($finding->getDomain()->getHostname(), '.'));
$hunterOption = $contactProviderOptions['hunter'];
$hunterDisabled = !$hunterOption['configured'] || !$hunterOption['available'] || $hunterOption['blocked'] || ($hunterOption['reserved'] >= $hunterOption['limit'] && !$hunterOption['cached']);
?>
<details class="studio-detail-fold" data-hunter-discovery-panel>
  <summary><?= $escape($t('Weitere Kontakte mit Hunter.io suchen')) ?></summary>
  <p class="studio-detail-hint"><?= $escape($t('Nur die angezeigte Domain geht an Hunter.io. Keine PoC-URL, Befunde oder Nachrichtentexte. Eine neue Abfrage kann Hunter-Credits verbrauchen und liefert höchstens zehn Vorschläge.')) ?></p>
  <p><?= $escape($t('Abgefragte Domain')) ?>: <code><?= $escape($hunterDomain) ?></code></p>
  <p class="studio-detail-hint"><?= $escape($t('Lokales Limit: {used} von {limit} Abfragen reserviert. Gültige Ergebnisse werden sieben Tage wiederverwendet; das Limit wird nicht automatisch zurückgesetzt.', ['used' => $hunterOption['reserved'], 'limit' => $hunterOption['limit']])) ?></p>
  <?php if (!$hunterOption['configured']): ?><p><?= $escape($t('Hunter.io ist noch nicht auf dem Server konfiguriert.')) ?> <a href="/settings/ai"><?= $escape($t('KI-Verwaltung')) ?></a></p>
  <?php elseif (!$hunterOption['available']): ?><p><?= $escape($t('Bitte zuerst die Datenbankmigration für Hunter-Abfragen ausführen.')) ?></p>
  <?php elseif ($hunterOption['blocked']): ?><p><?= $escape($t('Eine Hunter-Abfrage für diese Domain läuft oder hat einen unklaren Ausgang. Keine weitere Abfrage gestartet.')) ?></p>
  <?php elseif ($hunterOption['cached']): ?><p><?= $escape($t('Ein Hunter-Ergebnis ist lokal gespeichert. Solange es gültig ist, wird es ohne Anbieterabfrage genutzt. Nach Ablauf kann ein Klick erneut Credits verbrauchen.')) ?></p>
  <?php elseif ($hunterDisabled): ?><p><?= $escape($t('Das lokale Hunter-Limit von vier reservierten Abfragen für diese Domain ist erreicht.')) ?></p><?php endif; ?>
  <form method="post" action="<?= $escape($findingPath) ?>/contact-discovery" data-hunter-discovery>
    <?= $csrfField('finding_contact_discovery_'.$finding->getId()) ?><?= $returnField ?>
    <input type="hidden" name="provider" value="hunter"><input type="hidden" name="expected_domain" value="<?= $escape($hunterDomain) ?>">
    <label><input type="checkbox" name="consent" value="1" required<?= $hunterDisabled ? ' disabled' : '' ?>> <?= $escape($t('Ich bestätige diese Domain und den möglichen Creditverbrauch.')) ?></label>
    <button class="studio-detail-button" type="submit"<?= $hunterDisabled ? ' disabled' : '' ?>><?= $escape($t('Kontakte suchen: {provider}', ['provider' => 'Hunter.io'])) ?></button>
  </form>
  <p class="studio-detail-hint"><?= $escape($t('Ein Hunter-Treffer bestätigt keine Security-Zuständigkeit. Vorschläge erst prüfen und dann ausdrücklich als Meldeweg wählen.')) ?></p>
</details>
