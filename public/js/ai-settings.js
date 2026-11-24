(() => {
  'use strict';
  const form = document.querySelector('[data-ai-profile-form]');
  const summary = document.querySelector('[data-ai-errors]');
  if (summary) summary.focus();
  if (!form) return;
  const values = () => JSON.stringify(Array.from(new FormData(form)).filter(([key]) => key !== '_token'));
  const original = values();
  const retained = form.dataset.unsaved === 'true';
  let submitting = false;
  form.addEventListener('submit', () => { submitting = true; });
  window.addEventListener('pageshow', () => { submitting = false; });
  window.addEventListener('beforeunload', (event) => {
    if (!submitting && (retained || values() !== original)) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
})();
