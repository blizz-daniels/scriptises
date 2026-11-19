(() => {
  const storageKey = 'scriptises:theme';
  const root = document.documentElement;

  try {
    if (localStorage.getItem(storageKey) === 'dark') root.dataset.theme = 'dark';
  } catch {
    // The switch still works on the current page if browser storage is unavailable.
  }

  document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('[data-theme-toggle]');
    if (!toggle) return;
    const label = toggle.querySelector('.theme-label');

    const applyTheme = (theme) => {
      root.dataset.theme = theme;
      const nextMode = theme === 'dark' ? 'Light mode' : 'Dark mode';
      label.textContent = nextMode;
      toggle.setAttribute('aria-label', `Switch to ${nextMode.toLowerCase()}`);
      toggle.title = nextMode;
    };

    applyTheme(root.dataset.theme === 'dark' ? 'dark' : 'light');
    toggle.hidden = false;

    toggle.addEventListener('click', () => {
      const theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
      applyTheme(theme);
      try {
        localStorage.setItem(storageKey, theme);
      } catch {
        // The applied theme remains active on this page.
      }
    });

    window.addEventListener('storage', (event) => {
      if (event.key === storageKey) applyTheme(event.newValue === 'dark' ? 'dark' : 'light');
    });
  }, { once: true });
})();
