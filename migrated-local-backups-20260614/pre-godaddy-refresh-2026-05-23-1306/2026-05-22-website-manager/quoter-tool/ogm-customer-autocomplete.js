/**
 * OGM Customer name autocomplete — shared by Stone & Glass quoters.
 * Debounced search via customers-api.php?action=search-by-name
 */
(function (global) {
  'use strict';

  var cfg = {
    linkedGlobal: '_ogmLinkedCustomerId',
    streetField: 'cust-addr',
    cityField: 'cust-city',
    nameInputId: 'cust-name',
    acListId: 'ogm-cust-name-ac',
    useLinkedBar: true,
    linkedBarId: 'ogm-linked-bar',
    linkedNameId: 'ogm-linked-name',
    linkedPhoneId: 'ogm-linked-phone',
    linkedViewId: 'ogm-linked-view',
    onAfterSelect: null,
    onNameInput: null,
    onUnlink: null
  };

  var _timer = null;
  var _pickActive = false;
  var _lastLinkedName = '';
  var _listCache = null;
  var _listCacheAt = 0;
  var CACHE_MS = 45000;

  function getLinkedId() {
    return cfg.linkedGlobal ? (global[cfg.linkedGlobal] || null) : null;
  }

  function setLinkedId(id) {
    if (cfg.linkedGlobal) global[cfg.linkedGlobal] = id || null;
  }

  function escHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function customerDisplayName(c) {
    if (global.OgmCustomerDedupe && global.OgmCustomerDedupe.customerDisplayName) {
      return global.OgmCustomerDedupe.customerDisplayName(c) || '—';
    }
    var n = [c.firstName, c.lastName].filter(Boolean).join(' ').trim();
    return n || String(c.name || c.jobName || '').trim() || '—';
  }

  function customerSearchBlob(c) {
    return [
      c.firstName, c.lastName, c.name, c.phone, c.phone2, c.email,
      c.svcStreet, c.svcCity, c.billStreet, c.billCity, c.jobName, c.id
    ].filter(Boolean).join(' ').toLowerCase();
  }

  function customerMatchesQuery(c, q) {
    var qL = (q || '').toLowerCase().trim();
    if (!qL) return true;
    var blob = customerSearchBlob(c);
    if (blob.indexOf(qL) !== -1) return true;
    var tokens = qL.split(/\s+/).filter(Boolean);
    return tokens.length > 0 && tokens.every(function (t) { return blob.indexOf(t) !== -1; });
  }

  function customerToSuggestRow(c) {
    return {
      id: c.id,
      name: customerDisplayName(c),
      phone: c.phone || '',
      email: c.email || '',
      svcStreet: c.svcStreet || '',
      svcCity: c.svcCity || ''
    };
  }

  async function loadCustomersForSearch() {
    var now = Date.now();
    if (_listCache && (now - _listCacheAt) < CACHE_MS) return _listCache;
    try {
      var res = await fetch('customers-api.php?action=list-customers', { credentials: 'same-origin' });
      var j = await res.json();
      if (res.ok && j.ok && Array.isArray(j.customers)) {
        _listCache = j.customers;
        _listCacheAt = now;
        return j.customers;
      }
    } catch (e) { /* fall through */ }
    if (!global.storage || typeof global.storage.list !== 'function') return [];
    var keysRes = await global.storage.list('ogm-cust-');
    var keys = (keysRes && keysRes.keys) || [];
    var out = [];
    for (var i = 0; i < keys.length; i++) {
      try {
        var r = await global.storage.get(keys[i]);
        if (!r || !r.value) continue;
        var c = JSON.parse(r.value);
        if (c && c.id) out.push(c);
      } catch (err) {}
    }
    _listCache = out;
    _listCacheAt = now;
    return out;
  }

  async function searchCustomersByQuery(q, limit) {
    var qTrim = (q || '').trim();
    var lim = limit != null ? limit : 12;
    if (qTrim.length < 2) return [];
    try {
      var res = await fetch(
        'customers-api.php?action=search-by-name&q=' + encodeURIComponent(qTrim) + '&limit=' + lim,
        { credentials: 'same-origin' }
      );
      var j = await res.json();
      if (res.ok && j.ok && Array.isArray(j.customers)) return j.customers;
    } catch (e) { /* client filter fallback */ }
    var all = await loadCustomersForSearch();
    return all
      .filter(function (c) { return customerMatchesQuery(c, qTrim); })
      .sort(function (a, b) { return (a.lastName || '').localeCompare(b.lastName || ''); })
      .slice(0, lim)
      .map(customerToSuggestRow);
  }

  function hideSuggestions() {
    var ac = document.getElementById(cfg.acListId);
    if (ac) ac.style.display = 'none';
  }

  function onCustNameInput() {
    if (_pickActive) return;
    var inp = document.getElementById(cfg.nameInputId);
    if (!inp) return;
    if (getLinkedId()) {
      var linkedName = '';
      if (cfg.useLinkedBar) {
        var linked = document.getElementById(cfg.linkedNameId);
        linkedName = linked ? (linked.textContent || '').trim().toLowerCase() : '';
      } else {
        linkedName = (_lastLinkedName || '').trim().toLowerCase();
      }
      var typed = (inp.value || '').trim().toLowerCase();
      if (typed && linkedName && typed !== linkedName) unlinkCustomer();
    }
    clearTimeout(_timer);
    var q = (inp.value || '').trim();
    if (q.length < 2) {
      hideSuggestions();
      if (cfg.onNameInput) cfg.onNameInput();
      return;
    }
    _timer = setTimeout(function () { refreshCustNameSuggestions(q); }, 220);
    if (cfg.onNameInput) cfg.onNameInput();
  }

  async function refreshCustNameSuggestions(q) {
    var ac = document.getElementById(cfg.acListId);
    var inp = document.getElementById(cfg.nameInputId);
    if (!ac || !inp) return;
    if ((inp.value || '').trim() !== q) return;
    ac.style.display = 'block';
    ac.innerHTML = '<div class="ogm-cust-name-ac-empty">Searching…</div>';
    var matches = await searchCustomersByQuery(q, 10);
    if ((inp.value || '').trim() !== q) return;
    if (!matches.length) {
      ac.innerHTML = '<div class="ogm-cust-name-ac-empty">No matches in Customer DB</div>';
      return;
    }
    var enc = function (v) { return (v || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'"); };
    ac.innerHTML = matches.map(function (c) {
      var meta = [c.phone, c.svcCity].filter(Boolean).join(' · ');
      return '<button type="button" class="ogm-cust-name-ac-item"' +
        ' onmousedown="event.preventDefault();ogmPickCustomerFromNameSuggest(\'' + enc(c.id) + '\',\'' + enc(c.name) + '\',\'' + enc(c.phone || '') + '\',\'' + enc(c.email || '') + '\',\'' + enc(c.svcStreet || '') + '\',\'' + enc(c.svcCity || '') + '\')">' +
        '<span class="ogm-cust-name-ac-n">' + escHtml(c.name) + '</span>' +
        '<span class="ogm-cust-name-ac-m">' + escHtml(meta) + '</span>' +
        '</button>';
    }).join('');
  }

  function pickCustomerFromNameSuggest(id, name, phone, email, street, city) {
    _pickActive = true;
    selectCustomer(id, name, phone, email, street, city);
    hideSuggestions();
    setTimeout(function () { _pickActive = false; }, 0);
  }

  function onCustNameBlur() {
    setTimeout(function () {
      if (!_pickActive) hideSuggestions();
    }, 180);
  }

  function setFieldVal(elId, val) {
    var el = document.getElementById(elId);
    if (el && val) {
      el.value = val;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }
  }

  function selectCustomer(id, name, phone, email, street, city) {
    setLinkedId(id);
    _lastLinkedName = name || '';
    _pickActive = true;
    hideSuggestions();
    if (cfg.useLinkedBar) {
      var linkedNameEl = document.getElementById(cfg.linkedNameId);
      var linkedPhoneEl = document.getElementById(cfg.linkedPhoneId);
      if (linkedNameEl) linkedNameEl.textContent = name;
      if (linkedPhoneEl) linkedPhoneEl.textContent = phone;
      var view = document.getElementById(cfg.linkedViewId);
      var bar = document.getElementById(cfg.linkedBarId);
      if (view) view.href = 'customer-db.php?cid=' + id;
      if (bar) bar.classList.add('shown');
    }
    setFieldVal(cfg.nameInputId, name);
    setFieldVal('cust-phone', phone);
    setFieldVal('cust-email', email);
    if (cfg.streetField) setFieldVal(cfg.streetField, street);
    if (cfg.cityField) setFieldVal(cfg.cityField, city);
    if (cfg.onAfterSelect) cfg.onAfterSelect(id, name);
    setTimeout(function () { _pickActive = false; }, 0);
  }

  function unlinkCustomer() {
    setLinkedId(null);
    _lastLinkedName = '';
    if (cfg.useLinkedBar) {
      var bar = document.getElementById(cfg.linkedBarId);
      if (bar) bar.classList.remove('shown');
    }
    if (cfg.onUnlink) cfg.onUnlink();
  }

  async function prefillFromCustomerId(cid) {
    if (!cid) return false;
    var c = null;
    try {
      var res = await fetch(
        'customers-api.php?action=get-customer&id=' + encodeURIComponent(cid),
        { credentials: 'same-origin' }
      );
      var j = await res.json();
      if (res.ok && j.ok && j.customer) c = j.customer;
    } catch (e) {}
    if (!c && global.storage) {
      try {
        var r = await global.storage.get('ogm-cust-' + cid);
        if (r && r.value) c = JSON.parse(r.value);
      } catch (err) {}
    }
    if (!c) return false;
    selectCustomer(
      cid,
      customerDisplayName(c),
      c.phone || '',
      c.email || '',
      c.svcStreet || '',
      c.svcCity || ''
    );
    return true;
  }

  function install(options) {
    if (options) {
      Object.keys(options).forEach(function (k) {
        if (options[k] !== undefined) cfg[k] = options[k];
      });
    }
    global.ogmOnCustNameInput = onCustNameInput;
    global.ogmOnCustNameBlur = onCustNameBlur;
    global.ogmSelectCustomer = selectCustomer;
    global.ogmPickCustomerFromNameSuggest = pickCustomerFromNameSuggest;
    global.ogmUnlinkCustomer = unlinkCustomer;
    global.ogmSearchCustomersByQuery = searchCustomersByQuery;
    global.ogmHideCustNameSuggestions = hideSuggestions;
  }

  global.OgmCustomerAutocomplete = {
    install: install,
    prefillFromCustomerId: prefillFromCustomerId,
    searchCustomersByQuery: searchCustomersByQuery,
    selectCustomer: selectCustomer,
    unlinkCustomer: unlinkCustomer
  };
})(typeof window !== 'undefined' ? window : globalThis);
