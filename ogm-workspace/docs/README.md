# OGM Living Documentation

Canonical knowledge base for **Olive Glass & Marble** quoter-tool (`oliveglassandmarble.com/quoter-tool`). These files live in the `ogm-site-backups` GitHub repo under `ogm-workspace/docs/` so both Macs and Cursor agents share one source of truth.

**Repo policy (since 2026-06-14):** GitHub holds docs, scripts, and Cursor rules — **not** dated site file snapshots. Live site and upload history = GoDaddy + GoDaddy server `backups/`.

## Index

| Document | Audience | Purpose |
|----------|----------|---------|
| [OGM-SITE-STRUCTURE.md](./OGM-SITE-STRUCTURE.md) | Developers, agents | Map of site files, PHP APIs, where things live |
| [OGM-SOFTWARE-GUIDE.md](./OGM-SOFTWARE-GUIDE.md) | Sales reps, managers | How to use the internal tools (training) |
| [OGM-AGENT-WORKFLOW.md](./OGM-AGENT-WORKFLOW.md) | Cursor AI agents | Mandatory edit workflow before touching live files |
| [OGM-LOCAL-DISK-PLAN.md](./OGM-LOCAL-DISK-PLAN.md) | Tanya, IT | Keep Mac disk clean; prune schedule |
| [OGM-CHANGELOG.md](./OGM-CHANGELOG.md) | Everyone | Dated release notes for quoter-tool changes |

Related (outside this folder):

- `dev-tools/OGM-WORKFLOW.md` — edit workflow, FTPS, prune commands
- `dev-tools/scripts/ogm-workflow.sh` — `start`, `finish`, `upload`, `prune`, `prune-local`
- `.cursor/rules/ogm-edit-workflow.mdc` — Cursor rule (always apply)
- `.cursor/rules/ogm-knowledge-base.mdc` — read docs before OGM tasks

## How to maintain

When you ship a quoter-tool change:

1. Add a dated entry to **OGM-CHANGELOG.md** (what changed, why, who cares).
2. Update the relevant guide section (e.g. new hub tile → OGM-SITE-STRUCTURE + OGM-SOFTWARE-GUIDE).
3. If workflow or retention rules change, update **OGM-AGENT-WORKFLOW.md**, **OGM-LOCAL-DISK-PLAN.md**, and `dev-tools/OGM-WORKFLOW.md`.
4. Commit and push `github-backups` (`ogm-site-backups` repo). Sync updated scripts into `ogm-workspace/dev-tools/`.

Agents: read **OGM-AGENT-WORKFLOW.md** and **OGM-SITE-STRUCTURE.md** before any live-file task.
