#!/usr/bin/env bash
# OGM edit workflow: fresh pull → edit → finish → ask before GoDaddy upload
set -euo pipefail

OGM_ROOT="${OGM_ROOT:-/Users/tanyawhite/OGM}"
WORKING="${OGM_WORKING:-$OGM_ROOT/quoter-tool-working}"
FRESH_PULLS="${OGM_FRESH_PULLS:-$OGM_ROOT/fresh-godaddy-pulls}"
LOCAL_BACKUPS="${OGM_LOCAL_BACKUPS-}"
SSD_BACKUPS="${OGM_SSD_BACKUPS-}"
GITHUB_BACKUPS="${OGM_GITHUB_BACKUPS:-$OGM_ROOT/github-backups}"
WORKFLOW_STATE="${OGM_WORKFLOW_STATE:-$WORKING/.ogm-workflow}"
FTPS_SCRIPT="${OGM_FTPS_SCRIPT:-$OGM_ROOT/dev-tools/godaddy-ftps/godaddy-ftps.sh}"
TASK_BUNDLES="${OGM_TASK_BUNDLES:-$OGM_ROOT/dev-tools/task-bundles}"
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

# Normalize remote path to quoter-tool-relative (no leading slash, no ..)
normalize_remote_file() {
  local f="${1:-}"
  f="${f#/}"
  f="${f#public_html/quoter-tool/}"
  if [[ "$f" == *".."* ]]; then
    printf 'ERROR: invalid remote path (.. not allowed): %s\n' "$1" >&2
    return 1
  fi
  printf '%s' "$f"
}

# Safe marker filename: user-admin/index.php → user-admin-index.php
marker_key_for_file() {
  local filename="$1"
  printf '%s' "$filename" | tr '/' '-'
}

finish_marker() {
  local task="$1"
  local filename="$2"
  local key
  key="$(marker_key_for_file "$filename")"
  printf '%s/%s-%s.ready' "$WORKFLOW_STATE" "$task" "$key"
}

working_file_for() {
  local filename="$1"
  printf '%s/%s' "$WORKING" "$filename"
}

# FTPS pull lands at pull_dir/basename only; map back to relative path
pulled_file_for() {
  local pull_dir="$1"
  local filename="$2"
  printf '%s/%s' "$pull_dir" "$(basename "$filename")"
}

warn_basename_collision() {
  local filename="$1"
  local base
  base="$(basename "$filename")"
  [[ "$filename" == "$base" ]] && return 0
  local flat="$WORKING/$base"
  if [[ -f "$flat" ]]; then
    printf '\n*** WARNING: Stone/login %s exists at flat path %s\n' "$base" "$flat"
    printf '    Nested file %s will NOT overwrite it (paths preserved).\n' "$filename"
    printf '    Do not edit %s when you mean %s.\n\n' "$flat" "$WORKING/$filename"
  fi
}

maybe_backup_dirty_working_file() {
  local working_file="$1"
  local pulled="$2"
  local remote_file="$3"

  [[ -f "$working_file" ]] || return 0
  if cmp -s "$pulled" "$working_file"; then
    return 0
  fi

  local bak="${working_file}.bak-before-restart-$(stamp_full)"
  mkdir -p "$(dirname "$bak")"
  cp "$working_file" "$bak"
  printf '\n*** WARNING: Working copy differs from fresh GoDaddy pull ***\n'
  printf '  Remote:  %s\n' "$remote_file"
  printf '  Working: %s\n' "$working_file"
  printf '  Saved previous working copy: %s\n' "$bak"
  if [[ "${OGM_FORCE_START:-}" != "yes" ]]; then
    printf '  Proceeding with fresh pull (GoDaddy is source of truth).\n'
    printf '  Set OGM_FORCE_START=yes to suppress this warning.\n\n'
  else
    printf '  OGM_FORCE_START=yes — backup saved, warning suppressed.\n\n'
  fi
}

