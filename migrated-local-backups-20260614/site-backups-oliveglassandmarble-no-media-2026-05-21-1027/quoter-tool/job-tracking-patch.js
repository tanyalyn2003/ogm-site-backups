/**
 * OGM_JobTracking.html — drop-in function replacements
 * ─────────────────────────────────────────────────────────────────────────────
 * Replace the four functions listed below inside the <script> tag of
 * OGM_JobTracking.html.  Everything else in that file stays the same.
 *
 * Changes:
 *  1. createJob()      — after ClickUp task creation, calls link-clickup-task
 *                        so the CustomerDB record knows about this job.
 *  2. prefillNewJob()  — checks for existing ClickUp tasks linked to the same
 *                        customer BEFORE opening the modal; warns if found.
 *  3. addNote()        — posts notes to the server (customers-api.php) instead
 *                        of storing them in localStorage (device-only).
 *  4. renderNotes()    — loads notes from the server instead of localStorage.
 *
 * NEW helpers added (add these alongside the existing functions):
 *  • linkTaskToCustomer()
 *  • getServerNotes()
 *  • checkExistingJobs()
 * ─────────────────────────────────────────────────────────────────────────────
 */

/* ══════════════════════════════════════
   NEW HELPER: Link a ClickUp task to a CustomerDB customer record.
   Called right after createJob() succeeds.
══════════════════════════════════════ */
async function linkTaskToCustomer(customerId, taskId, quoteNumber) {
  if (!customerId || !taskId) return;
  try {
    await fetch('customers-api.php?action=link-clickup-task', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ customerId, taskId, quoteNumber: quoteNumber || '' })
    });
  } catch (e) {
    console.warn('[OGM] Could not link task to customer:', e);
  }
}

/* ══════════════════════════════════════
   NEW HELPER: Load server-side notes for a ClickUp task.
   Replaces the old localStorage read.
══════════════════════════════════════ */
async function getServerNotes(taskId) {
  try {
    const res = await fetch(`customers-api.php?action=get-notes&taskId=${encodeURIComponent(taskId)}`, {
      credentials: 'same-origin'
    });
    const data = await res.json();
    if (data.ok && Array.isArray(data.notes)) return data.notes;
  } catch (e) {
    console.warn('[OGM] Could not load server notes:', e);
  }
  // Fall back to localStorage so old notes aren't lost during the transition
  try {
    const local = JSON.parse(localStorage.getItem('ogm-notes-' + taskId) || '[]');
    return local;
  } catch (_) {
    return [];
  }
}

