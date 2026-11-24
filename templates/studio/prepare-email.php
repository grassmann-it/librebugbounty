<?php
/** @var \App\Entity\Finding $finding */
/** @var array $initialState */
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$de = $locale === 'de';
$copy = $de ? [
    'title'=>'E-Mail vorbereiten', 'back'=>'Zurück zum Fall', 'eyebrow'=>'Meldung vorbereiten',
    'intro'=>'Aus den ausgewählten Erkenntnissen einen Entwurf erstellen und anschließend prüfen.',
    'draft'=>'Ungeprüfter Entwurf', 'draftStatus'=>'Entwurfsstatus', 'setup'=>'Vorbereitung', 'language'=>'Sprache des Entwurfs',
    'contact'=>'Gewählter Meldeweg', 'noContact'=>'Wähle zuerst einen Meldeweg im Fall.',
    'contactEdit'=>'Meldeweg im Fall bearbeiten', 'preview'=>'Angaben prüfen', 'previewTitle'=>'Das wird an OpenAI übermittelt',
    'data'=>'Domain, ausgewählte Erkenntnisse und Sprache. Empfänger und vollständiger PoC werden lokal eingesetzt.',
    'payload'=>'Genaue API-Anfrage ansehen', 'downloadRequest'=>'Anfrage als JSON herunterladen',
    'generate'=>'Mit OpenAI entwerfen', 'manual'=>'Ohne KI beginnen', 'localSource'=>'Lokal', 'review'=>'Betreff, Aussagen, PoC und Empfänger vor der Verwendung prüfen.',
    'empty'=>'Dein Entwurf erscheint hier', 'emptyHelp'=>'Prüfe zuerst die Angaben. Beginne anschließend lokal oder erstelle den Entwurf mit OpenAI.',
    'subject'=>'Betreff', 'body'=>'Nachricht', 'recipient'=>'Empfänger', 'portal'=>'Meldeportal',
    'local'=>'Lokale Änderungen werden nicht automatisch gespeichert. Lade den Text herunter oder lege einen Gmail-Entwurf an.',
    'copy'=>'Nachricht kopieren', 'copySubject'=>'Betreff kopieren', 'download'=>'Entwurf herunterladen',
    'signature'=>'Signatur (optional)', 'signatureHint'=>'Wird nicht an OpenAI übermittelt. Sie wird beim Kopieren, Herunterladen und im Gmail-Entwurf angehängt.',
    'generateAgain'=>'Neuen Entwurf erstellen', 'replace'=>'Vorhandenen Entwurf ersetzen',
    'replaceHelp'=>'Der vorhandene Text bleibt erhalten, bis ein neuer Entwurf erfolgreich erstellt wurde.',
    'notConfigured'=>'OpenAI ist noch nicht eingerichtet. Du kannst ohne KI beginnen oder die API-Anfrage herunterladen.',
    'setupDetails'=>'API-Verbindung einrichten',
    'setupHelp'=>'Hinterlege OPENAI_API_KEY in der lokalen Serverkonfiguration (.env.local). Der Schlüssel gehört zur API-Organisation mit deinem Guthaben und bleibt auf dem Server.',
    'unavailable'=>'Die Meldung kann mit diesem Fallstand noch nicht vorbereitet werden.',
    'noScript'=>'Zum Prüfen der API-Anfrage und Erstellen eines Entwurfs ist JavaScript erforderlich.',
    'previewDone'=>'Angaben bereit. Du kannst die genaue Anfrage prüfen und anschließend den Entwurf erstellen.',
    'previewBusy'=>'Angaben werden geladen …', 'generateBusy'=>'OpenAI erstellt den Entwurf …',
    'generated'=>'Entwurf erstellt. Bitte vor der Verwendung prüfen.', 'copied'=>'Kopiert.',
    'copyFailed'=>'Kopieren ist hier nicht verfügbar. Markiere den Text oder lade den Entwurf herunter.',
    'downloaded'=>'Download vorbereitet.', 'network'=>'Verbindung fehlgeschlagen. Deine Eingaben bleiben erhalten.',
    'networkUnknown'=>'Die Verbindung wurde unterbrochen. Ob OpenAI den Entwurf erstellt hat, ist unbekannt. Deine Eingaben bleiben erhalten; es wurde kein zweiter Aufruf gestartet.',
    'timeout'=>'Die Anfrage hat zu lange gedauert. Das Ergebnis ist unbekannt; es wurde kein zweiter Aufruf gestartet.',
    'invalid'=>'Die Antwort konnte nicht verarbeitet werden. Deine Eingaben bleiben erhalten.',
    'changed'=>'Sprache geändert. Bitte die Angaben erneut prüfen.',
    'stale'=>'Der Fall oder Meldeweg hat sich geändert. Bitte die Angaben erneut prüfen.',
    'leave'=>'Dein Entwurf wurde noch nicht heruntergeladen oder kopiert.',
    'requestFailed'=>'Die Anfrage ist fehlgeschlagen.', 'newPreview'=>'Angaben erneut prüfen',
    'certainty'=>['confirmed'=>'Bestätigt', 'suspected'=>'Verdacht', 'observed'=>'Beobachtet'],
    'findingLabels'=>['Internal information disclosure'=>'Offenlegung interner Informationen', 'SQL error message'=>'SQL-Fehlermeldung', 'SQL injection'=>'SQL-Injection'],
    'gmailTitle'=>'Als Gmail-Entwurf ablegen', 'gmailAccount'=>'Gmail-Konto', 'gmailReceiptAccount'=>'Konto dieses Versuchs',
    'gmailReview'=>'Ich habe Gmail-Konto, Empfänger, Betreff, Nachricht einschließlich PoC und Signatur geprüft.',
    'gmailCreate'=>'Gmail-Entwurf anlegen', 'gmailCreating'=>'Gmail-Entwurf wird angelegt …',
    'gmailHelp'=>'Übernimmt den hier sichtbaren Text und die Signatur in einen Entwurf. Die E-Mail wird nicht versendet.',
    'gmailUnconfigured'=>'Die Gmail-Verbindung ist noch nicht eingerichtet. Kopieren und Herunterladen sind bereits möglich.',
    'gmailPortal'=>'Für diesen Fall ist ein Meldeportal gewählt. Nutze den Text dort oder wähle zuerst einen E-Mail-Meldeweg im Fall.',
    'gmailCheck'=>'Status bei Gmail prüfen', 'gmailCheckLocal'=>'Gespeicherten Status prüfen', 'gmailChecking'=>'Entwurfsstatus wird geprüft …',
    'gmailOpen'=>'Gmail-Entwürfe öffnen', 'gmailDraftId'=>'Entwurfs-ID',
    'gmailUnknown'=>'Der Ausgang dieses Versuchs ist unbekannt. Prüfe den gespeicherten Status, bevor du fortfährst. Es wird kein weiterer Entwurf angelegt.',
    'gmailStale'=>'Der Fall oder Meldeweg hat sich inzwischen geändert. Der Gmail-Entwurf kann bereits existieren; prüfe seine Angaben in Gmail.',
    'gmailLocked'=>'Die Fassung dieses Versuchs bleibt erhalten. Einen vorhandenen Gmail-Entwurf kannst du direkt in Gmail bearbeiten.',
    'gmailNoAttempt'=>'Es wurde kein Entwurfsversuch gespeichert. Prüfe den Text erneut, bevor du einen neuen Versuch startest.',
    'gmailRejectedSafe'=>'Es wurde kein Gmail-Entwurf angelegt. Prüfe die Verbindung und den Text erneut. Danach kannst du einen neuen Versuch starten.', 'gmailRetry'=>'Neuen Gmail-Versuch starten',
    'gmailOtherDraft'=>'Der gespeicherte Gmail-Versuch gehört zu einer anderen Textfassung. Der hier angezeigte lokale Text wurde damit nicht gespeichert. Lade ihn bei Bedarf herunter.',
    'gmailStates'=>['pending'=>'Entwurfsversuch gespeichert; Bestätigung steht aus.', 'uncertain'=>'Der Ausgang ist noch unklar. Bitte den vorhandenen Versuch prüfen.', 'verified'=>'Gmail-Entwurf angelegt und geprüft.', 'verification_failed'=>'Ein Entwurf wurde zurückgemeldet, konnte aber nicht vollständig geprüft werden.', 'rejected'=>'Der Entwurfsversuch wurde abgewiesen. Bitte den vorhandenen Status prüfen.'],
] : [
    'title'=>'Prepare email', 'back'=>'Back to case', 'eyebrow'=>'Prepare a report',
    'intro'=>'Create a draft from the selected findings, then review it.',
    'draft'=>'Unreviewed draft', 'draftStatus'=>'Draft status', 'setup'=>'Preparation', 'language'=>'Draft language',
    'contact'=>'Selected contact route', 'noContact'=>'Choose a contact route in the case first.',
    'contactEdit'=>'Edit contact route in case', 'preview'=>'Review details', 'previewTitle'=>'What will be shared with OpenAI',
    'data'=>'Domain, selected findings and language. The recipient and full PoC are inserted locally.',
    'payload'=>'View exact API request', 'downloadRequest'=>'Download request as JSON',
    'generate'=>'Draft with OpenAI', 'manual'=>'Start without AI', 'localSource'=>'Local', 'review'=>'Check the subject, statements, PoC and recipient before using the draft.',
    'empty'=>'Your draft will appear here', 'emptyHelp'=>'Review the details first. Then start locally or create your draft with OpenAI.',
    'subject'=>'Subject', 'body'=>'Message', 'recipient'=>'Recipient', 'portal'=>'Reporting portal',
    'local'=>'Local edits are not saved automatically. Download the text or create a Gmail draft.',
    'copy'=>'Copy message', 'copySubject'=>'Copy subject', 'download'=>'Download draft',
    'signature'=>'Signature (optional)', 'signatureHint'=>'Not shared with OpenAI. It is appended when you copy, download or create a Gmail draft.',
    'generateAgain'=>'Create another draft', 'replace'=>'Replace existing draft',
    'replaceHelp'=>'The existing text stays intact until a new draft has been created successfully.',
    'notConfigured'=>'OpenAI is not configured yet. You can start without AI or download the API request.',
    'setupDetails'=>'Set up API connection',
    'setupHelp'=>'Set OPENAI_API_KEY in your local server configuration (.env.local). Use a key from the API organization with your credits. It stays on the server.',
    'unavailable'=>'A report cannot be prepared from this case state yet.',
    'noScript'=>'JavaScript is required to review the API request and create a draft.',
    'previewDone'=>'Details ready. Review the exact request, then create your draft.',
    'previewBusy'=>'Loading details …', 'generateBusy'=>'OpenAI is creating your draft …',
    'generated'=>'Draft created. Review it before use.', 'copied'=>'Copied.',
    'copyFailed'=>'Copy is unavailable here. Select the text or download the draft.',
    'downloaded'=>'Download prepared.', 'network'=>'Connection failed. Your input has been kept.',
    'networkUnknown'=>'The connection was interrupted. It is unknown whether OpenAI completed the draft. Your input has been kept; no second request was started.',
    'timeout'=>'The request timed out. Its outcome is unknown; no second request was started.',
    'invalid'=>'The response could not be processed. Your input has been kept.',
    'changed'=>'Language changed. Review the details again.',
    'stale'=>'The case or contact route changed. Review the details again.',
    'leave'=>'Your draft has not been downloaded or copied yet.',
    'requestFailed'=>'The request failed.', 'newPreview'=>'Review details again',
    'certainty'=>['confirmed'=>'Confirmed', 'suspected'=>'Suspected', 'observed'=>'Observed'], 'findingLabels'=>[],
    'gmailTitle'=>'Save as a Gmail draft', 'gmailAccount'=>'Gmail account', 'gmailReceiptAccount'=>'Account for this attempt',
    'gmailReview'=>'I have reviewed the Gmail account, recipient, subject, message including PoC, and signature.',
    'gmailCreate'=>'Create Gmail draft', 'gmailCreating'=>'Creating Gmail draft …',
    'gmailHelp'=>'Copies the visible text and signature into a draft. The email is not sent.',
    'gmailUnconfigured'=>'The Gmail connection is not configured yet. You can already copy or download the text.',
    'gmailPortal'=>'This case uses a reporting portal. Use the text there or select an email contact route in the case first.',
    'gmailCheck'=>'Check status with Gmail', 'gmailCheckLocal'=>'Check saved status', 'gmailChecking'=>'Checking draft status …',
    'gmailOpen'=>'Open Gmail drafts', 'gmailDraftId'=>'Draft ID',
    'gmailUnknown'=>'The outcome of this attempt is unknown. Check the saved status before continuing. No additional draft will be created.',
    'gmailStale'=>'The case or contact route has since changed. The Gmail draft may already exist; review its details in Gmail.',
    'gmailLocked'=>'The text for this attempt is kept intact. You can edit an existing Gmail draft directly in Gmail.',
    'gmailNoAttempt'=>'No draft attempt was saved. Review the text again before starting a new attempt.',
    'gmailRejectedSafe'=>'No Gmail draft was created. Check the connection and review the text again, then you can start a new attempt.', 'gmailRetry'=>'Start a new Gmail attempt',
    'gmailOtherDraft'=>'The saved Gmail attempt belongs to a different version of the text. It did not save the local text shown here. Download your text if you need to keep it.',
    'gmailStates'=>['pending'=>'Draft attempt saved; confirmation is pending.', 'uncertain'=>'The outcome is still uncertain. Check the existing attempt.', 'verified'=>'Gmail draft created and verified.', 'verification_failed'=>'A draft was returned but could not be fully verified.', 'rejected'=>'The draft attempt was rejected. Check its existing status.'],
];
$findingPath = '/findings/'.rawurlencode($finding->getId());
$basePath = $findingPath.'/prepare-email';
$languageReturnPath = $basePath;
$initialState['language'] = in_array($initialState['language'] ?? null, ['de', 'en'], true) ? $initialState['language'] : $locale;
$config = ['basePath'=>$basePath, 'csrfToken'=>$csrfToken, 'initialState'=>$initialState, 'copy'=>$copy];
$contact = $initialState['contact'] ?? [];
?>
<!doctype html>
<html lang="<?= $escape($locale) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="theme-color" content="#121416">
  <title><?= $escape($copy['title']) ?> · <?= $escape($finding->getDomain()->getHostname()) ?></title>
  <?php require __DIR__.'/styles.php'; ?>
  <link rel="stylesheet" href="/css/email-preparation.css?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/css/email-preparation.css'), 0, 16) ?>">
  <script src="/js/email-preparation.js?v=<?= substr(hash_file('sha256', __DIR__.'/../../public/js/email-preparation.js'), 0, 16) ?>" defer></script>
