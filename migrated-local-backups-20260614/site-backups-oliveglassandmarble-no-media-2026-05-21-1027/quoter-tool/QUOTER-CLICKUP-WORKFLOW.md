# OGM Quoter ↔ ClickUp Workflow

A one-page reference for using the Stone/Glass Quoter together with Job Tracking, CustomerDB, and ClickUp.

**Live ClickUp board:** [OGM Workspace · Tasks space](https://app.clickup.com/9017868498/v/s/90174038740)
*Tasks created by "Send to Job Tracking" land here, in the `counter` / `glass` / `shower` lists.*

---

## At a glance

```text
CustomerDB ──"Open Quoter"──▶  Stone / Glass Quoter
                                      │
                                      │  fill quote · Save Quote
                                      │
                                      ▼
                             Send to Job Tracking
                                      │
                                      ▼
                           Job Tracking (new tab)
                          ┌─────────────────────────────────┐
                          │ Pre-filled New Job              │
                          │  • Pipeline phase: Sales / Sent │
                          │  • Product type: Counter        │
                          │ ⚠ duplicate banner if customer  │
                          │   already has ClickUp tasks     │
                          └─────────────────────────────────┘
                                      │  Create
                                      ▼
                         Task lands in chosen pipeline list
                          (Sales → Engineering → Production → Finance)
                                      │
                                      ▼
                        Linked back to CustomerDB
                          (clickupJobs array)
                                      │
                                      ▼
                  Rep advances phase via "→ Move to next" button
                           or via ClickUp Automation
```

## The pipeline (4 boards × 22 phases)

| Board | Phases (in order) |
|---|---|
| **Sales** | Leads → Assigned → Discovery → Sent/Follow Up → Accepted (→ Engineering) · Declined/Cold (Archive) |
| **Engineering** | Awaiting Cabinets → Awaiting to Measure → Template → CAD → Sent for Approval → Approved for Production (→ Production) |
| **Production** | Approved for Production → Saw Cut → Router/Polish → Final Polish → QC Check → Ready for Install (→ Finance) |
| **Finance** | Install Scheduled → Service Request → Installation Complete → Satisfaction Confirmed → Invoiced for QB |

Each phase is its own **ClickUp List**. A project is one task that moves between lists as work progresses. The current list IS the current phase.

---

## Rep-facing cheat sheet

| What to do | How to do it | Why it matters |
|---|---|---|
| Quote a **repeat customer** | Open **CustomerDB** → find the customer → "Open Stone Quoter" / "Open Glass Quoter" | This is what links the quote and ClickUp task back to that customer's record |
| Quote a **brand-new customer** | Open the quoter directly, fill in name/phone/address | Add them to CustomerDB later, or the duplicate-check will match them by phone |
| Save a quote | **Save Quote File** button | Assigns a quote number; required before sending to ClickUp if you want the quote # in the task |
| Send a quote to the office | **Send to Job Tracking** button | Opens Job Tracking with everything pre-filled |
| Start a fresh quote | **+ New Quote** button | Clears the active quote ID so your next save creates a new file instead of overwriting the last one |
| Confirm a ClickUp task | In Job Tracking, click **Create** in the New Job modal | The task gets created in the chosen pipeline phase (default Sales · Sent/Follow Up) and linked to the customer |
| Advance a job to the next phase | Open the job in Job Tracking → click **→ Move to [next phase]** | Physically moves the ClickUp task to the next list and re-renders the job card with the new phase badge |
| Add a note on a job | Open the job in Job Tracking → **Notes** tab → type → Add Note | Notes are stored server-side — every device sees them |

---

## The repeat-customer scenario (the headline feature)

**March — Jane Smith asks for a kitchen quote.**

1. Open **CustomerDB** → find Jane → "Open Stone Quoter."
2. Build the quote. Click **Save Quote File**. Quote # `Q-2026-4821` is assigned.
3. Click **Send to Job Tracking**. New tab opens with the New Job modal pre-filled.
4. No banner — Jane is new to ClickUp. Click **Create**.
5. A task appears in the **counter** list. Jane's CustomerDB record now lists that task in her `clickupJobs`.

**June — Jane comes back for a bathroom.**

1. Open **CustomerDB** → find Jane → "Open Stone Quoter."
2. Build the bath quote (new rooms, new pricing). Click **+ New Quote** first if you opened with old data; otherwise just save. Quote # `Q-2026-5104`.
3. Click **Send to Job Tracking**.
4. **Yellow banner appears in the modal:**
   > ⚠ This client already has 1 job in ClickUp: **Jane Smith — Quartz** [Quote sent]. Creating a new job is correct for a *separate project* (e.g. kitchen + bathroom). If this is a revised quote for the same project, update the existing job instead and close this modal.
5. It's a separate project → click **Create**. Second task created, also linked to Jane. She now owns two.

---

## What goes where (storage map)

| Thing | Where it lives |
|---|---|
| Customer record (name, phone, address, status, quotes, **clickupJobs**, notes) | Server: `customers/<id>.json` |
| Full quote JSON (rooms, stones, layout, addons) | Server: `quotes/<quoteNumber>.json` + auto-download backup on save |
| Quote summary (for the quote-list view) | Server: `quote_summaries/<quoteNumber>.json` |
| ClickUp task | [ClickUp space `90174038740`](https://app.clickup.com/9017868498/v/s/90174038740) — one of 22 pipeline lists across the **Sales / Engineering / Production / Finance** boards (plus a Sales · Declined/Cold archive). Legacy `counter` / `glass` / `shower` lists still load for any pre-pipeline tasks. |
| Linkage between ClickUp task and CustomerDB | `clickupJobs` array on the customer JSON |
| Per-task notes (Notes tab) | Server: `task_notes/<taskId>.json`, mirrored onto the customer record |

---

## What the buttons actually do (for when you need to explain it)

**"Save Quote File"** (in Stone/Glass Quoter)
- Pushes the full quote JSON to the server via `quotes-api.php?action=save`.
- Auto-downloads a JSON backup to your Downloads folder.
- Assigns/uses a quote # and stores it in `window.__ogmActiveQuoteId`.
- Re-saving the same loaded quote keeps the same quote #; **+ New Quote** clears that ID so the next save creates a fresh file.

**"Send to Job Tracking"** (in Stone/Glass Quoter)
- Collects customer info + quote # + the linked customer ID (`window._ogmLinkedCustomerId` if you opened the quoter from CustomerDB).
- Stores the bundle in `localStorage` under `ogm-clickup-pending-job`.
- Opens `job-tracking.php` in a new tab.

**Job Tracking — "New Job" modal opens automatically**
- Pre-fills name/phone/address/product type/rep/notes from the bundle.
- Defaults **Pipeline Phase** based on what the bridge sent: a fresh quote lands in *Sales / Sent / Follow Up*, a measure request in *Engineering / Awaiting to Measure*, and so on. Rep can override.
- Calls `customers-api.php?action=get-customer-jobs` (or `search-by-phone` if no ID) to find existing tasks for this customer.
- If any are found, shows the yellow duplicate-warning banner inside the modal with the active phase of each existing job.

**"Create"** (in the New Job modal)
- Creates a ClickUp task in the **chosen pipeline list** (e.g. `901713809324` — Sales / Sent-Follow-Up).
- All the metadata travels in the task **description**: `Phone:`, `Product:`, `Sales Rep:`, `Quote:`, `Install Date:`, `Notes:`. (The pipeline lists don't currently have the legacy NEXT_ACTION/SALES_REP custom fields; when those are added at the folder level the create call will populate them automatically.)
- On success, calls `customers-api.php?action=link-clickup-task` to append the new `taskId` to the customer's `clickupJobs` array.

**"→ Move to [next phase]"** (in the job detail header)
- Adds the task to the next pipeline list and removes it from the current one, via ClickUp's multi-list API. The task ID never changes, so the customer record and notes stay linked.
- The header badge re-colors to the new board (Sales = blue, Engineering = yellow, Production = red, Finance = green).

**"Add Note"** (in the job's Notes tab)
- POSTs to `customers-api.php?action=add-note`. The note is written to `task_notes/<taskId>.json` server-side and (if the job has a linked customer) mirrored onto the customer record.
- Also appended to the ClickUp task's description so it shows up in ClickUp directly.
- Falls back to browser `localStorage` if the server is unreachable, so a note is never lost.

---

## Current gaps (things to know)

- **Pipeline folders live in space `90174005518` for now** (where they were built on 2026-05-15). The list IDs are wired into Job Tracking directly so the wiring works regardless of which space owns the folders, but moving them into `90174038740` alongside the rest of the workflow makes the ClickUp views and dashboards align cleanly. The list IDs persist through a folder move.
- **Shared custom fields on pipeline lists are not yet created.** The legacy NEXT_ACTION / SALES_REP / NOTES / ADDRESS / INSTALL_DATE fields exist only on the original counter/shower/glass lists. For pipeline tasks, the same data travels in the task **description**. To upgrade: add Product Type (dropdown), Customer Phone (text), Quote # (text), Sales Rep (dropdown), Install Date (date) at the folder level so all child lists inherit. The createJob payload will start populating them as soon as `getListFieldMap()` returns IDs for them.
- **Phase advancement is manual.** "Move to next phase" is a button in the job detail. ClickUp Automations can be added at the folder level to auto-move tasks when a status changes (e.g. "Sales · Accepted → Project Created" auto-handoff into Engineering · Awaiting Cabinets) — recommended but not required.
- **Shower Builder ([OGM_ShowerBuilder.html](OGM_ShowerBuilder.html))** isn't wired into the bridge yet. The bridge function `sendShowerToClickUp()` exists, but the Shower Builder page itself doesn't include the bridge script or the customer-info inputs. For now: use the Glass Quoter's shower category, or create the shower job manually in Job Tracking.
- **Auth** still has a plaintext-password fallback in [auth-config.php](auth-config.php). To harden: on the server, run `php -r 'echo password_hash("YOUR_PASSWORD", PASSWORD_DEFAULT), "\n";'`, paste the result into `password_hash`, and clear the `password_plain` array. Don't commit the hash to the repo.

---

## Troubleshooting

| Symptom | Most likely cause | Fix |
|---|---|---|
| Yellow duplicate banner never appears for a known repeat customer | Quoter wasn't opened from CustomerDB (no `?cid=` in the URL), so `customerId` is blank and only the phone-number search can save you. | Re-open the quoter from CustomerDB. Or make sure the phone in CustomerDB matches what's in the quoter. |
| Quote # missing in the ClickUp task description | Quote wasn't saved before clicking Send to Job Tracking. | Save the quote first, then send. |
| Pipeline tasks don't appear in the jobs list | The space view doesn't cover the pipeline folder, and a per-list fetch failed (network, permissions, or list missing). | Job Tracking fetches every pipeline list directly in addition to the view — check the browser console for `[OGM] Could not load pipeline list …` warnings. |
| "Move to next phase" button is missing | The task is in a legacy `counter`/`glass`/`shower` list (the button only appears for pipeline-list tasks). | Either keep using the legacy "Next Action" buttons, or recreate the task by sending it through the Quoter again. |
| New quote got assigned an old quote's number | Forgot to click **+ New Quote** before starting fresh — the previous active ID was still in memory. | Click **+ New Quote**, then save. |
| Notes added on one device don't show on another | If still broken after this update, the server `task_notes/` directory couldn't be written. | Check folder permissions on the host (the script tries to `mkdir 0755` it on first write). |
| Quotes seem to "disappear" from the list | The on-disk index drifted out of sync with the actual quote JSONs. | The new `quotes-api.php` auto-rebuilds the index when it detects a count mismatch — should self-heal. If not, delete `quotes/_index.json` and reload; it'll regenerate. |
| Customer import from a QuickBooks export skips rows | Workbook has multiple sheets and the wrong one was picked. | The importer now prefers sheets that contain a `customer` column; make sure your data sheet has that header. |

---

## File index

| File | Purpose |
|---|---|
| [ogm-quoter-internal.html](ogm-quoter-internal.html) | Stone Quoter |
| [OGM_GlassQuoter.html](OGM_GlassQuoter.html) | Glass Quoter |
| [OGM_ShowerBuilder.html](OGM_ShowerBuilder.html) | 3D Shower Builder (not wired to ClickUp yet) |
| [OGM_CustomerDB.html](OGM_CustomerDB.html) | Customer database UI |
| [OGM_JobTracking.html](OGM_JobTracking.html) | ClickUp-backed job tracker |
| [ogm-clickup-bridge.js](ogm-clickup-bridge.js) | Glue between quoters and Job Tracking |
| [ogm-server-sync.js](ogm-server-sync.js) | `window.storage` shim that routes saves to the PHP API |
| [customers-api.php](customers-api.php) | CRUD + ClickUp link/notes endpoints |
| [quotes-api.php](quotes-api.php) | Quote save/load + index |
| [auth-config.php](auth-config.php) | Login secrets (server-side only — do not commit hashes) |
