#!/usr/bin/env bash
set -euo pipefail

FTPS_HOST="${OGM_FTPS_HOST:-72.167.70.202}"
FTPS_USER="${OGM_FTPS_USER:-codex@oliveglassandmarble.com}"
REMOTE_ROOT="${OGM_FTPS_REMOTE_ROOT:-public_html/quoter-tool}"
LOCAL_ROOT="${OGM_LOCAL_ROOT:-/Users/tanyawhite/OGM}"
ENV_FILE="${OGM_ENV_FILE:-$LOCAL_ROOT/.env.local}"

if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$ENV_FILE"
fi

usage() {
  local self="${0}"
  cat <<EOF
Usage:
  $self stat <remote-file> [...]
  $self pull <remote-file> [local-dir]
  $self backup <description> <remote-file> [...]
  $self upload <description> <local-file> <remote-file>

Examples:
  $self stat OGM_KitchenPlanner.html
  $self pull OGM_KitchenPlanner.html
  $self backup before-kitchen-upload OGM_KitchenPlanner.html
  $self upload kitchenplanner-upload /tmp/OGM_KitchenPlanner.html OGM_KitchenPlanner.html

Password:
  Set OGM_FTPS_PASS in your shell, or the script will prompt for it.

Notes:
  - Uses 72.167.70.202 by default because ftp.oliveglassandmarble.com has been unreliable.
  - Uses relative GoDaddy paths under public_html/quoter-tool.
  - Pulls are rejected if they look like a 403 page or empty/tiny invalid file.
EOF
}

need_pass() {
  if [[ -z "${OGM_FTPS_PASS:-}" ]]; then
    printf 'FTPS password for %s: ' "$FTPS_USER" >&2
    IFS= read -r -s OGM_FTPS_PASS
    printf '\n' >&2
    export OGM_FTPS_PASS
  fi
}

remote_path() {
  local path="${1#/}"
  if [[ "$path" == "$REMOTE_ROOT/"* ]]; then
    printf '%s' "$path"
  else
    printf '%s/%s' "$REMOTE_ROOT" "$path"
  fi
}

remote_dir() {
  dirname "$(remote_path "$1")"
}

remote_base() {
  basename "$(remote_path "$1")"
}

lftp_get_to_dir() {
  local remote_file="$1"
  local local_dir="$2"
  local dir base local_path
  dir="$(remote_dir "$remote_file")"
  base="$(remote_base "$remote_file")"
  local_path="${local_dir}/${base}"
  mkdir -p "$local_dir"
  lftp_run "cd ${dir}
get ${base} -o ${local_path}"
}

lftp_put_to_remote() {
  local local_file="$1"
  local remote_file="$2"
  local dir base
  dir="$(remote_dir "$remote_file")"
  base="$(remote_base "$remote_file")"
  lftp_run "cd ${dir}
put ${local_file} -o ${base}"
}

lftp_run() {
  need_pass
  lftp -u "$FTPS_USER","$OGM_FTPS_PASS" "$FTPS_HOST" -e "
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ssl:verify-certificate no
set xfer:clobber on
$1
bye
"
}

validate_pull() {
  local file="$1"
  if [[ ! -s "$file" ]]; then
    printf 'ERROR: pulled file is empty or missing: %s\n' "$file" >&2
    return 1
  fi
  if head -n 5 "$file" | grep -qi '403 Forbidden'; then
    printf 'ERROR: pulled file is a 403 page, not a valid GoDaddy source file: %s\n' "$file" >&2
    return 1
  fi
  local bytes
  bytes="$(wc -c < "$file" | tr -d ' ')"
  if [[ "$bytes" -lt 64 ]]; then
    printf 'ERROR: pulled file is suspiciously tiny (%s bytes): %s\n' "$bytes" "$file" >&2
    return 1
  fi
}

cmd_stat() {
  if [[ "$#" -lt 1 ]]; then usage; exit 2; fi
  local script=''
  local file
  for file in "$@"; do
    script+="cls -l $(remote_path "$file")
"
  done
  lftp_run "$script"
}

cmd_pull() {
  if [[ "$#" -lt 1 || "$#" -gt 2 ]]; then usage; exit 2; fi
  local remote local_dir local_file
  remote="$(remote_path "$1")"
  local_dir="${2:-$LOCAL_ROOT/fresh-godaddy-pulls/$(date +%Y-%m-%d-%H%M)-ftps-pull}"
  mkdir -p "$local_dir"
  lftp_get_to_dir "$1" "$local_dir"
  local_file="$local_dir/$(basename "$remote")"
  validate_pull "$local_file"
  stat -f '%Sm %z bytes %N' "$local_file"
}

cmd_backup() {
  if [[ "$#" -lt 2 ]]; then usage; exit 2; fi
  local desc="$1"
  shift
  local stamp backup_dir tmpdir remote file
  stamp="$(date +%Y%m%d-%H%M%S)"
  backup_dir="$REMOTE_ROOT/backups/$stamp-$desc"
  tmpdir="$(mktemp -d /tmp/ogm-ftps-backup.XXXXXX)"
  lftp_run "mkdir \"$backup_dir\""
  for file in "$@"; do
    remote="$(remote_path "$file")"
    lftp_get_to_dir "$file" "$tmpdir"
    validate_pull "$tmpdir/$(basename "$remote")"
    lftp_put_to_remote "$tmpdir/$(basename "$remote")" "$backup_dir/$(basename "$remote")"
  done
  printf 'Remote backup created: %s\n' "$backup_dir"
}

cmd_upload() {
  if [[ "$#" -ne 3 ]]; then usage; exit 2; fi
  local desc="$1"
  local local_file="$2"
  local remote
  remote="$(remote_path "$3")"
  if [[ ! -f "$local_file" ]]; then
    printf 'ERROR: local upload file not found: %s\n' "$local_file" >&2
    exit 1
  fi
  local stamp backup_dir tmpdir base
  stamp="$(date +%Y%m%d-%H%M%S)"
  backup_dir="$REMOTE_ROOT/backups/$stamp-$desc"
  tmpdir="$(mktemp -d /tmp/ogm-ftps-upload.XXXXXX)"
  base="$(basename "$remote")"
  lftp_run "mkdir \"$backup_dir\""
  if lftp_run "cls -l \"$remote\"" >/dev/null 2>&1; then
    lftp_get_to_dir "$3" "$tmpdir"
    validate_pull "$tmpdir/$base"
    lftp_put_to_remote "$tmpdir/$base" "$backup_dir/$base"
    printf 'Backed up existing remote file to: %s/%s\n' "$backup_dir" "$base"
  else
    printf 'No existing remote file found for %s; upload will create it.\n' "$remote"
  fi
  lftp_put_to_remote "$local_file" "$3"
  printf 'Uploaded: %s -> %s\n' "$local_file" "$remote"
}

case "${1:-}" in
  stat) shift; cmd_stat "$@" ;;
  pull) shift; cmd_pull "$@" ;;
  backup) shift; cmd_backup "$@" ;;
  upload) shift; cmd_upload "$@" ;;
  -h|--help|help|'') usage ;;
  *) usage; exit 2 ;;
esac