</head>
<body data-studio data-email-preparation>
  <div class="studio-shell email-shell">
    <header class="studio-header">
      <?php require __DIR__.'/brand.php'; ?>
      <div class="studio-header-tools"><?php require __DIR__.'/language.php'; ?></div>
    </header>
    <main class="email-main" id="email-main">
      <a class="email-back" href="<?= $escape($findingPath) ?>">← <?= $escape($copy['back']) ?></a>
      <header class="email-heading">
        <div><p class="studio-eyebrow"><?= $escape($copy['eyebrow']) ?></p><h1><?= $escape($copy['title']) ?></h1><p><?= $escape($copy['intro']) ?></p></div>
        <span class="email-domain"><?= $escape($finding->getDomain()->getHostname()) ?></span>
      </header>
      <noscript><p class="email-notice"><?= $escape($copy['noScript']) ?></p></noscript>
      <p class="email-status" data-email-status role="status" aria-live="polite" aria-atomic="true"></p>
      <div class="email-layout">
        <section class="email-card email-prepare" aria-labelledby="email-prepare-title">
          <h2 id="email-prepare-title"><span class="email-step" aria-hidden="true">1</span> <?= $escape($copy['setup']) ?></h2>
          <div class="email-field"><label for="email-language"><?= $escape($copy['language']) ?></label><select id="email-language" data-email-language><option value="de"<?= $initialState['language'] === 'de' ? ' selected' : '' ?>>Deutsch</option><option value="en"<?= $initialState['language'] === 'en' ? ' selected' : '' ?>>English</option></select></div>
          <div class="email-contact"><h3><?= $escape($copy['contact']) ?></h3><p data-email-contact><?= $escape($contact['destination'] ?? $copy['noContact']) ?></p><a href="<?= $escape($findingPath) ?>#kontakte"><?= $escape($copy['contactEdit']) ?> →</a></div>
          <?php if (!($initialState['eligible'] ?? false)): ?><p class="email-notice" data-email-ineligible><?= $escape($initialState['error'] ?? $copy['unavailable']) ?></p><?php endif; ?>
          <button class="email-button" type="button" data-email-preview<?= !($initialState['eligible'] ?? false) ? ' disabled' : '' ?>><?= $escape($copy['preview']) ?></button>
          <section class="email-preview" data-email-preview-content hidden aria-labelledby="email-preview-title">
            <h3 id="email-preview-title"><?= $escape($copy['previewTitle']) ?></h3>
            <p class="email-hint"><?= $escape($copy['data']) ?></p>
            <ul class="email-facts" data-email-facts></ul>
            <details class="email-payload"><summary><?= $escape($copy['payload']) ?></summary><pre data-email-payload tabindex="0"></pre><button class="email-text-button" type="button" data-email-download-request><?= $escape($copy['downloadRequest']) ?></button></details>
            <p class="email-model" data-email-model><?= $escape($initialState['model'] ?? '') ?></p>
            <div class="email-replace" data-email-replace hidden><label><input type="checkbox" data-email-replace-confirm> <?= $escape($copy['replace']) ?></label><p class="email-hint"><?= $escape($copy['replaceHelp']) ?></p></div>
            <button class="email-button email-primary" type="button" data-email-generate disabled><?= $escape($copy['generate']) ?></button>
            <button class="email-button email-manual" type="button" data-email-manual disabled><?= $escape($copy['manual']) ?></button>
          </section>
          <?php if (!($initialState['configured'] ?? false)): ?>
            <div class="email-notice"><p><?= $escape($copy['notConfigured']) ?></p><details><summary><?= $escape($copy['setupDetails']) ?></summary><p><?= $escape($copy['setupHelp']) ?></p></details></div>
          <?php endif; ?>
        </section>
        <section class="email-card email-draft" aria-labelledby="email-draft-title">
          <div class="email-card-heading"><h2 id="email-draft-title"><span class="email-step" aria-hidden="true">2</span> <span data-email-draft-title><?= $escape($copy['draft']) ?></span></h2><span class="email-pill" data-email-draft-source hidden></span></div>
          <div class="email-empty" data-email-empty><svg viewBox="0 0 48 48" width="48" height="48" fill="none" aria-hidden="true"><rect x="5" y="10" width="38" height="28" rx="4" stroke="currentColor" stroke-width="1.5"/><path d="m6 12 18 14 18-14" stroke="currentColor" stroke-width="1.5"/></svg><h3><?= $escape($copy['empty']) ?></h3><p><?= $escape($copy['emptyHelp']) ?></p></div>
          <div data-email-editor hidden>
            <p class="email-review-note"><?= $escape($copy['review']) ?></p>
            <div class="email-recipient" data-email-recipient-wrap><strong data-email-recipient-label><?= $escape($copy['recipient']) ?></strong><span data-email-recipient></span></div>
            <div class="email-field"><label for="email-subject"><?= $escape($copy['subject']) ?></label><input id="email-subject" data-email-subject type="text" maxlength="300" autocomplete="off"><button class="email-text-button" type="button" data-email-copy-subject><?= $escape($copy['copySubject']) ?></button></div>
            <div class="email-field"><label for="email-body"><?= $escape($copy['body']) ?></label><textarea id="email-body" data-email-body rows="18" autocomplete="off" spellcheck="true"></textarea></div>
            <div class="email-field"><label for="email-signature"><?= $escape($copy['signature']) ?></label><textarea id="email-signature" data-email-signature rows="3" maxlength="4000" autocomplete="off" aria-describedby="email-signature-help"></textarea><p class="email-hint" id="email-signature-help"><?= $escape($copy['signatureHint']) ?></p></div>
            <p class="email-hint"><?= $escape($copy['local']) ?></p>
            <div class="email-editor-actions"><button class="email-button email-primary" type="button" data-email-copy><?= $escape($copy['copy']) ?></button><button class="email-button" type="button" data-email-download><?= $escape($copy['download']) ?></button></div>
          </div>
          <section class="email-gmail" data-email-gmail hidden aria-labelledby="email-gmail-title">
            <h3 id="email-gmail-title"><?= $escape($copy['gmailTitle']) ?></h3>
            <p class="email-gmail-account"><strong><?= $escape($copy['gmailAccount']) ?>:</strong> <span data-email-gmail-account></span></p>
            <p class="email-hint" data-email-gmail-help><?= $escape($copy['gmailHelp']) ?></p>
            <div data-email-gmail-create-controls>
              <label class="email-gmail-review"><input type="checkbox" data-email-gmail-reviewed> <span><?= $escape($copy['gmailReview']) ?></span></label>
              <button class="email-button" type="button" data-email-gmail-create disabled><?= $escape($copy['gmailCreate']) ?></button>
            </div>
            <div class="email-gmail-receipt" data-email-gmail-receipt hidden>
              <p data-email-gmail-state role="status" aria-live="polite" aria-atomic="true"></p>
              <p class="email-gmail-id" data-email-gmail-receipt-account-wrap hidden><strong><?= $escape($copy['gmailReceiptAccount']) ?>:</strong> <span data-email-gmail-receipt-account></span></p>
              <p class="email-gmail-stale" data-email-gmail-stale hidden><?= $escape($copy['gmailStale']) ?></p>
              <p class="email-gmail-id" data-email-gmail-id-wrap hidden><strong><?= $escape($copy['gmailDraftId']) ?>:</strong> <code data-email-gmail-id></code></p>
              <div class="email-gmail-actions"><a class="email-button" data-email-gmail-open target="_blank" rel="noopener noreferrer" hidden><?= $escape($copy['gmailOpen']) ?> ↗</a><button class="email-button" type="button" data-email-gmail-check><?= $escape($copy['gmailCheck']) ?></button></div>
            </div>
          </section>
        </section>
      </div>
    </main>
    <?php $activeWorkspace = 'finding'; require __DIR__.'/navigation.php'; ?>
  </div>
  <script id="email-preparation-config" type="application/json"><?= json_encode($config, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</body>
</html>
