/**
 * Server-backed storage for Hub, Customer DB, and quoter OGM bridge.
 * Implements the window.storage contract (list / get / set / delete) used by those pages.
 * Requires PHP session login (same as quotes-api.php) — open index.php and sign in first.
 */
(function () {
  'use strict';

  var API = 'customers-api.php';

  function invalidateCustomers() {
    _custById = null;
  }
  function invalidateSummaries() {
    _sumByQuote = null;
  }

  var _custById = null;
  var _sumByQuote = null;

  async function parseJsonResponse(res) {
    var t = await res.text();
    try {
      return JSON.parse(t);
    } catch (e) {
      return {};
    }
  }

  async function loadAllCustomers() {
    var res = await fetch(API + '?action=list-customers', { credentials: 'same-origin' });
    var j = await parseJsonResponse(res);
    _custById = new Map();
    if (!res.ok || !j.ok || !Array.isArray(j.customers)) {
      return;
    }
    for (var i = 0; i < j.customers.length; i++) {
      var c = j.customers[i];
      if (c && c.id) {
        _custById.set(String(c.id), c);
      }
    }
  }

  async function loadAllSummaries() {
    var res = await fetch(API + '?action=list-quote-summaries', { credentials: 'same-origin' });
    var j = await parseJsonResponse(res);
    _sumByQuote = new Map();
    if (!res.ok || !j.ok || !Array.isArray(j.summaries)) {
      return;
    }
    for (var i = 0; i < j.summaries.length; i++) {
      var s = j.summaries[i];
      if (s != null && s.quoteNumber != null && s.quoteNumber !== '') {
        _sumByQuote.set(String(s.quoteNumber), s);
      }
    }
  }

  async function fetchOneCustomer(id) {
    var res = await fetch(API + '?action=get-customer&id=' + encodeURIComponent(id), {
      credentials: 'same-origin',
    });
    var j = await parseJsonResponse(res);
    if (!res.ok || !j.ok || !j.customer) {
      return null;
    }
    if (!_custById) {
      _custById = new Map();
    }
    _custById.set(String(id), j.customer);
    return j.customer;
  }

  async function fetchOneSummary(qn) {
    var res = await fetch(API + '?action=get-quote-summary&quoteNumber=' + encodeURIComponent(qn), {
      credentials: 'same-origin',
    });
    var j = await parseJsonResponse(res);
    if (!res.ok || !j.ok || !j.summary) {
      return null;
    }
    if (!_sumByQuote) {
      _sumByQuote = new Map();
    }
    _sumByQuote.set(String(qn), j.summary);
    return j.summary;
  }

  window.storage = {
    list: async function (prefix) {
      try {
        if (prefix === 'ogm-cust-') {
          await loadAllCustomers();
          if (!_custById) {
            return { keys: [] };
          }
          var keys = [];
          _custById.forEach(function (_c, id) {
            keys.push('ogm-cust-' + id);
          });
          return { keys: keys };
        }
        if (prefix === 'ogm-quote-') {
          await loadAllSummaries();
          if (!_sumByQuote) {
            return { keys: [] };
          }
          var qkeys = [];
          _sumByQuote.forEach(function (_s, qn) {
            qkeys.push('ogm-quote-' + qn);
          });
          return { keys: qkeys };
        }
      } catch (e) {
        console.warn('ogm-server-sync list:', e);
      }
      return { keys: [] };
    },

    get: async function (key) {
      try {
        if (typeof key === 'string' && key.indexOf('ogm-cust-') === 0) {
          var cid = key.slice('ogm-cust-'.length);
          if (!_custById) {
            await loadAllCustomers();
          }
          var c = _custById && _custById.get(cid);
          if (!c) {
            c = await fetchOneCustomer(cid);
          }
          return { value: c ? JSON.stringify(c) : null };
        }
        if (typeof key === 'string' && key.indexOf('ogm-quote-') === 0) {
          var qn = key.slice('ogm-quote-'.length);
          if (!_sumByQuote) {
            await loadAllSummaries();
          }
          var s = _sumByQuote && _sumByQuote.get(qn);
          if (!s) {
            s = await fetchOneSummary(qn);
          }
          return { value: s ? JSON.stringify(s) : null };
        }
      } catch (e) {
        console.warn('ogm-server-sync get:', e);
      }
      return { value: null };
    },

    set: async function (key, value) {
      if (typeof key !== 'string') {
        return;
      }
      if (key.indexOf('ogm-cust-') === 0) {
        var cust = JSON.parse(value);
        var res = await fetch(API + '?action=save-customer', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ customer: cust }),
        });
        var j = await parseJsonResponse(res);
        if (!res.ok || !j.ok) {
          throw new Error((j && j.error) || 'save-customer failed');
        }
        invalidateCustomers();
        return;
      }
      if (key.indexOf('ogm-quote-') === 0) {
        var summary = JSON.parse(value);
        var res2 = await fetch(API + '?action=save-quote-summary', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ summary: summary }),
        });
        var j2 = await parseJsonResponse(res2);
        if (!res2.ok || !j2.ok) {
          throw new Error((j2 && j2.error) || 'save-quote-summary failed');
        }
        invalidateSummaries();
        return;
      }
    },

    delete: async function (key) {
      if (typeof key !== 'string' || key.indexOf('ogm-cust-') !== 0) {
        return;
      }
      var id = key.slice('ogm-cust-'.length);
      var res = await fetch(API + '?action=delete-customer&id=' + encodeURIComponent(id), {
        credentials: 'same-origin',
      });
      var j = await parseJsonResponse(res);
      if (!res.ok || !j.ok) {
        throw new Error((j && j.error) || 'delete-customer failed');
      }
      invalidateCustomers();
    },
  };
})();
