(() => {
  'use strict';
  const root = document.querySelector('[data-email-preparation]');
  const configElement = document.getElementById('email-preparation-config');
  if (!root || !configElement) return;
  let config;
  try { config = JSON.parse(configElement.textContent); } catch (_) { return; }
  const copy = config.copy;
  const query = (selector) => root.querySelector(`[data-email-${selector}]`);
  const ui = Object.fromEntries(['language', 'preview', 'preview-content', 'payload', 'facts', 'model', 'generate', 'manual', 'draft-source', 'draft-title', 'status', 'contact', 'editor', 'empty', 'subject', 'body', 'signature', 'recipient', 'recipient-label', 'recipient-wrap', 'replace', 'replace-confirm', 'gmail', 'gmail-account', 'gmail-help', 'gmail-create-controls', 'gmail-reviewed', 'gmail-create', 'gmail-receipt', 'gmail-state', 'gmail-stale', 'gmail-id-wrap', 'gmail-id', 'gmail-open', 'gmail-check', 'gmail-receipt-account-wrap', 'gmail-receipt-account'].map((name) => [name, query(name)]));
  let preview = null;
  let busy = false;
  let operation = '';
  let hasDraft = false;
  let dirty = false;
  let copiedSubject = null;
  let copiedBody = null;
  let recipient = '';
  let channel = 'email';
  let draftContext = null;
  let draftSubmission = null;
  const gmail = {configured: false, account: '', canCreate: false, receipt: null, ...config.initialState.gmail};
  let gmailReceipt = gmail.receipt;
  let gmailAttempt = gmailReceipt?.attemptId || null;
  if (gmailReceipt && gmailReceipt.state !== 'rejected') gmail.canCreate = false;
  const gmailLocked = () => Boolean((gmailAttempt || gmailReceipt) && gmail.canCreate !== true);

  function status(message, tone = '') {
    ui.status.textContent = message;
    ui.status.dataset.tone = tone;
  }

  function buttons() {
    const locked = gmailLocked();
    ui.language.disabled = busy;
    ui.preview.disabled = busy || !config.initialState.eligible;
    ui.generate.disabled = busy || locked || !preview || !config.initialState.configured || (hasDraft && !ui['replace-confirm'].checked);
    ui.manual.disabled = busy || locked || !preview?.manualDraft || (hasDraft && !ui['replace-confirm'].checked);
    ui['replace-confirm'].disabled = busy;
    ui.preview.textContent = operation === 'preview' ? copy.previewBusy : (preview ? copy.newPreview : copy.preview);
    ui.generate.textContent = operation === 'generate' ? copy.generateBusy : (hasDraft ? copy.generateAgain : copy.generate);
    ui.subject.readOnly = locked || operation === 'generate';
    ui.body.readOnly = locked || operation === 'generate';
    ui.signature.readOnly = locked;
    ui['gmail-reviewed'].disabled = busy || locked || !hasDraft || !gmail.configured || channel !== 'email';
    ui['gmail-create'].disabled = busy || locked || !hasDraft || !draftContext || !gmail.configured || !gmail.canCreate || channel !== 'email' || !ui['gmail-reviewed'].checked;
    ui['gmail-create'].textContent = operation === 'gmail-create' ? copy.gmailCreating : (gmailReceipt?.state === 'rejected' && gmail.canCreate ? copy.gmailRetry : copy.gmailCreate);
    ui['gmail-check'].disabled = busy || !gmailAttempt;
    ui['gmail-check'].textContent = operation === 'gmail-check' ? copy.gmailChecking : (gmailReceipt ? copy.gmailCheck : copy.gmailCheckLocal);
    root.setAttribute('aria-busy', String(busy));
    renderGmail();
  }

  async function request(action, payload, method = 'POST') {
    const controller = new AbortController();
    const gmailAction = action.startsWith('gmail-draft');
    const timeout = window.setTimeout(() => controller.abort(), action === 'generate' || gmailAction ? 135000 : 20000);
    try {
      const response = await fetch(`${config.basePath}/${action}`, {
        method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        ...(method === 'POST' ? {body: JSON.stringify({_token: config.csrfToken, ...payload})} : {}),
      });
      let data;
      try { data = await response.json(); } catch (_) { throw new Error(copy.invalid); }
      if (!response.ok || data.ok !== true) {
        const error = new Error(typeof data.error === 'string' ? data.error : copy.requestFailed);
        error.stale = response.status === 409;
        error.data = data;
        throw error;
      }
      return data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error(gmailAction ? copy.gmailUnknown : copy.timeout);
      if (error instanceof TypeError) throw new Error(gmailAction ? copy.gmailUnknown : (action === 'generate' ? copy.networkUnknown : copy.network));
      throw error;
    } finally { window.clearTimeout(timeout); }
  }

  function invalidate(message) {
    preview = null;
    ui['preview-content'].hidden = true;
    ui.payload.textContent = '';
    ui.facts.replaceChildren();
    ui['replace-confirm'].checked = false;
    if (message) status(message);
    buttons();
  }

  ui.language.addEventListener('change', () => invalidate(copy.changed));
  ui['replace-confirm'].addEventListener('change', buttons);
  ui.preview.addEventListener('click', async () => {
    if (busy) return;
    invalidate();
    busy = true; operation = 'preview'; buttons(); status(copy.previewBusy);
    try {
      const data = await request('preview', {language: ui.language.value});
      if (typeof data.fingerprint !== 'string' || !data.request || typeof data.request !== 'object') throw new Error(copy.invalid);
      preview = data;
      ui.payload.textContent = JSON.stringify(data.request, null, 2);
      ui.model.textContent = data.model || config.initialState.model;
      ui.contact.textContent = data.contact?.destination || copy.noContact;
      ui['replace'].hidden = !hasDraft;
      const items = [];
      if (typeof data.summary?.host === 'string') items.push(data.summary.host);
      if (Array.isArray(data.summary?.components)) {
        for (const component of data.summary.components) {
          if (typeof component === 'string') items.push(component);
          else if (typeof component?.label === 'string') {
            const label = copy.findingLabels?.[component.label] || component.label;
            const certainty = copy.certainty?.[component.certainty] || component.certainty;
            items.push(label + (certainty ? ` · ${certainty}` : ''));
          }
        }
      }
      for (const text of items) { const li = document.createElement('li'); li.textContent = text; ui.facts.append(li); }
      ui['preview-content'].hidden = false;
      status(copy.previewDone);
    } catch (error) { status(error.message, 'error'); }
    finally { busy = false; operation = ''; buttons(); }
  });

  ui.generate.addEventListener('click', async () => {
    if (busy || gmailLocked() || !preview || !config.initialState.configured || (hasDraft && !ui['replace-confirm'].checked)) return;
    const context = {language: ui.language.value, fingerprint: preview.fingerprint};
    busy = true; operation = 'generate'; buttons(); status(copy.generateBusy);
    // Keep the existing draft intact until generation succeeds.
    const originalValues = [ui.subject.value, ui.body.value];
    ui.subject.readOnly = true;
    ui.body.readOnly = true;
    try {
      const data = await request('generate', context);
      if (typeof data.subject !== 'string' || typeof data.body !== 'string') throw new Error(copy.invalid);
      if (ui.subject.value !== originalValues[0] || ui.body.value !== originalValues[1]) throw new Error(copy.invalid);
      installDraft(data, context, 'OpenAI');
    } catch (error) {
      if (error.stale) invalidate();
      status(error.stale ? copy.stale : error.message, 'error');
    } finally {
      busy = false;
      operation = '';
      buttons();
    }
  });

  function installDraft(data, context, source) {
    if (typeof data.subject !== 'string' || typeof data.body !== 'string') throw new Error(copy.invalid);
    ui.subject.value = data.subject;
    ui.body.value = data.body;
    draftContext = {...context};
    draftSubmission = null;
    recipient = typeof data.recipient === 'string' ? data.recipient : '';
    channel = data.channel === 'web' ? 'web' : 'email';
    ui.recipient.textContent = recipient || copy.noContact;
    ui['recipient-label'].textContent = channel === 'web' ? copy.portal : copy.recipient;
    ui.editor.hidden = false;
    ui.empty.hidden = true;
    ui['replace'].hidden = false;
    ui['replace-confirm'].checked = false;
    ui['gmail-reviewed'].checked = false;
    ui['draft-source'].textContent = source;
    ui['draft-source'].hidden = false;
    hasDraft = true;
    dirty = true;
    copiedSubject = null;
    copiedBody = null;
    status(copy.generated, 'success');
    buttons();
    ui.subject.focus();
  }

  ui.manual.addEventListener('click', () => {
    if (busy || gmailLocked() || !preview?.manualDraft || (hasDraft && !ui['replace-confirm'].checked)) return;
    try { installDraft(preview.manualDraft, {language: ui.language.value, fingerprint: preview.fingerprint}, copy.localSource); }
    catch (error) { status(error.message, 'error'); }
  });

  function gmailUrl(value) {
    try {
      const url = new URL(value);
      return url.protocol === 'https:' && url.hostname === 'mail.google.com' && url.pathname.startsWith('/mail/') && !url.username && !url.password ? url.href : null;
    } catch (_) { return null; }
  }

  function gmailStatusMessage() {
    if (gmailReceipt?.stale) return copy.gmailStale;
    if (gmailReceipt?.state === 'rejected' && gmail.canCreate) return copy.gmailRejectedSafe;
    return gmailReceipt ? (copy.gmailStates[gmailReceipt.state] || copy.gmailUnknown) : copy.gmailUnknown;
  }

  function renderGmail() {
    const locked = gmailLocked();
    ui.gmail.hidden = !hasDraft && !gmailReceipt && !gmailAttempt;
    const displayedAccount = (locked ? gmailReceipt?.account : gmail.account) || gmail.account || '—';
    ui['gmail-account'].textContent = displayedAccount;
    ui['gmail-receipt-account'].textContent = gmailReceipt?.account || '';
    ui['gmail-receipt-account-wrap'].hidden = !gmailReceipt?.account || gmailReceipt.account === displayedAccount;
    const foreignReceipt = locked && gmailReceipt && hasDraft && (gmailReceipt.attemptId !== draftSubmission?.attemptId || ui.subject.value !== draftSubmission?.subject || message() !== draftSubmission?.body);
    ui['gmail-help'].textContent = foreignReceipt ? copy.gmailOtherDraft : (locked ? copy.gmailLocked : (channel !== 'email' ? copy.gmailPortal : (gmail.configured ? copy.gmailHelp : copy.gmailUnconfigured)));
    ui['gmail-create-controls'].hidden = locked || !hasDraft || !gmail.configured || channel !== 'email';
    ui['gmail-receipt'].hidden = !gmailReceipt && !gmailAttempt;
    ui['gmail-receipt'].dataset.state = gmailReceipt?.state || 'uncertain';
    ui['gmail-state'].textContent = gmailReceipt?.state === 'rejected' && gmail.canCreate ? copy.gmailRejectedSafe : (gmailReceipt ? (copy.gmailStates[gmailReceipt.state] || copy.gmailUnknown) : copy.gmailUnknown);
    ui['gmail-stale'].hidden = gmailReceipt?.stale !== true;
    ui['gmail-id-wrap'].hidden = !gmailReceipt?.draftId;
    ui['gmail-id'].textContent = gmailReceipt?.draftId || '';
    const href = gmailUrl(gmailReceipt?.gmailUrl);
    ui['gmail-open'].hidden = !href;
    if (href) ui['gmail-open'].href = href;
    else ui['gmail-open'].removeAttribute('href');
    // A receipt-only reload shows its result directly, without a duplicate empty editor.
    const receiptOnly = !hasDraft && Boolean(gmailReceipt || gmailAttempt);
    ui.empty.hidden = hasDraft || receiptOnly;
    ui['draft-title'].textContent = receiptOnly ? copy.draftStatus : copy.draft;
  }

  function acceptGmailStatus(data, allowEmpty = false) {
    if (!data || typeof data !== 'object') return false;
    if (typeof data.configured === 'boolean') gmail.configured = data.configured;
    // A flat receipt names its historical account; only a local status response
    // carries the current configured account for a future explicit attempt.
    if (typeof data.account === 'string' && typeof data.configured === 'boolean') gmail.account = data.account;
    const receipt = data.receipt && typeof data.receipt === 'object' ? data.receipt : (typeof data.state === 'string' && typeof data.attemptId === 'string' ? data : null);
    if (receipt) {
      if (typeof receipt.attemptId !== 'string' || !Object.prototype.hasOwnProperty.call(copy.gmailStates, receipt.state)) return false;
      gmailReceipt = receipt;
      gmailAttempt = receipt.attemptId || gmailAttempt;
      gmail.canCreate = receipt.state === 'rejected' && (data.canCreate === true || receipt.canCreate === true);
      ui['gmail-reviewed'].checked = false;
      if (receipt.state === 'verified' && !receipt.stale && hasDraft && receipt.attemptId === draftSubmission?.attemptId && ui.subject.value === draftSubmission.subject && message() === draftSubmission.body) {
        copiedSubject = ui.subject.value;
        copiedBody = message();
        dirty = false;
      }
      return true;
    }
    if (allowEmpty && data.receipt === null && data.canCreate === true) {
      gmailReceipt = null;
      gmailAttempt = null;
      gmail.canCreate = true;
      ui['gmail-reviewed'].checked = false;
      return true;
    }
    return false;
  }

  ui['gmail-reviewed'].addEventListener('change', buttons);
  ui['gmail-create'].addEventListener('click', async () => {
    if (busy || gmailLocked() || !hasDraft || !draftContext || !gmail.configured || !gmail.canCreate || channel !== 'email' || !ui['gmail-reviewed'].checked) return;
    try { gmailAttempt = crypto.randomUUID(); }
    catch (_) { status(copy.invalid, 'error'); return; }
    gmailReceipt = null;
    gmail.canCreate = false;
    const payload = {...draftContext, attemptId: gmailAttempt, expectedAccount: gmail.account, subject: ui.subject.value, body: message(), reviewed: true};
    draftSubmission = {...payload};
    busy = true; operation = 'gmail-create'; buttons(); status(copy.gmailCreating);
    try {
      const data = await request('gmail-draft', payload);
      if (!acceptGmailStatus(data)) throw new Error(copy.gmailUnknown);
      status(gmailStatusMessage(), gmailReceipt?.state === 'verified' && !gmailReceipt.stale ? 'success' : '');
    } catch (error) {
      const known = acceptGmailStatus(error.data, true);
      if (error.stale && !gmailReceipt && !gmailAttempt) { draftContext = null; invalidate(); }
      status(known ? error.message : copy.gmailUnknown, 'error');
    } finally { busy = false; operation = ''; buttons(); }
  });

  ui['gmail-check'].addEventListener('click', async () => {
    if (busy || !gmailAttempt) return;
    busy = true; operation = 'gmail-check'; buttons(); status(copy.gmailChecking);
    try {
      const data = gmailReceipt && gmailAttempt
        ? await request('gmail-draft/reconcile', {attemptId: gmailAttempt})
        : await request('gmail-draft', null, 'GET');
      if (!acceptGmailStatus(data, true)) throw new Error(copy.gmailUnknown);
      status(gmailReceipt ? gmailStatusMessage() : copy.gmailNoAttempt, gmailReceipt?.state === 'verified' && !gmailReceipt.stale ? 'success' : '');
    } catch (error) {
      acceptGmailStatus(error.data);
      status(error.message, 'error');
    } finally { busy = false; operation = ''; buttons(); }
  });

  function download(contents, filename, type) {
    const url = URL.createObjectURL(new Blob([contents], {type}));
    const anchor = document.createElement('a');
    anchor.href = url; anchor.download = filename;
    document.body.append(anchor); anchor.click(); anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    status(copy.downloaded, 'success');
  }

  const message = () => ui.body.value + (ui.signature.value.trim() ? `\n\n${ui.signature.value}` : '');
  query('download-request').addEventListener('click', () => {
    if (preview) download(JSON.stringify(preview.request, null, 2) + '\n', 'openai-email-request.json', 'application/json;charset=utf-8');
  });
  query('download').addEventListener('click', () => {
    if (!hasDraft) return;
    const destinationLabel = channel === 'web' ? copy.portal : copy.recipient;
    download(`${recipient ? `${destinationLabel}: ${recipient}\n` : ''}${copy.subject}: ${ui.subject.value}\n\n${message()}\n`, 'email-draft.txt', 'text/plain;charset=utf-8');
    copiedSubject = ui.subject.value;
    copiedBody = message();
    dirty = false;
  });
  async function copyText(text, field) {
    try {
      await navigator.clipboard.writeText(text);
      status(copy.copied, 'success');
      if (field === 'body') copiedBody = text;
      else copiedSubject = text;
      dirty = copiedSubject !== ui.subject.value || copiedBody !== message();
    } catch (_) { status(copy.copyFailed, 'error'); }
  }
  query('copy').addEventListener('click', () => { if (hasDraft) copyText(message(), 'body'); });
  query('copy-subject').addEventListener('click', () => { if (hasDraft) copyText(ui.subject.value, 'subject'); });
  for (const element of [ui.subject, ui.body, ui.signature]) element.addEventListener('input', () => {
    if (hasDraft) dirty = copiedSubject !== ui.subject.value || copiedBody !== message();
    ui['gmail-reviewed'].checked = false;
    buttons();
  });
  window.addEventListener('beforeunload', (event) => {
    if (dirty || busy) { event.preventDefault(); event.returnValue = ''; }
  });
  buttons();
})();
