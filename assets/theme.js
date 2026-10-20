'use strict';
(() => {
  const key = 'quackpack:theme';
  const valid = value => ['light', 'dark', 'system'].includes(value);
  const system = window.matchMedia?.('(prefers-color-scheme: dark)');
  let preference = 'system';
  try { const saved = localStorage.getItem(key); if (valid(saved)) preference = saved; } catch {}
  function apply() {
    const theme = preference === 'system' ? (system?.matches ? 'dark' : 'light') : preference;
    document.documentElement.dataset.theme = theme;
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#171b18' : '#f8f7f3');
    const picker = document.getElementById('theme-choice');
    if (picker) picker.value = preference;
  }
  // Runs before the stylesheet so a saved dark theme never flashes cream.
  apply();
  document.addEventListener('DOMContentLoaded', () => {
    apply();
    document.getElementById('theme-choice')?.addEventListener('change', event => {
      if (!valid(event.target.value)) return;
      preference = event.target.value;
      try { localStorage.setItem(key, preference); } catch {}
      apply();
    });
  }, {once: true});
  system?.addEventListener('change', () => { if (preference === 'system') apply(); });
  window.addEventListener('storage', event => {
    if (event.key === key) { preference = valid(event.newValue) ? event.newValue : 'system'; apply(); }
  });
})();
