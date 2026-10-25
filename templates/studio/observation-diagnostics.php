<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $escape($t('Observation-Diagnostik')) ?></title>
<style>body{font:16px system-ui;max-width:1100px;margin:2rem auto;padding:0 1rem;background:#111820;color:#e3eaf2}a{color:#8fcaff}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#1b2734;padding:1rem}table{border-collapse:collapse;width:100%}th,td{text-align:left;vertical-align:top;border-bottom:1px solid #536170;padding:.5rem;overflow-wrap:anywhere}summary{cursor:pointer;margin:1rem 0}.diagnostic-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,15rem),1fr));gap:.8rem;margin:1rem 0}.diagnostic-summary article{border:1px solid #536170;border-radius:.5rem;background:#1b2734;padding:.8rem}.diagnostic-summary h3{margin:.1rem 0 .5rem;font-size:1rem}.diagnostic-summary p{margin:.3rem 0}.diagnostic-note{border-left:3px solid #8fcaff;padding:.5rem .8rem;background:#1b2734}.diagnostic-raw{border-top:1px solid #536170;margin-top:1.3rem;padding-top:.5rem}.diagnostic-shadow{color:#ffdb8d}</style></head>
<body><main>
<a href="/findings/<?= $escape(rawurlencode($id)) ?>"><?= $escape($t('Zurück zum Fall')) ?></a>
<h1><?= $escape($t('Observation-Diagnostik')) ?></h1>
<p><?= $escape($t('Nur lokale Artefakte und gespeicherter Aufnahmekontext. Die Diagnose ändert keine Claims oder gespeicherten Ergebnisse.')) ?></p>
<nav aria-label="<?= $escape($t('Technische Beobachtungen')) ?>"><ul>
<?php foreach ($observations as $item): ?><li><a href="?observation=<?= $escape(rawurlencode($item->getId())) ?>"><?= $escape($item->getCreatedAt()->format('Y-m-d H:i:s').' · '.$item->getId()) ?></a></li><?php endforeach; ?>
</ul></nav>
<?php if ($diagnostic === null): ?><p><?= $escape($t('Noch keine technische Beobachtung aufgezeichnet.')) ?></p><?php else: ?>
<h2><?= $escape($diagnostic['id']) ?></h2>
<p>PoC revision <?= $escape($diagnostic['pocRevision']) ?> · raw <?= $escape($diagnostic['rawBytes']) ?> bytes · DOM <?= $escape($diagnostic['domBytes']) ?> bytes · truncated: <?= $diagnostic['truncated'] ? 'yes' : 'no' ?></p>
<?php
$stored = is_array($diagnostic['stored']) ? $diagnostic['stored'] : null;
$current = is_array($diagnostic['current']) ? $diagnostic['current'] : null;
$ruleCount = count($diagnostic['rules']);
$shadowCount = count(array_filter($diagnostic['rules'], static fn (array $rule): bool => !empty($rule['shadow'])));
?>
<p class="diagnostic-note"><?= $escape($t('Diese Ansicht wertet gespeicherte lokale Artefakte aus. Sie startet keinen neuen Besuch der Zielseite und ändert keine Claims.')) ?></p>
<div class="diagnostic-summary" aria-label="<?= $escape($t('Diagnose-Überblick')) ?>">
  <article><h3><?= $escape($t('Gespeicherte Klassifikation')) ?></h3><p><?= $stored === null ? $escape($t('Keine gespeicherte Klassifikation verfügbar.')) : $escape(count($stored['suggestions'] ?? []).' '.$t('Vorschläge')) ?></p><?php if ($stored !== null): ?><p><?= $escape(count($stored['signals'] ?? []).' '.$t('Signale')) ?></p><?php endif; ?></article>
  <article><h3><?= $escape($t('Aktuelle lokale Auswertung')) ?></h3><p><?= $current === null ? $escape($t('Nicht verfügbar; gespeichertes Ergebnis bleibt erhalten.')) : $escape(count($current['suggestions'] ?? []).' '.$t('Vorschläge').' · '.count($current['signals'] ?? []).' '.$t('Signale')) ?></p></article>
  <article><h3><?= $escape($t('Regeln und Shadow-Auswertung')) ?></h3><p><?= $escape($ruleCount.' '.$t('Regel-Einträge')) ?> · <span class="diagnostic-shadow"><?= $escape($shadowCount.' '.$t('Shadow-Einträge')) ?></span></p></article>
</div>
<section class="diagnostic-raw" aria-label="<?= $escape($t('Rohdaten und technische Details')) ?>">
<h3><?= $escape($t('Gespeicherte Klassifikation')) ?></h3><pre data-stored-classification><?= $escape($json($diagnostic['stored'])) ?></pre>
<h3><?= $escape($t('Aktuelle Packs und Core-Version')) ?></h3><pre data-active-versions><?= $escape($json(['classifierVersion' => $diagnostic['active']['classifierVersion'], 'packs' => $diagnostic['active']['packs']])) ?></pre>
<h3><?= $escape($t('Treffer, Vorschläge und Spans')) ?></h3><pre data-current-classification><?= $escape($json($diagnostic['current'])) ?></pre>
<details><summary><?= $escape($t('Regel-Treffer und Ausschlüsse')) ?></summary>
<table><thead><tr><th>Pack / version / rule</th><th>Outcome</th><th>Exclusion / span / error</th></tr></thead><tbody>
<?php foreach ($diagnostic['rules'] as $rule): ?><tr><td><?= $escape($rule['pack'].' / '.$rule['version'].' / '.$rule['rule']) ?></td><td><?= $escape($rule['outcome'].(!empty($rule['shadow']) ? ' (shadow)' : '')) ?></td><td><pre><?= $escape($json(['exclusion' => $rule['exclusion'] ?? null, 'span' => $rule['span'] ?? null, 'error' => $rule['error'] ?? null])) ?></pre></td></tr><?php endforeach; ?>
</tbody></table></details>
<?php foreach ($diagnostic['warnings'] as $warning): ?><p data-diagnostic-warning><?= $escape($warning) ?></p><?php endforeach; ?>
<h3><?= $escape($t('Fehler')) ?></h3><pre data-diagnostic-errors><?= $escape($json([...$diagnostic['errors'], ...$diagnostic['active']['diagnostics']])) ?></pre>
</section>
<?php endif; ?>
</main></body></html>