/* ══════════════════════════════════════
   NEW HELPER: Check if a customer already has ClickUp jobs.
   Returns array of existing job stubs { taskId, quoteNumber, createdAt }.
══════════════════════════════════════ */
async function checkExistingJobs(customerId, phone) {
  // Primary: look up by customerId
  if (customerId) {
    try {
      const res = await fetch(`customers-api.php?action=get-customer-jobs&id=${encodeURIComponent(customerId)}`, {
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (data.ok && Array.isArray(data.jobs) && data.jobs.length > 0) {
        return data.jobs;
      }
    } catch (e) { /* fall through */ }
  }

  // Secondary: phone-number search (catches cases where quoter had no customerId)
  if (phone && phone.replace(/\D/g, '').length >= 7) {
    try {
      const res = await fetch(`customers-api.php?action=search-by-phone&phone=${encodeURIComponent(phone)}`, {
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (data.ok && Array.isArray(data.customers)) {
        const allJobs = data.customers.flatMap(c => c.clickupJobs || []);
        if (allJobs.length > 0) return allJobs;
      }
    } catch (e) { /* ignore */ }
  }

  return [];
}

/* ══════════════════════════════════════
   REPLACEMENT: prefillNewJob()
   Checks for existing jobs and warns before opening the modal.
══════════════════════════════════════ */
// State for pending prefill (customerId + quoteNumber from the bridge)
let _pendingCustomerId  = '';
let _pendingQuoteNumber = '';

async function prefillNewJob(data) {
  // Store for use in createJob()
  _pendingCustomerId  = data.customerId  || '';
  _pendingQuoteNumber = data.quoteNumber || '';

  // Fill the modal form fields (same as before)
  openNewJob();
  if (data.customerName) document.getElementById('nj-name').value    = data.customerName;
  if (data.phone)        document.getElementById('nj-phone').value   = data.phone;
  if (data.address)      document.getElementById('nj-addr').value    = data.address;
  if (data.jobType)      document.getElementById('nj-type').value    = data.jobType;
  if (data.rep)          document.getElementById('nj-rep').value     = data.rep;
  if (data.notes)        document.getElementById('nj-notes').value   = data.notes;
  if (data.naKey) {
    const sel = document.getElementById('nj-action');
    if (sel) sel.value = data.naKey;
  }

  // ── NEW: Check for existing jobs for this customer ──────────────────────
  const existing = await checkExistingJobs(_pendingCustomerId, data.phone);
  if (existing.length === 0) return; // No existing jobs — proceed normally

  // Match existing task IDs against currently loaded allJobs
  const matchedJobs = existing
    .map(entry => allJobs.find(j => j.id === entry.taskId))
    .filter(Boolean);

  if (matchedJobs.length === 0) return; // Tasks exist in CustomerDB but not in this view

  // Show a banner inside the modal warning about existing jobs
  const warningId = 'existing-jobs-warning';
  let banner = document.getElementById(warningId);
  if (!banner) {
    banner = document.createElement('div');
    banner.id = warningId;
    banner.style.cssText = [
      'background:rgba(217,119,6,.12)',
      'border:1px solid rgba(217,119,6,.35)',
      'border-radius:2px',
      'padding:10px 14px',
      'font-size:12px',
      'color:#92400e',
      'margin-bottom:14px',
      'line-height:1.6',
    ].join(';');
    const modalBody = document.querySelector('#new-job-modal .mo-body');
    if (modalBody) modalBody.insertBefore(banner, modalBody.firstChild);
  }

  const jobLinks = matchedJobs.map(j =>
    `<strong>${esc(j.name)}</strong> [${esc(j.naLabel || 'Active')}]`
  ).join(', ');

  banner.innerHTML = `
    ⚠ This client already has ${matchedJobs.length} job${matchedJobs.length > 1 ? 's' : ''} in ClickUp: ${jobLinks}.
    Creating a new job is correct for a <em>separate project</em>
    (e.g. kitchen + bathroom). If this is a revised quote for the same
    project, update the existing job instead and close this modal.`;
}

/* ══════════════════════════════════════
   REPLACEMENT: createJob()
   After creating the ClickUp task, links it to the CustomerDB record.
══════════════════════════════════════ */
async function createJob() {
  const name = document.getElementById('nj-name').value.trim();
  if (!name) { showToast('Customer name is required', true); return; }

  const type        = document.getElementById('nj-type').value;
  const rep         = document.getElementById('nj-rep').value;
  const addr        = document.getElementById('nj-addr').value.trim();
  const naKey       = document.getElementById('nj-action').value;
  const notes       = document.getElementById('nj-notes').value.trim();
  const installDate = document.getElementById('nj-install-date').value;
  const phone       = document.getElementById('nj-phone').value.trim();
  const listId      = CU.LISTS[type];

  const fieldMap = await getListFieldMap(listId);
  const customFields = [];

  const nextActionFieldId   = (fieldMap.NEXT_ACTION && fieldMap.NEXT_ACTION.id) || CU.FIELDS.NEXT_ACTION;
  const nextActionOptionId  = resolveOptionId(fieldMap.NEXT_ACTION, optionAliases(NEXT_ACTION_OPTION_ALIASES, naKey), CU.NEXT_ACTION_IDS[naKey]);
  if (nextActionFieldId && nextActionOptionId)
    customFields.push({ id: nextActionFieldId, value: nextActionOptionId });

  const repFieldId    = (fieldMap.SALES_REP && fieldMap.SALES_REP.id) || CU.FIELDS.SALES_REP;
  const repOptionId   = resolveOptionId(fieldMap.SALES_REP, optionAliases(REP_OPTION_ALIASES, rep), CU.REP_IDS[rep]);
  if (repFieldId && repOptionId)
    customFields.push({ id: repFieldId, value: repOptionId });

  const notesFieldId = (fieldMap.NOTES && fieldMap.NOTES.id) || CU.FIELDS.NOTES;
  if (notes && notesFieldId)
    customFields.push({ id: notesFieldId, value: notes });

  const addrFieldId = (fieldMap.ADDRESS && fieldMap.ADDRESS.id) || CU.FIELDS.ADDRESS;
  if (addr && addrFieldId)
    customFields.push({ id: addrFieldId, value: addr });

  const installFieldId = (fieldMap.INSTALL_DATE && fieldMap.INSTALL_DATE.id) || CU.FIELDS.INSTALL_DATE;
  if (installDate && installFieldId)
    customFields.push({ id: installFieldId, value: installDate });

  // Include phone in description (no dedicated phone field in ClickUp yet)
  const desc = [
    phone       ? `Phone: ${phone}`             : '',
    _pendingQuoteNumber ? `Quote: ${_pendingQuoteNumber}` : '',
    notes
  ].filter(Boolean).join('\n');

  try {
    const result = await cuFetch(`/list/${listId}/task`, {
      method: 'POST',
      body: JSON.stringify({ name, description: desc, custom_fields: customFields })
    });

    const job = parseTask({ ...result, _listType: type });
    allJobs.unshift(job);
    renderJobList();
    closeMo('new-job-modal');

    // ── NEW: Link the new task back to the CustomerDB record ──────────────
    await linkTaskToCustomer(_pendingCustomerId, job.id, _pendingQuoteNumber);

    // Reset pending state
    _pendingCustomerId  = '';
    _pendingQuoteNumber = '';

    // Remove existing-jobs warning banner if present
    const banner = document.getElementById('existing-jobs-warning');
    if (banner) banner.remove();

    setMainView('list');
    selectJob(job.id);
    showToast('Job created in ClickUp — ' + name);
  } catch (e) {
    showToast('Error creating job: ' + e.message, true);
  }
}

/* ══════════════════════════════════════
   REPLACEMENT: renderNotes()
   Loads notes from the server instead of localStorage.
   Falls back to localStorage so old notes aren't lost.
══════════════════════════════════════ */
async function renderNotes(job, el) {
  el.innerHTML = `
    <div class="notes-add">
      <textarea class="notes-area" id="new-note-text"
        placeholder="Add a note — site conditions, customer requests, follow-up items…"></textarea>
      <button class="notes-add-btn" onclick="addNote('${job.id}')">Add Note</button>
    </div>
    <div id="notes-list"><div class="notes-empty" style="color:var(--s300);font-size:12px;padding:20px;text-align:center">Loading notes…</div></div>`;

  const notes = await getServerNotes(job.id);
  const listEl = document.getElementById('notes-list');
  if (!listEl) return;

  if (!notes.length) {
    listEl.innerHTML = `<div class="notes-empty">No notes yet</div>`;
    return;
  }

  listEl.innerHTML = notes.slice().reverse().map(n => `
    <div class="note-item">
      <div class="note-header">
        <span class="note-user">${esc(n.user || 'Rep')}</span>
        <span class="note-date">${esc(n.date || '')}</span>
      </div>
      <div class="note-text">${esc(n.text)}</div>
    </div>`).join('');
}

/* ══════════════════════════════════════
   REPLACEMENT: addNote()
   Posts notes to the server so all devices see them.
══════════════════════════════════════ */
async function addNote(taskId) {
  const text = (document.getElementById('new-note-text').value || '').trim();
  if (!text) return;

  const user = 'Rep'; // Could read from session / rep selector if available

  // POST to server
  try {
    await fetch('customers-api.php?action=add-note', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        customerId: _pendingCustomerId || '',  // may be empty if job wasn't created from a quote
        taskId,
        text,
        user,
      })
    });
  } catch (e) {
    console.warn('[OGM] Note server save failed, falling back to localStorage:', e);
    // localStorage fallback so the note isn't lost
    const local = (() => {
      try { return JSON.parse(localStorage.getItem('ogm-notes-' + taskId) || '[]'); } catch (_) { return []; }
    })();
    local.push({ text, date: new Date().toISOString(), user });
    try { localStorage.setItem('ogm-notes-' + taskId, JSON.stringify(local)); } catch (_) {}
  }

  // Also update the ClickUp task description with a note summary
  cuFetch(`/task/${taskId}`, {
    method: 'PUT',
    body: JSON.stringify({
      description: `[Note added ${new Date().toLocaleDateString('en-US')}] ${text}`
    })
  }).catch(() => {});

  document.getElementById('new-note-text').value = '';
  const job = allJobs.find(j => j.id === taskId);
  if (job) {
    // Re-render the notes tab with fresh data
    await renderNotes(job, document.getElementById('tab-content'));
  }
  showToast('Note saved');
}
