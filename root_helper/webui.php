<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/version.php';

const ROOT_HELPER_WEBUI_DEFAULT_TRAFFIC_PERCENT = 31;
const ROOT_HELPER_WEBUI_DEFAULT_TIMEZONE = 'Europe/Kyiv';
const ROOT_HELPER_WEBUI_DEFAULT_WIFI_SSID = 'Artline';
const ROOT_HELPER_WEBUI_DEFAULT_WIFI_DBM = '1.00';

function rootHelperExecStartUpdateOptions(string $execStartLine, array $updatedParams, string $daemonName): ?string
{
    $configAsArray = str_getcsv($execStartLine, ' ');
    if (!is_array($configAsArray) || $configAsArray === []) {
        return null;
    }

    $aliases = [
        'ifaces' => ['bind'],
        'bind' => ['ifaces'],
        'disable-udp-flood' => ['direct-udp-failover'],
        'direct-udp-failover' => ['disable-udp-flood'],
    ];
    $flagOnlyByDaemon = [
        'distress' => ['enable-icmp-flood', 'enable-packet-flood', 'disable-udp-flood'],
    ];
    $flagOnly = array_flip($flagOnlyByDaemon[$daemonName] ?? []);

    $baseTokens = [];
    $options = [];
    foreach ($configAsArray as $idx => $token) {
        if ($idx === 0) {
            $baseTokens[] = $token;
            continue;
        }
        if (!is_string($token) || !str_starts_with($token, '--')) {
            continue;
        }
        $key = substr($token, 2);
        $next = $configAsArray[$idx + 1] ?? null;
        if (is_string($next) && !str_starts_with($next, '--')) {
            $options[$key] = $next;
        } else {
            $options[$key] = true;
        }
    }

    if ($daemonName === 'distress') {
        unset($options['distress-concurrency-mode']);
    }

    foreach ($updatedParams as $updatedParamKey => $updatedParam) {
        if ($updatedParamKey === 'distress-concurrency-mode') {
            continue;
        }

        $updatedValue = trim((string)$updatedParam);
        $allKeys = array_merge([$updatedParamKey], $aliases[$updatedParamKey] ?? []);
        foreach ($allKeys as $optionKey) {
            unset($options[$optionKey]);
        }

        $isFlagOnly = isset($flagOnly[$updatedParamKey]);
        if ($updatedValue === '' || $updatedValue === '0') {
            continue;
        }

        $options[$updatedParamKey] = $isFlagOnly ? true : $updatedValue;
    }

    if (in_array($daemonName, ['mhddos', 'distress'], true)) {
        $options['source'] = 'itarmybox';
    }

    $out = $baseTokens;
    foreach ($options as $key => $value) {
        $out[] = '--' . $key;
        if ($value !== true) {
            $out[] = (string)$value;
        }
    }

    return implode(' ', $out);
}

function rootHelperWriteServiceDefaults(string $module, array $params, string $daemonName): bool
{
    $currentExecStart = readServiceExecStart($module);
    if (!is_string($currentExecStart) || $currentExecStart === '') {
        return false;
    }

    $updatedExecStart = rootHelperExecStartUpdateOptions($currentExecStart, $params, $daemonName);
    if (!is_string($updatedExecStart) || trim($updatedExecStart) === '') {
        return false;
    }

    return updateServiceExecStart($module, $updatedExecStart);
}

function rootHelperResetMhddosDefaults(): bool
{
    return rootHelperWriteServiceDefaults('mhddos', [
        'user-id' => '',
        'lang' => '',
        'copies' => 'auto',
        'use-my-ip' => '',
        'threads' => '6500',
        'proxies' => '',
    ], 'mhddos');
}

function rootHelperResetDistressDefaults(): bool
{
    if (!rootHelperWriteServiceDefaults('distress', [
        'user-id' => '',
        'use-my-ip' => '',
        'use-tor' => '',
        'concurrency' => (string)DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
        'enable-icmp-flood' => '',
        'enable-packet-flood' => '',
        'disable-udp-flood' => '',
        'udp-packet-size' => '',
        'direct-udp-mixed-flood-packets-per-conn' => '',
        'proxies-path' => '',
    ], 'distress')) {
        return false;
    }

    $result = setDistressAutotuneMode(true, DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY);
    return ($result['ok'] ?? false) === true;
}

function rootHelperSetX100ConfigValues(array $updatedConfig): bool
{
    $allowed = ['itArmyUserId', 'initialDistressScale', 'ignoreBundledFreeVpn'];
    $content = getX100Config();
    if (!is_string($content)) {
        return false;
    }

    foreach ($allowed as $key) {
        if (!array_key_exists($key, $updatedConfig)) {
            continue;
        }

        $value = trim((string)$updatedConfig[$key]);
        if ($key === 'ignoreBundledFreeVpn' && $value === '') {
            $value = '0';
        }

        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
        if (preg_match($pattern, $content)) {
            $content = (string)preg_replace($pattern, $key . '=' . $value, $content, 1);
        } else {
            $content .= PHP_EOL . $key . '=' . $value;
        }
    }

    return setX100Config($content);
}

function rootHelperResetX100Defaults(): bool
{
    return rootHelperSetX100ConfigValues([
        'initialDistressScale' => '',
        'ignoreBundledFreeVpn' => '0',
    ]);
}

function rootHelperSetWifiApNameWithRetry(string $ssid): array
{
    $response = setWifiApName($ssid);
    if (($response['ok'] ?? false) !== true && (($response['error'] ?? '') === 'root_helper_reloaded_retry')) {
        $response = setWifiApName($ssid);
    }
    return $response;
}

function rootHelperResetWebuiDefaults(array $modules): array
{
    $previousActiveModule = getActiveModule($modules);
    if ($previousActiveModule !== null) {
        $stopResult = applyExclusiveModuleState($modules, null);
        if (($stopResult['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => 'reset_active_stop_failed'];
        }
    }

    if (!rootHelperResetMhddosDefaults()) {
        return ['ok' => false, 'error' => 'reset_mhddos_failed'];
    }
    if (!rootHelperResetDistressDefaults()) {
        return ['ok' => false, 'error' => 'reset_distress_failed'];
    }
    if (!rootHelperResetX100Defaults()) {
        return ['ok' => false, 'error' => 'reset_x100_failed'];
    }

    $autostart = setAutostart($modules, null);
    if (($autostart['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'reset_autostart_failed'];
    }

    $schedule = setScheduleEntries($modules, []);
    if (($schedule['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'reset_schedule_failed'];
    }

    $traffic = setTrafficLimit(ROOT_HELPER_WEBUI_DEFAULT_TRAFFIC_PERCENT);
    if (($traffic['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'reset_traffic_failed'];
    }

    $wifiName = rootHelperSetWifiApNameWithRetry(ROOT_HELPER_WEBUI_DEFAULT_WIFI_SSID);
    if (($wifiName['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'reset_wifi_name_failed'];
    }

    $wifiPower = setWifiTxPower(ROOT_HELPER_WEBUI_DEFAULT_WIFI_DBM);
    if (($wifiPower['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'reset_wifi_power_failed'];
    }

    $timeSync = ensureTimeSyncForTimezone(ROOT_HELPER_WEBUI_DEFAULT_TIMEZONE);
    if (($timeSync['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'reset_time_sync_failed'];
    }

    if (!webui_set_selected_branch(WEBUI_DEFAULT_BRANCH)) {
        return ['ok' => false, 'error' => 'reset_update_branch_failed'];
    }

    return [
        'ok' => true,
        'stoppedActiveModule' => $previousActiveModule,
        'activeModule' => getActiveModule($modules),
    ];
}
