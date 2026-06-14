# OGM Edit Workflow

Standard process for editing live Olive Glass & Marble site files.

## Flow

```
GoDaddy (fresh pull) → pre-edit GitHub backup → edit locally → post-edit GitHub backup → ask → optional GoDaddy upload
```

## Quick start

```sh
# One-time: copy credentials (never commit .env.local)
cp /Users/tanyawhite/OGM/.env.local.example /Users/tanyawhite/OGM/.env.local
# Edit .env.local with your FTPS password

# One-time: connect GitHub repo ogm-site-backups
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-github-setup.sh
# 1. Create private repo: https://github.com/new?name=ogm-site-backups
# 2. Push: cd /Users/tanyawhite/OGM/github-backups && git push -u origin main
# Until the repo exists, backups commit locally and warn on push failure.

# Start work on a file
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh start email-center.php my-task

# Edit in quoter-tool-working, then:
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh finish email-center.php my-task

# Upload only when you approve:
OGM_CONFIRM_UPLOAD=yes /Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh upload email-center.php my-task
```

## GoDaddy FTPS

| Setting | Value |
|---------|-------|
| Host | `72.167.70.202` (more reliable than hostname) |
| User | `codex@oliveglassandmarble.com` |
| Port | 21 (FTPS) |
| Remote root | `public_html/quoter-tool` |

Low-level FTPS commands: `dev-tools/godaddy-ftps/godaddy-ftps.sh`

## Backup locations

| Stage | Local | GitHub |
|-------|-------|--------|
| Fresh pull | `fresh-godaddy-pulls/YYYY-MM-DD-HHMM-ftps-pull/` | — |
| Pre-edit | — (optional; see local disk policy) | `github-backups/YYYY-MM-DD-HHMM-task-pre-edit/` |
| Post-edit | — (optional) | `github-backups/YYYY-MM-DD-HHMM-task-post-edit/` |
| GoDaddy remote | — | `public_html/quoter-tool/backups/` on server |


## Local disk policy — github-backups only, no long-term backups/ folder

- **Source of truth for edit backups:** private GitHub repo `ogm-site-backups` (`github-backups/`). Pre/post-edit copies are committed and pushed on every `start` / `finish`.
- **`backups/` on the Mac:** not used by default. Legacy snapshots were migrated to `github-backups/migrated-local-backups-20260614/` (see its `MANIFEST.txt`). Do not accumulate a large local `backups/` tree again.
- **Optional duplicate local copies:** set `OGM_LOCAL_BACKUPS=/Users/tanyawhite/OGM/backups` when running `ogm-workflow.sh` if you want the old behavior (also creates `.bak-*` sidecars in `quoter-tool-working/` on `start`).
- **`fresh-godaddy-pulls/`:** short-term cache only. Prune pulls older than 7 days; keep at least the latest 3 FTPS pull folders.
- **`quoter-tool-working/*.backup-*` and `*.bak-*`:** not needed when GitHub backups are working; safe to delete anytime (they are not pushed to GitHub).


## Rules

- Never upload to GoDaddy without your explicit approval.
- Never upload without GitHub post-edit backup and GoDaddy remote backup.
- Password lives in `.env.local` only — never in git.
