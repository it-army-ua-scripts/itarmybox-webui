#!/bin/bash

echo "START UPDATE"
set -euo pipefail

REPO_DIR="/var/www/html/itarmybox-webui"
GITHUB_REPO="https://github.com/it-army-ua-scripts/itarmybox-webui.git"
BRANCH_STATE_FILE="/tmp/itarmybox-webui-update-branch.txt"
GITHUB_BRANCH="main"
ITARMY_DIR="/opt/itarmy"
INSTALL_ROOT_HELPER_SCRIPT="$REPO_DIR/systemd/install-root-helper.sh"

refresh_webui_systemd_units() {
  if [ -x "$INSTALL_ROOT_HELPER_SCRIPT" ] || [ -f "$INSTALL_ROOT_HELPER_SCRIPT" ]; then
    echo "Refreshing WebUI systemd units ..."
    /usr/bin/env bash "$INSTALL_ROOT_HELPER_SCRIPT"
    echo "DONE! WebUI systemd units refreshed."
  else
    echo "Skip WebUI systemd refresh: $INSTALL_ROOT_HELPER_SCRIPT not found."
  fi
}

persist_selected_branch() {
  printf '%s\n' "$GITHUB_BRANCH" > "$BRANCH_STATE_FILE"
}

if [ -n "${ITARMYBOX_UPDATE_BRANCH:-}" ]; then
  requested_branch="$(printf '%s' "$ITARMYBOX_UPDATE_BRANCH" | tr -d ' \t\r\n')"
  if [ "$requested_branch" = "main" ] || [ "$requested_branch" = "dev" ]; then
    GITHUB_BRANCH="$requested_branch"
  fi
elif [ -f "$BRANCH_STATE_FILE" ]; then
  saved_branch="$(tr -d ' \t\r\n' < "$BRANCH_STATE_FILE" 2>/dev/null || true)"
  if [ "$saved_branch" = "main" ] || [ "$saved_branch" = "dev" ]; then
    GITHUB_BRANCH="$saved_branch"
  fi
fi

cd "$REPO_DIR"

current_version="$(tr -d ' \t\r\n' < VERSION 2>/dev/null || true)"
if [ -z "$current_version" ]; then
  current_version="unknown"
fi
echo "Current version: $current_version"
echo "Selected branch: $GITHUB_BRANCH"

/usr/bin/git fetch "$GITHUB_REPO" "$GITHUB_BRANCH"

github_version="$(/usr/bin/git show FETCH_HEAD:VERSION 2>/dev/null | tr -d ' \t\r\n' || true)"
if [ -z "$github_version" ]; then
  echo "Cannot read VERSION from $GITHUB_REPO ($GITHUB_BRANCH), update aborted."
  exit 1
fi
echo "GitHub version: $github_version"

if [ "$GITHUB_BRANCH" != "dev" ]; then
  if [ "$github_version" = "$current_version" ]; then
    echo "Version is the same, update skipped."
    persist_selected_branch
    refresh_webui_systemd_units
    exit 0
  fi

  latest_version="$(printf '%s\n%s\n' "$current_version" "$github_version" | sort -V | tail -n 1)"
  if [ "$latest_version" != "$github_version" ]; then
    echo "GitHub version is not newer, update skipped."
    persist_selected_branch
    refresh_webui_systemd_units
    exit 0
  fi
else
  echo "Dev branch selected: version comparison disabled, update will run."
fi

echo "Updating to version $github_version ..."
/usr/bin/git reset --hard FETCH_HEAD
/usr/bin/git clean -fd
echo "DONE! Updated from $current_version to $github_version"
persist_selected_branch

if [ -d "$ITARMY_DIR/.git" ]; then
  echo "Updating $ITARMY_DIR ..."
  cd "$ITARMY_DIR"
  current_branch="$(/usr/bin/git rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)"
  if [ -z "$current_branch" ] || [ "$current_branch" = "HEAD" ]; then
    current_branch="main"
  fi
  /usr/bin/git fetch origin "$current_branch"
  /usr/bin/git reset --hard "origin/$current_branch"
  /usr/bin/git clean -fd
  echo "DONE! Updated $ITARMY_DIR (branch: $current_branch)"
else
  echo "Skip $ITARMY_DIR: not found or not a git repository."
fi

refresh_webui_systemd_units
