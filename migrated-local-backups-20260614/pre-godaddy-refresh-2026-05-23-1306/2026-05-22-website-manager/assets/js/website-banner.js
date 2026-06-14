(function () {
  'use strict';

  var API = '/website-banner.php';

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch];
    });
  }

  function isSafeUrl(url) {
    return /^https?:\/\//i.test(url) || url.charAt(0) === '/' || /^[a-z0-9][a-z0-9._-]*\.(html|php)([?#].*)?$/i.test(url);
  }

  function renderBanner(banner) {
    if (!banner || !banner.active || !banner.message || document.getElementById('ogm-website-banner')) return;
    var html = '<div class="ogm-website-banner__inner">' +
      '<div class="ogm-website-banner__message">' + esc(banner.message) + '</div>';
    if (banner.ctaLabel && banner.ctaUrl && isSafeUrl(String(banner.ctaUrl))) {
      html += '<a class="ogm-website-banner__cta" href="' + esc(banner.ctaUrl) + '">' + esc(banner.ctaLabel) + '</a>';
    }
    html += '</div>';
    var el = document.createElement('div');
    el.id = 'ogm-website-banner';
    el.className = 'ogm-website-banner';
    el.innerHTML = html;
    document.body.insertBefore(el, document.body.firstChild);
  }

  function loadBanner() {
    fetch(API, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        if (data && data.ok) renderBanner(data.banner);
      })
      .catch(function () {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadBanner);
  } else {
    loadBanner();
  }
})();

