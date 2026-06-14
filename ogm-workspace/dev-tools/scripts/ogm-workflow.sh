#!/usr/bin/env bash
# OGM edit workflow: fresh pull → edit → finish → ask before GoDaddy upload
set -euo pipefail

OGM_ROOT="${OGM_ROOT:-/Users/tanyawhite/OGM}"
WORKING="${OGM_WORKING:-$OGM_ROOT/quoter-tool-working}"
FRESH_PULLS="${OGM_FRESH_PULLS:-$OGM_ROOT/fresh-godaddy-pulls}"
LOCAL_BACKUPS="${OGM_LOCAL_BACKUPS-}"
GITHUB_BACKUPS="${OGM_GITHUB_BACKUPS:-$OGM_ROOT/github-backups}"
WORKFLOW_STATE="${OGM_WORKFLOW_STATE:-$WORKING/.ogm-workflow}"
FTPS_SCRIPT="${OGM_FTPS_SCRIPT:-$OGM_ROOT/dev-tools/godaddy-ftps/godaddy-ftps.sh}"
ENV_FILE="${OGM_ENV_FILE:-$OGM_ROOT/.env.local}"

if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$ENV_FILE"
fi

stamp() { date +%Y-%m-%d-%H%M; }
stamp_full() { date +%Y%m%d-%H%M%S; }

github_backups_enabled() {
  [[ "${OGM_GITHUB_BACKUP:-}" == "yes" ]]
}

finish_marker() {
  local task="$1"
  local filename="$2"
  printf '%s/%s-%s.ready' "$WORKFLOW_STATE" "$task" "$filename"
}

usage() {
  cat <<EOF
OGM website edit workflow

Usage:
  $0 start <remote-file> [task-slug]     Pull fresh from GoDaddy, copy to working dir
  $0 finish <filename> [task-slug]       Mark edits ready for upload (after you edit in quoter-tool-working)
  $0 upload <filename> [task-slug]       Upload to GoDaddy ONLY when OGM_CONFIRM_UPLOAD=yes is set
  $0 prune [--dry-run]                   Prune legacy dated workflow folders in github-backups/ (obsolete for new edits)
  $0 prune-local [--dry-run]             Prune old fresh-godaddy-pulls/ (keep latest 3; >7 days)
  $0 snapshot                            Full GoDaddy snapshot (planned — not implemented)

Examples:
  $0 start email-center.php email-ai-draft
  # ... edit $WORKING/email-center.php ...
  $0 finish email-center.php email-ai-draft
  OGM_CONFIRM_UPLOAD=yes $0 upload email-center.php email-ai-draft

  $0 prune-local --dry-run
  OGM_CONFIRM_PRUNE_LOCAL=yes $0 prune-local

Rules enforced:
  - Always fresh-pull before editing
  - GitHub file snapshots are OFF by default (set OGM_GITHUB_BACKUP=yes to opt in)
  - Never uploads to GoDaddy without OGM_CONFIRM_UPLOAD=yes AND finish for this task
  - GoDaddy upload creates a remote backup before overwrite (via godaddy-ftps.sh)
  - Prune defaults to dry-run; set OGM_CONFIRM_PRUNE=yes (or OGM_CONFIRM_PRUNE_LOCAL=yes) to delete

Environment:
  OGM_FTPS_PASS          GoDaddy FTPS password (or set in $ENV_FILE)
  OGM_CONFIRM_UPLOAD     Must be "yes" to run upload
  OGM_GITHUB_BACKUP      Set to "yes" to create dated pre/post-edit snapshots in github-backups/
  OGM_CONFIRM_PRUNE      Must be "yes" to execute github-backups prune (not dry-run)
  OGM_CONFIRM_PRUNE_LOCAL Must be "yes" to execute fresh-godaddy-pulls prune (or use OGM_CONFIRM_PRUNE=yes)
  OGM_GITHUB_REMOTE      Git remote name (default: origin)
EOF
}

