// Applies the saved / system colour scheme before first paint (loaded synchronously in <head>).
(function () {
  try {
    var t = localStorage.getItem('sff-theme');
    if (!t) { t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
    document.documentElement.setAttribute('data-bs-theme', t);
  } catch (e) { /* storage disabled */ }
})();
