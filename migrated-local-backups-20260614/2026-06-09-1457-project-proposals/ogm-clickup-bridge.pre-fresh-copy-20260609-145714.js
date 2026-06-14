/**
 * OGM ClickUp Bridge  v3
 * ─────────────────────────────────────────────────────────────────────────────
 * Connects the Stone, Glass, and Shower quoters to Job Tracking / ClickUp.
 *
 * v3: Passes square footage, material, quote amount, lead source, and cabinet
 *     brand from the active quote into the Job Tracking New Job modal.
 * ─────────────────────────────────────────────────────────────────────────────
 */
(function () {
  'use strict';

  function readValue(id) {
    var el = document.getElementById(id);
    return el ? String(el.value || '').trim() : '';
  }

  function readMeta(name) {
    var el = document.querySelector('meta[name="' + name + '"]');
    return el ? String(el.getAttribute('content') || '').trim() : '';
  }

  function parseMoney(text) {
    var n = parseFloat(String(text || '').replace(/[^0-9.\-]/g, ''));
    return Number.isFinite(n) ? n : 0;
  }

  function getCustomerId() {
    return (
      (window._ogmLinkedCustomerId ? String(window._ogmLinkedCustomerId) : '') ||
      (window._ogmGlassLinkedCustomerId ? String(window._ogmGlassLinkedCustomerId) : '') ||
      (window.ogmCustomerId ? String(window.ogmCustomerId) : '') ||
      readValue('ogm-customer-id') ||
      readMeta('ogm-customer-id') ||
      ''
    );
  }

  function getQuoteNumber() {
    if (window.__ogmActiveQuoteId != null && window.__ogmActiveQuoteId !== '') {
      return String(window.__ogmActiveQuoteId).trim();
    }
    var meta = window.__ogmQuoteWorkflowMeta;
    if (meta && meta.quoteNumber != null && meta.quoteNumber !== '') {
      return String(meta.quoteNumber).trim();
    }
    if (window.ogmCurrentQuoteNumber) {
      return String(window.ogmCurrentQuoteNumber).trim();
    }
    var inv = document.getElementById('inv-num');
    if (inv) {
      var txt = String(inv.textContent || '').trim();
      if (txt && txt !== 'N/A' && txt !== '-') return txt;
    }
    return readValue('quote-number') || readValue('quoteNumber') || '';
  }

  function resolveClickUpRepKey(label) {
    var raw = String(label || '').trim();
    if (!raw) return '';
    var n = raw.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
    if (n.indexOf('tanya') >= 0 || n.indexOf('watkins') >= 0) return 'Tanya';
    if (n.indexOf('sedberry') >= 0 || (n.indexOf('sed') >= 0 && n.indexOf('hunter') < 0)) return 'Sed';
    if (n.indexOf('austen') >= 0 || n.indexOf('parlett') >= 0) return 'Austen';
    if (n.indexOf('brennan') >= 0 || n.indexOf('binkley') >= 0) return 'Brennan';
    var first = raw.split(/\s+/)[0] || '';
    if (/^tanya$/i.test(first)) return 'Tanya';
    if (/^sed$/i.test(first)) return 'Sed';
    if (/^austen$/i.test(first)) return 'Austen';
    if (/^brennan$/i.test(first)) return 'Brennan';
    return '';
  }

  function getRepShortName(rep) {
    return resolveClickUpRepKey(rep) || '';
  }

  function showBridgeToast(message) {
    if (typeof window.showToast === 'function') {
      try { window.showToast(message); return; } catch (_) {}
    }
    try { console.info('[OGM Bridge]', message); } catch (_) {}
  }

  function mapMaterialKey(label) {
    var s = String(label || '').trim().toLowerCase();
    if (!s) return '';
    var keys = ['quartz', 'granite', 'marble', 'quartzite', 'cultured_marble', 'sintered_stone'];
    var i;
    for (i = 0; i < keys.length; i++) {
      var k = keys[i];
      var spaced = k.replace(/_/g, ' ');
      if (s === k || s === spaced || s.indexOf(spaced) >= 0 || s.indexOf(k) >= 0) return k;
    }
    if (s.indexOf('cultured') >= 0) return 'cultured_marble';
    if (s.indexOf('sintered') >= 0) return 'sintered_stone';
    return '';
  }

  function mapLeadSourceKey(label) {
    var s = String(label || '').trim().toLowerCase().replace(/[\s\/-]+/g, '_');
    if (!s) return '';
    var keys = [
      'walk_in', 'referral', 'repeat_client', 'website',
      'google_ads', 'social_media', 'builder_contractor'
    ];
    var i;
    for (i = 0; i < keys.length; i++) {
      if (s === keys[i] || s.indexOf(keys[i].replace(/_/g, '')) >= 0) return keys[i];
    }
    if (s.indexOf('walk') >= 0 && s.indexOf('in') >= 0) return 'walk_in';
    if (s.indexOf('repeat') >= 0) return 'repeat_client';
    if (s.indexOf('builder') >= 0 || s.indexOf('contractor') >= 0) return 'builder_contractor';
    if (s.indexOf('google') >= 0) return 'google_ads';
    if (s.indexOf('social') >= 0) return 'social_media';
    if (s.indexOf('refer') >= 0) return 'referral';
    return '';
  }

  function getCustomFieldValue(patterns) {
    var catalog = window.customerCatalog;
    if (!catalog || !Array.isArray(catalog.customFields)) return '';
    var i, j;
    for (i = 0; i < catalog.customFields.length; i++) {
      var f = catalog.customFields[i];
      var lbl = String(f.label || '').toLowerCase();
      for (j = 0; j < patterns.length; j++) {
        if (lbl.indexOf(patterns[j]) >= 0) {
          var el = document.getElementById('cust-custom-' + f.id);
          var val = el ? String(el.value || '').trim() : '';
          if (val) return val;
        }
      }
    }
    return '';
  }

  /** Pull SF, material, $, lead source, etc. from the live stone quote. */
  function buildStoneBridgeExtras() {
    var extras = {
      squareFootage: '',
      material: '',
      quoteAmount: '',
      leadSource: '',
      cabinetBrand: '',
      cabinetInstallDate: '',
      countertopInstallDate: '',
      notesDetail: ''
    };
    var detail = [];

    if (typeof window.buildQuoteSummary === 'function') {
      try {
        var summary = window.buildQuoteSummary();
        if (summary && summary.totalSF > 0) {
          extras.squareFootage = Math.round(summary.totalSF * 10) / 10;
        }
        if (summary && summary.stoneGroups && summary.stoneGroups.length) {
          var groups = summary.stoneGroups.slice().sort(function (a, b) {
            return (b.totalSF || 0) - (a.totalSF || 0);
          });
          var primary = groups[0];
          if (primary && primary.stone) {
            var matKey = mapMaterialKey(primary.stone.t || primary.stone.n);
            if (matKey) extras.material = matKey;
            detail.push(
              'Material: ' + [primary.stone.n, primary.stone.t, primary.stone.tk].filter(Boolean).join(' · ')
            );
          }
          if (groups.length > 1) {
            detail.push(
              'All materials: ' + groups.map(function (g) {
                return (g.stone && g.stone.n ? g.stone.n : 'Stone') + ' (' + (g.totalSF || 0).toFixed(1) + ' sf)';
              }).join(', ')
            );
          }
        }
        if (typeof window.getQuoteData === 'function') {
          var qd = window.getQuoteData();
          if (qd && qd.grand > 0) {
            extras.quoteAmount = Math.round(qd.grand * 100) / 100;
          }
        }
      } catch (err) {
        console.warn('[OGM Bridge] buildQuoteSummary failed', err);
      }
    }

    if (!extras.squareFootage) {
      var sfEl = document.getElementById('s-totalSF');
      if (sfEl) {
        var m = String(sfEl.textContent || '').match(/([\d.]+)/);
        if (m) extras.squareFootage = parseFloat(m[1]);
      }
    }

    if (!extras.quoteAmount) {
      var grandEl = document.getElementById('tot-grand');
      if (grandEl) extras.quoteAmount = parseMoney(grandEl.textContent);
      var invAmt = document.getElementById('inv-quote-amount');
      if (!extras.quoteAmount && invAmt) extras.quoteAmount = parseFloat(invAmt.value) || 0;
    }

    var leadRaw = getCustomFieldValue(['lead source', 'lead', 'referral', 'how did you hear']);
    if (leadRaw) {
      extras.leadSource = mapLeadSourceKey(leadRaw) || '';
      if (!extras.leadSource) detail.push('Lead source: ' + leadRaw);
    }

    var cabinet = getCustomFieldValue(['cabinet brand', 'cabinetry', 'cabinet']);
    if (cabinet) extras.cabinetBrand = cabinet;

    var ctDate = readValue('inv-jt-date');
    if (ctDate) extras.countertopInstallDate = ctDate;

    var job = readValue('job-name');
    if (job) detail.push('Scope: ' + job);
    if (extras.squareFootage) detail.push('Total SF: ' + extras.squareFootage);
    if (extras.quoteAmount) {
      detail.push('Quoted: $' + Number(extras.quoteAmount).toLocaleString(undefined, { maximumFractionDigits: 0 }));
    }

    extras.notesDetail = detail.join('\n');
    return extras;
  }

  function buildGlassBridgeExtras() {
    var extras = { quoteAmount: '', notesDetail: '' };
    var items = Array.isArray(window.lineItems) ? window.lineItems : [];
    var sub = items.reduce(function (s, i) { return s + (parseFloat(i.amount) || 0); }, 0);
    var grandEl = document.getElementById('tot-grand');
    var grand = grandEl ? parseMoney(grandEl.textContent) : 0;
    extras.quoteAmount = grand > 0 ? grand : (sub > 0 ? sub : 0);
    if (items.length) {
      extras.notesDetail = items.map(function (i) {
        var amt = parseFloat(i.amount) || 0;
        return (i.description || 'Line item') + (amt > 0 ? ' — $' + amt.toLocaleString() : '');
      }).join('\n');
    }
    return extras;
  }

  function openJobTrackingLoadingWindow() {
    var w = null;
    try {
      w = window.open('about:blank', '_blank');
      if (w) {
        w.document.write('<!doctype html><title>Opening Job Tracking</title><body style="font-family:system-ui;padding:24px;background:#111827;color:#f8fafc">Saving quote and opening Job Tracking...</body>');
        w.document.close();
      }
    } catch (_) {}
    return w;
  }

  function openJobTrackingWithPayload(payload, targetWindow) {
    try {
      localStorage.setItem('ogm-clickup-pending-job', JSON.stringify(payload));
    } catch (_) {}
    if (targetWindow && !targetWindow.closed) {
      try {
        targetWindow.location.href = 'job-tracking.php';
        return;
      } catch (_) {}
    }
    window.open('job-tracking.php', '_blank', 'noopener');
  }

  async function saveQuoteBeforeJobTracking() {
    if (typeof window.saveQuoteStateBeforeViewer === 'function') {
      return window.saveQuoteStateBeforeViewer();
    }
    if (
      typeof window.getQuoteFilePayload === 'function' &&
      typeof window.ogmAssignQuoteNumberToPayload === 'function' &&
      typeof window.quoteServerSave === 'function'
    ) {
      var payload = window.getQuoteFilePayload();
      window.ogmAssignQuoteNumberToPayload(payload);
      var res = await window.quoteServerSave(payload);
      if (res && res.ok) {
        if (res.id != null && res.id !== '') {
          payload.quoteNumber = String(res.id);
          window.ogmAssignQuoteNumberToPayload(payload);
        }
        if (typeof window.syncQuoteWorkflowMetaFromPayload === 'function') {
          window.syncQuoteWorkflowMetaFromPayload(payload);
        }
        if (typeof window.ogmSaveQuoteSummary === 'function') {
          await window.ogmSaveQuoteSummary(payload);
        }
        if (typeof window.quoteDbSetStatus === 'function') {
          window.quoteDbSetStatus('Saved before Job Tracking · Quote #' + (res.id || payload.quoteNumber || ''));
        }
        if (typeof window.quoteDbRefresh === 'function') window.quoteDbRefresh();
        if (typeof window.updateWorkflowPhaseBadge === 'function') window.updateWorkflowPhaseBadge();
        if (typeof window.ogmFinalizeQuoteSaveBaseline === 'function') window.ogmFinalizeQuoteSaveBaseline();
        return true;
      }
    }
    return false;
  }

  function mergeNotes(base, detail) {
    var parts = [];
    if (base) parts.push(base);
    if (detail) parts.push(detail);
    return parts.join('\n\n').trim();
  }

  function getJobBoardAccountingPayload() {
    var gate = window.ogmJobBoardGate;
    if (!gate || typeof gate.getAccountingPayload !== 'function') return {};
    try {
      return gate.getAccountingPayload() || {};
    } catch (err) {
      console.warn('[OGM Bridge] accounting payload unavailable', err);
      return {};
    }
  }

  function jobBoardAccountingGateAllows(productLabel) {
    var gate = window.ogmJobBoardGate;
    if (!gate || typeof gate.hasRequiredAccountingStep !== 'function') return true;
    var ok = false;
    try {
      ok = !!gate.hasRequiredAccountingStep();
    } catch (err) {
      console.warn('[OGM Bridge] accounting gate failed', err);
    }
    if (ok) return true;
    if (typeof gate.openRequiredAccountingPrompt === 'function') {
      try {
        gate.openRequiredAccountingPrompt({ productLabel: productLabel || '' });
      } catch (err2) {
        console.warn('[OGM Bridge] accounting prompt failed', err2);
        alert('Create a deposit receipt first, even if the balance is zero, before sending this job to Job Tracking.');
      }
    } else {
      alert('Create a deposit receipt first, even if the balance is zero, before sending this job to Job Tracking.');
    }
    return false;
  }

  function buildAccountingNotesDetail(accounting) {
    if (!accounting || typeof accounting !== 'object') return '';
    var lines = [];
    if (accounting.depositReceiptNumber) {
      lines.push('Deposit receipt: ' + accounting.depositReceiptNumber);
    }
    if (accounting.depositAmount != null && accounting.depositAmount !== '') {
      lines.push('Deposit amount: $' + Number(accounting.depositAmount || 0).toFixed(2));
    }
    if (accounting.invoiceNumber) {
      lines.push('Invoice: ' + accounting.invoiceNumber);
    }
    if (accounting.qbCustomerStatus) {
      lines.push('QuickBooks customer status: ' + accounting.qbCustomerStatus);
    }
    if (accounting.customerAddressMissing) {
      lines.push('Accounting warning: customer address missing.');
    }
    return lines.length ? 'Accounting step\n' + lines.join('\n') : '';
  }

  function getStoneJobLocationAddress() {
    var installStreet = readValue('cust-install-addr');
    var installCity = readValue('cust-install-city');
    var billingStreet = readValue('cust-addr');
    var billingCity = readValue('cust-city');
    var usingInstall = !!(installStreet || installCity);
    var street = usingInstall ? installStreet : billingStreet;
    var city = usingInstall ? installCity : billingCity;
    var jobLocationAddress = [street, city].filter(Boolean).join(' ').trim();
    var billingAddress = [billingStreet, billingCity].filter(Boolean).join(' ').trim();
    return {
      address: jobLocationAddress,
      jobLocationAddress: jobLocationAddress,
      billingAddress: billingAddress,
      usedCustomerAddressFallback: !usingInstall && !!billingAddress
    };
  }

  /* ── Stone Quoter ─────────────────────────────────────────────────────────── */

  window.sendToClickUp = async function sendToClickUp() {
    if (!jobBoardAccountingGateAllows('Stone')) return;
    var accounting = getJobBoardAccountingPayload();
    var jobWin = openJobTrackingLoadingWindow();
    var name = readValue('cust-name');
    var phone = readValue('cust-phone');
    var jobAddr = getStoneJobLocationAddress();
    var job = readValue('job-name');
    var salesperson = readValue('salesperson');
    var rep = getRepShortName(salesperson);
    var notes = readValue('notes');
    var quoteNumber = getQuoteNumber();
    var customerId = getCustomerId();
    var extras = buildStoneBridgeExtras();

    var taskName = name || job || 'New Countertop Job';
    if (name && job) taskName = name + ' \u2014 ' + job;

    try {
      showBridgeToast('Saving quote to Customer DB before Job Tracking...');
      await saveQuoteBeforeJobTracking();
      quoteNumber = getQuoteNumber() || quoteNumber;
      customerId = getCustomerId() || customerId;
    } catch (err) {
      console.warn('[OGM Bridge] save before Job Tracking failed', err);
      showBridgeToast('Opening Job Tracking — Customer DB save may need checking');
    }

    openJobTrackingWithPayload({
      customerName: taskName,
      phone: phone,
      address: jobAddr.address,
      addressLabel: 'Job Location Address',
      addressKind: 'job_location',
      jobLocationAddress: jobAddr.jobLocationAddress,
      billingAddress: jobAddr.billingAddress,
      usedCustomerAddressFallback: jobAddr.usedCustomerAddressFallback,
      jobType: 'counter',
      salesperson: salesperson,
      rep: rep,
      notes: mergeNotes(mergeNotes(notes || 'Countertop job from Stone Quoter', extras.notesDetail), buildAccountingNotesDetail(accounting)),
      naKey: 'quote',
      quoteNumber: quoteNumber,
      customerId: customerId,
      depositReceiptNumber: accounting.depositReceiptNumber || '',
      depositAmount: accounting.depositAmount,
      depositRecordedAt: accounting.depositRecordedAt || '',
      invoiceNumber: accounting.invoiceNumber || '',
      invoiceDate: accounting.invoiceDate || '',
      qbCustomerStatus: accounting.qbCustomerStatus || '',
      linkedCustomerId: accounting.linkedCustomerId || customerId,
      squareFootage: extras.squareFootage,
      material: extras.material,
      quoteAmount: extras.quoteAmount,
      leadSource: extras.leadSource,
      cabinetBrand: extras.cabinetBrand,
      cabinetInstallDate: extras.cabinetInstallDate,
      countertopInstallDate: extras.countertopInstallDate
    }, jobWin);

    showBridgeToast('Opening Job Tracking — quote details pre-filled');
  };

  /* ── Glass Quoter ─────────────────────────────────────────────────────────── */

  window.sendGlassToClickUp = function sendGlassToClickUp() {
    if (!jobBoardAccountingGateAllows('Glass')) return;
    var accounting = getJobBoardAccountingPayload();
    var name = readValue('cust-name');
    var phone = readValue('cust-phone');
    var addr = [readValue('svc-addr'), readValue('svc-city')].filter(Boolean).join(' ').trim();
    var job = readValue('job-name');
    var salesperson = readValue('salesperson');
    var rep = getRepShortName(salesperson);
    var notes = readValue('notes');
    var quoteNumber = getQuoteNumber();
    var customerId = getCustomerId();
    var extras = buildGlassBridgeExtras();

    var items = Array.isArray(window.lineItems) ? window.lineItems : [];
    var firstItem = items[0] && items[0].description ? String(items[0].description) : '';
    var isShower = /shower|tub|enclos/i.test(firstItem);

    var taskName = name || job || 'New Glass Job';
    if (name && job) taskName = name + ' \u2014 ' + job;

    openJobTrackingWithPayload({
      customerName: taskName,
      phone: phone,
      address: addr,
      jobType: isShower ? 'shower' : 'glass',
      salesperson: salesperson,
      rep: rep,
      notes: mergeNotes(mergeNotes(notes || firstItem || 'Glass job from Glass Quoter', extras.notesDetail), buildAccountingNotesDetail(accounting)),
      naKey: 'quote',
      quoteNumber: quoteNumber,
      customerId: customerId,
      depositReceiptNumber: accounting.depositReceiptNumber || '',
      depositAmount: accounting.depositAmount,
      depositRecordedAt: accounting.depositRecordedAt || '',
      invoiceNumber: accounting.invoiceNumber || '',
      invoiceDate: accounting.invoiceDate || '',
      qbCustomerStatus: accounting.qbCustomerStatus || '',
      linkedCustomerId: accounting.linkedCustomerId || customerId,
      quoteAmount: extras.quoteAmount
    });

    showBridgeToast('Opening Job Tracking — quote details pre-filled');
  };

  /* ── Shower Builder ───────────────────────────────────────────────────────── */

  window.sendShowerToClickUp = function sendShowerToClickUp() {
    var name = readValue('cust-name');
    var phone = readValue('cust-phone');
    var addr = [readValue('cust-addr'), readValue('cust-city')].filter(Boolean).join(' ').trim();
    var salesperson = readValue('salesperson');
    var rep = getRepShortName(salesperson);
    var notes = readValue('notes');
    var quoteNumber = getQuoteNumber();
    var customerId = getCustomerId();

    var taskName = name || 'New Shower Job';

    openJobTrackingWithPayload({
      customerName: taskName,
      phone: phone,
      address: addr,
      jobType: 'shower',
      salesperson: salesperson,
      rep: rep,
      notes: notes || 'Shower job from Shower Builder',
      naKey: 'quote',
      quoteNumber: quoteNumber,
      customerId: customerId
    });

    showBridgeToast('Opening Job Tracking — job pre-filled');
  };

})();
