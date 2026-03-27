#!/usr/bin/env bash
set -euo pipefail

WEBUI_DIR="/var/www/html/itarmybox-webui"
SYSTEMD_DIR="/etc/systemd/system"
SKIP_ROOT_HELPER_SOCKET_REFRESH="${ITARMYBOX_SKIP_ROOT_HELPER_REFRESH:-0}"

ln -sf "${WEBUI_DIR}/systemd/itarmybox-root-helper.socket" "${SYSTEMD_DIR}/itarmybox-root-helper.socket"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-root-helper@.service" "${SYSTEMD_DIR}/itarmybox-root-helper@.service"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-distress-autotune.service" "${SYSTEMD_DIR}/itarmybox-distress-autotune.service"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-distress-autotune.timer" "${SYSTEMD_DIR}/itarmybox-distress-autotune.timer"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-wifi-txpower.service" "${SYSTEMD_DIR}/itarmybox-wifi-txpower.service"

systemctl daemon-reload
if [ "$SKIP_ROOT_HELPER_SOCKET_REFRESH" != "1" ]; then
  systemctl enable --now itarmybox-root-helper.socket
else
  echo "Skipping root helper socket refresh for this run."
fi
systemctl enable --now itarmybox-distress-autotune.timer
systemctl enable itarmybox-wifi-txpower.service

echo "Root helper socket is enabled: /run/itarmybox-root-helper.sock"
