#!/usr/bin/env bash
# One-time setup: connect github-backups to GitHub remote ogm-site-backups
set -euo pipefail

OGM_ROOT="${OGM_ROOT:-/Users/tanyawhite/OGM}"
GITHUB_BACKUPS="${OGM_GITHUB_BACKUPS:-$OGM_ROOT/github-backups}"
GITHUB_USER="${OGM_GITHUB_USER:-tanyalyn2003}"
REPO_NAME="${OGM_GITHUB_REPO:-ogm-site-backups}"

ensure_github_repo() {
  if [[ ! -d "$GITHUB_BACKUPS/.git" ]]; then
    git -C "$GITHUB_BACKUPS" init -b main 2>/dev/null || git -C "$GITHUB_BACKUPS" init
    git -C "$GITHUB_BACKUPS" checkout -B main 2>/dev/null || true
    printf '# OGM site file backups\n\nTimestamped pre/post-edit copies from GoDaddy workflow.\n' > "$GITHUB_BACKUPS/README.md"
    git -C "$GITHUB_BACKUPS" add README.md
    git -C "$GITHUB_BACKUPS" commit -m "Initialize ogm-site-backups repository" || true
  fi
}

ensure_github_repo

REMOTE_URL="git@github.com:${GITHUB_USER}/${REPO_NAME}.git"

if git -C "$GITHUB_BACKUPS" remote get-url origin &>/dev/null; then
  printf 'Remote origin already set: %s\n' "$(git -C "$GITHUB_BACKUPS" remote get-url origin)"
else
  git -C "$GITHUB_BACKUPS" remote add origin "$REMOTE_URL"
  printf 'Added remote: %s\n' "$REMOTE_URL"
fi

cat <<INSTRUCTIONS

Next steps (one time):

1. Create the GitHub repo (private recommended):
   https://github.com/new?name=${REPO_NAME}

2. Push the backup repo:
   cd "$GITHUB_BACKUPS"
   git push -u origin main

After that, ogm-workflow.sh will push backups automatically on start and finish.

INSTRUCTIONS
