// Apply the saved theme before first paint (no flash). Runs in <head>.
(function () {
  try {
    var t = localStorage.getItem('aisseastats-theme');
    if (t === 'light' || t === 'dark') {
      document.documentElement.setAttribute('data-theme', t);
    }
  } catch (e) { /* storage unavailable */ }
})();
