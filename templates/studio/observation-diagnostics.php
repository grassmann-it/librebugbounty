<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $escape($t('Observation-Diagnostik')) ?></title>
<style>body{font:16px system-ui;max-width:1100px;margin:2rem auto;padding:0 1rem;background:#111820;color:#e3eaf2}a{color:#8fcaff}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#1b2734;padding:1rem}table{border-collapse:collapse;width:100%}th,td{text-align:left;vertical-align:top;border-bottom:1px solid #536170;padding:.5rem;overflow-wrap:anywhere}summary{cursor:pointer;margin:1rem 0}</style></head>
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
<h3><?= $escape($t('Gespeicherte Klassifikation')) ?></h3><pre data-stored-classification><?= $escape($json($diagnostic['stored'])) ?></pre>
<h3><?= $escape($t('Aktuelle Packs und Core-Version')) ?></h3><pre data-active-versions><?= $escape($json(['classifierVersion' => $diagnostic['active']['classifierVersion'], 'packs' => $diagnostic['active']['packs']])) ?></pre>
<h3><?= $escape($t('Treffer, Vorschläge und Spans')) ?></h3><pre data-current-classification><?= $escape($json($diagnostic['current'])) ?></pre>
<details><summary><?= $escape($t('Regel-Treffer und Ausschlüsse')) ?></summary>
<table><thead><tr><th>Pack / version / rule</th><th>Outcome</th><th>Exclusion / span / error</th></tr></thead><tbody>
<?php foreach ($diagnostic['rules'] as $rule): ?><tr><td><?= $escape($rule['pack'].' / '.$rule['version'].' / '.$rule['rule']) ?></td><td><?= $escape($rule['outcome'].(!empty($rule['shadow']) ? ' (shadow)' : '')) ?></td><td><pre><?= $escape($json(['exclusion' => $rule['exclusion'] ?? null, 'span' => $rule['span'] ?? null, 'error' => $rule['error'] ?? null])) ?></pre></td></tr><?php endforeach; ?>
</tbody></table></details>
<?php foreach ($diagnostic['warnings'] as $warning): ?><p data-diagnostic-warning><?= $escape($warning) ?></p><?php endforeach; ?>
<h3><?= $escape($t('Fehler')) ?></h3><pre data-diagnostic-errors><?= $escape($json([...$diagnostic['errors'], ...$diagnostic['active']['diagnostics']])) ?></pre>
<?php endif; ?>
</main></body></html>
