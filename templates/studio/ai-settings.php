<?php
/** @var array $profiles */
/** @var ?array $selectedProfile */
/** @var array $formValues */
/** @var array $errors */
/** @var array $connection */
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$de = $locale === 'de';
$copy = $de ? [
    'title'=>'KI-Verwaltung', 'back'=>'Zurück zu den Einstellungen', 'eyebrow'=>'Deine Schreibumgebung',
    'intro'=>'Schreibstil und Beispielmails für die E-Mail-Vorbereitung verwalten. Änderungen bleiben in dieser Installation.',
    'profiles'=>'Schreibprofile', 'profilesHelp'=>'Wähle ein Profil zum Ansehen oder Bearbeiten. Im E-Mail-Editor kannst du für jeden Entwurf ein anderes wählen.',
    'new'=>'Neues Profil', 'edit'=>'Profil bearbeiten', 'builtin'=>'Integriertes Profil', 'default'=>'Standard', 'archived'=>'Archiviert', 'archivedList'=>'Archivierte Profile',
    'version'=>'Version', 'examplesCount'=>'Beispielmails', 'exampleCount'=>'Beispielmail', 'name'=>'Profilname', 'nameHelp'=>'Ein kurzer Name, der im E-Mail-Editor wiedererkennbar ist.',
    'instructions'=>'Eigene Anweisungen', 'instructionsHelp'=>'Beschreibe Ton, Länge, Anrede und Aufbau für Betreff, Einleitung und Schluss. Die fachlichen Absätze und der Original-PoC bleiben fest vorgegeben. Eine Signatur ergänzt du im E-Mail-Editor.',
    'instructionsPlaceholder'=>'Zum Beispiel: Kurz und sachlich schreiben, ohne dramatische Formulierungen. Mit einer freundlichen Bitte um Rückmeldung abschließen.',
    'examples'=>'Beispielmails', 'examplesHelp'=>'Bis zu drei Texte als Stilvorlage. Namen, Adressen und sonstige private Angaben werden nicht automatisch entfernt. Nutze nur Text, den du beim Generieren an den Anbieter übermitteln möchtest.',
    'example'=>'Beispiel', 'optional'=>'optional', 'exampleName'=>'Bezeichnung', 'exampleText'=>'Beispieltext',
    'exampleHelp'=>'Der vollständige eingegebene Text wird als Stilbeispiel verwendet. Er ist kein Beleg für einen aktuellen Fall.',
    'scope'=>'Speichern macht keinen KI-Aufruf. Wenn du dieses Profil im E-Mail-Editor verwendest und mit OpenAI generierst, werden seine Anweisungen und alle ausgefüllten Beispiele übermittelt. Die genaue Anfrage ist dort vorher sichtbar.',
    'create'=>'Profil anlegen', 'save'=>'Neue Version speichern', 'saveHelp'=>'Vorherige Versionen bleiben erhalten. Geänderte Profile erfordern eine neue Vorschau im E-Mail-Editor.',
    'setDefault'=>'Als Standard wählen', 'archive'=>'Profil archivieren', 'archiveHelp'=>'Archivieren entfernt das Profil aus der Auswahl für neue Entwürfe. Die bisherigen Versionen bleiben gespeichert.', 'archiveDefault'=>'Wähle vor dem Archivieren ein anderes Standardprofil.',
    'builtinHelp'=>'Dieses integrierte Profil ist unveränderlich. Es nutzt die festen Schreibregeln der Anwendung und enthält keine persönlichen Beispiele. Lege für deinen eigenen Stil ein neues Profil an.',
    'archivedHelp'=>'Dieses Profil ist archiviert und kann für neue Entwürfe nicht gewählt werden. Sein letzter Stand bleibt hier lesbar.',
    'retainedTitle'=>'Nicht gespeicherte Änderungen', 'archivedConflict'=>'Das Profil wurde inzwischen archiviert. Deine eingegebenen Texte bleiben hier zum Kopieren erhalten; diese Fassung kann nicht mehr in das archivierte Profil gespeichert werden.',
    'error'=>'Das Profil wurde nicht gespeichert.', 'errorHelp'=>'Prüfe die Hinweise. Deine eingegebenen Texte bleiben erhalten.',
    'connection'=>'Verbindung für E-Mail-Entwürfe', 'provider'=>'Anbieter', 'model'=>'Modell', 'reasoning'=>'Reasoning', 'configured'=>'API-Schlüssel eingerichtet', 'unconfigured'=>'API-Schlüssel fehlt',
    'connectionHelp'=>'Die Verbindung wird auf dem Server eingerichtet. Hier werden keine Zugangsdaten angezeigt oder im Browser gespeichert. Ohne API-Schlüssel kannst du im E-Mail-Editor weiterhin ohne KI beginnen.',
    'connectionDocs'=>'Einrichtung und Datenumfang nachlesen', 'noExamples'=>'Keine Beispielmails hinterlegt.', 'emptyInstructions'=>'Keine zusätzlichen Anweisungen.',
    'hunter'=>'Kontaktsuche · Hunter.io', 'hunterHelp'=>'Eine Suche startest du ausdrücklich im Fall. Dabei werden Domain und mögliche Kontingentkosten angezeigt. Vorschläge ersetzen keinen von dir gewählten Meldeweg.', 'hunterSetup'=>'Die Verbindung nutzt HUNTER_API_KEY aus der lokalen Serverkonfiguration. Das Öffnen dieser Seite startet keine Suche.',
    'history'=>'Versionsverlauf', 'historyHelp'=>'Die letzten höchstens 20 Versionen, nur lesend. Ältere Versionen bleiben gespeichert. Die Inhalte hier ändern weder dein Profil noch den aktuellen Entwurf.', 'historyCurrent'=>'Aktuelle Version', 'savedAt'=>'Gespeichert',
] : [
    'title'=>'AI management', 'back'=>'Back to settings', 'eyebrow'=>'Your writing workspace',
    'intro'=>'Manage writing instructions and example emails for email preparation. Changes stay in this installation.',
    'profiles'=>'Writing profiles', 'profilesHelp'=>'Choose a profile to view or edit. You can select a different one for each draft in the email editor.',
    'new'=>'New profile', 'edit'=>'Edit profile', 'builtin'=>'Built-in profile', 'default'=>'Default', 'archived'=>'Archived', 'archivedList'=>'Archived profiles',
    'version'=>'Version', 'examplesCount'=>'example emails', 'exampleCount'=>'example email', 'name'=>'Profile name', 'nameHelp'=>'A short name you can recognize in the email editor.',
    'instructions'=>'Your instructions', 'instructionsHelp'=>'Describe tone, length, greeting and structure for the subject, opening and closing. Technical paragraphs and the original PoC remain fixed. Add a signature in the email editor.',
    'instructionsPlaceholder'=>'For example: Keep the message brief and factual, without dramatic language. End with a friendly request for a response.',
    'examples'=>'Example emails', 'examplesHelp'=>'Up to three texts as style references. Names, addresses and other private details are not removed automatically. Only use text you intend to share with the provider when generating.',
    'example'=>'Example', 'optional'=>'optional', 'exampleName'=>'Label', 'exampleText'=>'Example text',
    'exampleHelp'=>'The complete text you enter is used as a style reference. It is not evidence for a current case.',
    'scope'=>'Saving makes no AI request. When you use this profile in the email editor and generate with OpenAI, its instructions and all filled examples are shared. You can inspect the exact request there first.',
    'create'=>'Create profile', 'save'=>'Save new version', 'saveHelp'=>'Previous versions are kept. Changed profiles require a new preview in the email editor.',
    'setDefault'=>'Set as default', 'archive'=>'Archive profile', 'archiveHelp'=>'Archiving removes the profile from selection for new drafts. Previous versions remain stored.', 'archiveDefault'=>'Choose a different default profile before archiving this one.',
    'builtinHelp'=>'This built-in profile cannot be changed. It uses the application’s fixed writing rules and contains no personal examples. Create a new profile for your own style.',
    'archivedHelp'=>'This profile is archived and cannot be selected for new drafts. Its last version remains readable here.',
    'retainedTitle'=>'Unsaved changes', 'archivedConflict'=>'This profile was archived in the meantime. The text you entered remains available here to copy; it can no longer be saved into the archived profile.',
    'error'=>'The profile was not saved.', 'errorHelp'=>'Review the messages. The text you entered has been kept.',
    'connection'=>'Connection for email drafts', 'provider'=>'Provider', 'model'=>'Model', 'reasoning'=>'Reasoning', 'configured'=>'API key configured', 'unconfigured'=>'API key missing',
    'connectionHelp'=>'The connection is configured on the server. Credentials are not shown or stored in the browser here. Without an API key, you can still start without AI in the email editor.',
    'connectionDocs'=>'Read setup and data-sharing details', 'noExamples'=>'No example emails saved.', 'emptyInstructions'=>'No additional instructions.',
    'hunter'=>'Contact lookup · Hunter.io', 'hunterHelp'=>'Start a search explicitly from a case, where the domain and possible credit usage are shown. Suggestions do not replace your selected contact route.', 'hunterSetup'=>'The connection uses HUNTER_API_KEY from the local server configuration. Opening this page does not start a search.',
    'history'=>'Revision history', 'historyHelp'=>'Up to the 20 most recent versions, read-only. Older versions remain stored. Content shown here does not change your profile or current draft.', 'historyCurrent'=>'Current version', 'savedAt'=>'Saved',
];
$profiles = is_array($profiles ?? null) ? $profiles : [];
$selectedProfile = is_array($selectedProfile ?? null) ? $selectedProfile : null;
$formValues = is_array($formValues ?? null) ? $formValues : [];
$errors = is_array($errors ?? null) ? $errors : [];
$profileId = (string) ($formValues['profileId'] ?? $selectedProfile['id'] ?? '');
$revision = (string) ($formValues['revision'] ?? $selectedProfile['revision'] ?? '0');
$name = (string) ($formValues['name'] ?? $selectedProfile['name'] ?? '');
$instructions = (string) ($formValues['instructions'] ?? $selectedProfile['instructions'] ?? '');
$examples = $selectedProfile['examples'] ?? [];
$exampleNames = $formValues['example_names'] ?? array_column($examples, 'name');
$exampleTexts = $formValues['example_texts'] ?? array_column($examples, 'text');
$isBuiltin = $profileId === 'builtin';
$isArchived = (bool) ($selectedProfile['archived'] ?? false);
$retainedEdit = $retainedEdit ?? ($errors !== [] && (array_key_exists('name', $formValues) || array_key_exists('instructions', $formValues) || array_key_exists('example_texts', $formValues)));
$editable = !$isBuiltin && (!$isArchived || $retainedEdit);
$isDefault = (bool) ($selectedProfile['isDefault'] ?? false);
$profileHistory = is_array($profileHistory ?? null) ? $profileHistory : [];
$activeProfiles = array_filter($profiles, static fn (array $profile): bool => !($profile['archived'] ?? false));
$archivedProfiles = array_filter($profiles, static fn (array $profile): bool => (bool) ($profile['archived'] ?? false));
$languageReturnPath = $profileId !== '' ? '/settings/ai?profile='.rawurlencode($profileId) : '/settings/ai?new=1';
$fieldError = static function (string $key) use ($errors): ?string { return isset($errors[$key]) && is_string($errors[$key]) ? $errors[$key] : null; };
$errorAttributes = static function (string $key, string $helpId) use ($fieldError, $escape): string { return ' aria-describedby="'.$escape($helpId.($fieldError($key) !== null ? ' ai-'.$key.'-error' : '')).'"'.($fieldError($key) !== null ? ' aria-invalid="true"' : ''); };
$renderProfile = static function (array $profile) use ($escape, $copy, $profileId): void {
    $id = (string) $profile['id'];
    ?>
    <li><a href="/settings/ai?profile=<?= $escape(rawurlencode($id)) ?>"<?= $id === $profileId ? ' aria-current="page"' : '' ?>>
      <strong><?= $escape($id === 'builtin' ? $copy['builtin'] : $profile['name']) ?></strong>
      <span class="ai-profile-meta"><span><?= $escape($copy['version']) ?> <?= $escape($profile['revision']) ?></span><span><?= $escape($profile['exampleCount'] ?? 0) ?> <?= $escape(($profile['exampleCount'] ?? 0) === 1 ? $copy['exampleCount'] : $copy['examplesCount']) ?></span>
      <?php if ($profile['isDefault'] ?? false): ?><span class="ai-pill"><?= $escape($copy['default']) ?></span><?php endif; ?>
      <?php if ($profile['archived'] ?? false): ?><span class="ai-pill"><?= $escape($copy['archived']) ?></span><?php endif; ?></span>
    </a></li>
    <?php
};
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($copy['title']) ?> · <?= $escape(\App\AppInfo::NAME) ?></title>
  <?php require __DIR__.'/styles.php'; ?>
  <link rel="stylesheet" href="/css/ai-settings.css?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/css/ai-settings.css'), 0, 16) ?>">
  <script src="/js/ai-settings.js?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/js/ai-settings.js'), 0, 16) ?>" defer></script>
