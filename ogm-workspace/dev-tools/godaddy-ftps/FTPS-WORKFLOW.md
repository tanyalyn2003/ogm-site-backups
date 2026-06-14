# GoDaddy FTPS Workflow

Use `scripts/godaddy-ftps.sh` for GoDaddy pulls, remote backups, and uploads. It fixes the recurring FTPS problems by using:

- Host: `72.167.70.202`
- User: `codex@oliveglassandmarble.com`
- Relative remote root: `public_html/quoter-tool`
- No password stored in files
- 403/tiny-file validation after pulls
- Remote backup before upload

Set the password for the current terminal session:

```sh
export OGM_FTPS_PASS='PASTE_PASSWORD_HERE'
```

Pull a fresh file:

```sh
scripts/godaddy-ftps.sh pull OGM_KitchenPlanner.html
```

Check live modified times:

```sh
scripts/godaddy-ftps.sh stat client-viewer.js OGM_JobTracking.html ogm-clickup-bridge.js
```

Create a remote backup before a risky upload:

```sh
scripts/godaddy-ftps.sh backup before-kitchen-upload OGM_KitchenPlanner.html
```

Upload after approval:

```sh
scripts/godaddy-ftps.sh upload kitchenplanner-upload /path/to/OGM_KitchenPlanner.html OGM_KitchenPlanner.html
```

Rules:

- Always fresh-pull first.
- Always use the fresh pull as the base.
- Always create a remote backup before overwriting a live file.
- New remote files get a “no existing remote file” notice instead of an old-file backup.
- Do not use browser downloads as source of truth for protected files because they can return `403`.
