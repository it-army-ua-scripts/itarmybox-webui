<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/version.php';

const ROOT_HELPER_WEBUI_DEFAULT_TRAFFIC_PERCENT = 31;
const ROOT_HELPER_WEBUI_DEFAULT_TIMEZONE = 'Europe/Kyiv';
const ROOT_HELPER_WEBUI_DEFAULT_WIFI_SSID = 'Artline';
const ROOT_HELPER_WEBUI_DEFAULT_WIFI_DBM = '1.00';
const ROOT_HELPER_X100_CONFIG_PATH = '/opt/itarmy/x100-for-docker/put-your-ovpn-files-here/x100-config.txt';

function rootHelperResetAddLog(array &$log, string $phase, string $step, bool $ok, string $details = ''): void
{
    $entry = [
        'phase' => $phase,
        'step' => $step,
        'ok' => $ok,
    ];
    if ($details !== '') {
        $entry['details'] = $details;
    }
    $log[] = $entry;
}

function rootHelperResetResultOk($result): bool
{
    if (is_bool($result)) {
        return $result;
    }

    return is_array($result) && (($result['ok'] ?? false) === true);
}

function rootHelperResetResultError($result): string
{
    if (is_array($result) && isset($result['error']) && is_string($result['error'])) {
        return $result['error'];
    }

    return '';
}

function rootHelperResetResultDetails($result, string $fallback = ''): string
{
    $error = rootHelperResetResultError($result);
    return $error !== '' ? $error : $fallback;
}

function rootHelperReadX100ConfigSnapshot(): array
{
    $content = getX100Config();
    return [
        'exists' => is_string($content),
        'content' => is_string($content) ? $content : '',
    ];
}

function rootHelperRestoreX100ConfigSnapshot(array $snapshot): bool
{
    $exists = ($snapshot['exists'] ?? false) === true;
    if ($exists) {
        return setX100Config((string)($snapshot['content'] ?? ''));
    }

    if (is_file(ROOT_HELPER_X100_CONFIG_PATH)) {
        return @unlink(ROOT_HELPER_X100_CONFIG_PATH);
    }

    return true;
}

function rootHelperRestoreAutostartModules(array $modules, array $enabledModules): array
{
    foreach ($modules as $module) {
        removeAutostartLinks($module);
    }

    foreach ($enabledModules as $module) {
        if (!is_string($module) || !in_array($module, $modules, true)) {
            continue;
        }

        $service = escapeshellarg($module . '.service');
        runCommand("systemctl add-wants multi-user.target $service", $code);
        if ($code !== 0) {
            return ['ok' => false, 'error' => 'add_wants_failed'];
        }
    }

    runCommand('systemctl daemon-reload', $reloadCode);

    $currentEnabled = getEnabledAutostartModules($modules);
    if (!sameModuleSet($currentEnabled, $enabledModules)) {
        return ['ok' => false, 'error' => 'autostart_verification_failed'];
    }

    return ['ok' => true];
}

function rootHelperSetNtpEnabled(bool $enabled): bool
{
    $timedatectl = findTimedatectl();
    if ($timedatectl === null) {
        return false;
    }

    runCommand(
        escapeshellarg($timedatectl) . ' set-ntp ' . ($enabled ? 'true' : 'false'),
        $ntpCode
    );

    return $ntpCode === 0;
}

function rootHelperRestoreTimeSyncSnapshot(array $snapshot): array
{
    $timezone = isset($snapshot['timezone']) && is_string($snapshot['timezone'])
        ? trim($snapshot['timezone'])
        : '';
    $ntpEnabled = $snapshot['ntpEnabled'] ?? null;

    if ($timezone !== '') {
        $timedatectl = findTimedatectl();
        if ($timedatectl === null) {
            return ['ok' => false, 'error' => 'timedatectl_not_found'];
        }
        runCommand(escapeshellarg($timedatectl) . ' set-timezone ' . escapeshellarg($timezone), $timezoneCode);
        if ($timezoneCode !== 0) {
            return ['ok' => false, 'error' => 'set_timezone_failed'];
        }
    }

    if (is_bool($ntpEnabled) && !rootHelperSetNtpEnabled($ntpEnabled)) {
        return ['ok' => false, 'error' => 'set_ntp_failed'];
    }

    return getTimeSyncStatus();
}

