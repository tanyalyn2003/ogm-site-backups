(function () {
  var host = window.location.hostname;
  if (window.location.protocol === 'file:' || (host !== 'oliveglassandmarble.com' && host !== 'www.oliveglassandmarble.com')) {
    return;
  }

  var visitorStorageKey = 'ogm_traffic_visitor_id';
  var sessionStorageKey = 'ogm_traffic_session_id';

  function randomId() {
    return Math.random().toString(36).slice(2) + Date.now().toString(36);
  }

  function isSiteHost(candidateHost) {
    return candidateHost === 'oliveglassandmarble.com' || candidateHost === 'www.oliveglassandmarble.com';
  }

  function normalizeLabel(value, fallback) {
    var text = String(value || fallback || '').replace(/\s+/g, ' ').trim();
    return text.slice(0, 160);
  }

  function buildPayload(eventType) {
    return {
      event_type: eventType,
      page_id: pageId,
      path: window.location.pathname + window.location.search,
      title: document.title || '',
      referrer: document.referrer || '',
      visitor_id: visitorId,
      session_id: sessionId,
      screen: window.screen ? window.screen.width + 'x' + window.screen.height : '',
      viewport: window.innerWidth + 'x' + window.innerHeight,
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || ''
    };
  }

  function buildAbsoluteUrl(href) {
    try {
      return new URL(href, window.location.href);
    } catch (error) {
      return null;
    }
  }

  function isSocialHost(candidateHost) {
    return /(^|\.)facebook\.com$|(^|\.)instagram\.com$|(^|\.)pinterest\.com$|(^|\.)youtube\.com$|(^|\.)linkedin\.com$/i.test(candidateHost);
  }

  function isDownloadPath(pathname) {
    return /\.(pdf|zip|doc|docx|xls|xlsx|ppt|pptx|jpe?g|png|gif|webp|svg|mp4)$/i.test(pathname || '');
  }

  function sendPayload(payload) {
    var body = JSON.stringify(payload);

    if (navigator.sendBeacon) {
      var blob = new Blob([body], { type: 'application/json' });
      navigator.sendBeacon('traffic-log.php', blob);
      return;
    }

    fetch('traffic-log.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: body,
      credentials: 'same-origin',
      keepalive: true
    }).catch(function () {
      return null;
    });
  }

  function readPersistentId(key, storage) {
    try {
      var existing = storage.getItem(key);
      if (existing) {
        return existing;
      }

      var created = randomId();
      storage.setItem(key, created);
      return created;
    } catch (error) {
      return randomId();
    }
  }

  function sendEvent(eventType, extra) {
    var payload = buildPayload(eventType);
    var key;

    if (extra && typeof extra === 'object') {
      for (key in extra) {
        if (Object.prototype.hasOwnProperty.call(extra, key)) {
          payload[key] = extra[key];
        }
      }
    }

    sendPayload(payload);
  }

  var visitorId = readPersistentId(visitorStorageKey, window.localStorage);
  var sessionId = readPersistentId(sessionStorageKey, window.sessionStorage);
  var pageId = randomId();
  var activeStartedAt = document.visibilityState === 'hidden' ? 0 : Date.now();
  var activeMs = 0;
  var engagementSent = false;

  function pauseActiveTimer() {
    if (!activeStartedAt) {
      return;
    }

    activeMs += Math.max(0, Date.now() - activeStartedAt);
    activeStartedAt = 0;
  }

  function resumeActiveTimer() {
    if (document.visibilityState === 'hidden' || activeStartedAt) {
      return;
    }

    activeStartedAt = Date.now();
  }

  function sendEngagement() {
    if (engagementSent) {
      return;
    }

    pauseActiveTimer();

    var engagedMs = Math.min(Math.max(0, activeMs), 4 * 60 * 60 * 1000);
    engagementSent = true;

    if (engagedMs < 1000) {
      return;
    }

    var payload = buildPayload('engagement');
    payload.engaged_ms = Math.round(engagedMs);
    sendPayload(payload);
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest) {
      return;
    }

    var chatToggle = target.closest('.chat-toggle');
    if (chatToggle && chatToggle.getAttribute('aria-expanded') !== 'true') {
      sendEvent('contact_action', {
        event_name: 'chat_open',
        target_label: 'Chat Open'
      });
    }

    var link = target.closest('a[href]');
    if (!link) {
      return;
    }

    var href = String(link.getAttribute('href') || '').trim();
    if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) {
      return;
    }

    var label = normalizeLabel(link.getAttribute('aria-label') || link.textContent || link.title || href, href);
    if (/^tel:/i.test(href)) {
      sendEvent('contact_action', {
        event_name: 'phone_click',
        target_url: href,
        target_label: label || 'Phone Click'
      });
      return;
    }

    if (/^mailto:/i.test(href)) {
      sendEvent('contact_action', {
        event_name: 'email_click',
        target_url: href,
        target_label: label || 'Email Click'
      });
      return;
    }

    var absoluteUrl = buildAbsoluteUrl(href);
    if (!absoluteUrl) {
      return;
    }

    var targetHost = String(absoluteUrl.hostname || '').toLowerCase();
    var isCurrentSite = isSiteHost(targetHost);
    var targetPath = (absoluteUrl.pathname || '/') + (absoluteUrl.search || '');

    if (isCurrentSite && absoluteUrl.pathname === window.location.pathname && absoluteUrl.search === window.location.search && absoluteUrl.hash) {
      return;
    }

    if (isCurrentSite && /\/contact(?:\.html|\.php)?$/i.test(absoluteUrl.pathname || '')) {
      sendEvent('contact_action', {
        event_name: 'contact_page_click',
        target_url: absoluteUrl.href,
        target_label: label || 'Contact Page'
      });
      return;
    }

    if (isDownloadPath(absoluteUrl.pathname || '')) {
      sendEvent('link_click', {
        event_name: 'download',
        target_url: absoluteUrl.href,
        target_label: label || targetPath
      });
      return;
    }

    if (!isCurrentSite && isSocialHost(targetHost)) {
      sendEvent('link_click', {
        event_name: 'social_click',
        target_url: absoluteUrl.href,
        target_label: label || targetHost
      });
      return;
    }

    if (!isCurrentSite) {
      sendEvent('link_click', {
        event_name: 'outbound_click',
        target_url: absoluteUrl.href,
        target_label: label || targetHost
      });
    }
  }, { capture: true });

  document.addEventListener('submit', function (event) {
    var formElement = event.target;
    if (!formElement || formElement.nodeName !== 'FORM') {
      return;
    }

    var actionAttr = String(formElement.getAttribute('action') || '').trim();
    var actionUrl = buildAbsoluteUrl(actionAttr || (window.location.pathname + window.location.search));

    if (formElement.classList.contains('chat-lead-form')) {
      sendEvent('contact_action', {
        event_name: 'chat_lead_submit',
        target_url: actionUrl ? actionUrl.href : '',
        target_label: 'Chat Lead Form'
      });
      return;
    }

    if (actionUrl && /\/contact\.php$/i.test(actionUrl.pathname || '')) {
      sendEvent('contact_action', {
        event_name: 'contact_form_submit',
        target_url: actionUrl.href,
        target_label: 'Contact Form'
      });
    }
  }, { capture: true });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      pauseActiveTimer();
      return;
    }

    resumeActiveTimer();
  });

  window.addEventListener('blur', pauseActiveTimer, { passive: true });
  window.addEventListener('focus', resumeActiveTimer, { passive: true });
  window.addEventListener('pagehide', sendEngagement, { capture: true });
  window.addEventListener('beforeunload', sendEngagement, { capture: true });

  if (window.requestIdleCallback) {
    requestIdleCallback(function () { sendPayload(buildPayload('pageview')); }, { timeout: 2000 });
  } else {
    setTimeout(function () { sendPayload(buildPayload('pageview')); }, 200);
  }
}());
