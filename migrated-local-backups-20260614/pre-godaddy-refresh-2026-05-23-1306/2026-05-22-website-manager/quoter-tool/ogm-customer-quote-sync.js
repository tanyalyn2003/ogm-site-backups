/**
 * Sync Customer DB profile from stone / glass quote saves
 * (customers-api.php?action=upsert-from-quote — contact fields plus optional quote rows).
 * Requires session login (ogm-server-sync.js) and optional OgmCustomerDedupe for prompts.
 */
(function (global) {
  'use strict';

  var API = 'customers-api.php';

  function digitsPhone(p) {
    return String(p || '').replace(/\D/g, '');
  }

  function normEmail(e) {
    return String(e || '').trim().toLowerCase();
  }

  function canAutoCreate(fields) {
    var d = digitsPhone(fields.phone);
    var em = normEmail(fields.email);
    var nm = String(fields.name || '').trim();
    var job = String(fields.jobName || fields.job || '').trim();
    if (d.length >= 7) return true;
    if (em.indexOf('@') > 0) return true;
    if (nm) return true;
    return !!job;
  }

  async function parseJson(res) {
    try {
      return await res.json();
    } catch (e) {
      return {};
    }
  }

  async function upsertFromQuoteApi(payload) {
    var res = await fetch(API + '?action=upsert-from-quote', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    var j = await parseJson(res);
    if (!res.ok || !j.ok) {
      var err = new Error((j && j.error) || 'upsert-from-quote failed (' + res.status + ')');
      err.status = res.status;
      throw err;
    }
    return j;
  }

  function fieldsFromStoneCustomer(c) {
    return {
      name: c.name || '',
      phone: c.phone || '',
      email: c.email || '',
      addr: c.addr || '',
      city: c.city || '',
      installAddr: c.installAddr || '',
      installCity: c.installCity || '',
      jobName: c.job || '',
      job: c.job || '',
      salesperson: c.salesperson || '',
      rep: c.salesperson || '',
    };
  }

  function fieldsFromGlassBuild(d) {
    var name = String(d.custName || '').trim();
    var job = String(d.jobName || '').trim();
    return {
      name: name || job,
      phone: d.custPhone || '',
      email: d.custEmail || '',
      addr: d.svcAddr || '',
      city: d.svcCity || '',
      svcStreet: d.svcAddr || '',
      svcCity: d.svcCity || '',
      jobName: job,
      job: job,
      salesperson: d.salesperson || '',
      rep: d.salesperson || '',
    };
  }

  async function maybeDedupeChoose(incoming, options) {
    if (!options.allowDedupePrompt) return null;
    if (typeof global.OgmCustomerDedupe === 'undefined') return null;
    var inc = incoming;
    if (!inc && options.incomingFactory) {
      inc = options.incomingFactory();
    }
    if (!inc) return null;
    var dupes = await global.OgmCustomerDedupe.findCandidates(inc, {
      excludeIds: options.excludeIds || [],
      limit: 8,
      minScore: global.OgmCustomerDedupe.DEFAULT_MIN_SCORE,
    });
    if (!dupes.length) return null;
    return global.OgmCustomerDedupe.promptChooseExisting({
      incomingLabel: inc.label,
      subtitle: options.dedupeSubtitle || '',
      candidates: dupes,
    });
  }

  /**
   * @param {Object} opts
   * @param {string|null} opts.linkedCustomerId
   * @param {Object} opts.fields — contact fields from quote form
   * @param {string} opts.source
   * @param {boolean} opts.allowDedupePrompt
   * @param {Function} [opts.incomingFactory] — for OgmCustomerDedupe
   * @param {Function} [opts.onLinked] — (id, customerSubset) after link
   */
  async function syncFromQuote(opts) {
    opts = opts || {};
    var fields = opts.fields || {};
    var linkedId = opts.linkedCustomerId || null;

    if (!linkedId && !canAutoCreate(fields)) {
      return { ok: true, skipped: true };
    }

    var chosenId = linkedId;
    if (!chosenId && opts.allowDedupePrompt) {
      try {
        var choice = await maybeDedupeChoose(null, opts);
        if (choice && choice !== '__new__') {
          chosenId = choice;
        }
      } catch (e) {
        console.warn('OgmCustomerQuoteSync dedupe:', e);
      }
    }

    var payload = {
      customerId: chosenId || undefined,
      fields: fields,
      quote: opts.quote || undefined,
      source: opts.source || 'Quoter — save',
    };

    var result;
    try {
      result = await upsertFromQuoteApi(payload);
    } catch (e) {
      if (e.status === 401) throw e;
      console.warn('OgmCustomerQuoteSync API:', e);
      return { ok: false, error: String(e.message || e) };
    }

    if (result.skipped) {
      return result;
    }

    var id = result.id;
    if (id && typeof opts.onLinked === 'function' && result.customer) {
      opts.onLinked(id, result.customer);
    }

    return { ok: true, id: id, created: !!result.created };
  }

  global.OgmCustomerQuoteSync = {
    syncFromQuote: syncFromQuote,
    fieldsFromStoneCustomer: fieldsFromStoneCustomer,
    fieldsFromGlassBuild: fieldsFromGlassBuild,
    canAutoCreate: canAutoCreate,
  };
})(typeof window !== 'undefined' ? window : globalThis);
