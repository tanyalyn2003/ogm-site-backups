/**
 * Shared fuzzy duplicate detection for Customer DB profiles (quoters + CRM).
 * Requires ogm-server-sync.js (window.storage) and PHP session login.
 */
(function (global) {
  'use strict';

  var STYLE_ID = 'ogm-customer-dedupe-styles';

  function injectStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var css =
      '#ogm-dedupe-overlay{position:fixed;inset:0;background:rgba(15,23,42,.72);z-index:100060;display:flex;align-items:center;justify-content:center;padding:24px;font-family:"DM Sans",system-ui,sans-serif}' +
      '#ogm-dedupe-card{max-width:520px;width:100%;max-height:min(88vh,640px);overflow:auto;background:#0f172a;border:1px solid rgba(148,163,184,.25);border-radius:8px;box-shadow:0 24px 48px rgba(0,0,0,.45)}' +
      '#ogm-dedupe-head{padding:18px 20px 12px;border-bottom:1px solid rgba(148,163,184,.15)}' +
      '#ogm-dedupe-head h2{margin:0;font-size:15px;font-weight:500;color:#f1f5f9;letter-spacing:.02em}' +
      '#ogm-dedupe-head p{margin:8px 0 0;font-size:12px;line-height:1.55;color:#94a3b8}' +
      '#ogm-dedupe-body{padding:14px 20px 18px}' +
      '.ogm-dedupe-row{margin-bottom:10px;padding:11px 12px;background:rgba(30,41,59,.65);border:1px solid rgba(148,163,184,.18);border-radius:6px;display:flex;flex-direction:column;gap:6px}' +
      '.ogm-dedupe-row-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap}' +
      '.ogm-dedupe-name{font-size:13px;font-weight:500;color:#e2e8f0}' +
      '.ogm-dedupe-meta{font-size:11px;color:#64748b;line-height:1.45}' +
      '.ogm-dedupe-reason{font-size:10px;color:#c4a05a;letter-spacing:.04em}' +
      '.ogm-dedupe-use{margin-top:4px;align-self:flex-start;padding:6px 12px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;border-radius:4px;border:1px solid rgba(196,160,90,.45);background:rgba(196,160,90,.12);color:#fbbf24;cursor:pointer;font-family:inherit;font-weight:400}' +
      '.ogm-dedupe-use:hover{background:rgba(196,160,90,.22)}' +
      '#ogm-dedupe-foot{padding:14px 20px 18px;border-top:1px solid rgba(148,163,184,.12);display:flex;flex-direction:column;gap:10px}' +
      '#ogm-dedupe-new{width:100%;padding:11px 14px;font-size:12px;letter-spacing:.1em;text-transform:uppercase;border-radius:4px;border:none;background:#c4a05a;color:#0f172a;cursor:pointer;font-family:inherit;font-weight:500}' +
      '#ogm-dedupe-new:hover{background:#e8d5a3}' +
      '#ogm-dedupe-dismiss{font-size:11px;color:#64748b;background:none;border:none;cursor:pointer;text-decoration:underline;font-family:inherit;padding:4px 0}' +
      '#ogm-dedupe-dismiss:hover{color:#94a3b8}';

    var st = document.createElement('style');
    st.id = STYLE_ID;
    st.textContent = css;
    document.head.appendChild(st);
  }

  function normName(s) {
    return String(s || '')
      .toLowerCase()
      .replace(/[^a-z0-9\s]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function normCity(c) {
    return String(c || '')
      .toLowerCase()
      .replace(/[^a-z0-9]/g, '')
      .trim();
  }

  function nameTokens(displayName) {
    var n = normName(displayName);
    if (!n) return [];
    return n.split(' ').filter(function (t) {
      return t.length > 1;
    });
  }

  function tokenJaccard(a, b) {
    var ta = nameTokens(a);
    var tb = nameTokens(b);
    if (!ta.length || !tb.length) return 0;
    var setb = {};
    for (var i = 0; i < tb.length; i++) setb[tb[i]] = true;
    var inter = 0;
    var seen = {};
    for (var j = 0; j < ta.length; j++) {
      var t = ta[j];
      if (setb[t] && !seen[t]) {
        seen[t] = true;
        inter++;
      }
    }
    var uni = ta.length + tb.length - inter;
    return uni ? inter / uni : 0;
  }

  function substringBoost(displayA, displayB) {
    var na = normName(displayA);
    var nb = normName(displayB);
    if (!na || !nb) return 0;
    if (na === nb) return 1;
    var lo = na.length <= nb.length ? na : nb;
    var hi = na.length <= nb.length ? nb : na;
    if (lo.length >= 4 && hi.indexOf(lo) !== -1) return 0.9;
    return 0;
  }

  function combinedNameScore(incomingDisp, custDisp) {
    var t = Math.max(tokenJaccard(incomingDisp, custDisp), substringBoost(incomingDisp, custDisp));
    return t;
  }

  function customerDisplayName(c) {
    var n = [c.firstName, c.lastName].filter(Boolean).join(' ').trim();
    return n || String(c.jobName || '').trim();
  }

  function digitsPhone(p) {
    return String(p || '').replace(/\D/g, '');
  }

  async function loadAllCustomers() {
    var keysRes = await window.storage.list('ogm-cust-');
    var keys = (keysRes && keysRes.keys) || [];
    var out = [];
    for (var i = 0; i < keys.length; i++) {
      try {
        var r = await window.storage.get(keys[i]);
        if (!r || !r.value) continue;
        var c = JSON.parse(r.value);
        if (c && c.id) out.push(c);
      } catch (e) {}
    }
    return out;
  }

  function scoreIncomingVsCustomer(inc, c) {
    var score = 0;
    var reasons = [];
    var cn = customerDisplayName(c);
    var ns = combinedNameScore(inc.displayName, cn);

    if (ns >= 0.78) {
      score += 0.72;
      reasons.push('Very similar name');
    } else if (ns >= 0.48) {
      score += 0.52;
      reasons.push('Similar name');
    } else if (ns >= 0.3) {
      score += 0.26;
      reasons.push('Possible name match');
    }

    if (inc.cityNorm && normCity(c.svcCity) === inc.cityNorm && inc.cityNorm.length > 2) {
      score += 0.22;
      reasons.push('Same city');
    }

    var cd = digitsPhone(c.phone);
    if (inc.phoneDigits.length >= 7 && cd.length >= 7) {
      if (inc.phoneDigits === cd) {
        score += 1;
        reasons.push('Same phone');
      } else if (inc.phoneDigits.slice(-7) === cd.slice(-7)) {
        score += 0.34;
        reasons.push('Phone number nearly matches');
      }
    }

    var em = String(c.email || '')
      .trim()
      .toLowerCase();
    if (inc.emailLower && em && inc.emailLower === em) {
      score += 1;
      reasons.push('Same email');
    }

    return { score: Math.min(score, 1.35), reasons: reasons };
  }

  function incomingFromQuoteFields(fields) {
    var disp =
      String(fields.name || '').trim() ||
      String(fields.job || '').trim();
    return {
      label: disp ? '"' + disp + '"' : 'This quote',
      displayName: disp,
      phoneDigits: digitsPhone(fields.phone),
      emailLower: String(fields.email || '')
        .trim()
        .toLowerCase(),
      cityNorm: normCity(fields.installCity || fields.city || ''),
    };
  }

  function incomingFromGlassBuild(d) {
    var name = String(d.custName || '').trim();
    var job = String(d.jobName || '').trim();
    var disp = name || job;
    return {
      label: disp ? '"' + disp + '"' : 'This glass quote',
      displayName: disp,
      phoneDigits: digitsPhone(d.custPhone),
      emailLower: String(d.custEmail || '')
        .trim()
        .toLowerCase(),
      cityNorm: normCity(d.svcCity || ''),
    };
  }

  function incomingFromCustomerForm(form) {
    var fname = String(form.firstName || '').trim();
    var lname = String(form.lastName || '').trim();
    var disp = [fname, lname].filter(Boolean).join(' ').trim();
    return {
      label: disp ? '"' + disp + '"' : 'New entry',
      displayName: disp,
      phoneDigits: digitsPhone(form.phone),
      emailLower: String(form.email || '')
        .trim()
        .toLowerCase(),
      cityNorm: normCity(form.city || ''),
    };
  }

  /**
   * @param {object} incoming — built via incomingFrom*
   * @param {{ excludeIds?: string[], limit?: number, minScore?: number }} opts
   */
  async function findCandidates(incoming, opts) {
    opts = opts || {};
    var exclude = {};
    (opts.excludeIds || []).forEach(function (id) {
      exclude[String(id)] = true;
    });
    var minScore = opts.minScore != null ? opts.minScore : 0.45;
    var limit = opts.limit != null ? opts.limit : 8;
    var list = await loadAllCustomers();
    var ranked = [];
    for (var i = 0; i < list.length; i++) {
      var c = list[i];
      if (!c || !c.id || exclude[c.id]) continue;
      var sr = scoreIncomingVsCustomer(incoming, c);
      if (sr.score >= minScore) {
        ranked.push({ cust: c, score: sr.score, reasons: sr.reasons });
      }
    }
    ranked.sort(function (a, b) {
      return b.score - a.score;
    });
    return ranked.slice(0, limit);
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /**
   * @returns {Promise<string>} existing customer id or '__new__'
   */
  function promptChooseExisting(options) {
    injectStyles();
    options = options || {};
    var candidates = options.candidates || [];
    return new Promise(function (resolve) {
      var settled = false;
      function finish(v) {
        if (settled) return;
        settled = true;
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        document.removeEventListener('keydown', onKey);
        resolve(v);
      }

      var overlay = document.createElement('div');
      overlay.id = 'ogm-dedupe-overlay';
      var card = document.createElement('div');
      card.id = 'ogm-dedupe-card';
      card.setAttribute('role', 'dialog');
      card.setAttribute('aria-modal', 'true');

      var head = document.createElement('div');
      head.id = 'ogm-dedupe-head';
      head.innerHTML =
        '<h2>Possible duplicate customer</h2><p>' +
        esc(options.subtitle ||
          'This looks like one or more profiles you already have. Similar names are common — use an existing record when it is the same person, or create a new one when it is not.') +
        '</p><p style="margin-top:8px;color:#cbd5e1;font-size:12px">Matching: <strong>' +
        esc(options.incomingLabel || '—') +
        '</strong></p>';

      var body = document.createElement('div');
      body.id = 'ogm-dedupe-body';

      candidates.forEach(function (row) {
        var c = row.cust;
        var nm = customerDisplayName(c) || '—';
        var meta = [c.phone, c.svcCity, c.email].filter(Boolean).join(' · ') || 'No phone / city on file';
        var reasonStr = (row.reasons && row.reasons.length ? row.reasons.join(' · ') : 'Match');

        var wrap = document.createElement('div');
        wrap.className = 'ogm-dedupe-row';
        wrap.innerHTML =
          '<div class="ogm-dedupe-row-top">' +
          '<div><div class="ogm-dedupe-name">' +
          esc(nm) +
          '</div>' +
          '<div class="ogm-dedupe-meta">' +
          esc(meta) +
          '</div>' +
          '<div class="ogm-dedupe-reason">' +
          esc(reasonStr) +
          '</div></div></div>';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ogm-dedupe-use';
        btn.textContent = 'Use this profile';
        btn.addEventListener('click', function () {
          finish(String(c.id));
        });
        wrap.appendChild(btn);
        body.appendChild(wrap);
      });

      var foot = document.createElement('div');
      foot.id = 'ogm-dedupe-foot';
      var btnNew = document.createElement('button');
      btnNew.type = 'button';
      btnNew.id = 'ogm-dedupe-new';
      btnNew.textContent = options.primaryNewLabel || 'Create new customer anyway';
      btnNew.addEventListener('click', function () {
        finish('__new__');
      });
      var dismiss = document.createElement('button');
      dismiss.type = 'button';
      dismiss.id = 'ogm-dedupe-dismiss';
      dismiss.textContent = 'Same as creating new — dismiss';
      dismiss.addEventListener('click', function () {
        finish('__new__');
      });

      foot.appendChild(btnNew);
      foot.appendChild(dismiss);

      card.appendChild(head);
      card.appendChild(body);
      card.appendChild(foot);
      overlay.appendChild(card);

      function onKey(e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          finish('__new__');
        }
      }

      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) finish('__new__');
      });

      document.addEventListener('keydown', onKey);
      document.body.appendChild(overlay);
      btnNew.focus();
    });
  }

  global.OgmCustomerDedupe = {
    incomingFromQuoteFields: incomingFromQuoteFields,
    incomingFromGlassBuild: incomingFromGlassBuild,
    incomingFromCustomerForm: incomingFromCustomerForm,
    findCandidates: findCandidates,
    promptChooseExisting: promptChooseExisting,
    /** Tunable default used by callers */
    DEFAULT_MIN_SCORE: 0.45,
    customerDisplayName: customerDisplayName,
  };
})(typeof window !== 'undefined' ? window : globalThis);
