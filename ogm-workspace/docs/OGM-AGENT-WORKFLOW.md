# OGM Agent Workflow (Cursor AI)

**Read this file and `.cursor/rules/ogm-edit-workflow.mdc` before any OGM live-site task.**

Tanya edits on **two computers**. Local copies are often stale. **GoDaddy is the only source of truth at task start.**

---

## Source of truth

| Source | Valid for editing? |
|--------|------------------|
| GoDaddy via `ogm-workflow.sh start` → `quoter-tool-working/` | **Yes** (after fresh pull in this session) |
| `quoter-tool-working/` without same-session `start` | **No** |
| `github-backups/YYYY-MM-DD-*-pre-edit` / `*-post-edit` | **No** — history only |
| `github-backups/migrated-local-backups-*` | **No** |
| `backups/` on Mac | **No** — legacy |
| `fresh-godaddy-pulls/` (except just-created pull from `start`) | **No** |
| Memory, old chat, Desktop/Downloads | **No** |
| `github-backups/ogm-workspace/docs/` | **Yes** — documentation only |

`github-backups/` is **HISTORY**, not live.

---

## Mandatory command sequence

```sh
# 1. BEFORE any edit (every session, every file)
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh start <remote-file> [task-slug]

# 2. Edit ONLY
/Users/tanyawhite/OGM/quoter-tool-working/<filename>

# 3. AFTER every edit
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh finish <filename> [task-slug]

# 4. ASK Tanya: "Do you want me to upload this to GoDaddy?"
# 5. Upload ONLY after explicit upload approval:
OGM_CONFIRM_UPLOAD=yes /Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh upload <filename> [task-slug]
```

Upload approval must be **upload-specific** (“Yes, upload”, “Push it live”) — not generic “yes” or “go ahead”.

---

## Hard blocks (refuse to proceed)

- Edit live file without `start` for that file **in this session**
- Skip `start` because “we already pulled earlier” or “local looks fine”
- Upload without `finish` + explicit upload approval
- Upload on ambiguous approval
- Commit `.env.local` or FTPS passwords
- Commit parent `/Users/tanyawhite/OGM` git repo (no remote — use `github-backups` for backups)
- Use backup folders as edit source

---

## Retention / disk hygiene

Monthly (or when disk is tight):

```sh
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune --dry-run
/Users/tanyawhite/OGM/dev-tools/scripts/ogm-workflow.sh prune-local --dry-run
# Then with confirmation env vars if output looks correct
```

See `OGM-LOCAL-DISK-PLAN.md` and `dev-tools/OGM-WORKFLOW.md`.

---

## Skills & rules

- Skill: `ogm-godaddy-workflow` (user skills)
- Rules: `ogm-edit-workflow.mdc`, `ogm-knowledge-base.mdc`
- Site map: `OGM-SITE-STRUCTURE.md` (this folder)
- Changelog: `OGM-CHANGELOG.md` — update when shipping features

---

## Documentation updates

When you add a feature:

1. `OGM-CHANGELOG.md` entry
2. Relevant section in `OGM-SITE-STRUCTURE.md` or `OGM-SOFTWARE-GUIDE.md`
3. Push `github-backups` repo; sync `ogm-workspace/` copies of workflow scripts

---

## FTPS reference

- Script: `dev-tools/godaddy-ftps/godaddy-ftps.sh`
- Remote root: `public_html/quoter-tool`
- Password: `/Users/tanyawhite/OGM/.env.local` only
