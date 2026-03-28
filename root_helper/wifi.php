<?php

declare(strict_types=1);

function findIwBinary(): ?string
{
    return findExecutable(['/usr/sbin/iw', '/usr/bin/iw', '/sbin/iw', '/bin/iw']);
}

function getWifiApInterface(): string
{
    return WIFI_AP_INTERFACE;
}

function normalizeWifiSsid($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $ssid = trim($value);
    if ($ssid === '' || $ssid !== $value) {
        return null;
    }

    if (strlen($ssid) > 32) {
        return null;
    }

    if (preg_match('/^[\x20-\x7E]+$/', $ssid) !== 1) {
        return null;
    }

    return $ssid;
}

function readWifiApName(): array
{
    $config = @file_get_contents(HOSTAPD_CONFIG_PATH);
    if (!is_string($config) || $config === '') {
        return [
            'ok' => true,
            'iface' => WIFI_AP_INTERFACE,
            'ssid' => WIFI_AP_DEFAULT_NAME,
            'defaultSsid' => WIFI_AP_DEFAULT_NAME,
        ];
    }

    if (preg_match('/^\s*ssid=(.*)$/m', $config, $matches) !== 1) {
        return [
            'ok' => true,
            'iface' => WIFI_AP_INTERFACE,
            'ssid' => WIFI_AP_DEFAULT_NAME,
            'defaultSsid' => WIFI_AP_DEFAULT_NAME,
        ];
    }

    $ssid = trim((string)$matches[1]);
    if ($ssid === '') {
        $ssid = WIFI_AP_DEFAULT_NAME;
    }

    return [
        'ok' => true,
        'iface' => WIFI_AP_INTERFACE,
        'ssid' => $ssid,
        'defaultSsid' => WIFI_AP_DEFAULT_NAME,
    ];
}

