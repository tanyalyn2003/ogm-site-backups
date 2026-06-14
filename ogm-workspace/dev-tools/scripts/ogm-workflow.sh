#!/usr/bin/env bash
# OGM edit workflow: fresh pull → GitHub backup → edit → GitHub backup → ask before GoDaddy upload
set -euo pipefail

OGM_ROOT="${OGM_ROOT:-/Users/tanyawhite/OGM}"
WORKING="${OGM_WORKING:-$OGM_ROOT/quoter-tool-working}"
FRESH_PULLS="${OGM_FRESH_PULLS:-$OGM_ROOT/fresh-godaddy-pulls}"
LOCAL_BACKUPS="${OGM_LOCAL_BACKUPS-}"
GITHUB_BACKUPS="${OGM_GITHUB_BACKUPS:-$OGM_ROOT/github-backups}"
FTPS_SCRIPT="${OGM_FTPS_SCRIPT:-$OGM_ROOT/dev-tools/godaddy-ftps/godaddy-ftps.sh}"
ENV_FILE="${OGM_ENV_FILE:-$OGM_ROOT/.env.local}"

if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$ENV_FILE"
fi

stamp() { date +%Y-%m-%d-%H%M; }
stamp_full() { date +%Y%m%d-%H%M%S; }

usage() {
  cat <<EOF
OGM website edit workflow

Usage:
  $0 start <remote-file> [task-slug]     Pull fresh from GoDaddy, copy to working dir, pre-edit GitHub backup
  $0 finish <filename> [task-slug]       Post-edit GitHub backup (after you edit in quoter-tool-working)
  $0 upload <filename> [task-slug]       Upload to GoDaddy ONLY when OGM_CONFIRM_UPLOAD=yes is set

Examples:
  $0 start email-center.php email-ai-draft
  # ... edit $WORKING/email-center.php ...
  $0 finish email-center.php email-ai-draft
  OGM_CONFIRM_UPLOAD=yes $0 upload email-center.php email-ai-draft

Rules enforced:
  - Always fresh-pull before editing
  - Pre-edit and post-edit backups pushed to $GITHUB_BACKUPS
  - Never uploads to GoDaddy without OGM_CONFIRM_UPLOAD=yes AND a GitHub post-edit backup
  - GoDaddy upload creates a remote backup before overwrite (via godaddy-ftps.sh)

Environment:
  OGM_FTPS_PASS          GoDaddy FTPS password (or set in $ENV_FILE)
  OGM_CONFIRM_UPLOAD     Must be "yes" to run upload
  OGM_GITHUB_REMOTE      Git remote name (default: origin)
EOF
}

ensure_github_repo() {
  if [[ ! -d "$GITHUB_BACKUPS/.git" ]]; then
    git -C "$GITHUB_BACKUPS" init -b main
    printf '# OGM site file backups\n\nTimestamped pre/post-edit copies from GoDaddy workflow.\n' > "$GITHUB_BACKUPS/README.md"
    git -C "$GITHUB_BACKUPS" add README.md
    git -C "$GITHUB_BACKUPS" commit -m "Initialize ogm-site-backups repository"
  fi
}

github_push_backup() {
  local phase="$1"   # pre-edit | post-edit
  local task="$2"
  local file="$3"
  local src="$4"
  local ts dir manifest base

  ensure_github_repo
  ts="$(stamp_full)"
  dir="$GITHUB_BACKUPS/$(stamp)-${task}-${phase}"
  base="$(basename "$file")"
  mkdir -p "$dir"
  cp "$src" "$dir/$base"
  manifest="$dir/MANIFEST.txt"
  cat > "$manifest" <<MANIFEST
phase: $phase
task: $task
file: $base
remote_path: public_html/quoter-tool/$file
source: $src
timestamp: $ts
MANIFEST

  git -C "$GITHUB_BACKUPS" add "$dir"
  git -C "$GITHUB_BACKUPS" commit -m "$phase backup: $base ($task) at $ts"

  local remote="${OGM_GITHUB_REMOTE:-origin}"
  if git -C "$GITHUB_BACKUPS" remote get-url "$remote" &>/dev/null; then
    if git -C "$GITHUB_BACKUPS" push "$remote" main; then
      printf 'GitHub backup pushed: %s/%s\n' "$dir" "$base"
    else
      printf 'WARNING: GitHub push failed — backup saved locally at %s/%s\n' "$dir" "$base" >&2
      printf 'Create the repo at https://github.com/new?name=ogm-site-backups then run: cd %s && git push -u origin main\n' "$GITHUB_BACKUPS" >&2
    fi
  else
    printf 'GitHub backup committed locally: %s/%s\n' "$dir" "$base"
    printf 'NOTE: No git remote "%s" configured. Run ogm-github-setup.sh once to connect GitHub.\n' "$remote"
  fi
}

