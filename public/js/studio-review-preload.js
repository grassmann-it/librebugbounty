(() => {
  'use strict';
  if (!window.Image || !Image.prototype.decode || !window.AbortController) return;

  // Only stored artifacts, never /review HTML (GET changes the session trail).
  // Keep only the configured upcoming images and one speculative decode active.
  const cache = new Map();
  const selected = '[data-review-shot]:not([hidden]) [data-review-image]';
  const lifetime = 60000;
  const preloadLimit = root => {
    const value = root.dataset.reviewPreloadCount;
    return /^\d+$/.test(value || '') ? Math.min(50, Number(value)) : 5;
  };
  let state, timer, idle, expiryTimer, running = 0, suspended = false;
  const source = (hint) => {
    if (!hint || typeof hint.evidenceId !== 'string' || typeof hint.url !== 'string' || typeof hint.sha256 !== 'string') return null;
    try {
      const url = new URL(hint.url, location.href);
      if (url.origin !== location.origin || !url.pathname.startsWith('/artifacts/') || url.username || url.password) return null;
      return { url: url.href, key: JSON.stringify([hint.evidenceId, url.href, hint.sha256]) };
    } catch (_) { return null; }
  };
  const stop = () => {
    clearTimeout(timer);
    if (idle !== undefined) window.cancelIdleCallback?.(idle);
    idle = undefined;
  };
  const release = entry => {
    clearTimeout(entry.deadline);
    if (entry.background) { running--; entry.background = false; }
  };
  const drop = (key, entry) => {
    cache.delete(key);
    release(entry);
    entry.image.removeAttribute('src');
  };
  const expire = () => {
    clearTimeout(expiryTimer);
    let next = Infinity;
    for (const [key, entry] of cache) {
      // Failed speculation stays suppressed until the case/hints change.
      if (entry.status === 'failed') continue;
      if (Date.now() - entry.at >= lifetime) drop(key, entry);
      else next = Math.min(next, entry.at + lifetime);
    }
    if (next !== Infinity) expiryTimer = setTimeout(() => { expire(); schedule(true); }, Math.max(1, next - Date.now() + 1));
  };
  const allowed = () => !suspended && !document.hidden && !navigator.connection?.saveData && !['slow-2g', '2g'].includes(navigator.connection?.effectiveType);
  const schedule = (immediate = false) => {
    stop();
    if (!state || state.signal.aborted || !state.ready || !allowed()) return;
    timer = setTimeout(() => {
      if (immediate !== true && window.requestIdleCallback) idle = window.requestIdleCallback(pump, { timeout: 800 });
      else pump();
    }, immediate === true ? 0 : 150);
  };
  const pump = () => {
    if (!state || state.signal.aborted || !state.ready || !allowed() || running) return;
    if (state.root.dataset.reviewSubmitting === 'true') return;
    if (state.root.dataset.reviewArriving === 'true') { schedule(); return; }
    const hint = state.hints.find(hint => !cache.has(hint.key));
    if (!hint) return;
    const image = new Image();
    image.decoding = 'async';
    image.fetchPriority = 'low';
    const entry = { image, at: Date.now(), status: 'pending', claimed: false, background: true };
    cache.set(hint.key, entry);
    running++;
    image.src = hint.url;
    entry.deadline = setTimeout(() => {
      // A failed speculative fetch does not delay review or retry in a loop.
      if (!entry.claimed) {
        entry.status = 'failed'; release(entry); image.removeAttribute('src');
        expire(); schedule();
      }
    }, 10000);
    image.decode().then(() => { if (entry.status !== 'failed') entry.status = 'ready'; }, () => { entry.status = 'failed'; }).finally(() => {
      release(entry);
      expire(); schedule();
    });
    expire();
  };

  window.LibreReviewPreload = {
    update(root, signal) {
      stop();
      let hints = [];
      try {
        const raw = JSON.parse(root.querySelector('[data-review-preloads]')?.dataset.reviewPreloads || '[]');
        if (Array.isArray(raw)) hints = raw.slice(0, preloadLimit(root)).map(source).filter(Boolean);
      } catch (_) {}
      const wanted = new Set(hints.map(hint => hint.key));
      for (const [key, entry] of cache) {
        if (!wanted.has(key) || Date.now() - entry.at >= lifetime) drop(key, entry);
      }
      expire();
      const current = root.querySelector(selected);
      state = { root, signal, hints, ready: !current || current.complete };
      const currentState = state;
      const ready = () => { if (state === currentState) { state.ready = true; schedule(); } };
      if (!state.ready) {
        current.addEventListener('load', ready, { once: true, signal });
        current.addEventListener('error', ready, { once: true, signal });
      }
      signal.addEventListener('abort', stop, { once: true });
      document.addEventListener('visibilitychange', schedule, { signal });
      navigator.connection?.addEventListener('change', schedule, { signal });
      schedule();
    },

    refill() { schedule(true); },

    importShell(page) {
      const original = page.querySelector('.studio-review-shell');
      const fresh = original.querySelector(selected);
      const hint = fresh && source({
        evidenceId: fresh.closest('[data-review-shot]').dataset.reviewShot,
        url: fresh.getAttribute('src'), sha256: fresh.dataset.reviewSha256 || ''
      });
      const entry = hint && cache.get(hint.key);
      if (!entry || entry.status === 'failed' || Date.now() - entry.at >= lifetime) return document.importNode(original, true);

      // Fresh server HTML remains authoritative for case, image, hash, captions,
      // forms and tokens. Reuse ONLY the matching image node, including a fetch
      // already in flight. Avoid importing its src and starting a second request
      // for our deliberately no-cache artifact endpoint.
      cache.delete(hint.key);
      entry.claimed = true;
      release(entry);
      expire();
      const attributes = Array.from(fresh.attributes, attr => [attr.name, attr.value]);
      fresh.removeAttribute('src');
      const shell = document.importNode(original, true);
      const image = entry.image;
      image.removeAttribute('fetchpriority');
      for (const [name, value] of attributes) if (name !== 'src') image.setAttribute(name, value);
      shell.querySelector(selected).replaceWith(image);
      return shell;
    }
  };
  window.addEventListener('pagehide', () => {
    suspended = true;
    stop(); clearTimeout(expiryTimer);
    for (const [key, entry] of cache) drop(key, entry);
  });
  window.addEventListener('pageshow', event => { if (event.persisted) { suspended = false; schedule(); } });
})();
