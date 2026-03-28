<?php

declare(strict_types=1);

function rootHelperValidateModule($module, array $modules, string $error = 'invalid_module'): string
{
    if (!is_string($module) || !in_array($module, $modules, true)) {
        fail($error);
    }
    return $module;
}

function rootHelperValidateSelectedModule($module, array $modules): string
{
    if (!is_string($module) || !in_array($module, $modules, true)) {
        fail('invalid_selected_module');
    }
    return $module;
}

function rootHelperNormalizeScheduleEntries($entries, array $modules): array
{
    if (!is_array($entries)) {
        fail('invalid_entries');
    }

    $normalized = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            fail('invalid_entry');
        }
        $module = $entry['module'] ?? null;
        $days = $entry['days'] ?? null;
        $start = $entry['start'] ?? null;
        $stop = $entry['stop'] ?? null;
        if (!is_string($module) || !in_array($module, $modules, true)) {
            fail('invalid_schedule_module');
        }
        if (!is_array($days)) {
            fail('invalid_days');
        }
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$start) !== 1) {
            fail('invalid_start');
        }
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$stop) !== 1) {
            fail('invalid_stop');
        }
        $normalized[] = [
            'module' => $module,
            'days' => normalizeDays($days),
            'start' => (string)$start,
            'stop' => (string)$stop,
            'powerPercent' => normalizeTrafficPercentValue($entry['powerPercent'] ?? null),
        ];
    }

    return $normalized;
}

function rootHelperTrafficLimitGet(array $modules): array
{
    $scheduleEntry = getActiveScheduleControlEntry($modules);
    if ($scheduleEntry !== null) {
        $scheduledPercent = (int)$scheduleEntry['powerPercent'];
        $state = getTrafficLimitState();
        if (($state['ok'] ?? false) !== true || normalizeTrafficPercentValue($state['percent'] ?? null) !== $scheduledPercent) {
            $state = setTrafficLimit($scheduledPercent);
        }
        return $state + [
            'scheduleLocked' => true,
            'schedulePercent' => $scheduledPercent,
            'scheduleModule' => (string)$scheduleEntry['module'],
        ];
    }

    return getTrafficLimitState() + ['scheduleLocked' => false];
}

function rootHelperTrafficLimitSet(array $request, array $modules): array
{
    $percent = (int)($request['percent'] ?? 0);
    $scheduleEntry = getActiveScheduleControlEntry($modules);
    if ($scheduleEntry !== null) {
        $scheduledPercent = (int)$scheduleEntry['powerPercent'];
        $state = getTrafficLimitState();
        if (($state['ok'] ?? false) !== true || normalizeTrafficPercentValue($state['percent'] ?? null) !== $scheduledPercent) {
            $state = setTrafficLimit($scheduledPercent);
        }
        return [
            'ok' => false,
            'error' => 'schedule_power_locked',
            'scheduleLocked' => true,
            'schedulePercent' => $scheduledPercent,
            'scheduleModule' => (string)$scheduleEntry['module'],
            'currentPercent' => (int)($state['percent'] ?? $scheduledPercent),
            'currentMbit' => (int)($state['mbit'] ?? trafficLimitPercentToMbit($scheduledPercent)),
        ];
    }

    return setTrafficLimit($percent);
}

