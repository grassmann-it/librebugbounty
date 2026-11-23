(() => {
  'use strict';
  if (!window.fetch || !window.AbortController || !window.crypto?.getRandomValues) return;

  const t = window.LibreBugBountyI18n?.t || ((key) => key);
  const limit = 5;
  const maxPending = 10;
  const lifetime = 60000;
  const operationId = () => {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  };
  const jobs = [];
  const failures = [];
  let state, cards = [], fetching, pumping, timer, advancing = false, dirty = false, blocked = false, native = false;
  let restoredTrail, status, statusText, recovery, dismiss;
  const allowed = () => !document.hidden && !navigator.connection?.saveData && !['slow-2g', '2g'].includes(navigator.connection?.effectiveType);
  const generationOf = form => {
    try { return JSON.parse(atob(form?.querySelector('[name="queued_card"]')?.value || '')).generation ?? null; }
    catch (_) { return null; }
  };
  const storageKey = () => `lbb.review.queue.${state?.trail || ''}`;
  const persist = () => {
    try {
      if (!jobs.length && !failures.length) sessionStorage.removeItem(storageKey());
      else sessionStorage.setItem(storageKey(), JSON.stringify({
        pending: jobs.map(job => ({ id: job.id, operationId: job.operationId })),
        failures: failures.slice(-maxPending)
      }));
    } catch (_) {}
  };
  const recoveryUrl = () => {
    const url = new URL('/review', location.href);
    for (const key of ['kind', 'images', 'trail']) if (state?.[key]) url.searchParams.set(key, state[key]);
    return url.href;
  };
  const renderStatus = () => {
    if (!state) return;
    if (!status?.isConnected) {
      status = document.createElement('div');
      status.className = 'review-save-queue'; status.dataset.reviewSaveQueue = '';
      status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
      statusText = document.createElement('span'); statusText.dataset.reviewSaveQueueMessage = '';
      recovery = document.createElement('a'); recovery.dataset.reviewSaveQueueRecovery = '';
      recovery.textContent = t('Offene Fälle prüfen');
      dismiss = document.createElement('button'); dismiss.type = 'button'; dismiss.textContent = '×';
      dismiss.setAttribute('aria-label', t('Meldung schließen'));
      dismiss.addEventListener('click', () => { failures.length = 0; persist(); renderStatus(); });
      recovery.addEventListener('click', async (event) => {
        if (!jobs.length || event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); await drain(); window.location.assign(recoveryUrl());
      });
      status.append(statusText, recovery, dismiss);
      state.root.append(status);
    }
    state.root.dataset.reviewPending = String(jobs.length);
    state.root.dataset.reviewQueueReady = String(cards.length);
    status.hidden = jobs.length === 0 && failures.length === 0;
    status.dataset.failed = String(failures.length > 0);
    recovery.hidden = dismiss.hidden = failures.length === 0;
    recovery.href = recoveryUrl();
    statusText.textContent = failures.length
      ? t('{count} Bewertungen nicht bestätigt. Offene Fälle bitte erneut prüfen.', { count: failures.length })
        + (jobs.length ? ' ' + t('{count} Bewertungen werden gespeichert …', { count: jobs.length }) : '')
      : t('{count} Bewertungen werden gespeichert …', { count: jobs.length });
  };
  const fail = (job, uncertain, message) => {
    const existing = failures.find(failure => failure.operationId === job.operationId && failure.id === job.id);
    if (existing) { existing.uncertain ||= uncertain; existing.message ||= message; return; }
    failures.push({ id: job.id, operationId: job.operationId, uncertain, message });
    if (failures.length > maxPending) failures.shift();
  };
  const parse = (html, id) => {
    const page = new DOMParser().parseFromString(html, 'text/html');
    const body = page.querySelector('[data-studio-review]');
    if (!page.querySelector('.studio-review-shell') || body?.dataset.currentId !== id || body?.dataset.reviewTrail !== state.trail) throw new Error('Invalid review preview');
    return page;
  };
  const schedule = () => {
    clearTimeout(timer);
    if (!state?.generation || native || state.root.dataset.reviewSubmitting === 'true' || !allowed() || fetching || advancing || cards.length >= limit) return;
    timer = setTimeout(() => { void prefetch(); }, 200);
  };
  const prefetch = async () => {
    if (!state?.generation || native || state.root.dataset.reviewSubmitting === 'true' || !allowed() || fetching || advancing || cards.length >= limit || !state.id) return;
    const generation = state;
    const after = cards.at(-1)?.id || state.id;
    const url = new URL('/review/preview', location.href);
    for (const key of ['trail', 'kind', 'images']) url.searchParams.set(key, state[key]);
    url.searchParams.set('after', after); url.searchParams.set('limit', String(limit - cards.length));
    const request = new AbortController();
    fetching = request;
    const deadline = setTimeout(() => request.abort(), 15000);
    try {
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal });
      if (!response.ok) return;
      const batch = await response.json();
      if (state !== generation || !Array.isArray(batch.cards)) return;
      for (const card of batch.cards.slice(0, limit - cards.length)) {
        if (typeof card.id !== 'string' || typeof card.html !== 'string' || typeof card.url !== 'string') continue;
        const destination = new URL(card.url, location.href);
        if (destination.origin !== location.origin || destination.pathname !== '/review' || card.id === state.id || cards.some(c => c.id === card.id) || jobs.some(job => job.id === card.id)) continue;
        const page = parse(card.html, card.id);
        if (!page.querySelector('[data-review-form] [name="queued_card"]') || !page.querySelector('[data-review-form] [name="queued_token"]')) continue;
        if (generationOf(page.querySelector('[data-review-form]')) !== state.generation) continue;
        cards.push({ id: card.id, url: destination.href, page, at: Date.now() });
      }
    } catch (_) { /* Speculation never blocks the native review flow. */ }
    finally {
      clearTimeout(deadline); if (fetching === request) fetching = null; renderStatus();
      if (state !== generation) schedule();
    }
  };
  const pump = () => {
    if (pumping) return pumping;
    pumping = (async () => {
      while (jobs.length) {
        const job = jobs[0];
        const request = new AbortController();
        const deadline = setTimeout(() => request.abort(), 90000);
        try {
          const response = await fetch(job.url, { method: 'POST', body: job.data, credentials: 'same-origin', signal: request.signal, headers: { Accept: 'application/json' } });
          const receipt = await response.json();
          if (receipt.id !== job.id || receipt.operationId !== job.operationId || typeof receipt.ok !== 'boolean') throw new Error('Invalid assessment receipt');
          if (!response.ok || !receipt.ok) fail(job, receipt.outcome === 'unknown', receipt.message || '');
        } catch (_) {
          // A response can be lost after commit. Do not retry or relabel it as
          // unsaved: the authoritative open queue decides what needs review.
          fail(job, true, '');
        } finally {
          clearTimeout(deadline); jobs.shift(); persist(); renderStatus();
        }
      }
    })().finally(() => { pumping = null; schedule(); });
    return pumping;
  };
  const drain = async () => { while (jobs.length || pumping) await (pumping || pump()); };

  window.LibreReviewQueue = {
    update(root, callbacks) {
      const form = root.querySelector('[data-review-form]');
      const trail = root.dataset.reviewTrail;
      const kind = form?.querySelector('[name="kind"]')?.value || 'all';
      const images = form?.querySelector('[name="images"]')?.value || 'ready';
      const id = root.dataset.currentId;
      const generation = generationOf(form);
      const same = state?.trail === trail && state?.kind === kind && state?.images === images && state?.generation === generation;
      if (!same) { cards = []; fetching?.abort(); fetching = null; }
      else cards = cards.filter(card => card.id !== id && Date.now() - card.at < lifetime);
      state = { root, callbacks, trail, kind, images, id, generation };
      native = false;
      if (dirty) {
        const back = root.querySelector('[data-review-back]');
        if (back) back.disabled = false;
      }
      if (restoredTrail !== trail) {
        restoredTrail = trail;
        try {
          const journal = JSON.parse(sessionStorage.getItem(storageKey()) || '{}');
          for (const entry of [...(journal.pending || []), ...(journal.failures || [])].slice(-maxPending)) {
            if (entry && typeof entry.id === 'string') fail(entry, true, '');
          }
          persist();
        } catch (_) {}
      }
      renderStatus(); schedule();
    },

    trySubmit(form, submitter) {
      if (blocked) return true;
      if (!state || advancing || jobs.length >= maxPending || !form.matches('[data-review-form]') || !['confirmed', 'fixed', 'keep'].includes(submitter.value)) return false;
      if (!form.querySelector('[name="queued_card"]')?.value || !form.querySelector('[name="queued_token"]')?.value) return false;
      cards = cards.filter(card => Date.now() - card.at < lifetime);
      const next = cards[0];
      if (!next || !state.callbacks?.advance) { schedule(); return false; }
      const data = new FormData(form);
      if (submitter.name) data.append(submitter.name, submitter.value);
      const operation = operationId();
      data.set('operation_id', operation);
      const url = new URL(form.action, location.href);
      if (url.origin !== location.origin || !url.pathname.endsWith('/assessment')) return false;
      url.pathname = url.pathname.replace(/\/assessment$/, '/queued-assessment');
      const job = { id: state.id, operationId: operation, data, url: url.href };
      jobs.push(job); cards.shift(); dirty = true; advancing = true;
      state.root.dataset.reviewArriving = 'true';
      persist(); renderStatus();
      const callback = state.callbacks.advance;
      void pump();
      Promise.resolve().then(() => callback(next.page, next.url, submitter.value)).catch(() => {
        // Saving is independent of a cosmetic/rendering failure. Recovery GET
        // refreshes the actual state; never submit the old card a second time.
        fail(job, true, ''); persist(); renderStatus();
        blocked = true;
        state.root.dataset.reviewSubmitting = 'true';
        state.root.querySelectorAll('[data-review-confirm], [data-review-fixed], [data-review-keep], [data-review-skip], [data-review-back], [data-review-form] button[type="submit"], [data-review-defer-form] button[type="submit"]').forEach(button => { button.disabled = true; });
      }).finally(() => { advancing = false; delete state.root.dataset.reviewArriving; schedule(); });
      return true;
    },

    async prepareNative(form) {
      if (blocked) throw new Error('Review needs recovery');
      const data = new FormData(form);
      native = true; clearTimeout(timer); fetching?.abort(); fetching = null; cards = []; renderStatus();
      if (!dirty) return data;
      await drain();
      // Skip/Back still use their audited native trail. Refresh only their
      // navigation credentials; user-selected claims/evidence stay captured.
      const request = new AbortController();
      const deadline = setTimeout(() => request.abort(), 15000);
      let page;
      try {
        const response = await fetch(location.href, { credentials: 'same-origin', headers: { Accept: 'text/html' }, signal: request.signal });
        if (!response.ok) throw new Error('Could not synchronize review');
        page = parse(await response.text(), state.id);
      } finally { clearTimeout(deadline); }
      const selector = form.matches('[data-review-back-form]') ? '[data-review-back-form]'
        : form.matches('[data-review-skip-form]') ? '[data-review-skip-form]'
          : form.matches('[data-review-defer-form]') ? '[data-review-defer-form]' : '[data-review-form]';
      const fresh = page.querySelector(selector);
      if (!fresh) throw new Error('Missing synchronized review form');
      const previousContext = data.get('context_token');
      const nextContext = fresh.querySelector('[name="context_token"]')?.value;
      if (previousContext && previousContext.split(':')[0] !== nextContext?.split(':')[0]) throw new Error('Review context changed');
      for (const name of ['_token', 'trail_id', 'trail_version', 'context_token']) {
        if (!data.has(name)) continue;
        const value = fresh.querySelector(`[name="${name}"]`)?.value;
        if (value === undefined) throw new Error('Missing review credential');
        data.set(name, value);
      }
      cards = []; dirty = false; renderStatus();
      return data;
    },

    drain
  };
  window.addEventListener('beforeunload', event => {
    if (!jobs.length) return;
    event.preventDefault(); event.returnValue = '';
  });
  document.addEventListener('visibilitychange', schedule);
  navigator.connection?.addEventListener('change', schedule);
  window.addEventListener('pagehide', () => { clearTimeout(timer); fetching?.abort(); persist(); });
})();