</head>
<body data-studio data-ai-settings>
  <div class="studio-shell ai-shell">
    <header class="studio-header"><?php require __DIR__.'/brand.php'; ?><div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div></header>
    <main class="ai-main"><div class="ai-content">
      <a class="ai-back" href="/settings">← <?= $escape($copy['back']) ?></a>
      <header class="ai-heading"><div><p class="studio-eyebrow"><?= $escape($copy['eyebrow']) ?></p><h1><?= $escape($copy['title']) ?></h1><p><?= $escape($copy['intro']) ?></p></div></header>
      <?php if (is_string($message ?? null) && $message !== ''): ?><p class="ai-notice" data-tone="success" role="status"><?= $escape($message) ?></p><?php endif; ?>
      <?php if ($errors !== []): ?><div class="ai-notice" data-tone="error" role="alert" tabindex="-1" data-ai-errors><strong><?= $escape($copy['error']) ?></strong><p><?= $escape($copy['errorHelp']) ?></p><ul><?php foreach ($errors as $error): if (is_string($error)): ?><li><?= $escape($error) ?></li><?php endif; endforeach; ?></ul></div><?php endif; ?>
      <div class="ai-layout">
        <aside class="ai-card ai-profiles" aria-labelledby="ai-profiles-title"><h2 id="ai-profiles-title"><?= $escape($copy['profiles']) ?></h2><p class="ai-hint"><?= $escape($copy['profilesHelp']) ?></p>
          <ul class="ai-profile-list"><?php foreach ($activeProfiles as $profile) $renderProfile($profile); ?></ul>
          <a class="ai-button<?= $profileId === '' ? ' ai-primary' : '' ?>" href="/settings/ai?new=1"<?= $profileId === '' ? ' aria-current="page"' : '' ?>>+ <?= $escape($copy['new']) ?></a>
          <?php if ($archivedProfiles !== []): ?><details class="ai-secondary-actions"<?= $isArchived ? ' open' : '' ?>><summary><?= $escape($copy['archivedList']) ?> (<?= count($archivedProfiles) ?>)</summary><ul class="ai-profile-list"><?php foreach ($archivedProfiles as $profile) $renderProfile($profile); ?></ul></details><?php endif; ?>
        </aside>
        <div>
          <section class="ai-card" aria-labelledby="ai-profile-title">
            <header class="ai-editor-heading"><h2 id="ai-profile-title"><?= $escape($isBuiltin ? $copy['builtin'] : ($profileId === '' ? $copy['new'] : ($isArchived ? ($retainedEdit ? $copy['retainedTitle'] : $name) : $copy['edit']))) ?></h2><?php if ($profileId !== ''): ?><span class="ai-pill"><?= $escape($copy['version']) ?> <?= $escape($revision) ?></span><?php endif; ?></header>
            <?php if ($editable): ?>
              <?php if ($isArchived): ?><p class="ai-notice"><?= $escape($copy['archivedConflict']) ?></p><?php endif; ?>
              <p class="ai-hint"><?= $escape($copy['scope']) ?></p>
              <form action="/settings/ai" method="post" data-ai-profile-form data-unsaved="<?= $retainedEdit ? 'true' : 'false' ?>">
                <input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="<?= $profileId === '' ? 'create' : 'update' ?>"><input type="hidden" name="profileId" value="<?= $escape($profileId) ?>"><input type="hidden" name="revision" value="<?= $escape($revision) ?>">
                <div class="ai-field"><label for="ai-name"><?= $escape($copy['name']) ?></label><input id="ai-name" name="name" value="<?= $escape($name) ?>" maxlength="80" required autocomplete="off"<?= $errorAttributes('name', 'ai-name-help') ?>><p class="ai-hint" id="ai-name-help"><?= $escape($copy['nameHelp']) ?></p><?php if ($fieldError('name') !== null): ?><p class="ai-field-error" id="ai-name-error"><?= $escape($fieldError('name')) ?></p><?php endif; ?></div>
                <div class="ai-field"><label for="ai-instructions"><?= $escape($copy['instructions']) ?></label><textarea id="ai-instructions" name="instructions" rows="6" maxlength="6000" placeholder="<?= $escape($copy['instructionsPlaceholder']) ?>"<?= $errorAttributes('instructions', 'ai-instructions-help') ?>><?= $escape($instructions) ?></textarea><p class="ai-hint" id="ai-instructions-help"><?= $escape($copy['instructionsHelp']) ?></p><?php if ($fieldError('instructions') !== null): ?><p class="ai-field-error" id="ai-instructions-error"><?= $escape($fieldError('instructions')) ?></p><?php endif; ?></div>
                <section class="ai-examples" aria-labelledby="ai-examples-title"><h3 id="ai-examples-title"><?= $escape($copy['examples']) ?></h3><p class="ai-hint" id="ai-examples-help"><?= $escape($copy['examplesHelp']) ?></p>
                  <?php for ($index = 0; $index < 3; ++$index): $exampleName = (string) ($exampleNames[$index] ?? ''); $exampleText = (string) ($exampleTexts[$index] ?? ''); ?>
                    <details class="ai-example"<?= $exampleName !== '' || $exampleText !== '' || isset($errors['examples']) ? ' open' : '' ?>>
                      <summary><?= $escape($copy['example']) ?> <?= $index + 1 ?><?= $exampleName !== '' ? ' · '.$escape($exampleName) : ' · '.$escape($copy['optional']) ?></summary>
                      <div class="ai-field"><label for="ai-example-name-<?= $index ?>"><?= $escape($copy['exampleName']) ?></label><input id="ai-example-name-<?= $index ?>" name="example_names[]" value="<?= $escape($exampleName) ?>" maxlength="80" autocomplete="off"></div>
                      <div class="ai-field"><label for="ai-example-text-<?= $index ?>"><?= $escape($copy['exampleText']) ?></label><textarea id="ai-example-text-<?= $index ?>" name="example_texts[]" rows="7" maxlength="10000" aria-describedby="ai-example-help-<?= $index ?> ai-examples-help"><?= $escape($exampleText) ?></textarea><p class="ai-hint" id="ai-example-help-<?= $index ?>"><?= $escape($copy['exampleHelp']) ?></p></div>
                    </details>
                  <?php endfor; ?>
                  <?php if ($fieldError('examples') !== null): ?><p class="ai-field-error" id="ai-examples-error"><?= $escape($fieldError('examples')) ?></p><?php endif; ?>
                </section>
                <div class="ai-actions"><button class="ai-button ai-primary" type="submit"<?= $isArchived ? ' disabled' : '' ?>><?= $escape($profileId === '' ? $copy['create'] : $copy['save']) ?></button></div><p class="ai-hint ai-connection-note"><?= $escape($copy['saveHelp']) ?></p>
              </form>
            <?php else: ?>
              <p class="ai-hint"><?= $escape($isBuiltin ? $copy['builtinHelp'] : $copy['archivedHelp']) ?></p>
              <?php if ($isBuiltin): ?><p class="ai-notice"><?= $escape($copy['instructionsHelp']) ?></p><?php else: ?>
                <div class="ai-field"><h3><?= $escape($copy['instructions']) ?></h3><div class="ai-readonly-text"><?= $escape($instructions !== '' ? $instructions : $copy['emptyInstructions']) ?></div></div>
                <section class="ai-examples"><h3><?= $escape($copy['examples']) ?></h3><?php if ($examples === []): ?><p class="ai-hint"><?= $escape($copy['noExamples']) ?></p><?php endif; ?>
                  <?php foreach ($examples as $index => $example): ?><details class="ai-example"><summary><?= $escape($example['name'] ?? $copy['example'].' '.($index + 1)) ?></summary><p class="ai-readonly-text"><?= $escape($example['text'] ?? '') ?></p></details><?php endforeach; ?>
                </section>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($profileId !== '' && !$isArchived): ?>
              <div class="ai-secondary-actions"><p class="ai-hint"><?= $escape($isBuiltin ? $copy['profilesHelp'] : ($isDefault ? $copy['archiveDefault'] : $copy['archiveHelp'])) ?></p><div class="ai-actions">
                <?php if (!$isDefault): ?><form action="/settings/ai" method="post"><input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="default"><input type="hidden" name="profileId" value="<?= $escape($profileId) ?>"><input type="hidden" name="revision" value="<?= $escape($selectedProfile['revision'] ?? $revision) ?>"><input type="hidden" name="defaultRevision" value="<?= $escape($defaultRevision ?? 0) ?>"><button class="ai-button" type="submit"><?= $escape($copy['setDefault']) ?></button></form><?php else: ?><span class="ai-pill"><?= $escape($copy['default']) ?></span><?php endif; ?>
                <?php if (!$isBuiltin): ?><form action="/settings/ai" method="post"><input type="hidden" name="_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="profileId" value="<?= $escape($profileId) ?>"><input type="hidden" name="revision" value="<?= $escape($selectedProfile['revision'] ?? $revision) ?>"><button class="ai-button" type="submit"<?= $isDefault ? ' disabled' : '' ?>><?= $escape($copy['archive']) ?></button></form><?php endif; ?>
              </div></div>
            <?php endif; ?>
            <?php if (!$isBuiltin && $profileId !== '' && $profileHistory !== []): ?>
              <details class="ai-history ai-secondary-actions" data-ai-profile-history><summary><?= $escape($copy['history']) ?> (<?= count($profileHistory) ?>)</summary><p class="ai-hint ai-connection-note"><?= $escape($copy['historyHelp']) ?></p>
                <?php foreach ($profileHistory as $historicalProfile):
                    $historicalDate = (string) ($historicalProfile['createdAt'] ?? '');
                    try { $historicalDateLabel = $historicalDate === '' ? '—' : (new \DateTimeImmutable($historicalDate))->format($de ? 'd.m.Y H:i P' : 'Y-m-d H:i P'); }
                    catch (\Exception) { $historicalDateLabel = $historicalDate; }
                ?>
                  <details class="ai-example ai-history-entry" data-ai-history-revision="<?= $escape($historicalProfile['revision']) ?>"><summary><?= $escape($copy['version']) ?> <?= $escape($historicalProfile['revision']) ?> · <?= $escape($historicalProfile['name']) ?></summary>
                    <p class="ai-profile-meta ai-connection-note"><span><?= $escape($copy['savedAt']) ?>: <?php if ($historicalDate !== ''): ?><time datetime="<?= $escape($historicalDate) ?>"><?= $escape($historicalDateLabel) ?></time><?php else: ?>—<?php endif; ?></span><?php if ($historicalProfile['revision'] === ($selectedProfile['revision'] ?? null)): ?><span class="ai-pill"><?= $escape($copy['historyCurrent']) ?></span><?php endif; ?><?php if ($historicalProfile['archived'] ?? false): ?><span class="ai-pill"><?= $escape($copy['archived']) ?></span><?php endif; ?></p>
                    <h3><?= $escape($copy['instructions']) ?></h3><p class="ai-readonly-text"><?= $escape(($historicalProfile['instructions'] ?? '') !== '' ? $historicalProfile['instructions'] : $copy['emptyInstructions']) ?></p>
                    <h3><?= $escape($copy['examples']) ?></h3><?php if (($historicalProfile['examples'] ?? []) === []): ?><p class="ai-hint"><?= $escape($copy['noExamples']) ?></p><?php endif; ?>
                    <?php foreach ($historicalProfile['examples'] ?? [] as $historicalExample): ?><div class="ai-history-example"><strong><?= $escape($historicalExample['name']) ?></strong><p class="ai-readonly-text"><?= $escape($historicalExample['text']) ?></p></div><?php endforeach; ?>
                  </details>
                <?php endforeach; ?>
              </details>
            <?php endif; ?>
          </section>
          <section class="ai-card" aria-labelledby="ai-connection-title"><header class="ai-editor-heading"><h2 id="ai-connection-title"><?= $escape($copy['connection']) ?></h2><span class="ai-pill"><?= $escape(($connection['configured'] ?? false) ? $copy['configured'] : $copy['unconfigured']) ?></span></header>
            <dl class="ai-connection"><div><dt><?= $escape($copy['provider']) ?></dt><dd>OpenAI</dd></div><div><dt><?= $escape($copy['model']) ?></dt><dd><?= $escape($connection['model'] ?? '—') ?></dd></div><div><dt><?= $escape($copy['reasoning']) ?></dt><dd><?= $escape($connection['reasoning'] ?? '—') ?></dd></div></dl>
            <p class="ai-hint"><?= $escape($copy['connectionHelp']) ?></p>
          </section>
          <?php if (isset($hunterConnection) && is_array($hunterConnection)): ?><section class="ai-card" aria-labelledby="ai-hunter-title"><header class="ai-editor-heading"><h2 id="ai-hunter-title"><?= $escape($copy['hunter']) ?></h2><span class="ai-pill"><?= $escape(($hunterConnection['configured'] ?? false) ? $copy['configured'] : $copy['unconfigured']) ?></span></header><p class="ai-hint"><?= $escape($copy['hunterHelp']) ?></p><p class="ai-hint ai-connection-note"><?= $escape($copy['hunterSetup']) ?></p></section><?php endif; ?>
        </div>
      </div>
    </div></main>
    <?php $activeWorkspace = 'settings'; require __DIR__.'/navigation.php'; ?>
  </div>
</body>
</html>