usage() {
  cat <<EOF
OGM website edit workflow

Usage:
  $0 start <remote-file> [task-slug]       Pull fresh from GoDaddy, copy to working dir
  $0 finish <filename> [task-slug]         Mark edits ready for upload
  $0 upload <filename> [task-slug]         Upload when OGM_CONFIRM_UPLOAD=yes
  $0 bundle-start <bundle> [task-slug]     start each file in dev-tools/task-bundles/<bundle>.txt
  $0 bundle-finish <bundle> [task-slug]    finish each file in bundle
  $0 bundle-upload <bundle> [task-slug]    upload each file (requires OGM_CONFIRM_UPLOAD=yes)
  $0 prune [--dry-run]                     Prune legacy GitHub workflow folders
  $0 prune-local [--dry-run]               Prune old fresh-godaddy-pulls/
  $0 snapshot                              Full GoDaddy snapshot (planned)

Nested paths supported, e.g. user-admin/index.php → quoter-tool-working/user-admin/index.php

Examples:
  $0 start user-admin/index.php team-logins
  $0 finish user-admin/index.php team-logins
  $0 bundle-start api-access api-access
  OGM_CONFIRM_UPLOAD=yes $0 bundle-upload api-access api-access

Environment:
  OGM_FTPS_PASS           GoDaddy FTPS password (or in $ENV_FILE)
  OGM_CONFIRM_UPLOAD      Must be "yes" to upload
  OGM_GITHUB_BACKUP       Set to "yes" for dated snapshots in github-backups/
  OGM_SSD_BACKUPS         External SSD root (e.g. /Volumes/Crucial X10/Users/tanyawhite/OGM-backups)
  OGM_FORCE_START=yes     Suppress dirty-file warning (backup still created)
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
  local phase="$1"
  local task="$2"
  local file="$3"
  local src="$4"
  local ts dir manifest base dest_name

  ensure_github_repo
  ts="$(stamp_full)"
  dir="$GITHUB_BACKUPS/$(stamp)-${task}-${phase}"
  base="$(basename "$file")"
  dest_name="$base"
  if [[ "$file" == */* ]]; then
    dest_name="$(marker_key_for_file "$file")"
  fi
  mkdir -p "$dir"
  cp "$src" "$dir/$dest_name"
  manifest="$dir/MANIFEST.txt"
  cat > "$manifest" <<MANIFEST
phase: $phase
task: $task
file: $file
remote_path: public_html/quoter-tool/$file
source: $src
timestamp: $ts
MANIFEST

  git -C "$GITHUB_BACKUPS" add "$dir"
  git -C "$GITHUB_BACKUPS" commit -m "$phase backup: $file ($task) at $ts"

  local remote="${OGM_GITHUB_REMOTE:-origin}"
  if git -C "$GITHUB_BACKUPS" remote get-url "$remote" &>/dev/null; then
    if git -C "$GITHUB_BACKUPS" push "$remote" main; then
      printf 'GitHub backup pushed: %s/%s\n' "$dir" "$dest_name"
    else
      printf 'WARNING: GitHub push failed — backup saved locally at %s/%s\n' "$dir" "$dest_name" >&2
    fi
  else
    printf 'GitHub backup committed locally: %s/%s\n' "$dir" "$dest_name"
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
    printf '=== Skipping GitHub %s backup (set OGM_GITHUB_BACKUP=yes to opt in) ===\n' "$phase"
  fi
}

maybe_ssd_backup() {
  local phase="$1"
  local task="$2"
  local file="$3"
  local src="$4"
  local dir

  [[ -n "$SSD_BACKUPS" ]] || return 0
  dir="$SSD_BACKUPS/$(stamp)-${task}-${phase}"
  mkdir -p "$dir/$(dirname "$file")"
  cp "$src" "$dir/$file"
  printf 'SSD %s backup: %s/%s\n' "$phase" "$dir" "$file"
}

cmd_start() {
  local remote_file="${1:?remote file required}"
  local task="${2:-edit-$(marker_key_for_file "$(normalize_remote_file "$remote_file")")}"
  local rel pull_dir pulled working_file local_backup_dir

  rel="$(normalize_remote_file "$remote_file")" || exit 1
  remote_file="$rel"

  [[ -x "$FTPS_SCRIPT" ]] || { printf 'ERROR: FTPS script not found: %s\n' "$FTPS_SCRIPT" >&2; exit 1; }

  warn_basename_collision "$remote_file"

  pull_dir="$FRESH_PULLS/$(stamp_full)-ftps-pull"
  printf '=== Step 1: Fresh pull from GoDaddy ===\n'
  "$FTPS_SCRIPT" pull "$remote_file" "$pull_dir"
  pulled="$(pulled_file_for "$pull_dir" "$remote_file")"
  [[ -f "$pulled" ]] || { printf 'ERROR: pulled file not found: %s\n' "$pulled" >&2; exit 1; }

  working_file="$(working_file_for "$remote_file")"
  mkdir -p "$(dirname "$working_file")"

  printf '=== Step 2: Copy to working directory ===\n'
  maybe_backup_dirty_working_file "$working_file" "$pulled" "$remote_file"
  cp "$pulled" "$working_file"

  local_backup_dir=""
  if [[ -n "$LOCAL_BACKUPS" ]]; then
    printf '=== Step 3: Local pre-edit backup ===\n'
    local_backup_dir="$LOCAL_BACKUPS/$(stamp)-${task}-pre-edit"
    mkdir -p "$local_backup_dir/$(dirname "$remote_file")"
    cp "$working_file" "$local_backup_dir/$remote_file"
    cp "$working_file" "${working_file}.bak-$(stamp_full)"
  else
    printf '=== Step 3: Skipping local backup (set OGM_LOCAL_BACKUPS to re-enable) ===\n'
  fi

  maybe_ssd_backup "pre-edit" "$task" "$remote_file" "$working_file"
  maybe_github_push_backup "pre-edit" "$task" "$remote_file" "$working_file"

  cat <<DONE

=== Ready to edit ===
Working file: $working_file
Fresh pull:     $pulled
Local backup:   $local_backup_dir

Edit the working file, then run:
  $0 finish $remote_file $task

DONE
}

cmd_finish() {
  local filename="${1:?filename required}"
  local task="${2:-edit-$(marker_key_for_file "$(normalize_remote_file "$filename")")}"
  local rel working_file

  rel="$(normalize_remote_file "$filename")" || exit 1
  filename="$rel"
  working_file="$(working_file_for "$filename")"

  [[ -f "$working_file" ]] || { printf 'ERROR: working file not found: %s\n' "$working_file" >&2; exit 1; }

  local local_backup_dir=""
  if [[ -n "$LOCAL_BACKUPS" ]]; then
    printf '=== Post-edit local backup ===\n'
    local_backup_dir="$LOCAL_BACKUPS/$(stamp)-${task}-post-edit"
    mkdir -p "$local_backup_dir/$(dirname "$filename")"
    cp "$working_file" "$local_backup_dir/$filename"
  else
    printf '=== Skipping post-edit local backup ===\n'
  fi

  maybe_ssd_backup "post-edit" "$task" "$filename" "$working_file"
  maybe_github_push_backup "post-edit" "$task" "$filename" "$working_file"

  mkdir -p "$WORKFLOW_STATE"
  date -Iseconds > "$(finish_marker "$task" "$filename")"

  cat <<DONE

=== Edits saved ===
Working file: $working_file

Do you want to upload this file to GoDaddy?
  - I will NOT upload without your explicit approval.

To approve upload, run:
  OGM_CONFIRM_UPLOAD=yes $0 upload $filename $task

DONE
}

cmd_upload() {
  local filename="${1:?filename required}"
  local task="${2:-edit-$(marker_key_for_file "$(normalize_remote_file "$filename")")}"
  local rel working_file marker

  rel="$(normalize_remote_file "$filename")" || exit 1
  filename="$rel"
  working_file="$(working_file_for "$filename")"
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
      printf 'ERROR: Run finish first for task "%s" (file: %s).\n' "$task" "$filename" >&2
      exit 1
    fi
  fi

  printf '=== GoDaddy upload (remote backup happens automatically) ===\n'
  "$FTPS_SCRIPT" upload "$task" "$working_file" "$filename"
  rm -f "$marker"
  printf 'Upload complete: %s\n' "$filename"
}

bundle_list_files() {
  local bundle="${1:?bundle name required}"
  local list="$TASK_BUNDLES/${bundle}.txt"
  [[ -f "$list" ]] || { printf 'ERROR: bundle not found: %s\n' "$list" >&2; exit 1; }
  grep -v '^[[:space:]]*#' "$list" | grep -v '^[[:space:]]*$' || true
}

cmd_bundle_start() {
  local bundle="${1:?bundle required}"
  local task="${2:-$bundle}"
  local file
  while IFS= read -r file; do
    [[ -z "$file" ]] && continue
    printf '\n######## bundle-start: %s ########\n' "$file"
    cmd_start "$file" "$task"
  done < <(bundle_list_files "$bundle")
}

cmd_bundle_finish() {
  local bundle="${1:?bundle required}"
  local task="${2:-$bundle}"
  local file
  while IFS= read -r file; do
    [[ -z "$file" ]] && continue
    printf '\n######## bundle-finish: %s ########\n' "$file"
    cmd_finish "$file" "$task"
  done < <(bundle_list_files "$bundle")
}

cmd_bundle_upload() {
  local bundle="${1:?bundle required}"
  local task="${2:-$bundle}"
  local file
  while IFS= read -r file; do
    [[ -z "$file" ]] && continue
    printf '\n######## bundle-upload: %s ########\n' "$file"
    cmd_upload "$file" "$task"
  done < <(bundle_list_files "$bundle")
}

# --- Prune helpers (unchanged) ---

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
  printf 'Repo: %s\n' "$GITHUB_BACKUPS"

  local result
  result="$(python3 - "$GITHUB_BACKUPS" "$cutoff_90" "$cutoff_30" <<'PY'
import os, re, sys
repo = sys.argv[1]
cutoff_90 = sys.argv[2]
cutoff_30 = sys.argv[3]
protected_prefixes = ("migrated-local-backups-", "godaddy-full-snapshot-")
protected_exact = {"ogm-workspace", "README.md"}
pat = re.compile(r"^(\d{4}-\d{2}-\d{2})-(\d{4})-(.*)-(pre-edit|post-edit.*)$")

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
    return {"name": name, "date": date_s, "stamp": f"{date_s}-{time_s}", "month": date_s[:7], "task": task, "phase": phase, "is_pre": phase == "pre-edit", "is_post": phase.startswith("post-edit")}

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

  for name in "${to_delete[@]}"; do
    rm -rf "$FRESH_PULLS/$name"
  done
  printf 'Local prune complete.\n'
}

cmd_snapshot() {
  cat <<EOF
OGM full GoDaddy snapshot — not implemented yet.
See dev-tools/OGM-WORKFLOW.md and dev-tools/snapshots/*/RESTORE.md for manual rollback.
EOF
}

case "${1:-}" in
  start) shift; cmd_start "$@" ;;
  finish) shift; cmd_finish "$@" ;;
  upload) shift; cmd_upload "$@" ;;
  bundle-start) shift; cmd_bundle_start "$@" ;;
  bundle-finish) shift; cmd_bundle_finish "$@" ;;
  bundle-upload) shift; cmd_bundle_upload "$@" ;;
  prune) shift; cmd_prune "$@" ;;
  prune-local) shift; cmd_prune_local "$@" ;;
  snapshot) cmd_snapshot ;;
  -h|--help|help|'') usage ;;
  *) usage; exit 2 ;;
esac