function rootHelperExecuteResetRollback(array $rollbackStack, array &$log): array
{
    if ($rollbackStack === []) {
        return [
            'attempted' => false,
            'ok' => true,
            'completed' => 0,
            'failed' => 0,
        ];
    }

    $completed = 0;
    $failed = 0;
    foreach (array_reverse($rollbackStack) as $rollbackEntry) {
        $step = (string)($rollbackEntry['step'] ?? 'rollback_step');
        $handler = $rollbackEntry['handler'] ?? null;
        if (!is_callable($handler)) {
            $failed++;
            rootHelperResetAddLog($log, 'rollback', $step, false, 'handler_missing');
            continue;
        }

        $result = $handler();
        $ok = rootHelperResetResultOk($result);
        if ($ok) {
            $completed++;
        } else {
            $failed++;
        }

        rootHelperResetAddLog(
            $log,
            'rollback',
            $step,
            $ok,
            rootHelperResetResultDetails($result)
        );
    }

    return [
        'attempted' => true,
        'ok' => $failed === 0,
        'completed' => $completed,
        'failed' => $failed,
    ];
}

function rootHelperResetFailure(string $error, array &$log, array $rollbackStack): array
{
    return [
        'ok' => false,
        'error' => $error,
        'steps' => $log,
        'rollback' => rootHelperExecuteResetRollback($rollbackStack, $log),
    ];
}

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
        'concurrency' => (string)DISTRESS_AUTOTUNE_MANUAL_DEFAULT_CONCURRENCY,
        'enable-icmp-flood' => '',
        'enable-packet-flood' => '',
        'disable-udp-flood' => '',
        'udp-packet-size' => '',
        'direct-udp-mixed-flood-packets-per-conn' => '',
        'proxies-path' => '',
    ], 'distress')) {
        return false;
    }

    $result = setDistressAutotuneMode(false, DISTRESS_AUTOTUNE_MANUAL_DEFAULT_CONCURRENCY);
    return ($result['ok'] ?? false) === true;
}

