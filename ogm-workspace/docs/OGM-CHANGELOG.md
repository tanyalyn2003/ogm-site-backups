# OGM Changelog

Dated notes for quoter-tool and workflow changes. Add a new entry at the top when shipping.

---

## 2026-06-14

### Backup & workspace infrastructure

- Migrated legacy Mac `backups/` tree into `github-backups/migrated-local-backups-20260614/` on GitHub.
- Added `ogm-workspace/` to `ogm-site-backups` repo: dev-tools, Cursor rules, workspace file.
- Local disk policy: GitHub-only edit backups; optional `OGM_LOCAL_BACKUPS` for duplicate local copies.
- Added `prune` and `prune-local` to `ogm-workflow.sh` with dry-run default and retention rules.
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
