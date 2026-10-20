(() => {
  'use strict';
  const config = document.getElementById('studio-shortcuts-config');
  if (!config) return;
  let bindings;
  try { bindings = JSON.parse(config.textContent); } catch (_) { return; }
  const t = (key) => window.LibreBugBountyI18n?.t(key) || key;
  const symbols = { ArrowLeft: '←', ArrowRight: '→', ArrowUp: '↑', ArrowDown: '↓', Meta: '⌘', Ctrl: 'Ctrl', Space: 'Space' };
  const label = (action) => (bindings[action] || '').split('+').map(key => symbols[key] || key).join('+');
  const fromEvent = (event) => {
    if (event.isComposing || event.getModifierState?.('AltGraph') || (event.ctrlKey && event.altKey)) return '';
    let key = event.key === ' ' ? 'Space' : event.key.length === 1 ? event.key.toUpperCase() : event.key;
    // Shift changes digit characters (1 -> ! on many layouts). Bind the digit
    // key consistently when modifiers are held, including while recording it.
    if (event.shiftKey && /^Digit[0-9]$/.test(event.code)) key = event.code.slice(5);
    if (!/^(?:[A-Z0-9]|ArrowLeft|ArrowRight|ArrowUp|ArrowDown|Enter|Space)$/.test(key)) return '';
    return [event.ctrlKey && 'Ctrl', event.altKey && 'Alt', event.shiftKey && 'Shift', event.metaKey && 'Meta', key].filter(Boolean).join('+');
  };
  const refresh = (scope = document) => {
    scope.querySelectorAll('[data-shortcut-hint]').forEach(node => {
      const value = label(node.dataset.shortcutHint);
      node.textContent = value;
      node.hidden = !value;
    });
    scope.querySelectorAll('[data-shortcut-action]').forEach(node => {
      const binding = bindings[node.dataset.shortcutAction];
      if (binding) node.setAttribute('aria-keyshortcuts', binding.length === 1 ? binding.toLowerCase() : binding.replace('Ctrl+', 'Control+'));
      else node.removeAttribute('aria-keyshortcuts');
    });
    scope.querySelectorAll('[data-shortcut-help]').forEach(button => { button.hidden = false; });
  };
  window.LibreBugBountyShortcuts = {
    label, refresh,
    matches: (event, action) => Boolean(bindings[action]) && bindings[action] === fromEvent(event),
    // Review responses carry the latest persisted configuration, including
    // changes saved in another tab since this document was first rendered.
    update: (source) => {
      try {
        const next = source.getElementById('studio-shortcuts-config');
        const values = JSON.parse(next.textContent);
        if (!values || typeof values !== 'object' || Array.isArray(values)) return;
        bindings = values;
        config.textContent = next.textContent;
        refresh();
      } catch (_) { /* Keep the current bindings if an incomplete response arrives. */ }
    },
  };
  const protectedTarget = target => target instanceof Element && Boolean(target.closest(
    'input, textarea, select, button, a, summary, [contenteditable]:not([contenteditable="false"]), [role="textbox"], [role="combobox"], [role="slider"], [role="grid"], [data-review-novnc], canvas, iframe'
  ));
  document.addEventListener('keydown', event => {
    if (event.defaultPrevented || event.isComposing || document.querySelector('dialog[open]') || protectedTarget(event.target) || window.getSelection()?.toString()) return;
    const action = Object.keys(bindings).find(action => action.startsWith('nav.') && bindings[action] && bindings[action] === fromEvent(event));
    if (!action) return;
    const link = document.querySelector(`[data-shortcut-action="${action}"]`);
    if (!link) return;
    event.preventDefault();
    if (!event.repeat && !document.querySelector('[data-review-submitting="true"]')) link.click();
  });
  const setup = () => {
    refresh();
    // A real button keeps help discoverable without assigning another global key.
    // Review replaces its shell after each decision, including the help button.
    document.addEventListener('click', event => {
      const button = event.target instanceof Element ? event.target.closest('[data-shortcut-help]') : null;
      if (button) {
        if (document.querySelector('dialog[open]')) return;
        const template = document.querySelector('[data-shortcut-dialog-template]');
        const dialog = template?.content.firstElementChild.cloneNode(true);
        if (!dialog) return;
        document.body.append(dialog);
        refresh(dialog);
        dialog.querySelector('[data-shortcut-close]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('keydown', event => {
          event.stopPropagation();
          // Stop propagation must not prevent native Escape from closing help.
        });
        dialog.addEventListener('close', () => { dialog.remove(); button.focus(); }, { once: true });
        dialog.showModal();
        dialog.querySelector('button')?.focus();
      }
    });
    document.querySelectorAll('[data-shortcut-record]').forEach(button => {
      button.hidden = false;
      const input = document.getElementById(button.dataset.shortcutRecord);
      button.addEventListener('click', () => {
        input.focus();
        input.dataset.recording = 'true';
        button.textContent = t('Taste drücken …');
      });
      input.addEventListener('keydown', event => {
        if (input.dataset.recording !== 'true') return;
        if (event.key === 'Escape') { event.preventDefault(); input.blur(); return; }
        const value = fromEvent(event);
        if (!value) return;
        event.preventDefault(); event.stopPropagation();
        if (!event.repeat) input.value = value;
        delete input.dataset.recording;
        button.textContent = t('Taste aufnehmen');
      });
      input.addEventListener('blur', () => { delete input.dataset.recording; button.textContent = t('Taste aufnehmen'); });
    });
    const reset = document.querySelector('[data-shortcut-reset]');
    if (reset) reset.hidden = false;
    reset?.addEventListener('click', () => {
      document.querySelectorAll('[data-shortcut-default]').forEach(input => { input.value = input.dataset.shortcutDefault; });
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup, { once: true });
  else setup();
})();
