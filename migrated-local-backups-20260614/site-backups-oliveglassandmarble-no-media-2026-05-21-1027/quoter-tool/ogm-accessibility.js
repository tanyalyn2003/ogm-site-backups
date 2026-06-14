/**
 * Global accessibility module for the OGM suite.
 * Adds opt-in voice input, large controls, dwell clicking, and undo toast support.
 */
(function () {
  'use strict';

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
    } catch (_) {
    }
    if (!inFrame) return false;
    try {
      var path = String(window.location.pathname || '').toLowerCase();
      return path.indexOf('ogm_kitchenplanner') !== -1
        || path.indexOf('shape-connector') !== -1
        || path.indexOf('blueprintscanner') !== -1;
    } catch (_) {
      return false;
    }
  }

  if (isEmbeddedView()) return;

  var STORAGE_KEY = 'ogm-a11y-settings';
  var DEFAULTS = {
    on: false,
    voice: true,
    largeCtrl: true,
    dwell: false,
    dwellMs: 1500,
    voiceFB: true,
    textSize: 0
  };

  function loadSettings() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (raw) return Object.assign({}, DEFAULTS, JSON.parse(raw));
    } catch (_) {}
    return Object.assign({}, DEFAULTS);
  }

  var settings = loadSettings();
  var recognition = null;
  var voiceActive = false;
  var dwellTimer = null;
  var dwellTarget = null;
  var dwellRing = null;
  var dwellStart = 0;
  var dwellRAF = null;
  var settingsJustShown = false;
  var undoFn = null;
  var undoTimeout = null;

  function saveSettings() {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(settings)); } catch (_) {}
  }

  function detectPage() {
    var url = String(location.href || '').toLowerCase();
    var title = String(document.title || '').toLowerCase();
    if (url.indexOf('glass') !== -1 || title.indexOf('glass') !== -1) return 'glass';
    if (url.indexOf('shower') !== -1 || title.indexOf('shower') !== -1) return 'shower';
    if (url.indexOf('job-tracking') !== -1 || title.indexOf('job tracking') !== -1) return 'jobs';
    if (url.indexOf('customer') !== -1 || title.indexOf('customer') !== -1) return 'customers';
    if (url.indexOf('invoice') !== -1 || title.indexOf('invoice') !== -1) return 'invoice';
    if (url.indexOf('hub') !== -1 || title.indexOf('hub') !== -1) return 'hub';
    if (url.indexOf('report') !== -1 || title.indexOf('report') !== -1) return 'reports';
    if (url.indexOf('lead') !== -1 || title.indexOf('lead') !== -1) return 'leads';
    return 'quoter';
  }

  var currentPage = detectPage();

  function insertToggleButton() {
    if (document.getElementById('ogm-a11y-btn')) return document.getElementById('ogm-a11y-btn');

    var themeBtn = document.getElementById('ogm-theme-toggle')
      || document.querySelector('.ogm-theme-toggle-btn')
      || document.querySelector('[data-ogm-theme-toggle]');

    var btn = document.createElement('button');
    btn.id = 'ogm-a11y-btn';
    btn.type = 'button';
    btn.className = 'ogm-a11y-btn' + (settings.on ? ' is-on' : '');
    btn.title = 'Accessibility mode';
    btn.setAttribute('aria-label', 'Toggle accessibility mode');
    btn.setAttribute('aria-pressed', settings.on ? 'true' : 'false');
    btn.textContent = '🎤';

    var holdTimer = null;
    function startHold() {
      clearTimeout(holdTimer);
      holdTimer = setTimeout(showSettings, 800);
    }
    function endHold() { clearTimeout(holdTimer); }

    btn.addEventListener('mousedown', startHold);
    btn.addEventListener('mouseup', endHold);
    btn.addEventListener('mouseleave', endHold);
    btn.addEventListener('touchstart', startHold, { passive: true });
    btn.addEventListener('touchend', endHold);
    btn.addEventListener('click', function () {
      if (!settingsJustShown) toggleMode();
    });

    if (themeBtn && themeBtn.parentNode) {
      themeBtn.parentNode.insertBefore(btn, themeBtn.nextSibling);
    } else {
      var navRight = document.querySelector('.ogm-nav-right, .ph-right, .hdr-actions, .top-actions');
      if (navRight) navRight.appendChild(btn);
      else document.body.appendChild(btn);
    }
    return btn;
  }

  function toggleMode() {
    settings.on = !settings.on;
    saveSettings();
    applyMode();
  }

  function applyMode() {
    var btn = document.getElementById('ogm-a11y-btn');
    document.body.classList.toggle('a11y-mode', !!settings.on);
    if (btn) {
      btn.classList.toggle('is-on', !!settings.on);
      btn.setAttribute('aria-pressed', settings.on ? 'true' : 'false');
    }
    if (!settings.on) {
      document.body.classList.remove('a11y-text-lg', 'a11y-text-xl', 'a11y-dwell');
      stopVoice();
      stopDwell();
      hideMicFab();
      hideSettings();
      return;
    }
    applyTextSize();
    if (settings.voice) startVoice();
    else stopVoice();
    if (settings.dwell) startDwell();
    else stopDwell();
    showMicFab();
  }

  function applyTextSize() {
    document.body.classList.remove('a11y-text-lg', 'a11y-text-xl');
    if (!settings.on || !settings.largeCtrl) return;
    if (settings.textSize === 1) document.body.classList.add('a11y-text-lg');
    if (settings.textSize === 2) document.body.classList.add('a11y-text-xl');
  }

  var UNIVERSAL_COMMANDS = {
    'hub': function () { goToPage('hub.php'); },
    'stone': function () { goToPage('index.php'); },
    'glass': function () { goToPage('glass-quoter.php'); },
    'shower': function () { goToPage('shower-builder.php'); },
    'customers': function () { goToPage('customer-db.php'); },
    'jobs': function () { goToPage('job-tracking.php'); },
    'invoices': function () { goToPage('invoice-manager.php'); },
    'reports': function () { goToPage('sales-reports.php'); },
    'schedule': function () { goToPage('calendar.php'); },
    'leads': function () { goToPage('website-leads.php'); },
    'open hub': function () { goToPage('hub.php'); },
    'open customers': function () { goToPage('customer-db.php'); },
    'open jobs': function () { goToPage('job-tracking.php'); },
    'open calendar': function () { goToPage('calendar.php'); },
    'open glass': function () { goToPage('glass-quoter.php'); },
    'open stone': function () { goToPage('index.php'); },
    'open shower': function () { goToPage('shower-builder.php'); },
    'open invoices': function () { goToPage('invoice-manager.php'); },
    'open reports': function () { goToPage('sales-reports.php'); },
    'open schedule': function () { goToPage('calendar.php'); },
    'open leads': function () { goToPage('website-leads.php'); },
    'next field': function () { focusNext(1); },
    'tab': function () { focusNext(1); },
    'next': function () { focusNext(1); },
    'previous field': function () { focusNext(-1); },
    'previous': function () { focusNext(-1); },
    'go back': function () { history.back(); },
    'back': function () { history.back(); },
    'enter': function () { tryKeyboard('Enter', false); clickFocused(); },
    'escape': function () { tryKeyboard('Escape', false); },
    'click': function () { clickFocused(); },
    'copy': function () { document.execCommand('copy'); tryKeyboard('c', true); },
    'paste': function () { document.execCommand('paste'); tryKeyboard('v', true); },
    'select all': function () { selectFocusedText(); tryKeyboard('a', true); },
    'delete text': clearFocused,
    'clear field': clearFocused,
    'clear': clearFocused,
    'undo': function () { document.execCommand('undo'); tryKeyboard('z', true); },
    'save quote': function () { tryKeyboard('s', true); },
    'save': function () { tryKeyboard('s', true); },
    'calculate': function () { clickFirst('[onclick*="recalc"], [onclick*="calculate"], #btn-calc'); },
    'generate proposal': function () { clickFirst('.btn-gen, [onclick*="openPrint"]'); },
    'scroll down': function () { scrollBy(0, 300); },
    'scroll up': function () { scrollBy(0, -300); },
    'scroll to top': function () { scrollTo(0, 0); },
    'scroll to bottom': function () { scrollTo(0, document.body.scrollHeight); }
  };

  var PAGE_COMMANDS = {
    quoter: {
      'job name': function () { focusFirst('#job-name'); },
      'sales person': function () { focusFirst('#salesperson'); },
      'salesperson': function () { focusFirst('#salesperson'); },
      'date': function () { focusFirst('#quote-date'); },
      'name': function () { focusFirst('#cust-name'); },
      'phone': function () { focusFirst('#cust-phone'); },
      'email': function () { focusFirst('#cust-email'); },
      'address': function () { focusFirst('#cust-addr'); },
      'city': function () { focusFirst('#cust-city'); },
      'job': function () { focusFirst('#job-name'); },
      'installation address': function () { focusFirst('#cust-install-addr, #cust-addr'); },
      'install address': function () { focusFirst('#cust-install-addr, #cust-addr'); },
      'installation city': function () { focusFirst('#cust-install-city, #cust-city'); },
      'install city': function () { focusFirst('#cust-install-city, #cust-city'); },
      'room type': function () { focusActiveRoomControl('type'); },
      'pricing method': function () { focusActiveRoomControl('method'); },
      'custom': function () { focusActiveRoomControl('custom'); },
      'length': function () { focusRoomRunInput('counter', 0); },
      'width': function () { focusRoomRunInput('counter', 1); },
      'countertop run': function () { addRoomRunByVoice('counter'); },
      'counter top run': function () { addRoomRunByVoice('counter'); },
      'backsplash': function () { addRoomRunByVoice('splash'); },
      'backsplash length': function () { focusRoomRunInput('splash', 0); },
      'backsplash height': function () { focusRoomRunInput('splash', 1); },
      'generate proposal': function () { clickFirst('[onclick*="openPrint"]'); },
      'deposit receipt': function () { clickFirst('[onclick*="openDepositDocsModal"]'); },
      'deposit receipt and invoice': function () { clickFirst('[onclick*="openDepositDocsModal"]'); },
      'convert to invoice': function () { clickFirst('[onclick*="openInvoice"]'); },
      'copy to clipboard': function () { clickFirst('[onclick*="copyQuote"]'); },
      'send to job tracking': function () { clickFirst('[onclick*="sendToClickUp"]'); },
      'new quote': function () { clickFirst('[onclick*="clearAll"], [onclick*="newQuote"]'); },
      'save quote file': function () { clickFirst('[onclick*="saveQuoteFile"]'); },
      'load quote file': function () { clickFirst('[onclick*="quote-file-input"], [onclick*="loadQuoteFile"], label[for="quote-file-input"]'); focusFirst('#quote-file-input'); },
      'primary sink': function () { focusFirst('#sink-main'); },
      'primary sink selection': function () { focusFirst('#sink-selection'); },
      'secondary prep sink': function () { focusFirst('#sink-prep'); },
      'secondary sink': function () { focusFirst('#sink-prep'); },
      'secondary sink selection': function () { focusFirst('#sink-selection-2'); },
      'edge profile': function () { focusFirst('#edge'); },
      'cooktop range': function () { focusFirst('#range'); },
      'cooktop': function () { focusFirst('#range'); },
      'support bar': function () { focusFirst('#support'); },
      'countertop removal': function () { focusFirst('#removal'); },
      'no removal': function () { selectByText('#removal', 'no removal'); },
      'new cabinetry': function () { selectByText('#removal', 'new cabinetry'); },
      'customer provided': function () { selectByText('#removal', 'customer provided'); },
      'laminate removal': function () { selectByText('#removal', 'laminate removal'); },
      'corian removal': function () { selectByText('#removal', 'corian removal'); },
      'granite removal': function () { selectByText('#removal', 'granite removal'); },
      'tile removal': function () { selectByText('#removal', 'tile removal'); },
      'island surcharge': function () { focusFirst('#island'); },
      'distance': function () { focusFirst('#distance'); },
      'additional': function () { focusFirst('#addl-labor'); },
      'additional fee': function () { focusFirst('#addl-labor'); },
      'fee': function () { focusFirst('#cc-fee'); },
      'add room': function () { clickFirst('[onclick*="addRoom"]'); },
      'delete room': function () { clickFirst('.quote-tab-delete, .room-remove, [onclick*="removeRoom"]'); },
      'add sink': function () { clickFirst('[onclick*="openSinkEditor"]'); },
      'open layout': function () { clickByText('button, .layout-tab, .view-tab', 'layout'); },
      'open design': function () { clickByText('button, .layout-tab, .view-tab', 'design'); },
      'open assembly': function () { clickByText('button, .layout-tab, .view-tab', 'assembly'); },
      'print proposal': function () { clickFirst('.btn-gen, [onclick*="openPrint"]'); },
      'save quote': function () { tryKeyboard('s', true); },
      'next room': function () { moveActive('.quote-tab', 1); },
      'previous room': function () { moveActive('.quote-tab', -1); },
      'one': function () { setFocusedNumberOrSelect('1'); },
      'two': function () { setFocusedNumberOrSelect('2'); },
      'three': function () { setFocusedNumberOrSelect('3'); },
      'four': function () { setFocusedNumberOrSelect('4'); },
      'five': function () { setFocusedNumberOrSelect('5'); },
      'six': function () { setFocusedNumberOrSelect('6'); },
      'seven': function () { setFocusedNumberOrSelect('7'); },
      'eight': function () { setFocusedNumberOrSelect('8'); },
      'nine': function () { setFocusedNumberOrSelect('9'); },
      'ten': function () { setFocusedNumberOrSelect('10'); }
    },
    glass: {
      'job name': function () { focusFirst('#job-name'); },
      'sales person': function () { focusFirst('#salesperson'); },
      'salesperson': function () { focusFirst('#salesperson'); },
      'date': function () { focusFirst('#quote-date'); },
      'name': function () { focusFirst('#cust-name'); },
      'phone': function () { focusFirst('#cust-phone'); },
      'email': function () { focusFirst('#cust-email'); },
      'address': function () { focusFirst('#svc-addr'); },
      'city': function () { focusFirst('#svc-city'); },
      'job': function () { focusFirst('#job-name'); },
      'fee': function () { focusFirst('#cc-fee'); },
      'save quote': function () { clickFirst('[onclick*="saveQuote"]'); },
      'send to job tracking': function () { clickFirst('[onclick*="sendGlassToClickUp"]'); },
      'generate proposal': function () { clickFirst('[onclick*="openProposal"]'); },
      'add line': function () { clickFirst('[onclick*="addLine"], [onclick*="addItem"], [onclick*="addCustomItem"]'); },
      'remove line': function () { clickRowDelete(); },
      'remove last line': function () { clickLastRowDelete(); },
      'add shower enclosure': function () { clickQuickAdd('shower enclosure'); },
      'add shower door': function () { clickQuickAdd('shower door only'); },
      'add shower door only': function () { clickQuickAdd('shower door only'); },
      'add tub enclosure': function () { clickQuickAdd('tub enclosure'); },
      'add float glass': function () { clickQuickAdd('float glass'); },
      'add tempered glass': function () { clickQuickAdd('tempered glass'); },
      'add mirror glass': function () { clickQuickAdd('mirror glass'); },
      'add plexiglass': function () { clickQuickAdd('plexiglass'); },
      'add acrylic': function () { clickQuickAdd('plexiglass'); },
      'add white starboard': function () { clickQuickAdd('white starboard'); },
      'add starboard': function () { clickQuickAdd('white starboard'); },
      'add insulated glass': function () { clickQuickAdd('insulated glass unit'); },
      'add insulated glass unit': function () { clickQuickAdd('insulated glass unit'); },
      'add igu': function () { clickQuickAdd('insulated glass unit'); },
      'add beveled glass': function () { clickQuickAdd('beveled glass strips'); },
      'add beveled glass strips': function () { clickQuickAdd('beveled glass strips'); },
      'add beveled mirror': function () { clickQuickAdd('beveled mirrors'); },
      'add beveled mirrors': function () { clickQuickAdd('beveled mirrors'); },
      'add plate covers': function () { clickQuickAdd('mirrored plate covers'); },
      'add mirrored plate covers': function () { clickQuickAdd('mirrored plate covers'); },
      'add wine room': function () { clickQuickAdd('wine room'); },
      'add wine cellar': function () { clickQuickAdd('wine room'); },
      'add glass railing': function () { clickQuickAdd('glass railing'); },
      'add office partition': function () { clickQuickAdd('office partition'); },
      'add storefront': function () { clickQuickAdd('storefront'); },
      'add glass backsplash': function () { clickQuickAdd('glass backsplash'); },
      'add backsplash': function () { clickQuickAdd('glass backsplash'); },
      'add window': function () { clickQuickAdd('window'); },
      'add barn door': function () { clickQuickAdd('barn door'); },
      'add glass shelving': function () { clickQuickAdd('glass shelving'); },
      'add shelving': function () { clickQuickAdd('glass shelving'); },
      'add table top': function () { clickQuickAdd('table top'); },
      'add tabletop': function () { clickQuickAdd('table top'); },
      'add hardware': function () { clickQuickAdd('hardware only'); },
      'add hardware only': function () { clickQuickAdd('hardware only'); },
      'add labor': function () { clickQuickAdd('labor'); },
      'add installation': function () { clickQuickAdd('labor'); },
      'add custom item': function () { clickQuickAdd('custom item'); }
    },
    shower: {
      'alcove': function () { clickByText('.type-btn', 'alcove'); },
      'corner': function () { clickByText('.type-btn', 'corner'); },
      'walk in': function () { clickByText('.type-btn', 'walk-in'); },
      'walk-in': function () { clickByText('.type-btn', 'walk-in'); },
      'neo angle': function () { clickByText('.type-btn', 'neo-angle'); },
      'neo-angle': function () { clickByText('.type-btn', 'neo-angle'); },
      'tub enclosure': function () { clickByText('.type-btn', 'tub'); },
      'tub encl': function () { clickByText('.type-btn', 'tub'); },
      'custom': function () { clickByText('.type-btn', 'custom'); },
      'door': function () { focusFirst('#door-type'); },
      'swing out': function () { selectByText('#door-type', 'swing out'); },
      'swing out frameless': function () { selectByText('#door-type', 'swing out'); },
      'hinge left': function () { clickByText('#door-side-row button', 'hinge left'); },
      'hinge right': function () { clickByText('#door-side-row button', 'hinge right'); },
      'frame type': function () { focusFirstButtonByText('.sb-section button', 'frameless'); },
      'frameless': function () { clickByText('.tog-btn', 'frameless'); },
      'semi frame': function () { clickByText('.tog-btn', 'semi-frame'); },
      'semi-frame': function () { clickByText('.tog-btn', 'semi-frame'); },
      'framed': function () { clickByText('.tog-btn', 'framed'); },
      'glass type': function () { focusFirstButtonByText('.glass-chip', 'clear'); },
      'clear': function () { clickByText('.glass-chip', 'clear'); },
      'low iron': function () { clickByText('.glass-chip', 'low-iron'); },
      'low-iron': function () { clickByText('.glass-chip', 'low-iron'); },
      'frosted': function () { clickByText('.glass-chip', 'frosted'); },
      'rain': function () { clickByText('.glass-chip', 'rain'); },
      'privacy': function () { clickByText('.glass-chip', 'privacy'); },
      'glass thickness': function () { focusFirstButtonByText('.tog-btn', '3/8'); },
      'three eighths': function () { clickByText('.tog-btn', '3/8'); },
      'three eight': function () { clickByText('.tog-btn', '3/8'); },
      'three eights': function () { clickByText('.tog-btn', '3/8'); },
      '3/8': function () { clickByText('.tog-btn', '3/8'); },
      'half inch': function () { clickByText('.tog-btn', '1/2'); },
      'one half': function () { clickByText('.tog-btn', '1/2'); },
      '1/2': function () { clickByText('.tog-btn', '1/2'); },
      'add panel': function () { clickFirst('[onclick*="addPanel"], [onclick*="addSection"]'); },
      'next section': function () { focusNext(1); }
    },
    jobs: {
      'sync': function () { clickFirst('[onclick*="syncJobs"], [onclick*="sync"], #btn-sync'); },
      'settings': function () { clickFirst('[onclick*="openSettings"]'); },
      'sync calendar': function () { clickFirst('#sync-btn, [onclick*="loadEvents"]'); },
      'new job': function () { clickFirst('[onclick*="openNewJob"], #btn-new-job, [onclick*="openJobModal"], [onclick*="openNewJobModal"]'); },
      'search jobs': function () { focusFirst('#search-inp, #job-search, input[placeholder*="Search"], input[type="search"]'); },
      'search': function () { focusFirst('#job-search, input[placeholder*="Search"], input[type="search"]'); },
      'list view': function () { clickFirst('#view-list-btn'); },
      'kanban view': function () { clickFirst('#view-kanban-btn'); },
      'all': function () { setJobsProductFilter('all'); },
      'countertop': function () { setJobsProductFilter('counter'); },
      'shower': function () { setJobsProductFilter('shower'); },
      'glass': function () { setJobsProductFilter('glass'); },
      'all stages': function () { setJobsBoardFilter('all'); },
      'sales': function () { setJobsBoardFilter('Sales'); },
      'engineering': function () { setJobsBoardFilter('Engineering'); },
      'production': function () { setJobsBoardFilter('Production'); },
      'finance': function () { setJobsBoardFilter('Finance'); },
      'sent follow up': function () { selectByText('#nj-phase', 'sent / follow up') || clickByText('.kanban-col, .kanban-col-title, button', 'sent'); },
      'sent follow-up': function () { selectByText('#nj-phase', 'sent / follow up') || clickByText('.kanban-col, .kanban-col-title, button', 'sent'); },
      'completed jobs': function () { clickFirst("#view-completed-btn, [onclick*=\"setJobsScope('completed')\"]"); },
      'mark complete': function () { clickByText('button', 'mark complete'); },
      'open clickup': function () { openClickUp(); }
    },
    customers: {
      'new customer': function () { clickFirst('[onclick*="openAddCustomer"]'); },
      'edit customer': function () { clickFirst('[onclick*="openEditCustomer"]'); },
      'add note': function () { clickFirst('[onclick*="openAddNote"]'); },
      'search customer': function () { focusFirst('#search-inp, input[placeholder*="Name"], input[type="search"]'); },
      'duplicate watch': function () { clickFirst('[onclick*="openDuplicateWatch"]'); },
      'dedupe phone': function () { clickFirst('[onclick*="dedupeCustomersByPhone"]'); },
      'dedupe email': function () { clickFirst('[onclick*="dedupeCustomersByEmail"]'); }
    },
    invoice: {
      'new customer': function () { clickFirst('[onclick*="openAddCustomer"]'); }
    },
    reports: {},
    hub: {},
    calendar: {
      'new event': function () { clickFirst('#new-event-btn, [onclick*="openNewEvent"]'); },
      'title': function () { focusFirst('#ev-title'); },
      'install': function () { calendarQuickType('Install'); },
      'customer job': function () { focusFirst('#ev-customer'); },
      'customer': function () { focusFirst('#ev-customer'); },
      'job': function () { focusFirst('#ev-customer'); },
      'calendar': function () { focusFirst('#ev-calendar'); },
      'all day event': function () { toggleCalendarCheckbox('#ev-allday', true); },
      'all-day event': function () { toggleCalendarCheckbox('#ev-allday', true); },
      'all day': function () { toggleCalendarCheckbox('#ev-allday', true); },
      'date': function () { focusFirst('#ev-date'); },
      'end date': function () { focusFirst('#ev-enddate'); },
      'start time': function () { focusFirst('#ev-start'); },
      'end time': function () { focusFirst('#ev-end'); },
      'details': function () { focusFirst('#ev-desc'); },
      'description': function () { focusFirst('#ev-desc'); },
      'notes': function () { focusFirst('#ev-desc'); },
      'description notes': function () { focusFirst('#ev-desc'); },
      'address': function () { focusFirst('#ev-desc'); },
      'stone': function () { focusFirst('#ev-desc'); },
      'special instructions': function () { focusFirst('#ev-desc'); },
      'assigned to': function () { focusFirst('#ev-assigned'); },
      'assigned': function () { focusFirst('#ev-assigned'); },
      'save event': function () { clickFirst('[onclick*="saveEvent"]'); },
      'cancel': function () { clickFirst('#event-modal [onclick*="closeMo"], #event-modal .mbtn-ghost'); },
      'template': function () { calendarQuickType('Template'); },
      'day off': function () { calendarQuickType('Day Off'); },
      'meeting': function () { calendarQuickType('Meeting'); },
      'delivery': function () { calendarQuickType('Delivery'); },
      'measure': function () { calendarQuickType('Measure'); },
      'today': function () { clickFirst('.cal-today-btn, [onclick*="goToday"]'); },
      'next week': function () { if (typeof window.navigate === 'function') window.navigate(1); else clickByText('button', '›'); },
      'previous week': function () { if (typeof window.navigate === 'function') window.navigate(-1); else clickByText('button', '‹'); },
      'month view': function () { clickFirst('#vbtn-month'); },
      'week view': function () { clickFirst('#vbtn-week'); },
      'sync calendar': function () { clickFirst('#sync-btn, [onclick*="loadEvents"]'); }
    }
  };

  function getCommands() {
    return Object.assign({}, UNIVERSAL_COMMANDS, PAGE_COMMANDS[currentPage] || {});
  }

  function startVoice() {
    if (recognition || voiceActive) return;
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
      console.warn('[OGM A11y] Web Speech API is available in Chrome or Edge.');
      return;
    }
    recognition = new SpeechRecognition();
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.lang = 'en-US';
    recognition.maxAlternatives = 1;
    voiceActive = true;

    recognition.onstart = function () {
      setListening(true);
    };
    recognition.onresult = function (event) {
      var interim = '';
      var finalText = '';
      for (var i = event.resultIndex; i < event.results.length; i++) {
        var text = String(event.results[i][0].transcript || '').trim().toLowerCase();
        if (event.results[i].isFinal) finalText += text + ' ';
        else interim += text + ' ';
      }
      if (interim && settings.voiceFB) showVoiceStrip(interim.trim(), false);
      if (finalText) handleFinalTranscript(finalText.trim());
    };
    recognition.onerror = function (event) {
      if (event.error !== 'no-speech') console.warn('[OGM A11y] Speech error:', event.error);
    };
    recognition.onend = function () {
      setListening(false);
      if (settings.on && settings.voice && voiceActive) {
        setTimeout(function () { try { recognition && recognition.start(); } catch (_) {} }, 300);
      }
    };
    try { recognition.start(); } catch (_) {}
  }

  function stopVoice() {
    voiceActive = false;
    if (recognition) {
      try { recognition.stop(); } catch (_) {}
      recognition = null;
    }
    setListening(false);
    hideVoiceStrip();
  }

  function setListening(on) {
    var btn = document.getElementById('ogm-a11y-btn');
    var fab = document.getElementById('ogm-mic-fab');
    if (btn) btn.classList.toggle('is-listening', !!on);
    if (fab) fab.classList.toggle('is-listening', !!on);
  }

  function handleFinalTranscript(text) {
    hideVoiceStrip();
    if (handleDictationCommand(text)) return;

    var commands = getCommands();
    var keys = Object.keys(commands).sort(function (a, b) { return b.length - a.length; });
    for (var i = 0; i < keys.length; i++) {
      if (text.indexOf(keys[i]) !== -1) {
        commands[keys[i]]();
        return;
      }
    }
    if (text.indexOf('select ') === 0 && voiceSelectOption(text.slice(7).trim())) return;

    var el = document.activeElement;
    if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') && el.type !== 'checkbox' && el.type !== 'radio') {
      el.value = (el.value ? el.value + ' ' : '') + capitalize(text);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      if (settings.voiceFB) showVoiceStrip('Typed: ' + capitalize(text), true);
    }
  }

  function voiceSelectOption(query) {
    var active = document.activeElement;
    var selects = active && active.tagName === 'SELECT' ? [active] : Array.from(document.querySelectorAll('select'));
    for (var i = 0; i < selects.length; i++) {
      var options = Array.from(selects[i].options || []);
      for (var j = 0; j < options.length; j++) {
        if (String(options[j].text || '').toLowerCase().indexOf(query) !== -1) {
          selects[i].value = options[j].value;
          selects[i].dispatchEvent(new Event('change', { bubbles: true }));
          if (settings.voiceFB) showVoiceStrip('Selected: ' + options[j].text, true);
          return true;
        }
      }
    }
    return false;
  }

  function showMicFab() {
    var fab = document.getElementById('ogm-mic-fab');
    if (!fab) {
      fab = document.createElement('button');
      fab.id = 'ogm-mic-fab';
      fab.type = 'button';
      fab.title = 'Voice input';
      fab.setAttribute('aria-label', 'Voice input');
      fab.textContent = '🎤';
      fab.addEventListener('click', function () {
        if (settings.voice) {
          voiceActive ? stopVoice() : startVoice();
        }
      });
      document.body.appendChild(fab);
    }
    fab.style.display = 'flex';
  }

  function hideMicFab() {
    var fab = document.getElementById('ogm-mic-fab');
    if (fab) fab.style.display = 'none';
  }

  function showVoiceStrip(text, isFinal) {
    var strip = document.getElementById('ogm-voice-strip');
    var label = document.getElementById('ogm-voice-strip-text');
    if (!strip || !label) return;
    label.textContent = text;
    strip.classList.add('visible');
    if (isFinal) setTimeout(function () { strip.classList.remove('visible'); }, 1800);
  }

  function hideVoiceStrip() {
    var strip = document.getElementById('ogm-voice-strip');
    if (strip) strip.classList.remove('visible');
  }

  function startDwell() {
    document.body.classList.add('a11y-dwell');
    if (!dwellRing) {
      dwellRing = document.createElement('div');
      dwellRing.className = 'ogm-dwell-ring';
      dwellRing.innerHTML = '<svg width="52" height="52"><circle id="ogm-dwell-circle" cx="26" cy="26" r="23"/></svg>';
      document.body.appendChild(dwellRing);
    }
    document.addEventListener('mouseover', onDwellEnter);
    document.addEventListener('mouseout', onDwellLeave);
  }

  function stopDwell() {
    document.body.classList.remove('a11y-dwell');
    clearTimeout(dwellTimer);
    cancelAnimationFrame(dwellRAF);
    document.removeEventListener('mouseover', onDwellEnter);
    document.removeEventListener('mouseout', onDwellLeave);
    if (dwellRing) dwellRing.style.display = 'none';
  }

  function onDwellEnter(event) {
    var el = event.target && event.target.closest && event.target.closest('button, a[href], select, [role="button"], .menu-link, .view-tab');
    if (!el) return;
    dwellTarget = el;
    dwellStart = Date.now();
    positionDwellRing(event);
    if (dwellRing) dwellRing.style.display = 'block';
    animateDwell();
    dwellTimer = setTimeout(function () {
      if (dwellTarget === el) {
        el.click();
        if (dwellRing) dwellRing.style.display = 'none';
      }
    }, settings.dwellMs);
  }

  function onDwellLeave() {
    clearTimeout(dwellTimer);
    cancelAnimationFrame(dwellRAF);
    dwellTarget = null;
    if (dwellRing) dwellRing.style.display = 'none';
  }

  function positionDwellRing(event) {
    dwellRing.style.left = (event.clientX - 26) + 'px';
    dwellRing.style.top = (event.clientY - 26) + 'px';
  }

  function animateDwell() {
    var circle = document.getElementById('ogm-dwell-circle');
    if (!circle) return;
    var circ = 2 * Math.PI * 23;
    circle.style.strokeDasharray = circ;
    function step() {
      if (!dwellTarget) return;
      var progress = Math.min((Date.now() - dwellStart) / settings.dwellMs, 1);
      circle.style.strokeDashoffset = circ * (1 - progress);
      if (progress < 1) dwellRAF = requestAnimationFrame(step);
    }
    dwellRAF = requestAnimationFrame(step);
  }

  function focusNext(dir) {
    var all = Array.from(document.querySelectorAll('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'))
      .filter(function (el) { return el.offsetParent !== null && el.id !== 'ogm-a11y-btn' && el.id !== 'ogm-mic-fab'; });
    var idx = all.indexOf(document.activeElement);
    var next = all[Math.max(0, Math.min(all.length - 1, idx + dir))];
    if (next) {
      next.focus();
      next.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
  }

  function clearFocused() {
    var el = document.activeElement;
    if (!el) return;
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      el.value = '';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    } else if (el.tagName === 'SELECT') {
      el.selectedIndex = 0;
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function handleDictationCommand(text) {
    var m = text.match(/^(type|append)\s+(.+)$/);
    if (m) {
      insertIntoFocused(m[2], true);
      return true;
    }
    m = text.match(/^(replace field with|set field to)\s+(.+)$/);
    if (m) {
      insertIntoFocused(m[2], false);
      return true;
    }
    m = text.match(/^number\s+(.+)$/);
    if (m) {
      insertIntoFocused(normalizeSpokenNumber(m[1]), false);
      return true;
    }
    m = text.match(/^phone\s+(.+)$/);
    if (m) {
      insertIntoFocused(formatSpokenPhone(m[1]), false);
      return true;
    }
    m = text.match(/^email\s+(.+)$/);
    if (m) {
      insertIntoFocused(formatSpokenEmail(m[1]), false);
      return true;
    }
    return false;
  }

  function insertIntoFocused(value, append) {
    var el = document.activeElement;
    if (!el || (el.tagName !== 'INPUT' && el.tagName !== 'TEXTAREA')) return false;
    if (el.type === 'checkbox' || el.type === 'radio') return false;
    var text = String(value || '').trim();
    el.value = append && el.value ? el.value + ' ' + text : text;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    if (settings.voiceFB) showVoiceStrip((append ? 'Typed: ' : 'Set field: ') + text, true);
    return true;
  }

  function selectFocusedText() {
    var el = document.activeElement;
    if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') && typeof el.select === 'function') {
      el.select();
    }
  }

  function clickFocused() {
    var el = document.activeElement;
    if (el && typeof el.click === 'function' && el !== document.body) {
      el.click();
    }
  }

  function focusFirst(selector) {
    var el = document.querySelector(selector);
    if (el) {
      el.focus();
      el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
  }

  function focusFirstButtonByText(selector, needle) {
    var target = String(needle || '').toLowerCase();
    var els = Array.from(document.querySelectorAll(selector));
    for (var i = 0; i < els.length; i++) {
      if (String(els[i].textContent || els[i].title || els[i].getAttribute('aria-label') || '').toLowerCase().indexOf(target) !== -1) {
        return focusElement(els[i]);
      }
    }
    return false;
  }

  function getActiveStoneRoomId() {
    var activeTab = document.querySelector('.quote-tab.active[data-room-id]');
    if (activeTab && activeTab.dataset.roomId) return activeTab.dataset.roomId;
    var visibleCard = Array.from(document.querySelectorAll('.room-card[data-room-id]')).find(function (card) {
      return card.offsetParent !== null && getComputedStyle(card).display !== 'none';
    });
    if (visibleCard && visibleCard.dataset.roomId) return visibleCard.dataset.roomId;
    var firstCard = document.querySelector('.room-card[data-room-id]');
    return firstCard && firstCard.dataset.roomId ? firstCard.dataset.roomId : '';
  }

  function focusActiveRoomControl(kind) {
    var roomId = getActiveStoneRoomId();
    if (!roomId) return false;
    var suffix = kind === 'type' ? 'type' : kind === 'method' ? 'method' : 'custom';
    return focusSelector('#room-' + suffix + '-' + roomId);
  }

  function focusRoomRunInput(kind, inputIndex) {
    var roomId = getActiveStoneRoomId();
    if (!roomId) return false;
    var container = document.querySelector('#room-' + kind + '-runs-' + roomId);
    if (!container) return false;
    var rows = Array.from(container.querySelectorAll('.dim-row'));
    if (!rows.length) {
      addRoomRunByVoice(kind);
      rows = Array.from(container.querySelectorAll('.dim-row'));
    }
    var row = rows.find(function (item) {
      var inputs = item.querySelectorAll('input');
      return inputs[inputIndex] && !String(inputs[inputIndex].value || '').trim();
    }) || rows[rows.length - 1];
    var inputs = row ? row.querySelectorAll('input') : [];
    return focusElement(inputs[inputIndex]);
  }

  function addRoomRunByVoice(kind) {
    var roomId = getActiveStoneRoomId();
    if (!roomId) return false;
    if (typeof window.addRoomRun === 'function') {
      window.addRoomRun(parseInt(roomId, 10), kind);
      return true;
    }
    var selector = kind === 'splash'
      ? "#room-splash-runs-" + roomId + " + button, button[onclick*=\"addRoomRun(" + roomId + ",'splash')\"]"
      : "#room-counter-runs-" + roomId + " + button, button[onclick*=\"addRoomRun(" + roomId + ",'counter')\"]";
    return clickSelector(selector);
  }

  function focusSelector(selector) {
    return focusElement(document.querySelector(selector));
  }

  function focusElement(el) {
    if (!el) return false;
    el.focus();
    if (typeof el.select === 'function' && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) el.select();
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    return true;
  }

  function clickByText(selector, needle) {
    var target = String(needle || '').toLowerCase();
    var els = Array.from(document.querySelectorAll(selector));
    for (var i = 0; i < els.length; i++) {
      if (String(els[i].textContent || els[i].title || els[i].getAttribute('aria-label') || '').toLowerCase().indexOf(target) !== -1) {
        els[i].click();
        return true;
      }
    }
    return false;
  }

  function clickSelector(selector) {
    var el = document.querySelector(selector);
    if (!el) return false;
    el.click();
    return true;
  }

  function selectByText(selector, needle) {
    var select = document.querySelector(selector);
    if (!select || select.tagName !== 'SELECT') return false;
    var target = String(needle || '').toLowerCase();
    var options = Array.from(select.options || []);
    for (var i = 0; i < options.length; i++) {
      if (String(options[i].text || '').toLowerCase().indexOf(target) !== -1) {
        select.value = options[i].value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        focusElement(select);
        if (settings.voiceFB) showVoiceStrip('Selected: ' + options[i].text, true);
        return true;
      }
    }
    return false;
  }

  function setFocusedNumberOrSelect(value) {
    var el = document.activeElement;
    if (!el) return false;
    if (el.tagName === 'SELECT') {
      var wanted = String(value);
      var opts = Array.from(el.options || []);
      for (var i = 0; i < opts.length; i++) {
        if (String(opts[i].value) === wanted || String(opts[i].text).trim() === wanted) {
          el.value = opts[i].value;
          el.dispatchEvent(new Event('change', { bubbles: true }));
          return true;
        }
      }
      return false;
    }
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      el.value = String(value);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }
    return false;
  }

  function clickQuickAdd(needle) {
    if (clickByText('#quick-btns .qb, .quick-grid .qb', needle)) return true;
    return clickByText('button', needle);
  }

  function calendarQuickType(type) {
    var modal = document.getElementById('event-modal');
    var modalOpen = modal && (modal.classList.contains('open') || getComputedStyle(modal).display !== 'none');
    if (modalOpen && clickByText('#quick-type-row .qt-chip', type)) return true;
    if (typeof window.openNewEvent === 'function') {
      window.openNewEvent(type);
      return true;
    }
    return clickByText('.quick-types .qt-btn, #quick-type-row .qt-chip', type);
  }

  function toggleCalendarCheckbox(selector, wanted) {
    var el = document.querySelector(selector);
    if (!el) return false;
    if (typeof wanted === 'boolean') el.checked = wanted;
    else el.checked = !el.checked;
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return focusElement(el);
  }

  function setJobsProductFilter(value) {
    var btn = document.querySelector('.fc-type[data-f="' + value + '"]');
    if (btn) {
      btn.click();
      return true;
    }
    return false;
  }

  function setJobsBoardFilter(value) {
    var selector = value === 'all'
      ? '.fc-board[data-board="all"], .kb-dept-btn[data-board="all"]'
      : '.fc-board[data-board="' + value + '"], .kb-dept-btn[data-board="' + value + '"]';
    var btn = document.querySelector(selector);
    if (btn) {
      btn.click();
      return true;
    }
    return false;
  }

  function goToPage(path) {
    window.location.href = path;
  }

  function openClickUp() {
    var link = document.querySelector('a[href*="app.clickup.com"]');
    var url = link ? link.href : 'https://app.clickup.com/9017868498/v/cn/4-90174038740-8';
    window.open(url, '_blank', 'noopener');
  }

  function normalizeSpokenNumber(text) {
    var map = { zero:'0', oh:'0', o:'0', one:'1', two:'2', to:'2', too:'2', three:'3', four:'4', for:'4', five:'5', six:'6', seven:'7', eight:'8', ate:'8', nine:'9', point:'.', dot:'.' };
    return String(text || '').toLowerCase().split(/\s+/).map(function (part) {
      return map[part] !== undefined ? map[part] : part.replace(/[^0-9.]/g, '');
    }).join('').replace(/\.+/g, '.');
  }

  function formatSpokenPhone(text) {
    var digits = normalizeSpokenNumber(text).replace(/\D/g, '');
    if (digits.length > 10 && digits.charAt(0) === '1') digits = digits.slice(1);
    if (digits.length === 10) return '(' + digits.slice(0, 3) + ') ' + digits.slice(3, 6) + '-' + digits.slice(6);
    return digits;
  }

  function formatSpokenEmail(text) {
    return String(text || '')
      .toLowerCase()
      .replace(/\s+at\s+/g, '@')
      .replace(/\s+dot\s+/g, '.')
      .replace(/\s+underscore\s+/g, '_')
      .replace(/\s+dash\s+/g, '-')
      .replace(/\s+/g, '');
  }

  function tryKeyboard(key, ctrl) {
    var opts = { key: key, bubbles: true };
    if (ctrl) {
      opts.ctrlKey = true;
      opts.metaKey = true;
    }
    (document.activeElement || document.body).dispatchEvent(new KeyboardEvent('keydown', opts));
  }

  function clickFirst(selector) {
    var el = document.querySelector(selector);
    if (el) el.click();
  }

  function clickRowDelete() {
    var row = document.activeElement && document.activeElement.closest('tr, .line-row, .gl-row, .li-row');
    var del = row && row.querySelector('[onclick*="remove"], [onclick*="delete"], .del-btn, .li-del');
    if (del) del.click();
  }

  function clickLastRowDelete() {
    var rows = Array.from(document.querySelectorAll('tr, .line-row, .gl-row, .li-row'));
    for (var i = rows.length - 1; i >= 0; i--) {
      var del = rows[i].querySelector('[onclick*="remove"], [onclick*="delete"], .del-btn, .li-del');
      if (del) {
        del.click();
        return true;
      }
    }
    return false;
  }

  function moveActive(selector, dir) {
    var tabs = Array.from(document.querySelectorAll(selector));
    var active = document.querySelector(selector + '.active');
    var idx = tabs.indexOf(active);
    if (tabs[idx + dir]) tabs[idx + dir].click();
  }

  function capitalize(str) {
    return String(str || '').charAt(0).toUpperCase() + String(str || '').slice(1);
  }

  function showUndoToast(message, undoCallback, durationMs) {
    if (!settings.on) return;
    durationMs = durationMs || 2000;
    undoFn = undoCallback;
    var toast = document.getElementById('ogm-a11y-undo');
    var msg = document.getElementById('ogm-a11y-undo-msg');
    var bar = document.getElementById('ogm-a11y-undo-bar-fill');
    if (!toast || !msg) return;
    msg.textContent = message;
    toast.classList.add('visible');
    if (bar) {
      bar.style.transition = 'none';
      bar.style.width = '100%';
      requestAnimationFrame(function () {
        bar.style.transition = 'width ' + durationMs + 'ms linear';
        bar.style.width = '0%';
      });
    }
    clearTimeout(undoTimeout);
    undoTimeout = setTimeout(function () {
      toast.classList.remove('visible');
      undoFn = null;
    }, durationMs);
  }

  function showSettings() {
    settingsJustShown = true;
    setTimeout(function () { settingsJustShown = false; }, 300);
    var panel = document.getElementById('ogm-a11y-settings');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'ogm-a11y-settings';
      document.body.appendChild(panel);
    }
    panel.innerHTML = buildSettingsHTML();
    wireSettings(panel);
    panel.classList.add('visible');
  }

  function hideSettings() {
    var panel = document.getElementById('ogm-a11y-settings');
    if (panel) panel.classList.remove('visible');
  }

  function buildSettingsHTML() {
    function toggle(key, label) {
      return '<div class="a11y-setting-row"><span>' + label + '</span><button type="button" class="a11y-toggle' + (settings[key] ? ' on' : '') + '" data-key="' + key + '" aria-pressed="' + settings[key] + '"></button></div>';
    }
    return '<div class="a11y-settings-title">Accessibility Settings</div>'
      + toggle('voice', 'Voice input')
      + toggle('largeCtrl', 'Large controls')
      + toggle('voiceFB', 'Voice feedback')
      + toggle('dwell', 'Dwell clicking')
      + '<div class="a11y-setting-row"><span>Dwell time</span><select class="a11y-select" id="a11y-dwell-ms"><option value="1000">1s</option><option value="1500">1.5s</option><option value="2000">2s</option></select></div>'
      + '<div class="a11y-setting-row"><span>Text size</span><div class="a11y-text-size-row"><button type="button" class="a11y-size-btn" data-size="0">Normal</button><button type="button" class="a11y-size-btn" data-size="1">+1</button><button type="button" class="a11y-size-btn" data-size="2">+2</button></div></div>'
      + '<div class="a11y-setting-row" style="justify-content:center"><button type="button" class="a11y-size-btn" id="a11y-settings-close">Done</button></div>';
  }

  function wireSettings(panel) {
    var dwell = panel.querySelector('#a11y-dwell-ms');
    if (dwell) dwell.value = String(settings.dwellMs);
    panel.querySelectorAll('.a11y-size-btn[data-size]').forEach(function (btn) {
      btn.classList.toggle('active', Number(btn.dataset.size) === settings.textSize);
      btn.addEventListener('click', function () {
        settings.textSize = Number(btn.dataset.size);
        saveSettings();
        applyTextSize();
        showSettings();
      });
    });
    panel.querySelectorAll('.a11y-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        settings[btn.dataset.key] = !settings[btn.dataset.key];
        saveSettings();
        applyMode();
        showSettings();
      });
    });
    if (dwell) {
      dwell.addEventListener('change', function () {
        settings.dwellMs = Number(dwell.value);
        saveSettings();
        if (settings.dwell) {
          stopDwell();
          startDwell();
        }
      });
    }
    var close = panel.querySelector('#a11y-settings-close');
    if (close) close.addEventListener('click', hideSettings);
  }

  function buildDom() {
    if (!document.getElementById('ogm-voice-strip')) {
      var strip = document.createElement('div');
      strip.id = 'ogm-voice-strip';
      strip.innerHTML = '<span>🎤</span><span id="ogm-voice-strip-text">Listening...</span><button type="button" id="ogm-voice-strip-cancel">Cancel</button>';
      document.body.insertBefore(strip, document.body.firstChild);
      strip.querySelector('button').addEventListener('click', hideVoiceStrip);
    }
    if (!document.getElementById('ogm-a11y-undo')) {
      var undo = document.createElement('div');
      undo.id = 'ogm-a11y-undo';
      undo.innerHTML = '<span id="ogm-a11y-undo-msg"></span><div id="ogm-a11y-undo-bar"><div id="ogm-a11y-undo-bar-fill"></div></div><button type="button" id="ogm-a11y-undo-btn">Undo</button>';
      document.body.appendChild(undo);
      undo.querySelector('#ogm-a11y-undo-btn').addEventListener('click', function () {
        if (undoFn) undoFn();
        undoFn = null;
        clearTimeout(undoTimeout);
        undo.classList.remove('visible');
      });
    }
  }

  window.OGMAccessibility = {
    showUndoToast: showUndoToast,
    isOn: function () { return !!settings.on; },
    triggerVoice: function () { voiceActive ? stopVoice() : startVoice(); }
  };

  function init() {
    buildDom();
    insertToggleButton();
    applyMode();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