ensure_github_repo() {
  if [[ ! -d "$GITHUB_BACKUPS/.git" ]]; then
    git -C "$GITHUB_BACKUPS" init -b main
    printf '# OGM workspace\n\nDocs, scripts, and Cursor rules for the OGM quoter-tool workflow.\n' > "$GITHUB_BACKUPS/README.md"
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

maybe_github_push_backup() {
  local phase="$1"
  local task="$2"
  local file="$3"
  local src="$4"

  if github_backups_enabled; then
    printf '=== GitHub %s backup (OGM_GITHUB_BACKUP=yes) ===\n' "$phase"
    github_push_backup "$phase" "$task" "$file" "$src"
  else
    printf '=== Skipping GitHub %s backup (default; set OGM_GITHUB_BACKUP=yes to opt in) ===\n' "$phase"
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
    printf '=== Step 3: Skipping local backup (set OGM_LOCAL_BACKUPS to re-enable) ===\n'
  fi

  maybe_github_push_backup "pre-edit" "$task" "$remote_file" "$working_file"

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
    printf '=== Skipping post-edit local backup ===\n'
  fi

  maybe_github_push_backup "post-edit" "$task" "$filename" "$working_file"

  mkdir -p "$WORKFLOW_STATE"
  date -Iseconds > "$(finish_marker "$task" "$filename")"

  cat <<DONE

=== Edits saved ===
Working file: $working_file

Do you want to upload this file to GoDaddy?
  - I will NOT upload without your explicit approval.
  - Upload requires finish (done above) and a GoDaddy remote backup on upload.

To approve upload, run:
  OGM_CONFIRM_UPLOAD=yes $0 upload $filename $task

DONE
}

cmd_upload() {
  local filename="${1:?filename required}"
  local task="${2:-edit-$(basename "$filename" | tr '.' '-')}"
  local working_file="$WORKING/$filename"
  local marker
  marker="$(finish_marker "$task" "$filename")"

  if [[ "${OGM_CONFIRM_UPLOAD:-}" != "yes" ]]; then
    printf 'BLOCKED: GoDaddy upload requires OGM_CONFIRM_UPLOAD=yes and your explicit approval.\n' >&2
    printf 'Run: OGM_CONFIRM_UPLOAD=yes %s upload %s %s\n' "$0" "$filename" "$task" >&2
    exit 1
  fi

  [[ -f "$working_file" ]] || { printf 'ERROR: working file not found: %s\n' "$working_file" >&2; exit 1; }

  if [[ ! -f "$marker" ]]; then
    if github_backups_enabled; then
      if ! find "$GITHUB_BACKUPS" -type d -name "*-${task}-post-edit" 2>/dev/null | grep -q .; then
        printf 'ERROR: No finish marker and no post-edit GitHub backup for task "%s". Run finish first.\n' "$task" >&2
        exit 1
      fi
    else
      printf 'ERROR: Run finish first for task "%s".\n' "$task" >&2
      exit 1
    fi
  fi

  printf '=== GoDaddy upload (remote backup happens automatically) ===\n'
  "$FTPS_SCRIPT" upload "$task" "$working_file" "$filename"
  rm -f "$marker"
  printf 'Upload complete: %s\n' "$filename"
}

# --- Prune helpers ---

date_days_ago() {
  local days="$1"
  date -v-"${days}"d +%Y-%m-%d
}

cmd_prune() {
  local dry_run=1
  local arg

  for arg in "$@"; do
    if [[ "$arg" == "--dry-run" ]]; then
      dry_run=1
    fi
  done
  if [[ "${OGM_CONFIRM_PRUNE:-}" == "yes" ]] && [[ "$*" != *"--dry-run"* ]]; then
    dry_run=0
  fi

  local cutoff_90 cutoff_30
  cutoff_90="$(date_days_ago 90)"
  cutoff_30="$(date_days_ago 30)"

  printf '=== GitHub backup prune (%s) ===\n' "$([[ "$dry_run" -eq 1 ]] && echo 'dry-run' || echo 'EXECUTE')"
  printf 'NOTE: New edits no longer create dated GitHub snapshots by default. This prunes legacy folders only.\n'
  printf 'Repo: %s\n' "$GITHUB_BACKUPS"
  printf 'Keep all folders since: %s | pre-edit drop when post exists since: %s\n' "$cutoff_90" "$cutoff_30"

  local result
  result="$(python3 - "$GITHUB_BACKUPS" "$cutoff_90" "$cutoff_30" <<'PY'
import os, re, sys
from datetime import datetime

repo = sys.argv[1]
cutoff_90 = sys.argv[2]
cutoff_30 = sys.argv[3]

protected_prefixes = ("migrated-local-backups-", "godaddy-full-snapshot-")
protected_exact = {"ogm-workspace", "README.md"}
pat = re.compile(
    r"^(\d{4}-\d{2}-\d{2})-(\d{4})-(.*)-(pre-edit|post-edit.*)$"
)

def is_workflow(name):
    if name in protected_exact:
        return None
    for p in protected_prefixes:
        if name.startswith(p):
            return None
    m = pat.match(name)
    if not m:
        return None
    date_s, time_s, task, phase = m.groups()
    return {
        "name": name,
        "date": date_s,
        "stamp": f"{date_s}-{time_s}",
        "month": date_s[:7],
        "task": task,
        "phase": phase,
        "is_pre": phase == "pre-edit",
        "is_post": phase.startswith("post-edit"),
    }

folders = []
for entry in os.listdir(repo):
    path = os.path.join(repo, entry)
    if not os.path.isdir(path) and not os.path.isfile(path):
        continue
    info = is_workflow(entry)
    if info:
        folders.append(info)

keep = set()
for f in folders:
    if f["date"] >= cutoff_90:
        keep.add(f["name"])

old_by_month = {}
for f in folders:
    if f["date"] < cutoff_90:
        key = f["month"]
        if key not in old_by_month or f["stamp"] > old_by_month[key]["stamp"]:
            old_by_month[key] = f
for f in old_by_month.values():
    keep.add(f["name"])

tasks_with_post = {f["task"] for f in folders if f["is_post"]}
for f in folders:
    if f["is_pre"] and f["date"] < cutoff_30 and f["task"] in tasks_with_post:
        keep.discard(f["name"])

to_delete = sorted(f["name"] for f in folders if f["name"] not in keep)
to_keep = sorted(keep)
print(f"STATS:{len(folders)}:{len(to_keep)}:{len(to_delete)}")
for name in to_delete:
    print(f"DELETE:{name}")
PY
)"

  local stats_line delete_count=0
  stats_line="$(printf '%s\n' "$result" | grep '^STATS:' | head -1)"
  if [[ -z "$stats_line" ]]; then
    printf 'No legacy workflow folders matched prune rules.\n'
    return 0
  fi
  IFS=':' read -r _ total keep_count delete_count <<< "$stats_line"
  printf 'Workflow folders found: %s | keep: %s | delete: %s\n' "$total" "$keep_count" "$delete_count"

  if [[ "$delete_count" -eq 0 ]]; then
    printf 'Nothing to prune.\n'
    return 0
  fi

  if [[ "$dry_run" -eq 1 ]]; then
    printf '\nWould DELETE:\n'
    while IFS= read -r line; do
      [[ "$line" == DELETE:* ]] || continue
      printf '  %s\n' "${line#DELETE:}"
    done <<< "$result"
    printf '\nDry-run only. To execute: OGM_CONFIRM_PRUNE=yes %s prune\n' "$0"
    return 0
  fi

  printf '\nDeleting:\n'
  while IFS= read -r line; do
    [[ "$line" == DELETE:* ]] || continue
    name="${line#DELETE:}"
    dir="$GITHUB_BACKUPS/$name"
    if [[ -d "$dir" ]]; then
      rm -rf "$dir"
      printf '  deleted dir: %s\n' "$name"
    elif [[ -f "$dir" ]]; then
      rm -f "$dir"
      printf '  deleted file: %s\n' "$name"
    fi
  done <<< "$result"

  ensure_github_repo
  git -C "$GITHUB_BACKUPS" add -A
  if git -C "$GITHUB_BACKUPS" diff --cached --quiet; then
    printf 'No git changes after prune.\n'
    return 0
  fi
  git -C "$GITHUB_BACKUPS" commit -m "prune: remove $delete_count old workflow backup folders"
  local remote="${OGM_GITHUB_REMOTE:-origin}"
  if git -C "$GITHUB_BACKUPS" remote get-url "$remote" &>/dev/null; then
    git -C "$GITHUB_BACKUPS" push "$remote" main
    printf 'Prune committed and pushed to %s/main\n' "$remote"
  else
    printf 'Prune committed locally (no remote %s configured).\n' "$remote"
  fi
}

