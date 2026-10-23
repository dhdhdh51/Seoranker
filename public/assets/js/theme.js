// Apply the saved/preferred colour theme before first paint (no inline scripts: CSP-safe).
(function () {
  try {
    var saved = localStorage.getItem('bseo-theme');
    var theme = saved || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-bs-theme', theme);
  } catch (e) { /* storage unavailable */ }
})();
