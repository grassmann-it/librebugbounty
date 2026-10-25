<?php // Read-only evidence, history and diagnosis for the shared summary or case inspector. ?>
    <div class="cribrum-proof-panels" data-cribrum-proof-panels>
    <?php foreach ($badgeSummary['badges'] as $badge): ?>
      <details class="cribrum-proof" id="<?= $escape($badge['detailAnchor']) ?>" data-cribrum-proof tabindex="-1">
        <summary><?= $escape(($badge['known'] ? $badge['label'] : ($locale === 'de' ? 'Nicht eingeordneter Hinweis' : 'Unclassified hint')).' · '.\App\Value\CribrumBadgeCatalog::stateLabel($badge['state'], $locale)) ?></summary>
        <p class="cribrum-selection"><?= $escape($badgeCopy[$badge['reportSelected'] ? 'selected' : 'unselected']) ?></p>
        <dl class="studio-record-data">
          <dt><?= $escape($badgeCopy['source']) ?></dt><dd><?= $escape(implode(' · ', array_map(static fn (string $origin): string => $badgeOriginLabels[$origin] ?? $badgeCopy['unknown'], explode(', ', $badge['origin'])))) ?></dd>
          <dt><?= $escape($badgeCopy['observed']) ?></dt><dd><?= $escape($badge['observedAt'] ?? $badgeCopy['unknown']) ?></dd>
          <dt><?= $escape($badgeCopy['recognized']) ?></dt><dd><?= $escape($badge['recognizedAt'] ?? $badgeCopy['unknown']) ?></dd>
        </dl>
        <?php foreach ($badge['evidenceRefs'] as $reference):
          $badgeReferenceId = is_string($reference) ? $reference : ($reference['factId'] ?? $reference['id'] ?? '');
          $badgeReferenceUrl = is_array($reference) && ($reference['kind'] ?? '') === 'http_observation'
              ? '/findings/'.rawurlencode($badgeFindingId).'/diagnostics?'.http_build_query(['observation'=>$badgeReferenceId], '', '&', PHP_QUERY_RFC3986)
              : '#cribrum-evidence-'.$badgeReferenceId;
        ?><a class="cribrum-proof-link" href="<?= $escape($badgeReferenceUrl) ?>"><?= $escape($badgeCopy['proof']) ?></a><?php endforeach; ?>
        <details><summary><?= $escape($badgeCopy['diagnosis']) ?></summary><pre><?= $escape($reportText($badge)) ?></pre></details>
      </details>
    <?php endforeach; ?>
    </div>
    <?php if ($badgeSummary['history'] !== [] || $badgeSummary['shadow'] !== []): ?>
    <details class="cribrum-history"><summary><?= $escape($badgeCopy['history']) ?></summary>
      <?php foreach (['history', 'shadow'] as $historicGroup): ?>
        <?php if ($badgeSummary[$historicGroup] !== []): ?><h3><?= $escape($badgeCopy[$historicGroup]) ?></h3><?php endif; ?>
        <?php foreach ($badgeSummary[$historicGroup] as $historic): ?>
          <p><?= $escape($historic['label'].' · '.\App\Value\CribrumBadgeCatalog::stateLabel($historic['state'], $locale)) ?></p>
          <?php if (str_contains($historic['origin'], 'manual:fact_review')): ?>
          <p class="cribrum-selection"><?= $escape($t('Für diesen Fall verworfen. Der ursprüngliche Beleg bleibt erhalten.')) ?></p>
          <?php foreach ($historic['evidenceRefs'] as $reference):
            $historicId = is_string($reference) ? $reference : ($reference['factId'] ?? $reference['id'] ?? '');
            $historicUrl = is_array($reference) && ($reference['kind'] ?? '') === 'http_observation'
                ? '/findings/'.rawurlencode($badgeFindingId).'/diagnostics?'.http_build_query(['observation' => $historicId], '', '&', PHP_QUERY_RFC3986)
                : '#cribrum-evidence-'.$historicId;
          ?><a class="cribrum-proof-link" href="<?= $escape($historicUrl) ?>"><?= $escape($badgeCopy['proof']) ?></a><?php endforeach; ?>
          <?php endif; ?>
          <details><summary><?= $escape($badgeCopy['diagnosis']) ?></summary><pre><?= $escape($reportText($historic)) ?></pre></details>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </details>
    <?php endif; ?>
    <details class="cribrum-diagnostics"><summary><?= $escape($badgeCopy['diagnosis']) ?></summary>
    <details data-report-proof><summary><?= $escape($locale === 'de' ? 'Belege, Abdeckung und Revisionen' : 'Evidence, coverage and revisions') ?></summary>
      <p><?= $escape($locale === 'de' ? 'Reinheit gilt innerhalb der dokumentierten Klassifikationsabdeckung. OBB-Auswahl bleibt manuell. report-gen unterstützt auch reine XSS-Fälle.' : 'Purity applies within the documented classification coverage. OBB selection remains manual. report-gen also supports pure XSS cases.') ?></p>
      <dl class="studio-record-data">
        <?php foreach (['policyVersion', 'sourceInstanceId', 'basisRevision', 'decisionRevision', 'poc', 'legacyBasis', 'workerProof', 'coverage', 'components', 'factIds', 'reviewedFactExclusions', 'holds', 'reasonCodes', 'wafBlockReview', 'wafBlockFingerprint', 'acknowledgements'] as $field): ?>
          <?php $value = $field === 'components' ? ($summary[$field] ?? null) : ($reportProjection[$field] ?? null); if ($value === null) continue; ?>
          <dt><?= $escape($field) ?></dt><dd><code><?= $escape($reportText($value)) ?></code></dd>
        <?php endforeach; ?>
      </dl>
      <?php foreach ($reportEvidence ?? [] as $fact): ?>
        <details id="cribrum-evidence-<?= $escape($fact['factId'] ?? '') ?>" data-report-fact="<?= $escape($fact['factId'] ?? '') ?>"><summary><?= $escape($fact['code'] ?? 'Evidence') ?></summary>
          <dl class="studio-record-data"><?php foreach (['factId', 'sourceRef', 'capturedAt', 'producer', 'artifactSha256', 'pocRevision', 'pocSha256', 'spans', 'attributes', 'relations', 'relatedFactIds'] as $field): ?>
            <?php if (!array_key_exists($field, $fact)) continue; ?><dt><?= $escape($field) ?></dt><dd><code><?= $escape($reportText($fact[$field])) ?></code></dd>
          <?php endforeach; ?></dl>
        </details>
      <?php endforeach; ?>
    </details>

      <pre><?= $escape($reportText($reportProjection)) ?></pre>
    </details>
