# OGM Software Guide

Training guide for **sales reps, division managers, and general managers** using Olive Glass & Marble internal tools.

Login URL: `https://oliveglassandmarble.com/quoter-tool/`

---

## Logging in & roles

1. Open the quoter-tool URL in your browser.
2. Sign in with your team username and password (provided by management).
3. After login you land on the **Stone Quoter** or your configured home; use the top nav or **Hub** to switch tools.

**Roles** (what you see depends on your login):

| Role | Typical access |
|------|----------------|
| **Sales** | Quotes, customers, proposals, design tools, job tracking (as permitted) |
| **Division manager** | Above + timecards, email center, reports, AI Quick Start, team tools |
| **General manager** | Full access including user admin |

If a tool is missing from your hub, ask Tanya or a GM to check permissions in **User Admin**.

[TODO: expand with screenshots]

---

## Hub (home base)

The **Internal Hub** (`hub.php`) lists live tools as cards: Stone Quoter, Glass Quoter, Job Tracking, Customers, Invoices, Email Center, etc.

- Click a card to open that app.
- Top navigation repeats across most apps for quick switching.
- Hub can be customized (card order/colors) for power users.

[TODO: expand with screenshots]

---

## Stone quote workflow (high level)

1. **Customers** — find or create the customer (`OGM_CustomerDB.html`).
2. **Stone Quoter** (`ogm-quoter-internal.html`) — build rooms, slabs, edges, sinks, line items.
3. **Optional: AI Quick Start** — dictate or paste job notes; review AI-filled fields before applying.
4. **Design** — open Kitchen Planner for layout/shapes when needed (syncs with quoter rooms).
5. **Proposal** — generate/send proposal; customer can view via shared link/viewer.
6. **Job tracking** — move job through stages after sale.

[TODO: expand with screenshots — room tabs, line items, deposit]

---

## Glass quote workflow

1. Open **Glass Quoter** (`OGM_GlassQuoter.html`).
2. Select customer/project context (linked with stone jobs when applicable).
3. Enter shower/glass specifications, hardware, pricing from glass price tables.
4. Save and attach to customer / invoice workflow as needed.

[TODO: expand with screenshots]

---

## Design / kitchen planner

**Kitchen Planner** (`OGM_KitchenPlanner.html`) is the primary design surface:

- Draw counter shapes, backsplashes, sink cutouts.
- Room tabs sync with Stone Quoter when both are open on the same quote.
- Use **Shape Connector** or sink reference tools for complex layouts.

Changes in design can flow back to quoter line items (verify after sync).

[TODO: expand with screenshots]

---

## Proposals & customer sharing

1. From Stone Quoter or Job Tracking, open **proposal** actions.
2. Build multi-room proposals; attach documents/PDFs when needed.
3. **Email proposal** or copy **share link** for customer review (`viewer.php` / client viewer).
4. Customer sees branded view — not the internal quoter UI.

[TODO: expand with screenshots]

---

## AI Quick Start (speech notes)

Available on Stone Quoter for users with **AI Quick Start** permission (all roles as of June 2026):

1. Open the Quick Start panel.
2. **Type or use speech-to-text** to describe the job (rooms, stone, edges, sinks).
3. Review the AI-generated summary and field mapping.
4. Edit anything wrong before **Apply** — AI suggestions are not final quotes.

Tips: speak in clear room-by-room order; mention slab names, edge profiles, and sink models when known.

[TODO: expand with screenshots]

---

## Job tracking basics

**Job Tracking** (`OGM_JobTracking.html`) shows jobs by stage:

- Link quotes/proposals to production status.
- Coordinate with Production Board and Invoice Manager.
- Use for “where is this job?” questions — not for editing quote math (do that in Stone Quoter).

[TODO: expand with screenshots]

---

## Email center

**Email Center** (`email-center.php`) — division managers and GMs (and others with `email_center` permission):

- Connect Microsoft email (Graph) for sending from OGM templates.
- Draft proposal/invoice emails with less copy-paste.

Ask a GM if OAuth/connect shows errors.

[TODO: expand with screenshots]

---

## Who to ask for what

| Need | Ask |
|------|-----|
| Login/password, permissions, new user | Tanya / General Manager |
| Quote math, stone catalog, pricing rules | Sales lead / GM |
| Glass pricing, shower specs | Glass division manager |
| Design sync issues (rooms/shapes) | Tanya / dev (Cursor workflow) |
| Email center / Graph connection | Tanya |
| ClickUp integration, website leads | Tanya |
| “Site is down” / login loops | Tanya |

---

## Other tools (quick reference)

| Tool | Use for |
|------|---------|
| Invoice Manager | Deposits, QB-related gates, invoice PDFs |
| Sales Reports | Commission and sales analytics |
| Calendar | Shop/install scheduling |
| Message Center | Internal team messages |
| Intake Form | Structured lead/job intake |
| Website Leads | Form leads from public site |
| Stone Catalog | Slab/product reference |
| Production Board | Shop floor status |

[TODO: expand with screenshots]