cmd_start() {
  local remote_file="${1:?remote file required}"
  local task="${2:-edit-$(basename "$remote_file" | tr '.' '-')}"
  local pull_dir pulled working_file local_backup_dir

  [[ -x "$FTPS_SCRIPT" ]] || { printf 'ERROR: FTPS script not found: %s\n' "$FTPS_SCRIPT" >&2; exit 1; }

  pull_dir="$FRESH_PULLS/$(stamp)-ftps-pull"
  printf '=== Step 1: Fresh pull from GoDaddy ===\n'
  "$FTPS_SCRIPT" pull "$remote_file" "$pull_dir"
  pulled="$pull_dir/$(basename "$remote_file")"

  printf '=== Step 2: Copy to working directory ===\n'
  cp "$pulled" "$WORKING/$(basename "$remote_file")"
  working_file="$WORKING/$(basename "$remote_file")"

  local local_backup_dir=""
  if [[ -n "$LOCAL_BACKUPS" ]]; then
    printf '=== Step 3: Local pre-edit backup ===\n'
    local_backup_dir="$LOCAL_BACKUPS/$(stamp)-${task}-pre-edit"
    mkdir -p "$local_backup_dir"
    cp "$working_file" "$local_backup_dir/$(basename "$remote_file")"
    cp "$working_file" "$working_file.bak-$(stamp_full)"
  else
    printf '=== Step 3: Skipping local backup (GitHub-only; set OGM_LOCAL_BACKUPS to re-enable) ===\n'
  fi

  printf '=== Step 4: GitHub pre-edit backup (immediate push) ===\n'
  github_push_backup "pre-edit" "$task" "$remote_file" "$working_file"

  cat <<DONE

=== Ready to edit ===
Working file: $working_file
Fresh pull:     $pulled
Local backup:   $local_backup_dir

Edit the working file, then run:
  $0 finish $(basename "$remote_file") $task

DONE
}

cmd_finish() {
  local filename="${1:?filename required}"
  local task="${2:-edit-$(basename "$filename" | tr '.' '-')}"
  local working_file="$WORKING/$filename"

  [[ -f "$working_file" ]] || { printf 'ERROR: working file not found: %s\n' "$working_file" >&2; exit 1; }

  local local_backup_dir=""
  if [[ -n "$LOCAL_BACKUPS" ]]; then
    printf '=== Post-edit local backup ===\n'
    local_backup_dir="$LOCAL_BACKUPS/$(stamp)-${task}-post-edit"
    mkdir -p "$local_backup_dir"
    cp "$working_file" "$local_backup_dir/$filename"
  else
    printf '=== Skipping post-edit local backup (GitHub-only) ===\n'
  fi

  printf '=== GitHub post-edit backup (immediate push) ===\n'
  github_push_backup "post-edit" "$task" "$filename" "$working_file"

  cat <<DONE

=== Edits saved and backed up to GitHub ===
Working file: $working_file

Do you want to upload this file to GoDaddy?
  - I will NOT upload without your explicit approval.
  - Upload requires a GitHub post-edit backup (done above) and a GoDaddy remote backup.

To approve upload, run:
  OGM_CONFIRM_UPLOAD=yes $0 upload $filename $task

DONE
}

cmd_upload() {
  local filename="${1:?filename required}"
  local task="${2:-edit-$(basename "$filename" | tr '.' '-')}"
  local working_file="$WORKING/$filename"

  if [[ "${OGM_CONFIRM_UPLOAD:-}" != "yes" ]]; then
    printf 'BLOCKED: GoDaddy upload requires OGM_CONFIRM_UPLOAD=yes and your explicit approval.\n' >&2
    printf 'Run: OGM_CONFIRM_UPLOAD=yes %s upload %s %s\n' "$0" "$filename" "$task" >&2
    exit 1
  fi

  [[ -f "$working_file" ]] || { printf 'ERROR: working file not found: %s\n' "$working_file" >&2; exit 1; }

  # Require a post-edit GitHub backup for this task today
  if ! find "$GITHUB_BACKUPS" -type d -name "*-${task}-post-edit" 2>/dev/null | grep -q .; then
    printf 'ERROR: No post-edit GitHub backup found for task "%s". Run finish first.\n' "$task" >&2
    exit 1
  fi

  printf '=== GoDaddy upload (remote backup happens automatically) ===\n'
  "$FTPS_SCRIPT" upload "$task" "$working_file" "$filename"
  printf 'Upload complete: %s\n' "$filename"
}

case "${1:-}" in
  start) shift; cmd_start "$@" ;;
  finish) shift; cmd_finish "$@" ;;
  upload) shift; cmd_upload "$@" ;;
  -h|--help|help|'') usage ;;
  *) usage; exit 2 ;;
esac
