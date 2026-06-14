/**
 * Glass Quoter — deposit + invoice workflow (parity with Stone Quoter).
 */
(function (global) {
  'use strict';

  const WF = global.OgmInvoiceWorkflow;
  const QB_MISC_ITEM = 'Glass Installation';
  const QB_REAL_PROPERTY = 'Glass tax Real Property';
  let meta = {};
  let _ogmGlassInvoiceBuild = null;

  const DEFAULT_EMAIL_COPY = {
    layoutStyle: 'classic',
    subjectTemplate: 'Invoice {{invoiceNumber}} - {{customerName}}',
    introTemplate: 'Hi {{customerName}},\n\nPlease find invoice {{invoiceNumber}} for {{amountDue}}. A copy of the invoice is below and the PDF is attached.',
    closingTemplate: 'Thank you,\nOlive Glass & Marble',
    pdfNote: 'A PDF copy of this invoice is attached.'
  };

  function $(id) { return document.getElementById(id); }
  function hooks() { return global.ogmGlassInvoiceHooks || {}; }

  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function fmtMoney(n) {
    const v = parseFloat(n) || 0;
    return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function fmtDisplay(v) {
    return fmtMoney(v);
  }

  function loadEmailCopy() {
    try {
      const raw = localStorage.getItem('ogm-glass-invoice-email-copy');
      if (raw) return Object.assign({}, DEFAULT_EMAIL_COPY, JSON.parse(raw));
    } catch (e) {}
    return Object.assign({}, DEFAULT_EMAIL_COPY);
  }

  function saveEmailCopy(copy) {
    try { localStorage.setItem('ogm-glass-invoice-email-copy', JSON.stringify(copy)); } catch (e) {}
  }

  function syncMetaFromSummary(sum) {
    if (!sum || typeof sum !== 'object') { meta = {}; return; }
    meta = {
      invoiceNumber: sum.invoiceNumber || '',
      invoiceDate: sum.invoiceDate || '',
      invoiceTotal: sum.invoiceTotal,
      convertedAt: sum.convertedAt || '',
      depositReceiptNumber: sum.depositReceiptNumber || '',
      depositAmount: sum.depositAmount,
      depositRecordedAt: sum.depositRecordedAt || '',
      workflowPhase: sum.workflowPhase || '',
      cosTotal: sum.cosTotal,
      installDate: sum.installDate || '',
      hrsEst: sum.hrsEst,
      hrsAct: sum.hrsAct,
      glassWorkHours: sum.glassWorkHours,
      jobCode: sum.jobCode || '',
      taxMode: sum.taxMode || '',
      savedAt: sum.savedAt || ''
    };
  }

  function updateWorkflowBadge() {
    const el = $('inv-status');
    if (!el) return;
    const phase = WF ? WF.deriveWorkflowPhase(meta) : 'Draft';
    const h = hooks();
    if (phase === 'Invoiced') {
      el.textContent = 'Invoiced' + (meta.invoiceNumber ? ' · ' + meta.invoiceNumber : '');
      el.className = 'status-pill sp-saved';
    } else if (phase === 'Deposit') {
      el.textContent = 'Deposit' + (meta.depositReceiptNumber ? ' · ' + meta.depositReceiptNumber : '');
      el.className = 'status-pill sp-saved';
    } else if (phase === 'Quoted' || (h.isSaved && h.isSaved())) {
      el.textContent = 'Saved';
      el.className = 'status-pill sp-saved';
    } else {
      el.textContent = 'Draft';
      el.className = 'status-pill sp-draft';
    }
  }

  /** Map Glass buildData() → stone-style quoteData for invoice/email/QB */
  function normalizeQuoteData(raw) {
    const d = raw || {};
    const lines = [];
    (d.lineItems || []).forEach(function (item) {
      const amt = parseFloat(item.amount) || 0;
      if (amt <= 0) return;
      const label = [item.description, item.glassType].filter(Boolean).join(' — ') || 'Glass line item';
      lines.push([label, fmtMoney(amt)]);
    });
    if (d.ccOn && (parseFloat(d.ccAmt) || 0) > 0) {
      lines.push(['Credit card fee (3%)', fmtMoney(d.ccAmt)]);
    }
    if ((parseFloat(d.taxAmt) || 0) > 0) {
      lines.push(['NC Sales Tax (7%)', fmtMoney(d.taxAmt)]);
    }
    const grand = parseFloat(d.total) || 0;
    const dep = parseFloat(d.depositAmt) || 0;
    return {
      invNum: d.invNum || '',
      name: d.custName || d.jobName || 'Customer',
      job: d.jobName || '—',
      phone: d.custPhone || '—',
      email: d.custEmail || '',
      addr: d.svcAddr || '',
      city: d.svcCity || '',
      installAddr: d.svcAddr || '',
      installCity: d.svcCity || '',
      sp: d.salesperson || '',
      date: d.date || '',
      notes: d.notes || '',
      grand: grand,
      deposit: dep,
      balance: Math.max(0, grand - dep),
      depositPct: d.depositPct || 60,
      lines: lines,
      subtotal: parseFloat(d.subtotal) || 0
    };
  }

  function itemNameForLabel(label) {
    const s = String(label || '').trim();
    return s.length > 31 ? s.slice(0, 31) : (s || QB_MISC_ITEM);
  }

  function parseInvoiceAmount(v) {
    if (typeof v === 'number') return Number.isFinite(v) ? v : 0;
    const n = parseFloat(String(v || '').replace(/[^0-9.\-]/g, ''));
    return Number.isFinite(n) ? n : 0;
  }

  function buildInvoiceLineItems(d, amountOverride) {
    const lines = [];
    if (amountOverride !== null && amountOverride !== undefined && Number(amountOverride) < 0.004) {
      return [];
    }
    (d.lines || []).forEach(function (entry) {
      const label = entry[0] != null ? String(entry[0]) : '';
      const amount = parseInvoiceAmount(entry[1]);
      if (amount < 0.005) return;
      lines.push({
        item: itemNameForLabel(label),
        description: label,
        qty: 1,
        rate: amount,
        amount: amount,
        taxCode: 'Tax'
      });
    });
    if (lines.length) return lines;
    if (amountOverride !== null && amountOverride !== undefined) return [];
    const fallback = parseFloat(d.grand) || 0;
    if (fallback > 0.004) {
      const label = (d.job && d.job !== '—') ? String(d.job).trim() : QB_MISC_ITEM;
      return [{ item: itemNameForLabel(label), description: label, qty: 1, rate: fallback, amount: fallback, taxCode: 'Tax' }];
    }
    return [];
  }

  function readInvoiceJobAmountField() {
    const el = $('inv-quote-amount');
    if (!el || String(el.value || '').trim() === '') return null;
    const n = parseFloat(el.value);
    return Number.isFinite(n) ? Math.round(n * 100) / 100 : null;
  }

  function readInvoiceTaxHandling(invoiceTotal) {
    const exempt = $('inv-tax-exempt') && $('inv-tax-exempt').checked;
    const backout = $('inv-tax-backout') && $('inv-tax-backout').checked;
    const total = Math.max(0, parseFloat(invoiceTotal) || 0);
    if (exempt) {
      return { mode: 'exempt', taxAmount: 0, taxBase: total, reason: ($('inv-tax-exempt-reason') && $('inv-tax-exempt-reason').value) || '' };
    }
    if (backout || total > 0) {
      const base = Math.round((total / 1.07) * 100) / 100;
      const taxAmount = Math.max(0, Math.round((total - base) * 100) / 100);
      return { mode: 'backout', taxAmount: taxAmount, taxBase: base, reason: '' };
    }
    return { mode: 'cos', taxAmount: 0, taxBase: total, reason: '' };
  }

  function buildBackedOutTaxLines(inv) {
    const base = Math.max(0, Math.round((Number(inv.taxBase) || 0) * 100) / 100);
    if (base < 0.004) return [];
    return [{
      item: QB_REAL_PROPERTY,
      description: QB_REAL_PROPERTY + ' — tax backed out of invoice total',
      qty: 1,
      rate: base,
      amount: base,
      taxCode: 'Tax'
    }];
  }

  function getInvoiceExportLines(inv) {
    if (!inv) return [];
    if (inv.taxMode === 'exempt') {
      return (inv.lines || []).map(function (l) { return Object.assign({}, l, { taxCode: 'Non' }); });
    }
    if (inv.taxMode === 'backout') return buildBackedOutTaxLines(inv);
    return inv.lines || [];
  }

  function validateInvoiceForFinal(inv) {
    if (!inv) return false;
    if (inv.taxMode === 'exempt' && !String(inv.taxExemptReason || '').trim()) {
      alert('Tax exempt invoices require a memo/reason before saving or export.');
      const el = $('inv-tax-exempt-reason') || $('inv-memo');
      if (el && el.focus) el.focus();
      return false;
    }
    return true;
  }

  function buildInvoicePayload() {
    const h = hooks();
    const raw = h.getBuildData ? h.getBuildData() : {};
    const d = normalizeQuoteData(raw);
    const amountOverride = readInvoiceJobAmountField();
    const lines = buildInvoiceLineItems(d, amountOverride);
    const lineSum = lines.reduce(function (s, l) { return s + (Number(l.amount) || 0); }, 0);
    let depositApplied = 0;
    const depEl = $('inv-deposit');
    if (depEl && String(depEl.value || '').trim() !== '') {
      const p = parseFloat(depEl.value);
      if (Number.isFinite(p) && p > 0) depositApplied = Math.round(p * 100) / 100;
    }
    const jobAmount = parseFloat(d.grand) || 0;
    let totalRounded = amountOverride !== null
      ? amountOverride
      : Math.max(0, Math.round(Math.max(lineSum, jobAmount) * 100) / 100);
    depositApplied = Math.min(depositApplied, totalRounded);
    const balanceDue = Math.max(0, Math.round((totalRounded - depositApplied) * 100) / 100);
    const taxHandling = readInvoiceTaxHandling(totalRounded);
    const matEl = $('inv-material-cost');
    const cosTotal = matEl ? (parseFloat(matEl.value) || 0) : (parseFloat(d.subtotal) || 0);
    const memoBase = ($('inv-memo') && $('inv-memo').value || '').trim();
    const memo = taxHandling.exempt && taxHandling.reason
      ? [memoBase, 'Tax exempt: ' + taxHandling.reason].filter(Boolean).join(' | ')
      : memoBase;
    const inv = {
      quoteData: d,
      invoiceNumber: ($('inv-number') && $('inv-number').value || '').trim(),
      invoiceDate: ($('inv-date') && $('inv-date').value) || (WF ? WF.invoiceFmtDate(new Date()) : ''),
      terms: ($('inv-terms') && $('inv-terms').value) || 'Net 30',
      dueDate: ($('inv-due') && $('inv-due').value) || '',
      poNumber: ($('inv-po') && $('inv-po').value || '').trim(),
      memo: memo,
      lines: lines,
      total: totalRounded,
      depositApplied: depositApplied,
      balanceDue: balanceDue,
      cosTotal: cosTotal,
      taxMode: taxHandling.mode,
      taxBackoutAmount: taxHandling.taxAmount,
      taxBase: taxHandling.taxBase,
      taxExemptReason: taxHandling.reason,
      _useMiscSingleLine: taxHandling.mode === 'backout',
      miscLineItem: QB_MISC_ITEM,
      jobCode: ($('inv-job-code') && $('inv-job-code').value) || 'T1.Inv.Instal',
      installDate: ($('inv-install-date') && $('inv-install-date').value) || '',
      hrsEst: parseFloat($('inv-hrs-est') && $('inv-hrs-est').value) || 0,
      hrsAct: parseFloat($('inv-hrs-act') && $('inv-hrs-act').value) || 0
    };
    _ogmGlassInvoiceBuild = inv;
    return inv;
  }

  function getInvoiceForExport() {
    if (!_ogmGlassInvoiceBuild) buildInvoicePayload();
    return _ogmGlassInvoiceBuild;
  }

  function invoiceFmtDateMDY(d) {
    if (!(d instanceof Date) || Number.isNaN(d.getTime())) return '';
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return m + '/' + day + '/' + y;
  }

  function invoiceCurrencyToString(amount) {
    const n = Number(amount);
    return Number.isFinite(n) ? n.toFixed(2) : '0.00';
  }

  function csvEscape(v) {
    const s = v == null ? '' : String(v);
    if (/[",\r\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
    return s;
  }

  function csvRow(arr) { return arr.map(csvEscape).join(','); }

  function downloadTextFile(filename, content, mime) {
    const blob = new Blob([content], { type: mime || 'text/plain;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
  }

  function renderInvoiceContent() {
    const inv = buildInvoicePayload();
    const d = inv.quoteData;
    const el = $('invoice-content');
    if (!el) return;
    const linesHtml = inv.lines.map(function (l) {
      return '<div class="pm-line"><span>' + esc(l.description) + '</span><span>' + esc(fmtDisplay(l.amount)) + '</span></div>';
    }).join('');
    const billAddr = [d.addr, d.city].filter(Boolean).join(', ');
    const shipAddr = [d.installAddr, d.installCity].filter(Boolean).join(', ');
    const dateLabel = (function () {
      try { return new Date(inv.invoiceDate + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }); }
      catch (e) { return inv.invoiceDate; }
    })();
    const dueLabel = (function () {
      if (!inv.dueDate) return '';
      try { return new Date(inv.dueDate + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }); }
      catch (e) { return inv.dueDate; }
    })();
    el.innerHTML = `
    <div class="pm-sec" style="display:flex;justify-content:space-between;align-items:flex-start">
      <div>
        <div style="font-family:'Cormorant Garamond',serif;font-size:30px;font-weight:300;letter-spacing:.08em;color:#1c1917">OGM</div>
        <div style="font-size:10px;letter-spacing:.2em;color:#57534e">OLIVE GLASS &amp; MARBLE</div>
        <div style="font-size:11px;color:#44403c;margin-top:6px;line-height:1.8">714 Robeson Street · Fayetteville, NC 28305<br>(910) 484-5277 · www.oliveglassandmarble.com</div>
      </div>
      <div style="text-align:right">
        <div style="font-size:22px;font-family:'Cormorant Garamond',serif;color:#57534e">Invoice</div>
        <div style="font-size:11px;color:#44403c;line-height:1.8;margin-top:6px">
          Invoice #: ${esc(inv.invoiceNumber || '—')}<br>
          Date: ${esc(dateLabel || '—')}<br>
          Terms: ${esc(inv.terms || '')}${dueLabel ? '<br>Due: ' + esc(dueLabel) : ''}${inv.poNumber ? '<br>PO #: ' + esc(inv.poNumber) : ''}
        </div>
      </div>
    </div>
    <div class="pm-sec">
      <div class="pm-h">Bill To</div>
      <div style="font-size:15px;font-weight:400;margin-bottom:6px;color:#1c1917">${esc(d.name)}</div>
      <div style="font-size:12px;color:#44403c;line-height:1.8">${billAddr ? esc(billAddr) + '<br>' : ''}${d.phone !== '—' ? esc(d.phone) + '<br>' : ''}${d.email ? esc(d.email) : ''}</div>
      ${shipAddr && shipAddr !== billAddr ? '<div style="font-size:11px;margin-top:10px;color:#44403c;line-height:1.8"><strong style="color:#57534e">Service address</strong><br>' + esc(shipAddr) + '</div>' : ''}
      <div style="font-size:12px;margin-top:6px;color:#292524"><strong>Job:</strong> ${esc(d.job)}</div>
    </div>
    <div class="pm-sec">
      <div class="pm-h">Line items</div>
      ${linesHtml || '<div class="pm-line"><span>—</span><span>—</span></div>'}
    </div>
    <div class="pm-total"><span>Total</span><span>${esc(fmtDisplay(inv.total))}</span></div>
    ${inv.depositApplied > 0 ? '<div class="pm-line"><span>Deposit applied</span><span>−' + esc(fmtDisplay(inv.depositApplied)) + '</span></div>' : ''}
    <div class="pm-line"><span><strong>Balance due</strong></span><span><strong>${esc(fmtDisplay(inv.balanceDue))}</strong></span></div>
    ${inv.memo ? '<div class="pm-deposit" style="margin-top:12px">Memo: ' + esc(inv.memo) + '</div>' : ''}
    <div class="pm-sig">
      <div class="pm-sig-box">Customer signature</div>
      <div class="pm-sig-box">Date</div>
    </div>`;
  }

  function mergeQuoteDataWithDeposit(base, depAmount) {
    const grand = parseFloat(base.grand) || 0;
    const dep = Math.max(0, parseFloat(depAmount) || 0);
    return Object.assign({}, base, {
      deposit: dep,
      balance: Math.max(0, grand - dep)
    });
  }

  function buildDepositReceiptPrintHtml(kind) {
    const h = hooks();
    const raw = h.getBuildData ? h.getBuildData() : {};
    const dep = readDdrDeposit();
    const d = mergeQuoteDataWithDeposit(normalizeQuoteData(raw), dep);
    const receiptNo = ($('ddr-receipt-num') && $('ddr-receipt-num').value.trim()) || '—';
    const banner = kind === 'accountant'
      ? '<div class="ogm-doc-accountant-banner">Accountant / bookkeeping copy — QuickBooks</div>'
      : '';
    const title = kind === 'accountant' ? 'Deposit receipt (accountant copy)' : 'Deposit receipt';
    const roomLineItems = (d.lines || []).map(function (entry) {
      return '<div class="pm-line"><span>' + esc(entry[0]) + '</span><span>' + esc(entry[1]) + '</span></div>';
    }).join('');
    const depositBlock = '<strong>' + esc(String(d.depositPct)) + '% deposit required to confirm order:</strong> ' + esc(fmtDisplay(d.deposit)) +
      '<br>Balance after deposit: <strong>' + esc(fmtDisplay(d.balance)) + '</strong>';
    return `
    ${banner}
    <div class="pm-sec" style="display:flex;justify-content:space-between;align-items:flex-start">
      <div>
        <div style="font-family:'Cormorant Garamond',serif;font-size:30px;font-weight:300;letter-spacing:.08em">OGM</div>
        <div style="font-size:10px;letter-spacing:.2em;color:#57534e">OLIVE GLASS &amp; MARBLE</div>
        <div style="font-size:11px;color:#44403c;margin-top:6px;line-height:1.8">714 Robeson Street · Fayetteville, NC 28305<br>(910) 484-5277 · www.oliveglassandmarble.com</div>
      </div>
      <div style="text-align:right">
        <div style="font-size:18px;font-family:'Cormorant Garamond',serif;color:#57534e">${esc(title)}</div>
        <div style="font-size:11px;color:#44403c;line-height:1.8;margin-top:6px">Receipt #: ${esc(receiptNo)}<br>Date: ${esc(d.date)}<br>Salesperson: ${esc(d.sp)}</div>
      </div>
    </div>
    <div class="pm-sec">
      <div class="pm-h">Customer</div>
      <div style="font-size:15px;font-weight:400;margin-bottom:6px;color:#1c1917">${esc(d.name)}</div>
      <div style="font-size:12px;color:#44403c;line-height:1.8">${d.addr ? esc(d.addr) + '<br>' : ''}${d.city ? esc(d.city) + '<br>' : ''}${d.phone !== '—' ? esc(d.phone) + '<br>' : ''}${d.email ? esc(d.email) : ''}</div>
      <div style="font-size:12px;margin-top:6px;color:#292524"><strong>Job:</strong> ${esc(d.job)}</div>
      <div style="font-size:11px;margin-top:6px;color:#44403c">Proposal / quote #: ${esc(d.invNum)}</div>
    </div>
    <div class="pm-sec">
      <div class="pm-h">Quote reference</div>
      ${roomLineItems}
      <div class="pm-total"><span>Estimated total</span><span>${fmtDisplay(d.grand)}</span></div>
    </div>
    <div class="pm-sec">
      <div class="pm-h">Payment</div>
      <div class="pm-line"><span>Deposit received (this receipt)</span><span>${fmtDisplay(d.deposit)}</span></div>
      <div class="pm-line"><span>Remaining balance after deposit</span><span><strong>${fmtDisplay(d.balance)}</strong></span></div>
    </div>
    <div class="pm-deposit">${depositBlock}</div>
    ${d.notes ? '<div class="pm-sec" style="margin-top:14px"><div class="pm-h">Customer notes</div><div style="font-size:12px;color:#44403c;line-height:1.8">' + esc(d.notes) + '</div></div>' : ''}`;
  }

  function readDdrDeposit() {
    const el = $('ddr-deposit');
    return el ? (parseFloat(el.value) || 0) : 0;
  }

  function updateDepositDocsComputed() {
    const h = hooks();
    const d = normalizeQuoteData(h.getBuildData ? h.getBuildData() : {});
    const grand = parseFloat(d.grand) || 0;
    const dep = readDdrDeposit();
    if ($('ddr-quote-grand')) $('ddr-quote-grand').value = fmtMoney(grand);
    if ($('ddr-remaining')) $('ddr-remaining').value = fmtMoney(Math.max(0, grand - dep));
  }

  function depositDocsResetSixty() {
    const h = hooks();
    const d = normalizeQuoteData(h.getBuildData ? h.getBuildData() : {});
    const grand = parseFloat(d.grand) || 0;
    const dep = grand > 0 ? Math.round(grand * (parseFloat(d.depositPct) || 60) / 100 * 100) / 100 : 0;
    if ($('ddr-deposit')) $('ddr-deposit').value = String(dep);
    updateDepositDocsComputed();
  }

  function openDepositDocsModal() {
    const h = hooks();
    const d = normalizeQuoteData(h.getBuildData ? h.getBuildData() : {});
    const grand = parseFloat(d.grand) || 0;
    if (grand <= 0) {
      alert('Add line items so the quote total is greater than zero.');
      return;
    }
    const today = new Date();
    const stamp = today.getFullYear().toString()
      + String(today.getMonth() + 1).padStart(2, '0')
      + String(today.getDate()).padStart(2, '0');
    if ($('ddr-receipt-num') && !String($('ddr-receipt-num').value || '').trim()) {
      $('ddr-receipt-num').value = meta.depositReceiptNumber || ('REC-' + stamp + '-001');
    }
    if ($('ddr-deposit')) {
      const existing = parseFloat(meta.depositAmount);
      $('ddr-deposit').value = String(
        Number.isFinite(existing) && existing > 0 ? existing : (parseFloat(d.deposit) || Math.round(grand * 0.6 * 100) / 100)
      );
    }
    updateDepositDocsComputed();
    const m = $('deposit-docs-modal');
    if (m) m.classList.add('open');
  }

  function closeDepositDocsModal() {
    const m = $('deposit-docs-modal');
    if (m) m.classList.remove('open');
  }

  function closePrintModal() {
    if (typeof global.closeGlassPrintPreview === 'function') {
      global.closeGlassPrintPreview();
      return;
    }
    const m = $('print-modal');
    if (m) m.classList.remove('open');
    document.documentElement.classList.remove('ogm-print-preview-open');
    global.__ogmGlassPrintModalSource = null;
    const titleEl = document.getElementById('print-modal-title');
    if (titleEl) titleEl.textContent = 'Proposal — Olive Glass & Marble';
  }

  function installPrintAfterprintHook() {
    if (global.__ogmGlassPrintAfterprintBound) return;
    global.__ogmGlassPrintAfterprintBound = true;
    global.addEventListener('afterprint', function () {
      const src = global.__ogmGlassPrintModalSource;
      global.__ogmGlassPrintModalSource = null;
      if (src !== 'deposit-receipt') return;
      const receipt = ($('ddr-receipt-num') && String($('ddr-receipt-num').value || '').trim()) || '';
      if (!receipt) return;
      persistDepositMetaToServer().catch(function () {});
    });
  }

  function printDepositReceipt(kind) {
    const h = hooks();
    const d = normalizeQuoteData(h.getBuildData ? h.getBuildData() : {});
    if ((parseFloat(d.grand) || 0) <= 0) {
      alert('Quote total must be greater than zero.');
      return;
    }
    global.__ogmGlassPrintModalSource = 'deposit-receipt';
    installPrintAfterprintHook();
    const titleEl = document.getElementById('print-modal-title');
    if (titleEl) {
      titleEl.textContent = kind === 'accountant'
        ? 'Deposit receipt (accountant) — Olive Glass & Marble'
        : 'Deposit receipt — Olive Glass & Marble';
    }
    const k = kind === 'accountant' ? 'accountant' : 'customer';
    const host = $('pm-content');
    if (host) host.innerHTML = buildDepositReceiptPrintHtml(k);
    closeDepositDocsModal();
    const runPrint = function () {
      if (typeof global.printOgmCustomerDoc === 'function') {
        global.printOgmCustomerDoc();
        return;
      }
      if (typeof global.printGlassCustomerDoc === 'function') {
        global.printGlassCustomerDoc();
        return;
      }
      global.print();
    };
    const openPreview = function () {
      if (typeof global.openGlassPrintPreview === 'function') {
        global.openGlassPrintPreview();
      } else {
        const m = $('print-modal');
        if (m) m.classList.add('open');
        document.documentElement.classList.add('ogm-print-preview-open');
        global.scrollTo(0, 0);
      }
      global.requestAnimationFrame(function () {
        global.setTimeout(runPrint, 150);
      });
    };
    openPreview();
  }

  function printInvoicePdf() {
    const inv = buildInvoicePayload();
    if (!inv.invoiceNumber) {
      alert('Enter an invoice # before printing or saving the invoice PDF.');
      const el = $('inv-number');
      if (el && el.focus) el.focus();
      return;
    }
    if (!validateInvoiceForFinal(inv)) return;
    renderInvoiceContent();
    const m = $('invoice-modal');
    if (m && !m.classList.contains('open')) openGlassInvoiceModal(false);
    const host = $('invoice-content');
    if (!host) return;
    const printTitle = 'Invoice ' + inv.invoiceNumber + ' — Olive Glass & Marble';
    const runPrint = function () {
      renderInvoiceContent();
      if (typeof global.printOgmCustomerDoc === 'function') {
        global.printOgmCustomerDoc({
          host: host,
          title: printTitle,
          renderInvoice: renderInvoiceContent
        });
        return;
      }
      if (typeof global.printGlassCustomerDoc === 'function') {
        global.printGlassCustomerDoc();
        return;
      }
      global.print();
    };
    global.requestAnimationFrame(function () {
      global.setTimeout(runPrint, 150);
    });
  }

  async function persistDepositMetaToServer() {
    const h = hooks();
    const d = normalizeQuoteData(h.getBuildData ? h.getBuildData() : {});
    const grand = parseFloat(d.grand) || 0;
    if (grand <= 0) {
      alert('Add measurements and pricing so the quote total is greater than zero before recording a deposit.');
      return;
    }
    const receipt = ($('ddr-receipt-num') && String($('ddr-receipt-num').value || '').trim()) || '';
    if (!receipt) {
      alert('Enter a receipt # before recording the deposit on this quote.');
      return;
    }
    const dep = readDdrDeposit();
    const fields = {
      depositReceiptNumber: receipt,
      depositAmount: dep,
      depositRecordedAt: new Date().toISOString(),
      workflowPhase: 'Deposit',
      status: 'deposit'
    };
    if (h.persistSummary) await h.persistSummary(fields);
    Object.assign(meta, fields);
    updateWorkflowBadge();
    if (h.showToast) h.showToast('Deposit recorded on quote');
  }

  function invoiceRecalcDueDate() {
    const d = $('inv-date');
    const t = $('inv-terms');
    const due = $('inv-due');
    if (!d || !t || !due || !WF) return;
    const baseStr = d.value;
    if (!baseStr) return;
    const opt = t.options[t.selectedIndex];
    const days = parseInt(opt && opt.getAttribute('data-days'), 10);
    if (!Number.isFinite(days)) return;
    const base = new Date(baseStr + 'T00:00:00');
    if (Number.isNaN(base.getTime())) return;
    const out = new Date(base.getTime() + days * 86400000);
    due.value = WF.invoiceFmtDate(out);
  }

  function openInvoice(opts) {
    opts = opts || {};
    const fromDeposit = !!opts.fromDeposit;
    const fromExisting = !!opts.fromExisting;
    const h = hooks();
    const raw = h.getBuildData ? h.getBuildData() : {};
    const d = normalizeQuoteData(raw);
    const today = new Date();
    const todayStr = WF ? WF.invoiceFmtDate(today) : '';
    const due = new Date(today.getTime() + 30 * 86400000);
    const useSaved = fromExisting || (!fromDeposit && !!String(meta.invoiceNumber || '').trim());
    if (useSaved && meta.invoiceNumber && $('inv-number')) {
      $('inv-number').value = meta.invoiceNumber;
      if ($('inv-date')) $('inv-date').value = (meta.invoiceDate || '').slice(0, 10) || todayStr;
    } else {
      const stamp = today.getFullYear().toString()
        + String(today.getMonth() + 1).padStart(2, '0')
        + String(today.getDate()).padStart(2, '0');
      if ($('inv-number')) $('inv-number').value = 'INV-' + stamp + '-GLS';
      if ($('inv-date')) $('inv-date').value = todayStr;
    }
    if ($('inv-due')) $('inv-due').value = WF ? WF.invoiceFmtDate(due) : '';
    if ($('inv-terms')) $('inv-terms').value = 'Net 30';
    if ($('inv-memo')) {
      $('inv-memo').value = d.job && d.job !== '—' ? ('Job: ' + d.job) : '';
      if (fromDeposit && global.__ogmGlassInvoicePresetMemo) {
        $('inv-memo').value = global.__ogmGlassInvoicePresetMemo;
        delete global.__ogmGlassInvoicePresetMemo;
      }
    }
    if ($('inv-deposit')) {
      if (fromDeposit && Number.isFinite(global.__ogmGlassInvoicePresetDeposit)) {
        $('inv-deposit').value = String(global.__ogmGlassInvoicePresetDeposit);
        delete global.__ogmGlassInvoicePresetDeposit;
      } else if (!fromDeposit) {
        $('inv-deposit').value = meta.depositAmount != null ? String(meta.depositAmount) : '';
      }
    }
    if ($('inv-material-cost')) {
      $('inv-material-cost').value = meta.cosTotal != null ? String(meta.cosTotal) : String(d.subtotal || '');
    }
    if ($('inv-job-code')) $('inv-job-code').value = meta.jobCode || 'T1.Inv.Instal';
    if ($('inv-install-date')) $('inv-install-date').value = (meta.installDate || '').slice(0, 10);
    if ($('inv-hrs-est')) $('inv-hrs-est').value = meta.hrsEst != null ? String(meta.hrsEst) : '';
    if ($('inv-hrs-act')) $('inv-hrs-act').value = meta.hrsAct != null ? String(meta.hrsAct) : '';
    if ($('inv-tax-backout')) $('inv-tax-backout').checked = meta.taxMode !== 'exempt';
    if ($('inv-quote-amount')) $('inv-quote-amount').value = meta.invoiceTotal != null ? String(meta.invoiceTotal) : String(d.grand || '');
    renderInvoiceContent();
    openGlassInvoiceModal(fromDeposit);
  }

  function openGlassInvoiceModal(fromDeposit) {
    if (fromDeposit) {
      closeDepositDocsModal();
      global.__ogmGlassInvoiceReturnToDeposit = true;
    } else {
      global.__ogmGlassInvoiceReturnToDeposit = false;
    }
    document.documentElement.classList.remove('ogm-print-preview-open');
    const printM = $('print-modal');
    if (printM) printM.classList.remove('open');
    const modal = $('invoice-modal');
    if (modal) modal.classList.add('open');
    if (!document.documentElement.classList.contains('ogm-embed-invoice')) {
      document.documentElement.classList.add('ogm-glass-invoice-open');
      global.scrollTo(0, 0);
    }
  }

  function closeInvoice(opts) {
    opts = opts || {};
    if (document.documentElement.classList.contains('ogm-embed-invoice')) {
      try { global.parent.postMessage({ type: 'ogm-close-invoice-embed' }, '*'); } catch (e) {}
      return;
    }
    const returnDeposit = !!global.__ogmGlassInvoiceReturnToDeposit;
    global.__ogmGlassInvoiceReturnToDeposit = false;
    document.documentElement.classList.remove('ogm-glass-invoice-open');
    const modal = $('invoice-modal');
    if (modal) modal.classList.remove('open');
    if (returnDeposit) {
      const dep = $('deposit-docs-modal');
      if (dep) dep.classList.add('open');
    }
  }

  function openInvoiceFromDepositDocs() {
    const dep = readDdrDeposit();
    const receipt = ($('ddr-receipt-num') && $('ddr-receipt-num').value) || '';
    global.__ogmGlassInvoicePresetDeposit = dep;
    global.__ogmGlassInvoicePresetMemo = 'Deposit receipt ' + receipt;
    openInvoice({ fromDeposit: true });
  }

  async function persistInvoiceMetaToServer(inv) {
    const h = hooks();
    const fields = {
      invoiceNumber: inv.invoiceNumber,
      invoiceDate: inv.invoiceDate,
      invoiceTotal: inv.total,
      convertedAt: new Date().toISOString(),
      workflowPhase: 'Invoiced',
      status: 'invoiced',
      cosTotal: inv.cosTotal,
      taxMode: inv.taxMode,
      taxBackoutAmount: inv.taxBackoutAmount,
      jobCode: inv.jobCode,
      installDate: inv.installDate,
      hrsEst: inv.hrsEst,
      hrsAct: inv.hrsAct,
      glassWorkHours: inv.hrsAct || inv.hrsEst
    };
    if (h.persistSummary) await h.persistSummary(fields);
    Object.assign(meta, fields);
    updateWorkflowBadge();
  }

  async function saveInvoiceAndJobTracking(opts) {
    opts = opts || {};
    const inv = buildInvoicePayload();
    if (!inv.invoiceNumber) {
      alert('Enter an invoice # before saving invoice tracking.');
      return;
    }
    if (!validateInvoiceForFinal(inv)) return;
    const btn = $('inv-save-server-btn');
    const old = btn ? btn.textContent : '';
    try {
      if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
      await persistInvoiceMetaToServer(inv);
      const h = hooks();
      if (h.showToast) h.showToast('Invoice saved');
      if (opts.close) closeInvoice({ skipConfirm: true });
    } catch (e) {
      console.error('saveInvoiceAndJobTracking', e);
      alert('Could not save invoice tracking to the server.');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = old || 'Save + Close'; }
    }
  }

  function buildInvoiceEmailPayload(inv) {
    const d = inv && inv.quoteData ? inv.quoteData : {};
    const copy = loadEmailCopy();
    return {
      invoiceNumber: inv.invoiceNumber || '',
      invoiceDate: inv.invoiceDate || '',
      terms: inv.terms || '',
      dueDate: inv.dueDate || '',
      poNumber: inv.poNumber || '',
      memo: inv.memo || '',
      total: Number(inv.total) || 0,
      depositApplied: Number(inv.depositApplied) || 0,
      balanceDue: Number(inv.balanceDue) || 0,
      lines: (inv.lines || []).map(function (line) {
        return {
          item: line.item || '',
          description: line.description || '',
          qty: Number(line.qty) || 1,
          rate: Number(line.rate) || 0,
          amount: Number(line.amount) || 0,
          taxCode: line.taxCode || ''
        };
      }),
      quoteData: {
        name: d.name || '',
        phone: d.phone || '',
        email: d.email || '',
        addr: d.addr || '',
        city: d.city || '',
        installAddr: d.installAddr || '',
        installCity: d.installCity || '',
        job: d.job || '',
        date: d.date || '',
        sp: d.sp || ''
      },
      emailTemplate: copy
    };
  }

  async function emailInvoiceWithPdf() {
    const btn = $('inv-email-btn');
    const old = btn ? btn.textContent : '';
    try {
      const inv = buildInvoicePayload();
      const d = inv.quoteData || {};
      const rawEmail = String(d.email || '').trim();
      if (!rawEmail) {
        alert('Enter the customer email before sending the invoice.');
        const el = $('cust-email');
        if (el && el.focus) el.focus();
        return;
      }
      if (!inv.invoiceNumber) {
        alert('Enter an invoice # before sending the invoice.');
        if ($('inv-number') && $('inv-number').focus) $('inv-number').focus();
        return;
      }
      if (!validateInvoiceForFinal(inv)) return;
      renderInvoiceContent();
      if (!confirm('Email invoice ' + inv.invoiceNumber + ' to ' + rawEmail + ' with a PDF attached?')) return;
      if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }
      const res = await fetch('invoice-email-api.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ invoice: buildInvoiceEmailPayload(inv) })
      });
      const text = await res.text();
      let data = null;
      try { data = JSON.parse(text); } catch (e) {}
      if (!res.ok || !data || !data.ok) {
        throw new Error((data && data.error) || 'Server could not send the invoice email.');
      }
      try {
        await persistInvoiceMetaToServer(inv);
      } catch (saveErr) {
        alert('Invoice emailed to ' + rawEmail + ', but tracking data could not save to reports.');
        return;
      }
      const h = hooks();
      if (h.showToast) h.showToast('Invoice emailed to ' + rawEmail);
    } catch (err) {
      console.error('emailInvoiceWithPdf', err);
      alert('Could not email the invoice: ' + (err && err.message ? err.message : 'unknown error'));
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = old || 'Email Invoice + PDF'; }
    }
  }

  function openInvoiceEmailEditor() {
    const body = $('glass-invoice-email-editor-body');
    const modal = $('glass-invoice-email-editor-modal');
    if (!body || !modal) return;
    const copy = loadEmailCopy();
    body.innerHTML = `
      <p class="inv-email-hint">Placeholders: {{customerName}}, {{invoiceNumber}}, {{amountDue}}, {{total}}, {{balanceDue}}, {{deposit}}, {{dueDate}}, {{jobName}}</p>
      <label class="inv-email-lbl">Subject</label>
      <input type="text" id="gie-subject" value="${esc(copy.subjectTemplate)}">
      <label class="inv-email-lbl">Intro</label>
      <textarea id="gie-intro" rows="5">${esc(copy.introTemplate)}</textarea>
      <label class="inv-email-lbl">Closing</label>
      <textarea id="gie-closing" rows="3">${esc(copy.closingTemplate)}</textarea>
      <label class="inv-email-lbl">PDF note</label>
      <input type="text" id="gie-pdf" value="${esc(copy.pdfNote)}">
      <div style="margin-top:14px;display:flex;gap:8px">
        <button type="button" class="btn-print btn-export-csv" onclick="saveGlassInvoiceEmailEditor()">Save</button>
        <button type="button" class="btn-cprint" onclick="closeInvoiceEmailEditor()">Close</button>
      </div>`;
    modal.classList.add('open');
  }

  function saveGlassInvoiceEmailEditor() {
    saveEmailCopy({
      layoutStyle: 'classic',
      subjectTemplate: ($('gie-subject') && $('gie-subject').value) || '',
      introTemplate: ($('gie-intro') && $('gie-intro').value) || '',
      closingTemplate: ($('gie-closing') && $('gie-closing').value) || '',
      pdfNote: ($('gie-pdf') && $('gie-pdf').value) || ''
    });
    closeInvoiceEmailEditor();
    const h = hooks();
    if (h.showToast) h.showToast('Email message saved');
  }

  function closeInvoiceEmailEditor() {
    const modal = $('glass-invoice-email-editor-modal');
    if (modal) modal.classList.remove('open');
  }

  function downloadInvoiceCsv() {
    const inv = getInvoiceForExport();
    if (!inv || !validateInvoiceForFinal(inv)) return;
    const d = inv.quoteData;
    const billLines = [d.addr, d.city].filter(Boolean);
    const shipLines = [d.installAddr, d.installCity].filter(Boolean);
    if (!shipLines.length) billLines.forEach(function (l) { shipLines.push(l); });
    const headers = ['InvoiceNo', 'Date', 'Customer', 'Terms', 'DueDate', 'BillAddr1', 'BillAddr2', 'BillAddr3', 'ShipAddr1', 'ShipAddr2', 'ShipAddr3', 'PONumber', 'Memo', 'Item', 'Description', 'Qty', 'Rate', 'Amount', 'Class', 'TaxCode'];
    const rows = [csvRow(headers)];
    const dateUS = invoiceFmtDateMDY(new Date(inv.invoiceDate + 'T00:00:00'));
    const dueUS = inv.dueDate ? invoiceFmtDateMDY(new Date(inv.dueDate + 'T00:00:00')) : '';
    getInvoiceExportLines(inv).forEach(function (l) {
      rows.push(csvRow([
        inv.invoiceNumber, dateUS, d.name || 'Customer', inv.terms, dueUS,
        d.name || '', billLines[0] || '', billLines[1] || '',
        d.name || '', shipLines[0] || '', shipLines[1] || '',
        inv.poNumber || '', inv.memo || '',
        l.item, l.description,
        invoiceCurrencyToString(l.qty || 1),
        invoiceCurrencyToString(l.rate || 0),
        invoiceCurrencyToString(l.amount || 0),
        d.sp || '', l.taxCode || ''
      ]));
    });
    downloadTextFile('OGM-Invoice-' + (inv.invoiceNumber || 'INV') + '.csv', rows.join('\r\n'), 'text/csv;charset=utf-8');
    persistInvoiceMetaToServer(inv).catch(function () {});
  }

  function downloadCustomerCsv() {
    const inv = getInvoiceForExport();
    if (!inv) return;
    const d = inv.quoteData;
    const headers = ['Customer Name', 'Company', 'Mr/Ms', 'First Name', 'Last Name', 'Main Phone', 'Main Email', 'Bill to 1', 'Bill to 2', 'Bill to 3', 'Bill to 4', 'Ship to 1', 'Ship to 2', 'Ship to 3', 'Ship to 4', 'Notes'];
    const billLines = [d.name, d.addr, d.city].filter(Boolean);
    const shipLines = [d.name, d.installAddr || d.addr, d.installCity || d.city].filter(Boolean);
    const nameParts = String(d.name || '').trim().split(/\s+/);
    const first = nameParts.shift() || '';
    const last = nameParts.join(' ');
    const row = [d.name || '', '', '', first, last, d.phone !== '—' ? d.phone : '', d.email || '', billLines[0] || '', billLines[1] || '', billLines[2] || '', billLines[3] || '', shipLines[0] || '', shipLines[1] || '', shipLines[2] || '', shipLines[3] || '', d.job && d.job !== '—' ? ('Job: ' + d.job) : ''];
    downloadTextFile('OGM-Customer-' + (d.name || 'Customer').replace(/[^A-Za-z0-9]+/g, '_') + '.csv', [csvRow(headers), csvRow(row)].join('\r\n'), 'text/csv;charset=utf-8');
  }

  function downloadInvoiceIif() {
    const inv = getInvoiceForExport();
    if (!inv || !validateInvoiceForFinal(inv)) return;
    const d = inv.quoteData;
    const dateUS = invoiceFmtDateMDY(new Date(inv.invoiceDate + 'T00:00:00'));
    const dueUS = inv.dueDate ? invoiceFmtDateMDY(new Date(inv.dueDate + 'T00:00:00')) : dateUS;
    const total = inv.total;
    const customer = d.name || 'Customer';
    const memo = inv.memo || (d.job && d.job !== '—' ? 'Job: ' + d.job : '');
    const klass = d.sp || '';
    const out = [];
    out.push(['!TRNS', 'TRNSID', 'TRNSTYPE', 'DATE', 'ACCNT', 'NAME', 'CLASS', 'AMOUNT', 'DOCNUM', 'MEMO', 'CLEAR', 'TOPRINT', 'NAMEISTAXABLE', 'DUEDATE', 'TERMS'].join('\t'));
    out.push(['!SPL', 'SPLID', 'TRNSTYPE', 'DATE', 'ACCNT', 'NAME', 'CLASS', 'AMOUNT', 'DOCNUM', 'MEMO', 'CLEAR', 'QNTY', 'PRICE', 'INVITEM', 'TAXABLE', 'EXTRA'].join('\t'));
    out.push('!ENDTRN');
    out.push(['TRNS', '', 'INVOICE', dateUS, 'Accounts Receivable', customer, klass, total.toFixed(2), inv.invoiceNumber || '', memo, 'N', 'Y', 'N', dueUS, inv.terms || ''].join('\t'));
    getInvoiceExportLines(inv).forEach(function (l) {
      out.push(['SPL', '', 'INVOICE', dateUS, 'Sales', customer, klass, (-Math.abs(Number(l.amount) || 0)).toFixed(2), inv.invoiceNumber || '', l.description || l.item, 'N', String(l.qty || 1), (l.rate || 0).toFixed(2), l.item, l.taxCode === 'Tax' ? 'Y' : 'N', ''].join('\t'));
    });
    downloadTextFile('OGM-Invoice-' + (inv.invoiceNumber || 'INV') + '.iif', out.join('\r\n') + '\r\n', 'text/plain;charset=utf-8');
    persistInvoiceMetaToServer(inv).catch(function () {});
  }

  function downloadInvoiceAndItemsCsv() {
    downloadInvoiceCsv();
  }

  function setInvoiceJobAmountZero() {
    if ($('inv-quote-amount')) $('inv-quote-amount').value = '0';
    renderInvoiceContent();
  }

  function onInvoiceTaxModeChange(mode) {
    if (mode === 'exempt' && $('inv-tax-backout')) $('inv-tax-backout').checked = false;
    if (mode === 'backout' && $('inv-tax-exempt')) $('inv-tax-exempt').checked = false;
    renderInvoiceContent();
  }

  global.OgmGlassInvoice = {
    setMeta: syncMetaFromSummary,
    updateWorkflowBadge: updateWorkflowBadge,
    openDepositDocsModal: openDepositDocsModal,
    closeDepositDocsModal: closeDepositDocsModal,
    openInvoice: openInvoice,
    closeInvoice: closeInvoice,
    openInvoiceFromDepositDocs: openInvoiceFromDepositDocs,
    persistDepositMetaToServer: persistDepositMetaToServer,
    saveInvoiceAndJobTracking: saveInvoiceAndJobTracking,
    printDepositReceipt: printDepositReceipt,
    depositDocsResetSixty: depositDocsResetSixty,
    updateDepositDocsComputed: updateDepositDocsComputed,
    renderInvoiceContent: renderInvoiceContent,
    invoiceRecalcDueDate: invoiceRecalcDueDate,
    printInvoicePdf: printInvoicePdf,
    emailInvoiceWithPdf: emailInvoiceWithPdf,
    openInvoiceEmailEditor: openInvoiceEmailEditor,
    downloadInvoiceAndItemsCsv: downloadInvoiceAndItemsCsv,
    downloadCustomerCsv: downloadCustomerCsv,
    downloadInvoiceIif: downloadInvoiceIif,
    closePrintModal: closePrintModal
  };

  global.openDepositDocsModal = openDepositDocsModal;
  global.closeDepositDocsModal = closeDepositDocsModal;
  global.openInvoice = openInvoice;
  global.closeInvoice = closeInvoice;
  global.openInvoiceFromDepositDocs = openInvoiceFromDepositDocs;
  global.persistDepositMetaToServer = persistDepositMetaToServer;
  global.saveInvoiceAndJobTracking = saveInvoiceAndJobTracking;
  global.printDepositReceipt = printDepositReceipt;
  global.renderInvoiceContent = renderInvoiceContent;
  global.depositDocsResetSixty = depositDocsResetSixty;
  global.updateDepositDocsComputed = updateDepositDocsComputed;
  global.renderInvoiceContent = renderInvoiceContent;
  global.invoiceRecalcDueDate = invoiceRecalcDueDate;
  global.printInvoicePdf = printInvoicePdf;
  global.emailInvoiceWithPdf = emailInvoiceWithPdf;
  global.openInvoiceEmailEditor = openInvoiceEmailEditor;
  global.saveGlassInvoiceEmailEditor = saveGlassInvoiceEmailEditor;
  global.closeInvoiceEmailEditor = closeInvoiceEmailEditor;
  global.downloadInvoiceAndItemsCsv = downloadInvoiceAndItemsCsv;
  global.downloadCustomerCsv = downloadCustomerCsv;
  global.downloadInvoiceIif = downloadInvoiceIif;
  global.closePrintModal = closePrintModal;
  global.setInvoiceJobAmountZero = setInvoiceJobAmountZero;
  global.onInvoiceTaxModeChange = onInvoiceTaxModeChange;

  function bindInvoiceControlListeners() {
    const n = $('inv-number');
    const d = $('inv-date');
    const t = $('inv-terms');
    const due = $('inv-due');
    const po = $('inv-po');
    const memo = $('inv-memo');
    const dep = $('inv-deposit');
    if (n) n.oninput = renderInvoiceContent;
    if (d) d.onchange = function () { invoiceRecalcDueDate(); renderInvoiceContent(); };
    if (t) t.onchange = function () { invoiceRecalcDueDate(); renderInvoiceContent(); };
    if (due) due.onchange = renderInvoiceContent;
    if (po) po.oninput = renderInvoiceContent;
    if (memo) memo.oninput = renderInvoiceContent;
    if (dep) dep.oninput = renderInvoiceContent;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindInvoiceControlListeners);
  } else {
    bindInvoiceControlListeners();
  }
})(typeof window !== 'undefined' ? window : this);
