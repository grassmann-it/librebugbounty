(() => {
  'use strict';
  const root = document.querySelector('[data-email-preparation]');
  const configElement = document.getElementById('email-preparation-config');
  if (!root || !configElement) return;
  let config;
  try { config = JSON.parse(configElement.textContent); } catch (_) { return; }
  const copy = config.copy;
  const query = (selector) => root.querySelector(`[data-email-${selector}]`);
  const ui = Object.fromEntries(['language', 'preview', 'preview-content', 'payload', 'facts', 'model', 'generate', 'status', 'contact', 'editor', 'empty', 'subject', 'body', 'signature', 'recipient', 'recipient-label', 'recipient-wrap', 'replace', 'replace-confirm'].map((name) => [name, query(name)]));
  let preview = null;
  let busy = false;
  let hasDraft = false;
  let dirty = false;
  let copiedSubject = null;
  let copiedBody = null;
  let recipient = '';
  let channel = 'email';

  function status(message, tone = '') {
    ui.status.textContent = message;
    ui.status.dataset.tone = tone;
  }

  function buttons() {
    ui.language.disabled = busy;
    ui.preview.disabled = busy || !config.initialState.eligible;
    ui.generate.disabled = busy || !preview || !config.initialState.configured || (hasDraft && !ui['replace-confirm'].checked);
    ui['replace-confirm'].disabled = busy;
    ui.preview.textContent = busy && !preview ? copy.previewBusy : (preview ? copy.newPreview : copy.preview);
    ui.generate.textContent = busy && preview ? copy.generateBusy : (hasDraft ? copy.generateAgain : copy.generate);
    root.setAttribute('aria-busy', String(busy));
  }

  async function request(action, payload) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), action === 'generate' ? 135000 : 20000);
    try {
      const response = await fetch(`${config.basePath}/${action}`, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({_token: config.csrfToken, ...payload}),
      });
      let data;
      try { data = await response.json(); } catch (_) { throw new Error(copy.invalid); }
      if (!response.ok || data.ok !== true) {
        const error = new Error(typeof data.error === 'string' ? data.error : copy.requestFailed);
        error.stale = response.status === 409;
        throw error;
      }
      return data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error(copy.timeout);
      if (error instanceof TypeError) throw new Error(action === 'generate' ? copy.networkUnknown : copy.network);
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
    busy = true; buttons(); status(copy.previewBusy);
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
    finally { busy = false; buttons(); }
  });

  ui.generate.addEventListener('click', async () => {
    if (busy || !preview || !config.initialState.configured || (hasDraft && !ui['replace-confirm'].checked)) return;
    busy = true; buttons(); status(copy.generateBusy);
    // Keep the existing draft intact until generation succeeds.
    const originalValues = [ui.subject.value, ui.body.value];
    ui.subject.readOnly = true;
    ui.body.readOnly = true;
    try {
      const data = await request('generate', {language: ui.language.value, fingerprint: preview.fingerprint});
      if (typeof data.subject !== 'string' || typeof data.body !== 'string') throw new Error(copy.invalid);
      if (ui.subject.value !== originalValues[0] || ui.body.value !== originalValues[1]) throw new Error(copy.invalid);
      ui.subject.value = data.subject;
      ui.body.value = data.body;
      recipient = typeof data.recipient === 'string' ? data.recipient : '';
      channel = data.channel === 'web' ? 'web' : 'email';
      ui.recipient.textContent = recipient || copy.noContact;
      ui['recipient-label'].textContent = channel === 'web' ? copy.portal : copy.recipient;
      ui.editor.hidden = false;
      ui.empty.hidden = true;
      ui['replace'].hidden = false;
      ui['replace-confirm'].checked = false;
      hasDraft = true;
      dirty = true;
      copiedSubject = null;
      copiedBody = null;
      status(copy.generated, 'success');
      ui.subject.focus();
    } catch (error) {
      if (error.stale) invalidate();
      status(error.stale ? copy.stale : error.message, 'error');
    } finally {
      busy = false;
      ui.subject.readOnly = false;
      ui.body.readOnly = false;
      buttons();
    }
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
  });
  window.addEventListener('beforeunload', (event) => {
    if (dirty || busy) { event.preventDefault(); event.returnValue = ''; }
  });
  buttons();
})();
