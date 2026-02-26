#!/bin/bash

echo "START UPDATE"
set -euo pipefail

REPO_DIR="/var/www/html/itarmybox-webui"

cd "$REPO_DIR"

current_version="$(tr -d ' \t\r\n' < VERSION 2>/dev/null || true)"
if [ -z "$current_version" ]; then
  current_version="unknown"
fi
echo "Current version: $current_version"

/usr/bin/git fetch origin main

github_version="$(/usr/bin/git show origin/main:VERSION 2>/dev/null | tr -d ' \t\r\n' || true)"
if [ -z "$github_version" ]; then
  echo "Cannot read VERSION from origin/main, update aborted."
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
/usr/bin/git reset --hard origin/main
/usr/bin/git clean -fd
echo "DONE! Updated from $current_version to $github_version"