pull_folder_date() {
  local name="$1"
  if [[ "$name" =~ ^([0-9]{4}-[0-9]{2}-[0-9]{2}) ]]; then
  printf '%s\n' "${BASH_REMATCH[1]}"
    return 0
  fi
  if [[ -d "$FRESH_PULLS/$name" ]]; then
    stat -f '%Sm' -t '%Y-%m-%d' "$FRESH_PULLS/$name"
    return 0
  fi
  return 1
}

cmd_prune_local() {
  local dry_run=1
  local arg name cutoff_7 folder_date
  local -a all_dirs=()
  local -a protected=()
  local -a to_delete=()

  for arg in "$@"; do
    if [[ "$arg" == "--dry-run" ]]; then
      dry_run=1
    fi
  done
  if [[ "${OGM_CONFIRM_PRUNE:-}" == "yes" || "${OGM_CONFIRM_PRUNE_LOCAL:-}" == "yes" ]] && [[ "$*" != *"--dry-run"* ]]; then
    dry_run=0
  fi

  cutoff_7="$(date_days_ago 7)"

  printf '=== Local fresh-godaddy-pulls prune (%s) ===\n' "$([[ "$dry_run" -eq 1 ]] && echo 'dry-run' || echo 'EXECUTE')"
  printf 'Directory: %s\n' "$FRESH_PULLS"
  printf 'Delete pulls older than %s except newest 3 folders\n' "$cutoff_7"

  [[ -d "$FRESH_PULLS" ]] || { printf 'Nothing to prune (directory missing).\n'; return 0; }

  while IFS= read -r path; do
    [[ -z "$path" ]] && continue
    all_dirs+=("$(basename "$path")")
  done < <(ls -1td "$FRESH_PULLS"/*/ 2>/dev/null || true)

  local idx=0
  for name in "${all_dirs[@]}"; do
    if [[ "$idx" -lt 3 ]]; then
      protected+=("$name")
      idx=$((idx + 1))
      continue
    fi
    folder_date="$(pull_folder_date "$name" || echo "")"
    if [[ -z "$folder_date" ]]; then
      to_delete+=("$name")
      continue
    fi
    if [[ "$folder_date" < "$cutoff_7" ]]; then
      to_delete+=("$name")
    fi
  done

  printf 'Pull folders: %d | protected (newest 3): %d | delete: %d\n' "${#all_dirs[@]}" "${#protected[@]}" "${#to_delete[@]}"
  if [[ "${#protected[@]}" -gt 0 ]]; then
    printf 'Always keeping newest:\n'
    for name in "${protected[@]}"; do
      printf '  %s\n' "$name"
    done
  fi

  if [[ "${#to_delete[@]}" -eq 0 ]]; then
    printf 'Nothing to prune locally.\n'
    return 0
  fi

  if [[ "$dry_run" -eq 1 ]]; then
    printf '\nWould DELETE:\n'
    for name in "${to_delete[@]}"; do
      printf '  %s\n' "$name"
    done
    printf '\nDry-run only. To execute: OGM_CONFIRM_PRUNE_LOCAL=yes %s prune-local\n' "$0"
    return 0
  fi

  printf '\nDeleting:\n'
  for name in "${to_delete[@]}"; do
    rm -rf "$FRESH_PULLS/$name"
    printf '  %s\n' "$name"
  done
  printf 'Local prune complete.\n'
}

cmd_snapshot() {
  cat <<EOF
OGM full GoDaddy snapshot — not implemented yet.

Planned: pull entire public_html/quoter-tool tree into github-backups/godaddy-full-snapshot-YYYYMMDD/
Routine edits use start/finish with GoDaddy as live source and server backups/ on upload.
See dev-tools/OGM-WORKFLOW.md and ogm-workspace/docs/OGM-LOCAL-DISK-PLAN.md.
EOF
}

case "${1:-}" in
  start) shift; cmd_start "$@" ;;
  finish) shift; cmd_finish "$@" ;;
  upload) shift; cmd_upload "$@" ;;
  prune) shift; cmd_prune "$@" ;;
  prune-local) shift; cmd_prune_local "$@" ;;
  snapshot) cmd_snapshot ;;
  -h|--help|help|'') usage ;;
  *) usage; exit 2 ;;
esac