function dispatchRootHelperAction(string $action, array $request, array $modules): array
{
    switch ($action) {
        case 'autostart_get':
            return getAutostart($modules);
        case 'autostart_set':
            $selected = $request['selected'] ?? null;
            if ($selected !== null && (!is_string($selected) || !in_array($selected, $modules, true))) {
                fail('invalid_selected_module');
            }
            return setAutostart($modules, $selected);
        case 'schedule_get':
            return getSchedule($modules);
        case 'schedule_set':
            return setScheduleEntries($modules, rootHelperNormalizeScheduleEntries($request['entries'] ?? [], $modules));
        case 'schedule_activate':
            $module = rootHelperValidateModule($request['module'] ?? null, $modules);
            $percent = normalizeTrafficPercentValue($request['percent'] ?? null);
            if ($percent === null) {
                fail('invalid_traffic_limit_percent');
            }
            if (hasScheduleManualOverride()) {
                return ['ok' => true, 'skipped' => true, 'manualOverride' => true];
            }
            return applyExclusiveModuleState($modules, $module, $percent);
        case 'schedule_deactivate':
            $module = rootHelperValidateModule($request['module'] ?? null, $modules);
            if (hasScheduleManualOverride()) {
                return ['ok' => true, 'skipped' => true, 'manualOverride' => true];
            }
            if (serviceIsActive($module)) {
                return applyExclusiveModuleState($modules, null);
            }
            return ['ok' => true, 'stopped' => false];
        case 'schedule_boot_sync':
            return scheduleBootSync($modules);
        case 'service_activate_exclusive':
            return serviceActivateExclusive($modules, rootHelperValidateSelectedModule($request['selected'] ?? null, $modules));
        case 'service_stop':
            return serviceStop($modules, rootHelperValidateModule($request['module'] ?? null, $modules));
        case 'service_restart':
            return serviceRestart(rootHelperValidateModule($request['module'] ?? null, $modules));
        case 'system_reboot':
            return systemReboot();
        case 'system_update_run':
            return runSystemUpdate(isset($request['branch']) && is_string($request['branch']) ? $request['branch'] : null);
        case 'webui_reset_defaults':
            return rootHelperResetWebuiDefaults($modules);
        case 'traffic_limit_get':
            return rootHelperTrafficLimitGet($modules);
        case 'traffic_limit_set':
            return rootHelperTrafficLimitSet($request, $modules);
        case 'vnstat_status':
            return getVnstatStatus();
        case 'vnstat_install':
            return installVnstat();
        case 'time_sync_status':
            return getTimeSyncStatus();
        case 'time_sync_ensure':
            $timezone = $request['timezone'] ?? 'Europe/Kyiv';
            if (!is_string($timezone) || trim($timezone) === '') {
                fail('invalid_timezone');
            }
            return ensureTimeSyncForTimezone(trim($timezone));
        case 'wifi_txpower_get':
            return readWifiTxPower();
        case 'wifi_txpower_set':
            return setWifiTxPower($request['dbm'] ?? null);
        case 'wifi_ap_name_get':
            return readWifiApName();
        case 'wifi_ap_name_set':
            return setWifiApName($request['ssid'] ?? null);
        case 'distress_autotune_get':
            return getDistressAutotuneStatus();
        case 'distress_autotune_set':
            return setDistressAutotuneMode($request['enabled'] ?? null, $request['concurrency'] ?? null);
        case 'distress_autotune_tick':
            return distressAutotuneTick($request['loadAverage'] ?? null, $request['ramFreePercent'] ?? null);
        case 'service_logs':
            $lines = (int)($request['lines'] ?? 80);
            $module = rootHelperValidateModule($request['module'] ?? null, $modules);
            return [
                'ok' => true,
                'logs' => getServiceLogsRaw($module, $lines),
            ];
        case 'status_snapshot':
            return statusSnapshot($modules, (int)($request['lines'] ?? 80));
        case 'service_info':
            return serviceInfo(rootHelperValidateModule($request['module'] ?? null, $modules));
        case 'service_execstart_get':
            $execStart = readServiceExecStart(rootHelperValidateModule($request['module'] ?? null, $modules));
            if ($execStart === null) {
                return ['ok' => false, 'error' => 'execstart_read_failed'];
            }
            return ['ok' => true, 'execStart' => $execStart];
        case 'service_execstart_set':
            $execStart = $request['execStart'] ?? null;
            if (!is_string($execStart) || trim($execStart) === '') {
                fail('invalid_execstart');
            }
            return ['ok' => updateServiceExecStart(
                rootHelperValidateModule($request['module'] ?? null, $modules),
                trim($execStart)
            )];
        case 'x100_config_get':
            $content = getX100Config();
            if ($content === null) {
                return ['ok' => false, 'error' => 'x100_config_read_failed'];
            }
            return ['ok' => true, 'content' => $content];
        case 'x100_config_set':
            $content = $request['content'] ?? null;
            if (!is_string($content)) {
                fail('invalid_content');
            }
            return ['ok' => setX100Config($content)];
        default:
            fail('unknown_action');
    }
}
