# OGM Local Disk Plan

Keep Tanya’s Macs lean. **Live site + upload history = GoDaddy.** GitHub holds docs/scripts only.

---

## What stays on each Mac

| Path | Purpose | Size discipline |
|------|---------|-----------------|
| `/Users/tanyawhite/OGM/quoter-tool-working/` | Active edit copies | One file per live name; delete `*.bak-*` / `*.backup-*` freely |
| `/Users/tanyawhite/OGM/fresh-godaddy-pulls/` | FTPS cache | **Max ~7 days**; always keep newest **3** folders (`prune-local`) |
| `/Users/tanyawhite/OGM/dev-tools/` | Workflow scripts, FTPS | Small — keep |
| `/Users/tanyawhite/OGM/.env.local` | FTPS password | **Never commit** |
| `/Users/tanyawhite/OGM/github-backups/` | Clone of `ogm-site-backups` (docs + scripts) | Small — no site file snapshots |
| `/Users/tanyawhite/OGM/.cursor/` | Cursor rules (local) | Small |

---

## What should NOT accumulate on Mac

- Duplicate `github-backups` clones elsewhere
- Large `backups/` trees (legacy — do not recreate)
- `Archive` folders with old quoter copies
- Second full copy of `quoter-tool-working` outside OGM root
- `node_modules` / Playwright browsers unless actively developing
- Dated pre/post-edit snapshot folders (removed from GitHub policy 2026-06-14)

---

## Monthly maintenance

```sh
# 1. Preview local FTPS pull cleanup
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune-local --dry-run

# 2. Execute if list looks correct
OGM_CONFIRM_PRUNE_LOCAL=yes /Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune-local

# 3. Manual sweep
# - Delete quoter-tool-working/*.bak-* and *.backup-*
# - Empty Trash after large prunes
```

`prune` (GitHub dated folders) is **mostly obsolete** — only needed if you opt in with `OGM_GITHUB_BACKUP=yes`.

---

## GitHub repo layout (`ogm-site-backups`)

| Path | Purpose |
|------|---------|
| `ogm-workspace/docs/` | Living documentation |
| `ogm-workspace/dev-tools/` | Synced workflow scripts |
| `ogm-workspace/.cursor/rules/` | Cursor rules |
| `README.md` | Repo overview |

**Not stored on GitHub:** routine quoter-tool PHP/HTML/JS copies. Upload history lives in GoDaddy `public_html/quoter-tool/backups/`.

Optional opt-in: `OGM_GITHUB_BACKUP=yes` creates dated `YYYY-MM-DD-HHMM-task-pre-edit/` folders (legacy behavior).

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

`ogm-workflow.sh snapshot` may eventually archive full `quoter-tool/` to `godaddy-full-snapshot-YYYYMMDD/` on GitHub. Until then, rely on GoDaddy live files + server `backups/` on upload.
