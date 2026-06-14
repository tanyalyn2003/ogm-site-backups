# OGM Site Structure

Base URL: `https://oliveglassandmarble.com/quoter-tool/`

Remote path on GoDaddy: `public_html/quoter-tool/`

## Where things live

| Location | Role |
|----------|------|
| **GoDaddy** (`public_html/quoter-tool/`) | **Live production** — source of truth at task start |
| **Mac** `quoter-tool-working/` | Edit copy only after `ogm-workflow.sh start` fresh pull |
| **Mac** `fresh-godaddy-pulls/` | Short-term FTPS cache (prune after 7 days; keep newest 3) |
| **GitHub** `ogm-site-backups` (`github-backups/`) | Timestamped pre/post-edit **history** — not live |
| **GitHub** `ogm-workspace/` | Dev tools, docs, Cursor rules (synced essentials) |
| **Mac** `backups/` | Legacy — do not use; migrated to `migrated-local-backups-*` |

Never edit from dated `github-backups/YYYY-MM-DD-*` folders, `backups/`, Trash, or stale `quoter-tool-working/` without a fresh `start`.

---

## Hub & navigation

| File | URL / entry | Notes |
|------|-------------|-------|
| `index.php` | `/quoter-tool/` or `/quoter-tool/index.php` | Login page; session gate |
| `hub.php` | `/quoter-tool/hub.php` | PHP wrapper; injects `window.OGM_CURRENT_USER` |
| `OGM_Hub.html` | Served via `hub.php` | Internal hub — tool cards, stats, nav |
| `logout.php` | Logout | Clears quoter session |

Shared nav pattern: `ogm-nav-bar` links across HTML apps. Theme: `ogm-theme-toggle.js`, `ogm-theme-toggle.css`, `ogm-accessibility.css`.

---

## Core applications (HTML + PHP shells)

| App | Primary files | PHP shell (if any) |
|-----|-----------------|-------------------|
| **Stone quoter** | `ogm-quoter-internal.html` | `index.php` (post-login default) |
| **Glass quoter** | `OGM_GlassQuoter.html` | `glass-quoter.php` |
| **Kitchen planner / design** | `OGM_KitchenPlanner.html` | — |
| **Job tracking** | `OGM_JobTracking.html` | `job-tracking.php` |
| **Customer database** | `OGM_CustomerDB.html` | `customer-db.php` |
| **Invoice manager** | `OGM_InvoiceManager.html` | `invoice-manager.php` |
| **Email center** | `email-center.php` (UI + server) | — |
| **Message center** | `OGM_MessageCenter.html` | `message-center.php` |
| **Calendar** | `OGM_Calendar.html` | `calendar.php` |
| **Sales reports** | `OGM_SalesReports.html` | `sales-reports.php`, `reports.php` |
| **Production board** | `OGM_ProductionBoard.html` | `production-board.php` |
| **Intake form** | `OGM_IntakeForm.html` | `intake-form.php` |
| **Shower builder** | `OGM_ShowerBuilder.html` | `shower-builder.php` |
| **Stone catalog** | `OGM_StoneCatalog.html` | — |
| **Blueprint scanner** | `OGM_BlueprintScanner.html` | — |
| **Website manager** | `OGM_WebsiteManager.html` | — |
| **Team marketplace** | `OGM_TeamMarketplace.html` | `team-marketplace.php` |
| **Employee hub** | `employee-hub.php`, `employee-board.php`, `community.php` | Employee area |
| **User admin** | `user-admin/index.php`, `user-admin/update-users.php` | `user-admin.php` |
| **Website leads** | `website-leads/index.php` + subfolder | Lead inbox (separate mini-app) |
| **Quote search** | `quote-search.html` | — |
| **Shape connector** | `shape-connector.html` | Design helper |
| **Products** | `products.html` | Product reference |
| **Client / proposal viewer** | `viewer.php`, `client-viewer.js` | Shared customer-facing views |

---

## AI Quick Start

| File | Role |
|------|------|
| `ai-quickstart-api.php` | Speech/notes → structured quote fields API |
| `ai-quickstart-config.php` | Model/provider config (secrets in server config, not git) |
| UI embedded in `ogm-quoter-internal.html` | Quick Start panel for reps |

Permission: `ai_quickstart` in `quoter-users.php` (enabled for managers by default; extended to all users June 2026).

---

## Proposals & customer sharing

| File | Role |
|------|------|
| `project-proposals-api.php` | Project proposals CRUD, room linkage |
| `proposal-email-api.php` | Email proposals to customers |
| `share-api.php` | Share links / tokens |
| `quote-documents-api.php` | PDF/document attachments |
| `quotes-api.php` | Quote persistence |
| `ogm-customer-quote-sync.js` | Client sync between quoter and customer DB |

---

## PHP APIs (grouped by function)

### Auth & users

- `auth.php` — sessions, login, capability checks
- `auth-config.php` / `auth-config.example.php` — credentials template
- `quoter-users.php` — roles, permissions, user list
- `user-admin.php`, `user-admin/*` — admin UI for team logins

### Customers & CRM

- `customers-api.php`, `customers-api-new-actions.php`
- `customer-phone-dedupe-lib.php`, `run-customer-phone-dedupe-cli.php`

### Quotes & production

- `quotes-api.php`, `quote-documents-api.php`
- `production-board-api.php`, `production-board.php`
- `slab-photo-upload.php`

### Email & messaging

- `email-api.php`, `email-ai-config.php`, `email-callback.php`
- `graph-config.php` — Microsoft Graph (email)
- `messages-api.php`, `message-center.php`
- `invoice-email-api.php`, `invoice-email-smtp.example.php`

### Integrations

- `clickup-api-key.php`, `clickup-proxy.php`, `clickup-webhook.php`, `clickup-webhook-setup.php`
- `ogm-clickup-bridge.js` — client bridge
- `maps-distance-api.php` — driving distance
- `calendar-api.php`, `calendar-client-id.php`

### Timesheets & reports

- `timesheet-api.php`, `timesheet-lib.php`
- `reports-api.php`, `reports.php`, `sales-reports.php`
- `overhead-rates.php`

### Intake & website

- `intake-form-api.php`, `intake-form.php`
- `website-leads.php`, `website-leads/*`

### Employee / internal

- `employee-api.php`, `employee-shell.php`

### Debug / utilities

- `ogm-debug-ingest.php`
- `ogm-server-sync.js`, `ogm-staff.js`, `ogm-glass-prices.js`, `ogm-glass-invoice.js`, `ogm-invoice-workflow.js`

### Glass-specific JS

- `ogm-glass-prices.js`, `sink-reference-picker.js`, `assets/js/sink-reference-catalog.js`

---

## Auth model

- Session cookie: `ogm_quoter_tool` (`auth.php`)
- Login: `index.php` → `qtAttemptLogin`
- Roles (in `quoter-users.php`): `general_manager`, `division_manager`, `sales`, plus permission flags (`ai_quickstart`, `email_center`, `user_admin`, etc.)
- PHP pages require `auth.php`; HTML apps rely on hub session or embedded checks

---

## GoDaddy server backups

On upload, `godaddy-ftps.sh` saves prior remote file to:

`public_html/quoter-tool/backups/YYYYMMDD-HHMMSS/` on the server.

This is separate from GitHub `github-backups/` workflow folders.
