#!/usr/bin/env bash
set -euo pipefail

WEBUI_DIR="/var/www/html/itarmybox-webui"
SYSTEMD_DIR="/etc/systemd/system"

ln -sf "${WEBUI_DIR}/systemd/itarmybox-root-helper.socket" "${SYSTEMD_DIR}/itarmybox-root-helper.socket"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-root-helper@.service" "${SYSTEMD_DIR}/itarmybox-root-helper@.service"

systemctl daemon-reload
systemctl enable --now itarmybox-root-helper.socket

echo "Root helper socket is enabled: /run/itarmybox-root-helper.sock"