function rootHelperSetX100ConfigValues(array $updatedConfig): bool
{
    $allowed = ['itArmyUserId', 'initialDistressScale', 'ignoreBundledFreeVpn'];
    $content = getX100Config();
    if (!is_string($content)) {
        $content = '';
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
        'itArmyUserId' => '',
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
    $previousActiveModules = getActiveModules($modules);
    $previousMhddosExecStart = readServiceExecStart('mhddos');
    $previousDistressExecStart = readServiceExecStart('distress');
    $previousDistressAutotune = getDistressAutotuneStatus();
    $previousX100Config = rootHelperReadX100ConfigSnapshot();
    $previousAutostartModules = getEnabledAutostartModules($modules);
    $previousSchedule = getSchedule($modules);
    $previousTrafficState = getTrafficLimitRollbackSnapshot();
    $previousWifiName = readWifiApName();
    $previousWifiPower = readWifiTxPower();
    $previousTimeSync = getTimeSyncStatus();
    $previousBranch = webui_selected_branch();
    $steps = [];
    $rollbackStack = [];

    if ($previousActiveModules !== []) {
        $stopResult = applyExclusiveModuleState($modules, null);
        if (($stopResult['ok'] ?? false) !== true) {
            rootHelperResetAddLog($steps, 'apply', 'stop_active_modules', false, rootHelperResetResultDetails($stopResult));
            return rootHelperResetFailure('reset_active_stop_failed', $steps, $rollbackStack);
        }
        rootHelperResetAddLog($steps, 'apply', 'stop_active_modules', true, implode(', ', $previousActiveModules));
        $rollbackStack[] = [
            'step' => 'restore_active_modules',
            'handler' => static function () use ($modules, $previousActiveModules): array {
                return restoreModuleStateSet($modules, $previousActiveModules);
            },
        ];
    } else {
        rootHelperResetAddLog($steps, 'apply', 'stop_active_modules', true, 'none_active');
    }

    if (!rootHelperResetMhddosDefaults()) {
        rootHelperResetAddLog($steps, 'apply', 'reset_mhddos', false);
        return rootHelperResetFailure('reset_mhddos_failed', $steps, $rollbackStack);
    }
    rootHelperResetAddLog($steps, 'apply', 'reset_mhddos', true);
    if (is_string($previousMhddosExecStart) && $previousMhddosExecStart !== '') {
        $rollbackStack[] = [
            'step' => 'restore_mhddos',
            'handler' => static function () use ($previousMhddosExecStart): bool {
                return updateServiceExecStart('mhddos', $previousMhddosExecStart);
            },
        ];
    }

    if (!rootHelperResetDistressDefaults()) {
        rootHelperResetAddLog($steps, 'apply', 'reset_distress', false);
        return rootHelperResetFailure('reset_distress_failed', $steps, $rollbackStack);
    }
    rootHelperResetAddLog($steps, 'apply', 'reset_distress', true);
    if (
        is_string($previousDistressExecStart) && $previousDistressExecStart !== '' &&
        (($previousDistressAutotune['ok'] ?? false) === true)
    ) {
        $rollbackStack[] = [
            'step' => 'restore_distress',
            'handler' => static function () use ($previousDistressExecStart, $previousDistressAutotune): array {
                return saveDistressSettings(
                    $previousDistressExecStart,
                    (($previousDistressAutotune['enabled'] ?? false) === true),
                    (int)($previousDistressAutotune['desiredConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY)
                );
            },
        ];
    }

    if (!rootHelperResetX100Defaults()) {
        rootHelperResetAddLog($steps, 'apply', 'reset_x100', false);
        return rootHelperResetFailure('reset_x100_failed', $steps, $rollbackStack);
    }
    rootHelperResetAddLog($steps, 'apply', 'reset_x100', true);
    $rollbackStack[] = [
        'step' => 'restore_x100',
        'handler' => static function () use ($previousX100Config): bool {
            return rootHelperRestoreX100ConfigSnapshot($previousX100Config);
        },
    ];

    $autostart = setAutostart($modules, null);
    if (($autostart['ok'] ?? false) !== true) {
        rootHelperResetAddLog($steps, 'apply', 'clear_autostart', false, rootHelperResetResultDetails($autostart));
        return rootHelperResetFailure('reset_autostart_failed', $steps, $rollbackStack);
    }
    rootHelperResetAddLog($steps, 'apply', 'clear_autostart', true);
    $rollbackStack[] = [
        'step' => 'restore_autostart',
        'handler' => static function () use ($modules, $previousAutostartModules): array {
            return rootHelperRestoreAutostartModules($modules, $previousAutostartModules);
        },
    ];

    $schedule = setScheduleEntries($modules, []);
    if (($schedule['ok'] ?? false) !== true) {
        rootHelperResetAddLog($steps, 'apply', 'clear_schedule', false, rootHelperResetResultDetails($schedule));
        return rootHelperResetFailure('reset_schedule_failed', $steps, $rollbackStack);
    }
    rootHelperResetAddLog($steps, 'apply', 'clear_schedule', true);
    if (($previousSchedule['ok'] ?? false) === true) {
        $rollbackStack[] = [
            'step' => 'restore_schedule',
            'handler' => static function () use ($modules, $previousSchedule): array {
                return setScheduleEntries($modules, (array)($previousSchedule['entries'] ?? []));
            },
        ];
    }

    $traffic = setTrafficLimit(ROOT_HELPER_WEBUI_DEFAULT_TRAFFIC_PERCENT);
    if (($traffic['ok'] ?? false) !== true) {
        rootHelperResetAddLog($steps, 'apply', 'reset_traffic_limit', false, rootHelperResetResultDetails($traffic));
        return rootHelperResetFailure('reset_traffic_failed', $steps, $rollbackStack);
    }
    rootHelperResetAddLog($steps, 'apply', 'reset_traffic_limit', true, (string)ROOT_HELPER_WEBUI_DEFAULT_TRAFFIC_PERCENT . '%');
    if ($previousTrafficState !== null) {
        $rollbackStack[] = [
            'step' => 'restore_traffic_limit',
            'handler' => static function () use ($previousTrafficState): array {
                return setTrafficLimit((int)$previousTrafficState['percent']);
            },
        ];
    }

    $wifiName = rootHelperSetWifiApNameWithRetry(ROOT_HELPER_WEBUI_DEFAULT_WIFI_SSID);
    if (($wifiName['ok'] ?? false) !== true) {
        rootHelperResetAddLog($steps, 'apply', 'reset_wifi_name', false, rootHelperResetResultDetails($wifiName));
        return rootHelperResetFailure('reset_wifi_name_failed', $steps, $rollbackStack);
    }
    rootHelperResetAddLog($steps, 'apply', 'reset_wifi_name', true, ROOT_HELPER_WEBUI_DEFAULT_WIFI_SSID);
    if (($previousWifiName['ok'] ?? false) === true && isset($previousWifiName['ssid']) && is_string($previousWifiName['ssid'])) {
        $rollbackStack[] = [
            'step' => 'restore_wifi_name',
            'handler' => static function () use ($previousWifiName): array {
                return rootHelperSetWifiApNameWithRetry((string)$previousWifiName['ssid']);
            },
        ];
    }

    $wifiPower = setWifiTxPower(ROOT_HELPER_WEBUI_DEFAULT_WIFI_DBM);
    if (($wifiPower['ok'] ?? false) !== true) {
        rootHelperResetAddLog($steps, 'apply', 'reset_wifi_power', false, rootHelperResetResultDetails($wifiPower));
        return rootHelperResetFailure('reset_wifi_power_failed', $steps, $rollbackStack);
    }
    rootHelperResetAddLog($steps, 'apply', 'reset_wifi_power', true, ROOT_HELPER_WEBUI_DEFAULT_WIFI_DBM . ' dBm');
    if (($previousWifiPower['ok'] ?? false) === true && isset($previousWifiPower['currentDbm']) && is_string($previousWifiPower['currentDbm'])) {
        $rollbackStack[] = [
            'step' => 'restore_wifi_power',
            'handler' => static function () use ($previousWifiPower): array {
                return setWifiTxPower((string)$previousWifiPower['currentDbm']);
            },
        ];
    }

    $timeSync = ensureTimeSyncForTimezone(ROOT_HELPER_WEBUI_DEFAULT_TIMEZONE);
    if (($timeSync['ok'] ?? false) !== true) {
        rootHelperResetAddLog($steps, 'apply', 'reset_time_sync', false, rootHelperResetResultDetails($timeSync));
        return rootHelperResetFailure('reset_time_sync_failed', $steps, $rollbackStack);
    }
    rootHelperResetAddLog($steps, 'apply', 'reset_time_sync', true, ROOT_HELPER_WEBUI_DEFAULT_TIMEZONE);
    if (($previousTimeSync['ok'] ?? false) === true) {
        $rollbackStack[] = [
            'step' => 'restore_time_sync',
            'handler' => static function () use ($previousTimeSync): array {
                return rootHelperRestoreTimeSyncSnapshot($previousTimeSync);
            },
        ];
    }

    if (!webui_set_selected_branch(WEBUI_DEFAULT_BRANCH)) {
        rootHelperResetAddLog($steps, 'apply', 'reset_update_branch', false);
        return rootHelperResetFailure('reset_update_branch_failed', $steps, $rollbackStack);
    }
    rootHelperResetAddLog($steps, 'apply', 'reset_update_branch', true, WEBUI_DEFAULT_BRANCH);
    $rollbackStack[] = [
        'step' => 'restore_update_branch',
        'handler' => static function () use ($previousBranch): bool {
            return webui_set_selected_branch($previousBranch);
        },
    ];

    return [
        'ok' => true,
        'steps' => $steps,
        'stoppedActiveModule' => count($previousActiveModules) === 1 ? $previousActiveModules[0] : null,
        'stoppedActiveModules' => $previousActiveModules,
        'activeModule' => getActiveModule($modules),
        'activeModules' => getActiveModules($modules),
    ];
}
