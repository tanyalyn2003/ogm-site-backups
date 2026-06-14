# OGM Changelog

Dated notes for quoter-tool and workflow changes. Add a new entry at the top when shipping.

---

## 2026-06-14

### Brand & scripts backup (audit cleanup)

- Added `ogm-workspace/brand/logos/` and `brand/background.ogm.psd` from local design assets.
- Added `ogm-workspace/scripts/ogm-github-setup.sh` (mirror of dev-tools script).

### Snapshot removal & workflow policy

- **Removed all dated website file snapshots** from `ogm-site-backups` GitHub repo (`2026-*` workflow folders and `migrated-local-backups-20260614/`).
- **GitHub repo policy:** docs, scripts, and Cursor rules only — no routine site file copies.
- **`ogm-workflow.sh`:** GitHub pre/post-edit snapshots **off by default**; opt in with `OGM_GITHUB_BACKUP=yes`.
- Upload gating uses `finish` marker in `quoter-tool-working/.ogm-workflow/` instead of GitHub post-edit folders.
- Live site + upload history: GoDaddy live files and GoDaddy server `backups/` folder (on upload).
- Updated agent workflow docs, disk plan, and Cursor rules to match.

### Backup & workspace infrastructure (earlier same day)

- Added `ogm-workspace/` to `ogm-site-backups` repo: dev-tools, Cursor rules, workspace file.
- Added `prune` and `prune-local` to `ogm-workflow.sh` with dry-run default.
- Living documentation in `ogm-workspace/docs/` (site structure, software guide, agent workflow, disk plan).

### AI Quick Start

- Extended **AI Quick Start** access to all user roles (permission still gated in `quoter-users.php`).
- Added **speech-to-text** input path in Stone Quoter Quick Start panel.
- Multiple iteration fixes: dimensions, multi-room, splash/cutout, hours sum, apply flow, word limit, editable review, sink dropdown, design shapes sync.

### Navigation

- Hub/nav fix: **Customers** tile order/placement aligned across apps (`nav-customers-order` tasks).

### Design sync

- **All-rooms design sync**: Kitchen Planner shape/state sync across room tabs with Stone Quoter.

---

## Template for new entries

```markdown
## YYYY-MM-DD

### Area (e.g. Stone Quoter, Email)

- Bullet: what changed and who it affects.
```
