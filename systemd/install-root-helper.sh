#!/usr/bin/env bash
set -euo pipefail

WEBUI_DIR="/var/www/html/itarmybox-webui"
SYSTEMD_DIR="/etc/systemd/system"
SKIP_ROOT_HELPER_SOCKET_REFRESH="${ITARMYBOX_SKIP_ROOT_HELPER_REFRESH:-0}"
WEBUI_STATE_DIR="${WEBUI_DIR}/var/state"
LEGACY_DISTRESS_STATE="/opt/itarmy/distress-autotune.json"
LEGACY_WIFI_STATE="/opt/itarmy/wifi-txpower.json"
WEBUI_DISTRESS_STATE="${WEBUI_STATE_DIR}/distress-autotune.json"
WEBUI_WIFI_STATE="${WEBUI_STATE_DIR}/wifi-txpower.json"
DISTRESS_DROPIN_DIR="${SYSTEMD_DIR}/distress.service.d"

cleanup_legacy_webui_files() {
  if [ -f "${WEBUI_DISTRESS_STATE}" ] && [ -f "${LEGACY_DISTRESS_STATE}" ]; then
    rm -f "${LEGACY_DISTRESS_STATE}"
  fi
  if [ -f "${WEBUI_WIFI_STATE}" ] && [ -f "${LEGACY_WIFI_STATE}" ]; then
    rm -f "${LEGACY_WIFI_STATE}"
  fi
}

mkdir -p "${WEBUI_STATE_DIR}"
mkdir -p "${DISTRESS_DROPIN_DIR}"

if [ ! -f "${WEBUI_DISTRESS_STATE}" ] && [ -f "${LEGACY_DISTRESS_STATE}" ]; then
  cp -f "${LEGACY_DISTRESS_STATE}" "${WEBUI_DISTRESS_STATE}"
fi
if [ ! -f "${WEBUI_WIFI_STATE}" ] && [ -f "${LEGACY_WIFI_STATE}" ]; then
  cp -f "${LEGACY_WIFI_STATE}" "${WEBUI_WIFI_STATE}"
fi

ln -sf "${WEBUI_DIR}/systemd/itarmybox-root-helper.socket" "${SYSTEMD_DIR}/itarmybox-root-helper.socket"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-root-helper@.service" "${SYSTEMD_DIR}/itarmybox-root-helper@.service"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-distress-bps-collector.service" "${SYSTEMD_DIR}/itarmybox-distress-bps-collector.service"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-distress-bps-collector.timer" "${SYSTEMD_DIR}/itarmybox-distress-bps-collector.timer"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-distress-autotune-safety.service" "${SYSTEMD_DIR}/itarmybox-distress-autotune-safety.service"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-distress-autotune-safety.timer" "${SYSTEMD_DIR}/itarmybox-distress-autotune-safety.timer"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-distress-autotune.service" "${SYSTEMD_DIR}/itarmybox-distress-autotune.service"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-distress-autotune.timer" "${SYSTEMD_DIR}/itarmybox-distress-autotune.timer"
ln -sf "${WEBUI_DIR}/systemd/itarmybox-wifi-txpower.service" "${SYSTEMD_DIR}/itarmybox-wifi-txpower.service"
ln -sf "${WEBUI_DIR}/systemd/distress.service.d/itarmybox-upload-cap.conf" "${DISTRESS_DROPIN_DIR}/itarmybox-upload-cap.conf"

systemctl daemon-reload
if [ "$SKIP_ROOT_HELPER_SOCKET_REFRESH" != "1" ]; then
  systemctl enable --now itarmybox-root-helper.socket
else
  echo "Skipping root helper socket refresh for this run."
fi
systemctl enable itarmybox-distress-bps-collector.timer
systemctl restart itarmybox-distress-bps-collector.timer
systemctl enable itarmybox-distress-autotune-safety.timer
systemctl restart itarmybox-distress-autotune-safety.timer
systemctl enable itarmybox-distress-autotune.timer
systemctl restart itarmybox-distress-autotune.timer
systemctl enable itarmybox-wifi-txpower.service

cleanup_legacy_webui_files

echo "Root helper socket is enabled: /run/itarmybox-root-helper.sock"
