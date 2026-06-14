/**
 * Shared invoice/deposit workflow helpers (stone + glass quoters).
 */
(function (global) {
  'use strict';

  var OGM_CUSTOMER_DOC_PRINT_CSS = [
    'body{margin:0;padding:28px 32px;background:#fff;color:#1c1917;font-family:"DM Sans",sans-serif;font-weight:300;font-size:14px}',
    '.ogm-customer-doc .pm-sec{margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #eae6da}',
    '.ogm-customer-doc .pm-sec:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0}',
    '.ogm-customer-doc > .pm-sec:first-child,.ogm-customer-doc > .pm-sec[style*="flex"]{display:flex;justify-content:space-between;align-items:flex-start}',
    '.ogm-customer-doc .pm-h{font-size:9px;letter-spacing:.2em;text-transform:uppercase;color:#57534e;margin-bottom:8px}',
    '.ogm-customer-doc .pm-line{display:flex;justify-content:space-between;align-items:baseline;font-size:13px;padding:6px 0;border-bottom:1px solid #f0ebe0}',
    '.ogm-customer-doc .pm-line:last-child{border-bottom:none}',
    '.ogm-customer-doc .pm-line span:first-child{color:#44403c;flex:1;padding-right:12px}',
    '.ogm-customer-doc .pm-line span:last-child{font-family:"DM Sans",sans-serif;font-size:13px;color:#1c1917;white-space:nowrap;margin-left:0}',
    '.ogm-customer-doc .pm-room-total{display:flex;justify-content:space-between;align-items:baseline;font-size:13px;font-weight:500;padding:8px 0 7px;margin:8px 0 7px;border-top:2px solid #cbbf9d;border-bottom:1px solid #eae6da;color:#44403c}',
    '.ogm-customer-doc .pm-room-total span:first-child{color:#44403c;font-weight:400;flex:1}',
    '.ogm-customer-doc .pm-room-total span:last-child{font-family:"DM Sans",sans-serif;font-size:13px;color:#44403c;font-weight:400}',
    '.ogm-customer-doc .pm-total{display:flex;justify-content:space-between;align-items:baseline;padding:10px 0 0;margin-top:8px;border-top:2px solid #cbbf9d}',
    '.ogm-customer-doc .pm-total span:first-child{font-family:"Cormorant Garamond",serif;font-size:18px;color:#57534e}',
    '.ogm-customer-doc .pm-total span:last-child{font-family:"Cormorant Garamond",serif;font-size:28px;color:#9e7c3a;font-weight:500;line-height:1.1}',
    '.ogm-customer-doc .pm-deposit{background:#f0e6cc;border:1px solid #d4b87a;padding:10px 14px;font-size:12px;color:#4a4538;line-height:1.8;margin-top:12px;border-radius:2px}',
    '.ogm-customer-doc .ogm-doc-accountant-banner{border:2px dashed #64748b;padding:10px 12px;text-align:center;font-size:11px;letter-spacing:.18em;text-transform:uppercase;color:#64748b;margin-bottom:16px}',
    '.ogm-customer-doc .pm-sig{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:20px}',
    '.ogm-customer-doc .pm-sig-box{border-top:1px solid #e7e5e4;padding-top:8px;font-size:10px;color:#57534e}',
    '.ogm-customer-doc .pm-footer-txt{text-align:center;font-size:11px;color:#57534e;padding-top:14px;border-top:1px solid #eae6da;line-height:1.8;margin-top:14px}',
    '@media print{@page{margin:0.45in}body{padding:28px 32px;-webkit-print-color-adjust:exact;print-color-adjust:exact}}'
  ].join('');

  function escPrintTitle(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function resolvePrintHost(opts) {
    opts = opts || {};
    const printModal = document.getElementById('print-modal');
    const invoiceModal = document.getElementById('invoice-modal');
    if (printModal && printModal.classList.contains('open')) {
      return {
        host: document.getElementById('pm-content'),
        title: opts.title
          || (document.getElementById('print-modal-title') && document.getElementById('print-modal-title').textContent)
          || (printModal.querySelector('.pm-head h2') && printModal.querySelector('.pm-head h2').textContent)
          || 'Olive Glass & Marble'
      };
    }
    if (invoiceModal && invoiceModal.classList.contains('open')) {
      if (typeof opts.renderInvoice === 'function') opts.renderInvoice();
      return {
        host: document.getElementById('invoice-content'),
        title: opts.invoiceTitle || 'Invoice — Olive Glass & Marble'
      };
    }
    return { host: null, title: opts.title || 'Olive Glass & Marble' };
  }

  /** Print customer doc preview WYSIWYG via isolated iframe (proposal, deposit receipt, invoice). */
  function printOgmCustomerDoc(opts) {
    opts = opts || {};
    let host = opts.host || null;
    let title = opts.title || opts.invoiceTitle || 'Olive Glass & Marble';
    if (typeof opts.renderInvoice === 'function') opts.renderInvoice();
    if (!host) {
      const resolved = resolvePrintHost(opts);
      host = resolved.host;
      title = opts.title || opts.invoiceTitle || resolved.title;
    }
    if (!host || !String(host.innerHTML || '').trim()) {
      global.print();
      return;
    }
    let frame = document.getElementById('ogm-customer-doc-print-frame');
    if (!frame) {
      frame = document.createElement('iframe');
      frame.id = 'ogm-customer-doc-print-frame';
      frame.setAttribute('aria-hidden', 'true');
      frame.setAttribute('title', 'Print preview');
      frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
      document.body.appendChild(frame);
    }
    const win = frame.contentWindow;
    const doc = win && win.document;
    if (!win || !doc) {
      global.print();
      return;
    }
    const title = escPrintTitle(title);
    doc.open();
    doc.write('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' + title + '</title>');
    doc.write('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600&family=DM+Sans:wght@300;400;500&family=DM+Mono:wght@400;500&display=swap">');
    doc.write('<style>' + OGM_CUSTOMER_DOC_PRINT_CSS + '</style></head><body>');
    doc.write('<div class="ogm-customer-doc">' + host.innerHTML + '</div></body></html>');
    doc.close();

    const doPrint = function () {
      try {
        win.focus();
        win.print();
      } catch (err) {
        console.error('printOgmCustomerDoc', err);
        global.print();
      }
    };

    if (!win.__ogmCustomerDocFrameAfterprintBound) {
      win.__ogmCustomerDocFrameAfterprintBound = true;
      win.addEventListener('afterprint', function () {
        try { global.dispatchEvent(new Event('afterprint')); } catch (e) { /* deposit receipt hooks */ }
      });
    }

    const schedulePrint = function () {
      if (doc.fonts && doc.fonts.ready) {
        doc.fonts.ready.then(function () { setTimeout(doPrint, 80); }).catch(function () { setTimeout(doPrint, 200); });
      } else {
        setTimeout(doPrint, 200);
      }
    };

    if (doc.readyState === 'complete') {
      schedulePrint();
    } else {
      frame.onload = function () { frame.onload = null; schedulePrint(); };
    }
  }

  function deriveWorkflowPhase(meta) {
    if (!meta || typeof meta !== 'object') return 'Draft';
    const phase = String(meta.workflowPhase || '').trim();
    if (phase === 'Invoiced' || phase === 'Deposit' || phase === 'Quoted') return phase;
    if (String(meta.invoiceNumber || '').trim() || String(meta.convertedAt || '').trim()) return 'Invoiced';
    if (String(meta.depositReceiptNumber || '').trim() || String(meta.depositRecordedAt || '').trim()) return 'Deposit';
    if (meta.savedAt) return 'Quoted';
    return 'Draft';
  }

  function invoiceFmtDate(d) {
    const dt = d instanceof Date ? d : new Date(d);
    if (Number.isNaN(dt.getTime())) return '';
    const y = dt.getFullYear();
    const m = String(dt.getMonth() + 1).padStart(2, '0');
    const day = String(dt.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function installEmbedCloseListener() {
    if (global.__ogmEmbedCloseBound) return;
    global.__ogmEmbedCloseBound = true;
    global.addEventListener('message', function (e) {
      if (!e.data || e.data.type !== 'ogm-close-invoice-embed') return;
      if (!document.documentElement.classList.contains('ogm-embed-invoice')) return;
      try {
        global.parent.postMessage({ type: 'ogm-close-invoice-embed' }, '*');
      } catch (err) {}
    });
  }

  function bootstrapEmbedFromUrl(params, openInvoiceFn) {
    const embed = String(params.get('embed') || '').trim();
    const openInv = String(params.get('openInvoice') || '').trim().toLowerCase();
    if (embed === '1' || embed === 'true') {
      document.documentElement.classList.add('ogm-embed-invoice');
      installEmbedCloseListener();
    }
    if (openInv === '1' || openInv === 'true') {
      const run = function () {
        if (typeof openInvoiceFn === 'function') {
          openInvoiceFn({ fromExisting: true });
        }
      };
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
          requestAnimationFrame(run);
        });
      } else {
        requestAnimationFrame(run);
      }
    }
  }

  global.printOgmCustomerDoc = printOgmCustomerDoc;
  global.printGlassCustomerDoc = printOgmCustomerDoc;

  global.OgmInvoiceWorkflow = {
    deriveWorkflowPhase: deriveWorkflowPhase,
    invoiceFmtDate: invoiceFmtDate,
    installEmbedCloseListener: installEmbedCloseListener,
    bootstrapEmbedFromUrl: bootstrapEmbedFromUrl,
    printOgmCustomerDoc: printOgmCustomerDoc
  };
})(typeof window !== 'undefined' ? window : this);
