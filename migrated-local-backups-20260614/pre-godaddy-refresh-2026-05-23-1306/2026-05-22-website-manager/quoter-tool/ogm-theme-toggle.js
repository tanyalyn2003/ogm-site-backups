(function () {
  var KEY = 'ogm-theme';

  function isEmbeddedView() {
    var inFrame = false;
    try {
      inFrame = window.self !== window.top;
    } catch (e) {
      inFrame = true;
    }
    try {
      var p = new URLSearchParams(window.location.search || '');
      var embed = String(p.get('embed') || '').toLowerCase();
      if (embed === '1' || embed === 'true' || embed === 'yes' || embed === 'invoice') return true;
    } catch (e) {
    }
    if (!inFrame) return false;
    try {
      var path = String(window.location.pathname || '').toLowerCase();
      return path.indexOf('ogm_kitchenplanner') !== -1
        || path.indexOf('shape-connector') !== -1
        || path.indexOf('blueprintscanner') !== -1;
    } catch (e) {
      return false;
    }
  }

  function isDark() {
    return document.documentElement.getAttribute('data-ogm-theme') === 'dark';
  }

  function apply(dark) {
    var html = document.documentElement;
    if (dark) html.setAttribute('data-ogm-theme', 'dark');
    else html.removeAttribute('data-ogm-theme');
    try {
      localStorage.setItem(KEY, dark ? 'dark' : 'light');
    } catch (e) {}
    sync();
  }

  function toggle() {
    apply(!isDark());
  }

  function sync() {
    var btn = document.getElementById('ogm-theme-toggle');
    if (!btn) return;
    var dark = isDark();
    btn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
    btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    btn.innerHTML = dark
      ? '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>'
      : '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
  }

  function mount() {
    if (isEmbeddedView()) return;
    if (document.getElementById('ogm-theme-toggle')) return;
    var btn = document.createElement('button');
    btn.id = 'ogm-theme-toggle';
    btn.type = 'button';
    btn.className = 'ogm-theme-toggle-btn';
    btn.addEventListener('click', toggle);
    document.body.appendChild(btn);
    sync();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
