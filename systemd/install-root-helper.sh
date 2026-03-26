#!/usr/bin/env bash
set -euo pipefail

WEBUI_DIR="/var/www/html/itarmybox-webui"
SYSTEMD_DIR="/etc/systemd/system"

ln -sf "${WEBUI_DIR}/systemd/itarmybox-root-helper.socket" "${SYSTEMD_DIR}/itarmybox-root-helper.socket"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-root-helper@.service" "${SYSTEMD_DIR}/itarmybox-root-helper@.service"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-distress-autotune.service" "${SYSTEMD_DIR}/itarmybox-distress-autotune.service"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-distress-autotune.timer" "${SYSTEMD_DIR}/itarmybox-distress-autotune.timer"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-wifi-txpower.service" "${SYSTEMD_DIR}/itarmybox-wifi-txpower.service"

systemctl daemon-reload
systemctl enable --now itarmybox-root-helper.socket
systemctl enable --now itarmybox-distress-autotune.timer
systemctl enable itarmybox-wifi-txpower.service

echo "Root helper socket is enabled: /run/itarmybox-root-helper.sock"