function setWifiApName($value): array
{
    $ssid = normalizeWifiSsid($value);
    if ($ssid === null) {
        return ['ok' => false, 'error' => 'invalid_wifi_ap_name'];
    }

    $config = @file_get_contents(HOSTAPD_CONFIG_PATH);
    if (!is_string($config) || $config === '') {
        return ['ok' => false, 'error' => 'hostapd_config_unavailable'];
    }

    $line = 'ssid=' . $ssid;
    if (preg_match('/^\s*ssid=.*$/m', $config) === 1) {
        $updated = preg_replace('/^\s*ssid=.*$/m', $line, $config, 1);
    } else {
        $separator = str_ends_with($config, "\n") ? '' : "\n";
        $updated = $config . $separator . $line . "\n";
    }

    if (!is_string($updated) || @file_put_contents(HOSTAPD_CONFIG_PATH, $updated) === false) {
        if (repairRootHelperAccess()) {
            return ['ok' => false, 'error' => 'root_helper_reloaded_retry'];
        }
        return ['ok' => false, 'error' => 'hostapd_config_write_failed'];
    }

    $restartOutput = '';
    $systemctl = findSystemctl();
    if ($systemctl !== null) {
        $restartOutput = runCommandVerbose(
            escapeshellarg($systemctl) . ' restart ' . escapeshellarg(HOSTAPD_SERVICE_NAME),
            $restartCode
        );
        if ($restartCode === 0) {
            $restartOutput = '';
        }
    } else {
        $restartCode = 1;
    }

    if ($restartCode !== 0) {
        $service = findServiceBinary();
        if ($service !== null) {
            $restartOutput = runCommandVerbose(
                escapeshellarg($service) . ' hostapd restart',
                $serviceCode
            );
            $restartCode = $serviceCode;
        }
    }

    if ($restartCode !== 0) {
        return ['ok' => false, 'error' => 'hostapd_restart_failed', 'details' => $restartOutput];
    }

    $state = readWifiApName();
    if (($state['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'wifi_ap_name_verify_failed'];
    }

    return $state;
}

function centiDbmToDbmString(int $centiDbm): string
{
    return number_format($centiDbm / 100, 2, '.', '');
}

function persistWifiTxPowerState(int $centiDbm): bool
{
    ensureWebuiVarLayout();
    migrateLegacyFileIfNeeded(WIFI_TXPOWER_LEGACY_STATE_FILE, WIFI_TXPOWER_STATE_FILE);
    if (!ensureParentDirectoryExists(WIFI_TXPOWER_STATE_FILE)) {
        return false;
    }

    $payload = json_encode([
        'centiDbm' => $centiDbm,
        'dbm' => centiDbmToDbmString($centiDbm),
        'updatedAt' => time(),
    ], JSON_UNESCAPED_SLASHES);
    return is_string($payload) && @file_put_contents(WIFI_TXPOWER_STATE_FILE, $payload) !== false;
}

function ensureWifiTxPowerServiceInstalled(): bool
{
    if (!is_file(WIFI_TXPOWER_SERVICE_PATH)) {
        return false;
    }

    $target = '/etc/systemd/system/itarmybox-wifi-txpower.service';
    if (is_link($target)) {
        $current = readlink($target);
        if ($current !== WIFI_TXPOWER_SERVICE_PATH) {
            @unlink($target);
        }
    } elseif (file_exists($target)) {
        @unlink($target);
    }

    if (!is_link($target) && !@symlink(WIFI_TXPOWER_SERVICE_PATH, $target)) {
        return false;
    }

    $systemctl = findSystemctl();
    if ($systemctl === null) {
        return false;
    }

    runCommand(escapeshellarg($systemctl) . ' daemon-reload', $reloadCode);
    if ($reloadCode !== 0) {
        return false;
    }

    runCommand(escapeshellarg($systemctl) . ' enable itarmybox-wifi-txpower.service', $enableCode);
    return $enableCode === 0;
}

function normalizeWifiTxPowerCentiDbm($value): ?int
{
    if (is_int($value)) {
        $centiDbm = $value;
    } elseif (is_float($value)) {
        $centiDbm = (int)round($value * 100);
    } elseif (is_string($value)) {
        $raw = trim($value);
        if ($raw === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $raw) !== 1) {
            return null;
        }
        $centiDbm = (int)round(((float)$raw) * 100);
    } else {
        return null;
    }

    if ($centiDbm < WIFI_TXPOWER_MIN_CENTIDBM || $centiDbm > WIFI_TXPOWER_MAX_CENTIDBM) {
        return null;
    }
    return $centiDbm;
}

function readWifiTxPower(): array
{
    $iface = getWifiApInterface();
    $iw = findIwBinary();
    if ($iw === null) {
        return ['ok' => false, 'error' => 'iw_not_found', 'iface' => $iface];
    }

    $output = runCommand(escapeshellarg($iw) . ' dev ' . escapeshellarg($iface) . ' info', $code);
    if ($code !== 0 || trim($output) === '') {
        return ['ok' => false, 'error' => 'wifi_txpower_read_failed', 'iface' => $iface];
    }

    if (preg_match('/\btxpower\s+(\d+(?:\.\d+)?)\s+dBm\b/i', $output, $matches) !== 1) {
        return ['ok' => false, 'error' => 'wifi_txpower_parse_failed', 'iface' => $iface];
    }

    $centiDbm = normalizeWifiTxPowerCentiDbm($matches[1]);
    if ($centiDbm === null) {
        return ['ok' => false, 'error' => 'wifi_txpower_parse_failed', 'iface' => $iface];
    }

    return [
        'ok' => true,
        'iface' => $iface,
        'currentCentiDbm' => $centiDbm,
        'currentDbm' => centiDbmToDbmString($centiDbm),
        'defaultDbm' => centiDbmToDbmString(WIFI_TXPOWER_DEFAULT_CENTIDBM),
        'maxDbm' => centiDbmToDbmString(WIFI_TXPOWER_MAX_CENTIDBM),
    ];
}

function setWifiTxPower($value): array
{
    $iface = getWifiApInterface();
    $centiDbm = normalizeWifiTxPowerCentiDbm($value);
    if ($centiDbm === null) {
        return ['ok' => false, 'error' => 'invalid_wifi_txpower', 'iface' => $iface];
    }

    $iw = findIwBinary();
    if ($iw === null) {
        return ['ok' => false, 'error' => 'iw_not_found', 'iface' => $iface];
    }

    runCommand(
        escapeshellarg($iw) . ' dev ' . escapeshellarg($iface) . ' set txpower fixed ' . escapeshellarg((string)$centiDbm),
        $code
    );
    if ($code !== 0) {
        return ['ok' => false, 'error' => 'wifi_txpower_apply_failed', 'iface' => $iface];
    }
    if (!persistWifiTxPowerState($centiDbm)) {
        return ['ok' => false, 'error' => 'wifi_txpower_state_write_failed', 'iface' => $iface];
    }
    if (!ensureWifiTxPowerServiceInstalled()) {
        return ['ok' => false, 'error' => 'wifi_txpower_service_install_failed', 'iface' => $iface];
    }

    $state = readWifiTxPower();
    if (($state['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'wifi_txpower_verify_failed', 'iface' => $iface];
    }

    return $state + ['requestedDbm' => centiDbmToDbmString($centiDbm)];
}
