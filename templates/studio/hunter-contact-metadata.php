<?php $hunter = $contact['hunter']; ?>
<p class="studio-detail-hint"><?= $escape($t('Hunter-Angaben')) ?>: <?= $escape($hunter['type']) ?> · <?= $escape($hunter['provenance']) ?> · <?= $escape($t('Anbieter-Konfidenz')) ?>: <?= $hunter['confidence'] === null ? '—' : (int) $hunter['confidence'].'/100' ?> · <?= $escape($t('Zustellstatus beim Anbieter')) ?>: <?= $escape($hunter['verification']) ?></p>
<?php if ($hunter['department'] !== null || $hunter['position'] !== null): ?><p class="studio-detail-hint"><?= $escape(implode(' · ', array_filter([$hunter['department'], $hunter['position']]))) ?></p><?php endif; ?>
<?php if ($hunter['sources'] !== []): ?>
  <details><summary><?= $escape($t('Hunter-Quellen ansehen')) ?></summary><ul>
    <?php foreach ($hunter['sources'] as $hunterSource): $sourceParts = parse_url($hunterSource['url']); ?>
      <li><?php if (is_array($sourceParts) && in_array($sourceParts['scheme'] ?? '', ['https', 'http'], true) && !isset($sourceParts['user']) && !isset($sourceParts['pass'])): ?><a href="<?= $escape($hunterSource['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($hunterSource['url']) ?></a><?php else: ?><code><?= $escape($hunterSource['url']) ?></code><?php endif; ?><?php if ($hunterSource['last_seen_on'] !== null): ?> · <?= $escape($t('Zuletzt vom Anbieter gesehen')) ?>: <?= $escape($hunterSource['last_seen_on']) ?><?php endif; ?></li>
    <?php endforeach; ?>
  </ul></details>
<?php endif; ?>
