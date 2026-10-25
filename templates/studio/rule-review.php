<?php
$pageUrl = static fn (int $number, ?string $filter = null): string => '/settings/cribrum/rule-review?'.http_build_query(['status' => $filter ?? $status, 'page' => $number]);
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title><?= $escape($t('Regelprüfliste')) ?> · <?= $escape(\App\AppInfo::NAME) ?></title>
  <?php require __DIR__.'/styles.php'; ?>
  <link rel="stylesheet" href="/css/studio-rule-review.css?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/css/studio-rule-review.css'), 0, 16) ?>">
</head>
<body data-studio data-rule-review>
  <div class="studio-shell rule-review-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div>
    </header>
    <main class="rule-review-main">
      <header class="rule-review-heading">
        <div><p class="studio-eyebrow">Cribrum</p><h1><?= $escape($t('Regelprüfliste')) ?></h1></div>
        <a href="/review"><?= $escape($t('Zurück zum Review')) ?> →</a>
      </header>
      <p class="rule-review-intro"><?= $escape($t('Beim Review abgewählte Vorschläge sind hier zur Prüfung vorgemerkt. Öffne den Fall und prüfe den gespeicherten Beleg zur auslösenden Regel.')) ?></p>
      <p class="rule-review-intro"><?= $escape($t('Der Regelhinweis ist noch ungeprüft. Regeln bleiben aktiv; eine erledigte Prüfung ändert weder Regeln noch Fallbewertungen.')) ?></p>
      <?php if ($error !== null): ?><p role="alert" class="rule-review-error"><?= $escape($error) ?></p><?php endif; ?>
      <?php if (!$available): ?><p role="alert"><?= $escape($t('Die Regelprüfliste benötigt das aktuelle Datenbankschema. Bitte das lokale Upgrade abschließen.')) ?></p><?php else: ?>
      <nav class="rule-review-tabs" aria-label="<?= $escape($t('Prüfstatus')) ?>">
        <?php foreach (['pending' => 'Zur Prüfung', 'reviewed' => 'Geprüft'] as $value => $label): ?>
        <a href="<?= $escape($pageUrl(1, $value)) ?>"<?= $status === $value ? ' aria-current="page"' : '' ?>><?= $escape($t($label)) ?></a>
        <?php endforeach; ?>
      </nav>
      <p class="rule-review-count"><?= $escape($t('{count} Regelhinweise', ['count' => $result['total']])) ?></p>
      <?php if ($result['rows'] === []): ?><p class="rule-review-empty"><?= $escape($t('Keine Regelhinweise in dieser Auswahl.')) ?></p><?php endif; ?>
      <div class="rule-review-list">
      <?php foreach ($result['rows'] as $row):
        $casePath = '/findings/'.rawurlencode($row['finding_id']).'?'.http_build_query(['return_to' => $pageUrl($page)]);
        $claimCode = preg_replace('/^(?:claim|component|observation):/', '', $row['claim']);
        $claimLabel = \App\Value\CribrumBadgeCatalog::entry($claimCode, $locale)['label'];
      ?>
        <article class="rule-review-card" data-rule-review-id="<?= $escape($row['id']) ?>">
          <header><div><span class="rule-review-claim"><?= $escape($claimLabel) ?></span><h2><?= $escape($row['rule_id'] ?: $t('Regelzuordnung offen')) ?></h2></div><span class="rule-review-status"><?= $escape($t($status === 'pending' ? 'Ungeprüfter Hinweis' : 'Geprüft')) ?></span></header>
          <a class="rule-review-case" href="<?= $escape($casePath) ?>"><?= $escape($row['poc_url'] ?: $row['url']) ?></a>
          <dl>
            <div><dt><?= $escape($t('Klassifikator')) ?></dt><dd><?= $escape($row['classifier_version']) ?></dd></div>
            <div><dt><?= $escape($t('Regelpaket')) ?></dt><dd><?= $escape($row['pack_version']) ?></dd></div>
            <div><dt><?= $escape($t('Beobachtung')) ?></dt><dd><?= $escape($row['observation_id']) ?></dd></div>
            <div><dt><?= $escape($t('Vorgemerkt')) ?></dt><dd><?= $escape($row['created_at']) ?></dd></div>
          </dl>
          <a class="rule-review-evidence" href="<?= $escape($casePath) ?>"><?= $escape($t('Fall und gespeicherte Belege öffnen')) ?> →</a>
          <?php if ($status === 'pending'): ?>
          <form method="post" action="/settings/cribrum/rule-review/<?= $escape($row['id']) ?>">
            <input type="hidden" name="_token" value="<?= $escape($this->csrf->getToken('cribrum_rule_review_'.$row['id'])->getValue()) ?>">
            <input type="hidden" name="revision" value="<?= $escape($row['revision']) ?>">
            <label for="rule-note-<?= $escape($row['id']) ?>"><?= $escape($t('Prüfnotiz (optional)')) ?></label>
            <textarea id="rule-note-<?= $escape($row['id']) ?>" name="note" rows="2" maxlength="2000"></textarea>
            <button type="submit"><?= $escape($t('Prüfung als erledigt markieren')) ?></button>
          </form>
          <?php elseif (is_string($row['note'] ?? null) && $row['note'] !== ''): ?><p class="rule-review-note"><?= nl2br($escape($row['note'])) ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
      </div>
      <nav class="rule-review-pagination" aria-label="<?= $escape($t('Seitennavigation')) ?>">
        <?php if ($page > 1): ?><a href="<?= $escape($pageUrl($page - 1)) ?>">← <?= $escape($t('Zurück')) ?></a><?php endif; ?>
        <span><?= $escape($t('Seite {page}', ['page' => $page])) ?></span>
        <?php if ($page * 25 < $result['total']): ?><a href="<?= $escape($pageUrl($page + 1)) ?>"><?= $escape($t('Weiter')) ?> →</a><?php endif; ?>
      </nav>
      <?php endif; ?>
    </main>
    <?php $activeWorkspace = 'settings'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="studio-i18n" type="application/json"><?= $i18nJson ?></script>
</body>
</html>
