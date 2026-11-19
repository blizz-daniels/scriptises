(() => {
  const form = document.querySelector('[data-pdf-options]');
  if (!form) return;
  const scope = form.querySelector('[data-pdf-scope]');
  const refresh = () => {
    for (const group of form.querySelectorAll('[data-pdf-scopes]')) {
      const active = group.dataset.pdfScopes.split(' ').includes(scope.value);
      group.hidden = !active;
      for (const input of group.querySelectorAll('input, select')) input.disabled = !active;
    }
  };
  scope.addEventListener('change', refresh);
  refresh();
})();
