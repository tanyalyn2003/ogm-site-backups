# OGM Local Disk Plan

Keep Tanya’s Macs lean while preserving safe edit history on **GitHub** (`ogm-site-backups`).

---

## What stays on each Mac

| Path | Purpose | Size discipline |
|------|---------|-----------------|
| `/Users/tanyawhite/OGM/quoter-tool-working/` | Active edit copies | One file per live name; delete `*.bak-*` / `*.backup-*` freely |
| `/Users/tanyawhite/OGM/fresh-godaddy-pulls/` | FTPS cache | **Max ~7 days**; always keep newest **3** folders (`prune-local`) |
| `/Users/tanyawhite/OGM/dev-tools/` | Workflow scripts, FTPS | Small — keep |
| `/Users/tanyawhite/OGM/.env.local` | FTPS password | **Never commit** |
| `/Users/tanyawhite/OGM/github-backups/` | **One** clone of `ogm-site-backups` | Prune dated folders monthly (`prune`) |
| `/Users/tanyawhite/OGM/.cursor/` | Cursor rules (local) | Small |

---

## What should NOT accumulate on Mac

- Duplicate `github-backups` clones elsewhere
- Large `backups/` trees (legacy — migrated to `migrated-local-backups-20260614` on GitHub)
- `Archive` folders with old quoter copies
- `quoter-tool-working/*.bak-*` and `*.backup-*` sidecars (GitHub has pre/post-edit)
- Second full copy of `quoter-tool-working` outside OGM root
- `node_modules` / Playwright browsers unless actively developing

---

## Monthly maintenance

```sh
# 1. Preview
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune --dry-run
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune-local --dry-run

# 2. Execute if lists look correct
OGM_CONFIRM_PRUNE=yes /Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune
OGM_CONFIRM_PRUNE_LOCAL=yes /Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune-local

# 3. Manual sweep
# - Delete quoter-tool-working/*.bak-* and *.backup-*
# - Empty Trash after large prunes
```

GitHub retention (after `prune`): all backups **90 days**, then **one folder per month** for older dates; pre-edit dropped when post-edit exists (>30 days).

---

## GitHub repo layout (disk on GitHub, not duplicated locally)

- `YYYY-MM-DD-HHMM-task-pre-edit/` / `*-post-edit/` — workflow snapshots (prunable)
- `ogm-workspace/` — tools + docs (**never prune**)
- `migrated-local-backups-*` — legacy import (**never prune**)
- `godaddy-full-snapshot-*` — future full pulls (**never prune**)

---

## Optional future: shallow clone

If `github-backups` clone grows large on disk:

```sh
git clone --depth 1 <ogm-site-backups-url> github-backups
```

History remains on GitHub; local clone is thin. Run `git fetch --depth=1` before pushes if using shallow clone.

---

## Second computer setup

1. Clone `ogm-site-backups` → `/Users/tanyawhite/OGM/github-backups`
2. Copy or symlink `OGM` workspace layout:
   - `quoter-tool-working/` (empty or stale until `start`)
   - `dev-tools/` from `github-backups/ogm-workspace/dev-tools/` OR full OGM folder
   - `.env.local` manually (never from git)
3. Copy `.cursor/rules/` from `ogm-workspace/.cursor/rules/` or parent OGM `.cursor/rules/`
4. Run `ogm-github-setup.sh` if remote not configured
5. **Always `start` before edit** — never assume the other Mac’s `quoter-tool-working` is current

---

## Snapshot plan (not implemented)

`ogm-workflow.sh snapshot` will eventually archive full `quoter-tool/` to `godaddy-full-snapshot-YYYYMMDD/` on GitHub. Until then, rely on per-file `start`/`finish` and GoDaddy server `backups/` folder on upload.
