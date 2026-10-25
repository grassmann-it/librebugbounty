(() => {
  const form = document.querySelector('#export-filters');
  const preview = document.querySelector('[data-export-preview-state]');
  const download = document.querySelector('[data-export-download]');
  if (!form || !preview || !download) return;

  // The counts and case examples are server-rendered for the last applied GET.
  // A changed field makes them stale even though a native download would use
  // the new form values. Keep the native no-JS path available.
  const applied = new URLSearchParams(new FormData(form));
  const snapshot = () => {
    const entries = [...new URLSearchParams(new FormData(form)).entries()];
    return entries.sort(([left, lv], [right, rv]) => left.localeCompare(right) || lv.localeCompare(rv));
  };
  const baseline = JSON.stringify([...applied.entries()].sort(([left, lv], [right, rv]) => left.localeCompare(right) || lv.localeCompare(rv)));
  const refresh = () => {
    const stale = JSON.stringify(snapshot()) !== baseline;
    preview.textContent = stale ? preview.dataset.stale : preview.dataset.current;
    preview.classList.toggle('is-stale', stale);
    preview.classList.toggle('is-current', !stale);
    download.disabled = stale;
    download.setAttribute('aria-describedby', 'export-preview-state');
  };
  preview.id = 'export-preview-state';
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  refresh();
})();
