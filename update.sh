#!/bin/bash

echo "START UPDATE"
set -euo pipefail

REPO_DIR="/var/www/html/itarmybox-webui"
GITHUB_REPO="https://github.com/it-army-ua-scripts/itarmybox-webui.git"
GITHUB_BRANCH="main"

cd "$REPO_DIR"

current_version="$(tr -d ' \t\r\n' < VERSION 2>/dev/null || true)"
if [ -z "$current_version" ]; then
  current_version="unknown"
fi
echo "Current version: $current_version"

/usr/bin/git fetch "$GITHUB_REPO" "$GITHUB_BRANCH"

github_version="$(/usr/bin/git show FETCH_HEAD:VERSION 2>/dev/null | tr -d ' \t\r\n' || true)"
if [ -z "$github_version" ]; then
  echo "Cannot read VERSION from $GITHUB_REPO ($GITHUB_BRANCH), update aborted."
  exit 1
fi
echo "GitHub version: $github_version"

if [ "$github_version" = "$current_version" ]; then
  echo "Version is the same, update skipped."
  exit 0
fi

latest_version="$(printf '%s\n%s\n' "$current_version" "$github_version" | sort -V | tail -n 1)"
if [ "$latest_version" != "$github_version" ]; then
  echo "GitHub version is not newer, update skipped."
  exit 0
fi

echo "Updating to version $github_version ..."
/usr/bin/git reset --hard FETCH_HEAD
/usr/bin/git clean -fd
echo "DONE! Updated from $current_version to $github_version"
