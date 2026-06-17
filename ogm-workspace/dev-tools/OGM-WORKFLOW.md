# OGM Edit Workflow

Standard process for editing live Olive Glass & Marble site files.

## GitHub: one repo only

| Item | Value |
|------|-------|
| **GitHub repo** | [`tanyalyn2003/ogm-site-backups`](https://github.com/tanyalyn2003/ogm-site-backups) |
| **Local clone** | `/Users/tanyawhite/OGM/github-backups/` |
| **Workspace** | `/Users/tanyawhite/OGM/` — working directory, **not** a GitHub push target |

Do **not** create, push to, or reference `tanyalyn2003/ogm`. Parent `/Users/tanyawhite/OGM/.git` (if present) is optional local history only.

To commit docs/rules/script changes: sync into `github-backups/ogm-workspace/`, then `git -C github-backups add`, `commit`, `push origin main`.

## Flow

```
GoDaddy (fresh pull) → edit locally → finish → ask → optional GoDaddy upload
```

GitHub file snapshots are **off by default**. Set `OGM_GITHUB_BACKUP=yes` in `.env.local` if you want dated pre/post-edit copies in `github-backups/`.

## Nested paths and safety

- **Nested paths:** `start user-admin/index.php` copies to `quoter-tool-working/user-admin/index.php` (not flat `index.php`). A warning prints if Stone login `quoter-tool-working/index.php` also exists.
- **Dirty working copy:** If you `start` again while local edits differ from GoDaddy, the script saves `*.bak-before-restart-<timestamp>` and warns. Set `OGM_FORCE_START=yes` to suppress the warning (backup still created).
- **Rollback snapshots:** Before risky workflow changes, copies live under `dev-tools/snapshots/<date>-pre-<task>/` with `RESTORE.md` instructions.
- **Task bundles:** `dev-tools/task-bundles/*.txt` list files that must ship together. Use `bundle-start`, `bundle-finish`, `bundle-upload`.

## Quick start

```sh
# One-time: copy credentials (never commit .env.local)
cp /Users/tanyawhite/OGM/.env.local.example /Users/tanyawhite/OGM/.env.local
# Edit .env.local with your FTPS password

# One-time: connect GitHub repo ogm-site-backups (docs + scripts only)
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-github-setup.sh
# 1. Create private repo: https://github.com/new?name=ogm-site-backups
# 2. Push: cd /Users/tanyawhite/OGM/github-backups && git push -u origin main

# Start work on a file
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh start email-center.php my-task

# Edit in quoter-tool-working, then:
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh finish email-center.php my-task

# Upload only when you approve:
OGM_CONFIRM_UPLOAD=yes /Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh upload email-center.php my-task

# Nested paths (preserves folders under quoter-tool-working/):
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh start user-admin/index.php team-logins
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh finish user-admin/index.php team-logins

# Multi-file features (see dev-tools/task-bundles/*.txt):
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh bundle-start api-access api-access
# ... edit all listed files ...
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh bundle-finish api-access api-access
OGM_CONFIRM_UPLOAD=yes /Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh bundle-upload api-access api-access
```

## GoDaddy FTPS

| Setting | Value |
|---------|-------|
| Host | `72.167.70.202` (more reliable than hostname) |
| User | `codex@oliveglassandmarble.com` |
| Port | 21 (FTPS) |
| Remote root | `public_html/quoter-tool` |

Low-level FTPS commands: `dev-tools/godaddy-ftps/godaddy-ftps.sh`

## Where files live

| Stage | Local | GoDaddy | GitHub (optional) |
|-------|-------|---------|-------------------|
| Fresh pull | `fresh-godaddy-pulls/YYYY-MM-DD-HHMM-ftps-pull/` | source of truth | — |
| Working copy | `quoter-tool-working/<file>` | — | — |
| After upload | — | live file + `backups/` on server | — |
| Legacy snapshots | — | — | `YYYY-MM-DD-HHMM-task-pre-edit/` (opt-in only) |

## GitHub repo policy (`ogm-site-backups`)

The `github-backups/` repo holds **workspace docs and workflow scripts only** — not routine site file copies.

| Path | Purpose |
|------|---------|
| `ogm-workspace/docs/` | Living documentation |
| `ogm-workspace/dev-tools/` | Synced workflow scripts |
| `ogm-workspace/.cursor/rules/` | Cursor rules |

**Live site + history:** GoDaddy live files and GoDaddy server `backups/` folder (created automatically on upload).

**Local:** `quoter-tool-working/` plus the latest `fresh-godaddy-pulls/` from `start`.

### Optional GitHub file snapshots

Set `OGM_GITHUB_BACKUP=yes` when running `start` / `finish` to restore the old behavior (dated pre/post-edit folders committed to GitHub). Default is **no** new snapshots.

### Optional duplicate local copies

Set `OGM_LOCAL_BACKUPS=/Users/tanyawhite/OGM/backups` when running `ogm-workflow.sh` if you want local pre/post-edit folders (also creates `.bak-*` sidecars in `quoter-tool-working/` on `start`).

### Optional external SSD backups

Set `OGM_SSD_BACKUPS` in `.env.local` to mirror pre/post-edit copies to an external drive for immediate rollback (no GoDaddy upload involved).

```sh
# Example — Crucial X10 (writable under Users/tanyawhite/, not volume root)
export OGM_SSD_BACKUPS="/Volumes/Crucial X10/Users/tanyawhite/OGM-backups"
```

When set, `start` and `finish` copy dated folders like `YYYY-MM-DD-HHMM-<task>-pre-edit/` and `-post-edit/` under that path, preserving nested paths (e.g. `user-admin/index.php`). See `RESTORE.md` on the SSD for rollback steps.

Initial archive (2026-06-17): snapshots, newest FTPS pulls, key quoter-tool-working files, and workflow scripts were copied manually to the SSD before workflow-audit script changes.

## Local disk policy

- **`fresh-godaddy-pulls/`:** short-term cache only. Prune pulls older than 7 days; keep at least the latest 3 FTPS pull folders (`prune-local`).
- **`quoter-tool-working/*.backup-*` and `*.bak-*`:** safe to delete anytime.
- **`quoter-tool-working/.ogm-workflow/`:** finish markers for upload gating; cleared after successful upload.
- **`backups/` on the Mac:** not used by default. Do not accumulate a large local tree.

## Retention / prune

| Command | What it cleans | When to use |
|---------|----------------|-------------|
| `prune-local` | Old `fresh-godaddy-pulls/` folders | Monthly or when disk is tight |
| `prune` | Legacy dated GitHub workflow folders | Mostly obsolete after 2026-06-14 snapshot removal; only if opt-in snapshots were created |

### Local prune rules (`prune-local`)

- Delete pull folders older than **7 days**, except always keep the **newest 3** folders.
- Default dry-run; execute with `OGM_CONFIRM_PRUNE_LOCAL=yes` or `OGM_CONFIRM_PRUNE=yes`.

### GitHub prune rules (`prune`) — legacy only

Targets dated workflow folders at repo root matching `YYYY-MM-DD-HHMM-*-pre-edit` or `*-post-edit` (from when `OGM_GITHUB_BACKUP=yes` was used).

1. **Always keep** folders from the last **90 days**.
2. For folders **older than 90 days**: keep the **newest folder per calendar month**.
3. If both pre-edit and post-edit exist for the same task slug and the folder is **older than 30 days**, delete pre-edit (keep post-edit).
4. **Default is dry-run** — lists what would be deleted.
5. Execute only when `OGM_CONFIRM_PRUNE=yes` is set (without `--dry-run`).

## Prune commands

```sh
# Preview local FTPS pull cleanup (recommended monthly)
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune-local --dry-run
OGM_CONFIRM_PRUNE_LOCAL=yes /Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune-local

# Preview legacy GitHub snapshot cleanup (only if OGM_GITHUB_BACKUP=yes was used)
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune --dry-run
OGM_CONFIRM_PRUNE=yes /Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune
```

## Snapshot plan (future)

`ogm-workflow.sh snapshot` is a stub — **not implemented yet**.

Planned behavior: pull the entire `public_html/quoter-tool` tree into `github-backups/godaddy-full-snapshot-YYYYMMDD/` for periodic full-site archives. Today, rely on GoDaddy live files + server `backups/` on upload.

## Living documentation

Canonical docs live in `github-backups/ogm-workspace/docs/` (synced to GitHub). Start at `ogm-workspace/docs/README.md`. Pointer copy: `dev-tools/docs/README.md`.

## Safety rules

- Never upload to GoDaddy without your explicit approval.
- Never upload without `finish` first and GoDaddy remote backup on upload.
- Password lives in `.env.local` only — never in git.
