<?php
/** Shared GET controls; submitting starts at page one. */
$cribrumFilterCopy = $locale === 'de' ? ['title'=>'Cribrum-Erkenntnisse', 'codes'=>'Beobachtungen und Verdachtsmomente', 'extra'=>'Zusatzbefunde', 'new'=>'Neue Erkenntnisse', 'fresh'=>'Aktualität der Auswertung', 'sort'=>'Reihenfolge', 'history'=>'Frühere Erkenntnisse einbeziehen', 'all'=>'Alle', 'yes'=>'Ja', 'no'=>'Nein'] : ['title'=>'Cribrum findings', 'codes'=>'Observations and suspicions', 'extra'=>'Additional findings', 'new'=>'New findings', 'fresh'=>'Evaluation freshness', 'sort'=>'Order', 'history'=>'Include previous findings', 'all'=>'All', 'yes'=>'Yes', 'no'=>'No'];
$cribrumSortLabels = $locale === 'de' ? ['default'=>$filter->view === 'workbasket' ? 'Früheste Termine zuerst' : 'Neueste zuerst', 'additional_first'=>'Zusatzbefunde zuerst', 'cribrum_changed'=>'Zuletzt neue Erkenntnisse', 'additional_count'=>'Anzahl Zusatzbefunde'] : ['default'=>$filter->view === 'workbasket' ? 'Earliest due dates first' : 'Newest first', 'additional_first'=>'Additional findings first', 'cribrum_changed'=>'Most recent new findings', 'additional_count'=>'Number of additional findings'];
$cribrumFreshLabels = $locale === 'de' ? [''=>'Alle', 'current'=>'Aktuell', 'missing'=>'Auswertung fehlt', 'stale'=>'Nicht aktuell'] : [''=>'All', 'current'=>'Current', 'missing'=>'Evaluation missing', 'stale'=>'Out of date'];
$cribrumFiltersActive = ($filter->cribrumCodes ?? []) !== [] || ($filter->extraFindings ?? '') !== '' || ($filter->newFindings ?? '') !== '' || ($filter->cribrumFreshness ?? '') !== '' || ($filter->cribrumHistory ?? false) || ($filter->sort ?? 'default') !== 'default';
?>
<details class="cribrum-filter-disclosure"<?= $cribrumFiltersActive ? ' open' : '' ?> data-cribrum-filter-disclosure>
  <summary><?= $escape($cribrumFilterCopy['title']) ?><?= $cribrumFiltersActive ? ' · '. $escape($locale === 'de' ? 'Filter aktiv' : 'Filters active') : '' ?><span><?= $escape($locale === 'de' ? 'Codes, Aktualität und Reihenfolge' : 'Codes, freshness and order') ?></span></summary>
<fieldset class="cribrum-filters" data-cribrum-filters>
  <legend><?= $escape($cribrumFilterCopy['title']) ?></legend>
  <div class="cribrum-filter-grid">
    <label><?= $escape($cribrumFilterCopy['codes']) ?><select name="cribrum[]" multiple size="4" aria-describedby="cribrum-filter-help">
      <?php foreach (\App\Value\CribrumBadgeCatalog::entries($locale) as $entry): $code = $entry['code']; $label = $entry['label']; ?><option value="<?= $escape($code) ?>"<?= in_array($code, $filter->cribrumCodes ?? [], true) ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?>
    </select></label>
    <?php foreach (['extra_findings'=>['extra', $filter->extraFindings ?? ''], 'new_findings'=>['new', $filter->newFindings ?? '']] as $name => [$copy, $selected]): ?><label><?= $escape($cribrumFilterCopy[$copy]) ?><select name="<?= $escape($name) ?>"><?php foreach ([''=>'all', 'yes'=>'yes', 'no'=>'no'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $selected === $value ? ' selected' : '' ?>><?= $escape($cribrumFilterCopy[$label]) ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
    <label><?= $escape($cribrumFilterCopy['fresh']) ?><select name="cribrum_freshness"><?php foreach ($cribrumFreshLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= ($filter->cribrumFreshness ?? '') === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
    <label><?= $escape($cribrumFilterCopy['sort']) ?><select name="sort"><?php foreach ($cribrumSortLabels as $value => $label): ?><option value="<?= $escape($value) ?>"<?= ($filter->sort ?? 'default') === $value ? ' selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
  </div>
  <label class="cribrum-history-toggle"><input type="checkbox" name="cribrum_history" value="1"<?= ($filter->cribrumHistory ?? false) ? ' checked' : '' ?>> <?= $escape($cribrumFilterCopy['history']) ?></label>
  <p id="cribrum-filter-help" class="cribrum-filter-help"><?= $escape($locale === 'de' ? 'Keine Auswahl: alle Fälle. Mehrere Erkenntnisse: mindestens eine trifft zu. Andere Filter gelten zusätzlich. Aktuelle Erkenntnisse sind der Standard.' : 'No selection: all cases. Multiple findings: at least one matches. Other filters also apply. Current findings are the default.') ?></p>
</fieldset>
</details>
