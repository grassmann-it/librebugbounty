(() => {
  'use strict';
  const mounted = new WeakSet();
  const select = (panel, focus = false) => {
    const panels = panel.closest('[data-cribrum-proof-panels]');
    if (!panels) return;
    panels.querySelectorAll(':scope > [data-cribrum-proof]').forEach(item => {
      item.hidden = item !== panel;
      item.open = item === panel;
    });
    document.querySelectorAll('.cribrum-badge-item > .cribrum-proof-link').forEach(link => {
      if (link.hash === '#' + panel.id) link.setAttribute('aria-current', 'true');
      else link.removeAttribute('aria-current');
    });
    if (focus) panel.focus({preventScroll: true});
  };
  const reveal = (focus = true) => {
    let id;
    try { id = decodeURIComponent(location.hash.slice(1)); } catch { return; }
    const target = document.getElementById(id);
    if (!target) return;
    if (target.matches('[data-cribrum-proof]')) select(target, focus);
    for (let node = target; node; node = node.parentElement) {
      if (node instanceof HTMLDetailsElement) node.open = true;
    }
    if (focus) {
      if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
      target.focus({preventScroll: true});
      target.scrollIntoView({block: 'nearest'});
    }
  };
  const mount = () => {
    document.querySelectorAll('[data-cribrum-proof-panels]').forEach(panels => {
      if (mounted.has(panels)) return;
      mounted.add(panels);
      const host = document.querySelector('[data-cribrum-panel-host]');
      if (host && !host.contains(panels)) host.prepend(panels);
      panels.dataset.enhanced = 'true';
      const first = panels.querySelector('[data-cribrum-proof]');
      if (first) select(first);
    });
    reveal(false);
  };
  document.addEventListener('click', event => {
    const link = event.target.closest?.('.cribrum-proof-link');
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || url.pathname !== location.pathname || url.search !== location.search || !url.hash) return;
    if (!document.getElementById(url.hash.slice(1))) return;
    event.preventDefault();
    if (location.hash !== url.hash) history.pushState(history.state, '', url.hash);
    reveal();
  });
  window.addEventListener('hashchange', () => reveal());
  window.addEventListener('popstate', () => reveal());
  new MutationObserver(records => {
    if (records.some(record => [...record.addedNodes].some(node => node.nodeType === 1 && (node.matches('[data-report-summary], [data-cribrum-proof-panels]') || node.querySelector('[data-report-summary], [data-cribrum-proof-panels]'))))) mount();
  }).observe(document.body, {childList: true, subtree: true});
  mount();
})();
