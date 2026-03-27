<?php

declare(strict_types=1);

const SCHEDULE_BEGIN_MARKER = '# ITARMYBOX-SCHEDULE-BEGIN';
const SCHEDULE_END_MARKER = '# ITARMYBOX-SCHEDULE-END';
const MAX_SCHEDULE_ENTRIES = 2;
const SCHEDULE_MANUAL_OVERRIDE_FILE = '/tmp/itarmybox-schedule-manual-override';
const TRAFFIC_LIMIT_STATE_FILE = '/tmp/itarmybox-traffic-limit.json';
const ROOT_HELPER_SCRIPT_PATH = '/var/www/html/itarmybox-webui/root_helper.php';
const WIFI_TXPOWER_MIN_CENTIDBM = 100;
const WIFI_TXPOWER_MAX_CENTIDBM = 3100;
const WIFI_TXPOWER_DEFAULT_CENTIDBM = 100;
const WIFI_TXPOWER_STATE_FILE = '/opt/itarmy/wifi-txpower.json';
const WIFI_TXPOWER_SERVICE_PATH = '/var/www/html/itarmybox-webui/systemd/itarmybox-wifi-txpower.service';
const WIFI_AP_INTERFACE = 'wlan0';
const WIFI_AP_DEFAULT_NAME = 'Artline';
const HOSTAPD_CONFIG_PATH = '/etc/hostapd/hostapd.conf';
const HOSTAPD_SERVICE_NAME = 'hostapd.service';
const ROOT_HELPER_INSTALL_SCRIPT = '/var/www/html/itarmybox-webui/systemd/install-root-helper.sh';
const VNSTAT_INTERFACE = 'eth0';
const UPDATE_SCRIPT_PATH = '/var/www/html/itarmybox-webui/update.sh';

require_once __DIR__ . '/root_helper/vnstat.php';
require_once __DIR__ . '/root_helper/time_sync.php';
require_once __DIR__ . '/root_helper/wifi.php';
require_once __DIR__ . '/root_helper/traffic_limit.php';
require_once __DIR__ . '/root_helper/distress_autotune.php';
require_once __DIR__ . '/root_helper/system.php';
require_once __DIR__ . '/root_helper/webui.php';
require_once __DIR__ . '/root_helper/dispatch.php';

function respond(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function fail(string $message): void
{
    respond(['ok' => false, 'error' => $message]);
    exit(0);
}

function runCommand(string $command, ?int &$exitCode = null): string
{
    $output = [];
    $code = 1;
    exec($command . ' 2>/dev/null', $output, $code);
    $exitCode = $code;
    return implode("\n", $output);
}

function runCommandVerbose(string $command, ?int &$exitCode = null): string
{
    $output = [];
    $code = 1;
    exec($command . ' 2>&1', $output, $code);
    $exitCode = $code;
    return implode("\n", $output);
}

function findTcBinary(): ?string
{
    foreach (['/usr/sbin/tc', '/sbin/tc'] as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }
    return null;
}

function findExecutable(array $paths): ?string
{
    foreach ($paths as $path) {
        if (is_string($path) && $path !== '' && is_executable($path)) {
            return $path;
        }
    }
    return null;
}

function findServiceBinary(): ?string
{
    return findExecutable(['/usr/sbin/service', '/usr/bin/service', '/sbin/service', '/bin/service']);
}

function repairRootHelperAccess(): bool
{
    if (!is_file(ROOT_HELPER_INSTALL_SCRIPT)) {
        return false;
    }

    $output = runCommandVerbose('/usr/bin/env bash ' . escapeshellarg(ROOT_HELPER_INSTALL_SCRIPT), $code);
    return $code === 0;
}

function isSystemdUnitKnown(string $unitName): bool
{
    $systemctl = findSystemctl();
    if ($systemctl === null || trim($unitName) === '') {
        return false;
    }

    runCommand(
        escapeshellarg($systemctl) . ' status ' . escapeshellarg($unitName) . ' --no-pager',
        $code
    );

    return $code === 0 || $code === 3;
}

function getExecStartOptionValue(string $execStartLine, string $option): ?string
{
    $pattern = '/(?:^|\s)--' . preg_quote($option, '/') . '\s+((?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s]+))/';
    if (preg_match($pattern, $execStartLine, $matches) !== 1) {
        return null;
    }

    $value = trim((string)($matches[1] ?? ''));
    $length = strlen($value);
    if ($length >= 2) {
        $first = $value[0];
        $last = $value[$length - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }
    }

    return $value;
}

function appendExecStartOption(string $execStartLine, string $option, string $value): string
{
    $trimmed = rtrim($execStartLine);
    if ($trimmed === '') {
        return $execStartLine;
    }
    return $trimmed . ' --' . $option . ' ' . $value;
}

function replaceExecStartOptionValue(string $execStartLine, string $option, string $value): string
{
    $pattern = '/(^|\s)--' . preg_quote($option, '/') . '\s+((?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s]+))/';
    if (preg_match($pattern, $execStartLine) !== 1) {
        return appendExecStartOption($execStartLine, $option, $value);
    }

    return (string)preg_replace($pattern, '$1--' . $option . ' ' . $value, $execStartLine, 1);
}

function isValidModule(string $module): bool
{
    return preg_match('/^[a-zA-Z0-9_-]+$/', $module) === 1;
}

function parseTimeParts(string $hhmm): array
{
    [$h, $m] = explode(':', $hhmm, 2);
    return [(int)$h, (int)$m];
}

function timeToMinutes(string $hhmm): int
{
    [$h, $m] = parseTimeParts($hhmm);
    return ($h * 60) + $m;
}

function stripScheduleBlock(string $crontab): string
{
    $lines = preg_split('/\r\n|\r|\n/', $crontab);
    $clean = [];
    $inside = false;
    foreach ($lines as $line) {
        if ($line === SCHEDULE_BEGIN_MARKER) {
            $inside = true;
            continue;
        }
        if ($line === SCHEDULE_END_MARKER) {
            $inside = false;
            continue;
        }
        if (!$inside && trim($line) !== '') {
            $clean[] = $line;
        }
    }
    return implode("\n", $clean);
}

function normalizeDays(array $days): array
{
    $set = [];
    foreach ($days as $day) {
        if (preg_match('/^[0-6]$/', (string)$day) === 1) {
            $set[(int)$day] = true;
        }
    }
    $values = array_keys($set);
    sort($values);
    return $values;
}

function expandScheduleEntrySegments(array $entry): array
{
    $days = normalizeDays((array)($entry['days'] ?? []));
    $start = (string)($entry['start'] ?? '');
    $stop = (string)($entry['stop'] ?? '');
    if (
        $days === [] ||
        preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) !== 1 ||
        preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $stop) !== 1 ||
        $start === $stop
    ) {
        return [];
    }

    $startMinutes = timeToMinutes($start);
    $stopMinutes = timeToMinutes($stop);
    $segments = [];
    foreach ($days as $day) {
        if ($startMinutes < $stopMinutes) {
            $segments[] = ['day' => $day, 'start' => $startMinutes, 'stop' => $stopMinutes];
            continue;
        }

        $segments[] = ['day' => $day, 'start' => $startMinutes, 'stop' => 1440];
        $segments[] = ['day' => (($day + 1) % 7), 'start' => 0, 'stop' => $stopMinutes];
    }
    return $segments;
}

function scheduleEntriesOverlap(array $entries): bool
{
    $segmentsByDay = [];
    foreach ($entries as $entry) {
        foreach (expandScheduleEntrySegments($entry) as $segment) {
            $segmentsByDay[(int)$segment['day']][] = $segment;
        }
    }

    foreach ($segmentsByDay as $segments) {
        usort($segments, static function (array $a, array $b): int {
            return ($a['start'] <=> $b['start']) ?: ($a['stop'] <=> $b['stop']);
        });

        $previousStop = null;
        foreach ($segments as $segment) {
            if ($previousStop !== null && (int)$segment['start'] < $previousStop) {
                return true;
            }
            $previousStop = max($previousStop ?? 0, (int)$segment['stop']);
        }
    }

    return false;
}

function parseDowField(string $dow): ?array
{
    if ($dow === '*') {
        return [0, 1, 2, 3, 4, 5, 6];
    }
    if (preg_match('/^[0-6](?:,[0-6])*$/', $dow) !== 1) {
        return null;
    }
    return normalizeDays(explode(',', $dow));
}

function shiftDays(array $days, int $delta): array
{
    $shifted = [];
    foreach (normalizeDays($days) as $day) {
        $shifted[] = (($day + $delta) % 7 + 7) % 7;
    }
    return normalizeDays($shifted);
}

function getNextScheduleBoundaryTimestamp(array $entries, ?DateTimeImmutable $now = null): ?int
{
    $now = $now ?? new DateTimeImmutable('now');
    $nextTs = null;

    for ($offset = 0; $offset <= 8; $offset++) {
        $day = $now->modify('+' . $offset . ' day');
        $weekday = (int)$day->format('w');

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $days = normalizeDays((array)($entry['days'] ?? []));
            $start = (string)($entry['start'] ?? '');
            $stop = (string)($entry['stop'] ?? '');
            if (
                $days === [] ||
                preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) !== 1 ||
                preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $stop) !== 1
            ) {
                continue;
            }

            $boundarySpecs = [];
            if ($start < $stop) {
                if (in_array($weekday, $days, true)) {
                    $boundarySpecs[] = $start;
                    $boundarySpecs[] = $stop;
                }
            } else {
                if (in_array($weekday, $days, true)) {
                    $boundarySpecs[] = $start;
                }
                if (in_array($weekday, shiftDays($days, 1), true)) {
                    $boundarySpecs[] = $stop;
                }
            }

            foreach ($boundarySpecs as $hhmm) {
                [$hour, $minute] = parseTimeParts($hhmm);
                $candidate = $day->setTime($hour, $minute, 0);
                if ($candidate <= $now) {
                    continue;
                }
                $candidateTs = $candidate->getTimestamp();
                if ($nextTs === null || $candidateTs < $nextTs) {
                    $nextTs = $candidateTs;
                }
            }
        }
    }

    return $nextTs;
}

function readScheduleManualOverride(): ?array
{
    $raw = @file_get_contents(SCHEDULE_MANUAL_OVERRIDE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        clearScheduleManualOverride();
        return null;
    }

    $module = $data['module'] ?? null;
    $action = $data['action'] ?? null;
    $timestamp = $data['timestamp'] ?? null;
    $expiresAt = $data['expiresAt'] ?? null;
    if (
        !is_string($module) || $module === '' ||
        !is_string($action) || $action === '' ||
        !is_int($timestamp) ||
        !is_int($expiresAt) || $expiresAt <= 0
    ) {
        clearScheduleManualOverride();
        return null;
    }

    return $data;
}

function getActiveScheduleManualOverride(): ?array
{
    $payload = readScheduleManualOverride();
    if ($payload === null) {
        return null;
    }

    $expiresAt = (int)($payload['expiresAt'] ?? 0);
    if ($expiresAt <= 0 || time() >= $expiresAt) {
        clearScheduleManualOverride();
        return null;
    }

    return $payload;
}

function setScheduleManualOverride(array $modules, string $module, string $action): void
{
    $schedule = getSchedule($modules);
    $entries = (($schedule['ok'] ?? false) === true && isset($schedule['entries']) && is_array($schedule['entries']))
        ? (array)$schedule['entries']
        : [];
    $expiresAt = getNextScheduleBoundaryTimestamp($entries);
    if ($expiresAt === null) {
        clearScheduleManualOverride();
        return;
    }

    $payload = [
        'module' => $module,
        'action' => $action,
        'timestamp' => time(),
        'expiresAt' => $expiresAt,
    ];
    @file_put_contents(SCHEDULE_MANUAL_OVERRIDE_FILE, json_encode($payload, JSON_UNESCAPED_SLASHES));
}

function clearScheduleManualOverride(): void
{
    if (is_file(SCHEDULE_MANUAL_OVERRIDE_FILE)) {
        @unlink(SCHEDULE_MANUAL_OVERRIDE_FILE);
    }
}

function hasScheduleManualOverride(): bool
{
    return getActiveScheduleManualOverride() !== null;
}

function getActiveScheduleControlEntry(array $modules): ?array
{
    if (hasScheduleManualOverride()) {
        return null;
    }

    $schedule = getSchedule($modules);
    if (($schedule['ok'] ?? false) !== true) {
        return null;
    }

    $entries = (array)($schedule['entries'] ?? []);
    if ($entries === []) {
        return null;
    }

    return resolveScheduleEntryForCurrentTime($entries);
}

function switchExclusiveModuleState(array $modules, ?string $selected): array
{
    runCommand('systemctl daemon-reload', $reloadCode);
    if ($reloadCode !== 0) {
        return ['ok' => false, 'error' => 'daemon_reload_failed'];
    }

    foreach ($modules as $module) {
        $service = escapeshellarg($module . '.service');
        if ($selected !== null && $module === $selected) {
            runCommand("systemctl start $service", $code);
        } else {
            runCommand("systemctl stop $service", $code);
        }
        if ($code !== 0) {
            return ['ok' => false, 'error' => 'service_switch_failed'];
        }
    }

    return ['ok' => true];
}

function applyExclusiveModuleState(array $modules, ?string $selected, ?int $trafficPercent = null): array
{
    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        return ['ok' => false, 'error' => 'distress_autotune_lock_failed'];
    }

    $previousSelected = getActiveModule($modules);
    $previousTrafficState = ($selected !== null && $trafficPercent !== null)
        ? getTrafficLimitRollbackSnapshot()
        : null;

    $resetDistressBaseline = $previousSelected === 'distress' && $selected !== 'distress';
    $switchResult = switchExclusiveModuleState($modules, $selected);
    if (($switchResult['ok'] ?? false) !== true) {
        $rollbackResult = switchExclusiveModuleState($modules, $previousSelected);
        releaseDistressAutotuneLock($lockHandle);
        return $switchResult + [
            'serviceRollbackOk' => (($rollbackResult['ok'] ?? false) === true),
        ];
    }

    if ($resetDistressBaseline && !resetDistressAutotuneBaseline()) {
        $rollbackResult = switchExclusiveModuleState($modules, $previousSelected);
        releaseDistressAutotuneLock($lockHandle);
        return [
            'ok' => false,
            'error' => 'distress_autotune_state_write_failed',
            'serviceRollbackOk' => (($rollbackResult['ok'] ?? false) === true),
        ];
    }

    if ($selected !== null && $trafficPercent !== null) {
        $limitResult = setTrafficLimit($trafficPercent);
        if (($limitResult['ok'] ?? false) !== true) {
            $serviceRollbackResult = switchExclusiveModuleState($modules, $previousSelected);
            $trafficRollbackOk = true;
            if ($previousTrafficState !== null) {
                $trafficRollbackResult = setTrafficLimit((int)$previousTrafficState['percent']);
                $trafficRollbackOk = (($trafficRollbackResult['ok'] ?? false) === true);
            }
            releaseDistressAutotuneLock($lockHandle);
            return $limitResult + [
                'serviceRollbackOk' => (($serviceRollbackResult['ok'] ?? false) === true),
                'trafficRollbackOk' => $trafficRollbackOk,
            ];
        }
    }

    releaseDistressAutotuneLock($lockHandle);
    return ['ok' => true];
}

function getAutostart(array $modules): array
{
    $enabled = getEnabledAutostartModules($modules);
    return ['ok' => true, 'active' => $enabled[0] ?? null];
}

function isModuleAutostartEnabled(string $module): bool
{
    $service = escapeshellarg($module . '.service');
    $raw = trim(runCommand("systemctl is-enabled $service", $code));
    if ($code !== 0) {
        return false;
    }
    return str_starts_with($raw, 'enabled');
}

function getEnabledAutostartModules(array $modules): array
{
    $enabled = [];
    foreach ($modules as $module) {
        if (isModuleAutostartEnabled($module)) {
            $enabled[] = $module;
        }
    }
    return $enabled;
}

function removeAutostartLinks(string $module): void
{
    $service = $module . '.service';
    $bases = ['/etc/systemd/system', '/run/systemd/system'];
    $targets = ['multi-user.target.wants', 'default.target.wants', 'graphical.target.wants'];
    foreach ($bases as $base) {
        foreach ($targets as $targetDir) {
            $path = $base . '/' . $targetDir . '/' . $service;
            if (is_link($path) || file_exists($path)) {
                @unlink($path);
            }
        }
    }
}

function setAutostart(array $modules, ?string $selected): array
{
    foreach ($modules as $module) {
        removeAutostartLinks($module);
    }

    if ($selected !== null) {
        $service = escapeshellarg($selected . '.service');
        runCommand("systemctl add-wants multi-user.target $service", $code);
        if ($code !== 0) {
            return ['ok' => false, 'error' => 'add_wants_failed'];
        }
    }

    runCommand('systemctl daemon-reload', $reloadCode);

    if ($selected !== null) {
        $scheduleResult = setScheduleEntries($modules, []);
        if (($scheduleResult['ok'] ?? false) !== true) {
            return $scheduleResult;
        }
    }

    $enabled = getEnabledAutostartModules($modules);
    if ($selected === null) {
        if (count($enabled) !== 0) {
            return ['ok' => false, 'error' => 'autostart_verification_failed'];
        }
    } else {
        if (count($enabled) !== 1 || $enabled[0] !== $selected) {
            return ['ok' => false, 'error' => 'autostart_verification_failed'];
        }
    }

    return ['ok' => true];
}

function serviceIsActive(string $module): bool
{
    $service = escapeshellarg($module . '.service');
    runCommand("systemctl is-active --quiet $service", $code);
    return $code === 0;
}

function getActiveModule(array $modules): ?string
{
    foreach ($modules as $module) {
        if (serviceIsActive($module)) {
            return $module;
        }
    }
    return null;
}

function getServiceLogsRaw(string $module, int $lines): string
{
    $lines = max(1, min(500, $lines));
    [$text, $meta] = getServiceLogWithMeta($module, $lines);
    return $text;
}

function getServiceLogWithMeta(string $module, int $lines): array
{
    $lines = max(1, min(500, $lines));
    $logFile = getServiceLogFile($module);
    if ($logFile !== null) {
        $pathSafe = escapeshellarg($logFile);
        $text = runCommand("tail -n $lines $pathSafe", $code);
        if (trim($text) !== '') {
            return [$text, ['source' => 'file', 'path' => $logFile]];
        }
        // If file is empty/missing, fall back to journal.
    }

    $service = escapeshellarg($module . '.service');
    $text = runCommand("journalctl -u $service --no-pager -o cat -n $lines", $code);
    return [$text, ['source' => 'journal', 'path' => null]];
}

function knownLogFileByModule(string $module): ?string
{
    // From ADSS unit files:
    // - mhddos/distress append to /var/log/adss.log
    // - x100 appends to /opt/itarmy/.../x100-log-short.txt
    static $map = [
        'mhddos' => '/var/log/adss.log',
        'distress' => '/var/log/adss.log',
        'x100' => '/opt/itarmy/x100-for-docker/put-your-ovpn-files-here/x100-log-short.txt',
    ];
    return $map[$module] ?? null;
}

function parseStandardOutputPath(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    // Examples:
    // append:/var/log/adss.log
    // file:/path/to/log
    // journal
    if (str_starts_with($value, 'append:')) {
        return substr($value, strlen('append:'));
    }
    if (str_starts_with($value, 'file:')) {
        return substr($value, strlen('file:'));
    }
    return null;
}

function getServiceLogFile(string $module): ?string
{
    $known = knownLogFileByModule($module);
    if (is_string($known) && $known !== '') {
        return $known;
    }

    $service = escapeshellarg($module . '.service');
    $value = runCommand("systemctl show -p StandardOutput --value $service", $code);
    if ($code !== 0) {
        return null;
    }
    $path = parseStandardOutputPath($value);
    if ($path === null) {
        return null;
    }
    $path = trim($path);
    if ($path === '' || $path[0] !== '/') {
        return null;
    }
    return $path;
}

function serviceActivateExclusive(array $modules, string $selected): array
{
    $result = applyExclusiveModuleState($modules, $selected);
    if (($result['ok'] ?? false) !== true) {
        return $result;
    }
    setScheduleManualOverride($modules, $selected, 'start');
    return ['ok' => true];
}

function serviceStop(array $modules, string $module): array
{
    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        return ['ok' => false, 'error' => 'distress_autotune_lock_failed'];
    }

    runCommand('systemctl daemon-reload', $reloadCode);
    if ($reloadCode !== 0) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'daemon_reload_failed'];
    }
    $service = escapeshellarg($module . '.service');
    runCommand("systemctl stop $service", $stopCode);
    if ($stopCode !== 0) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'service_stop_failed'];
    }
    if ($module === 'distress' && !resetDistressAutotuneBaseline()) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'distress_autotune_state_write_failed'];
    }
    setScheduleManualOverride($modules, $module, 'stop');
    releaseDistressAutotuneLock($lockHandle);
    return ['ok' => true];
}

function serviceRestart(string $module): array
{
    if (!serviceIsActive($module)) {
        return ['ok' => true, 'restarted' => false];
    }
    runCommand('systemctl daemon-reload', $reloadCode);
    if ($reloadCode !== 0) {
        return ['ok' => false, 'error' => 'daemon_reload_failed'];
    }
    $service = escapeshellarg($module . '.service');
    runCommand("systemctl restart $service", $restartCode);
    if ($restartCode !== 0) {
        return ['ok' => false, 'error' => 'service_restart_failed'];
    }
    return ['ok' => true, 'restarted' => true];
}

function statusSnapshot(array $modules, int $lines): array
{
    $activeModule = getActiveModule($modules);
    $logs = '';
    $logSource = null;
    $logPath = null;
    if ($activeModule !== null) {
        [$logs, $meta] = getServiceLogWithMeta($activeModule, $lines);
        $logSource = $meta['source'] ?? null;
        $logPath = $meta['path'] ?? null;
    }

    return [
        'ok' => true,
        'activeModule' => $activeModule,
        'commonLogs' => $logs,
        'logSource' => $logSource,
        'logPath' => $logPath,
    ];
}

function isAutostartWanted(string $module): ?bool
{
    $depsRaw = runCommand('systemctl list-dependencies --plain --no-legend multi-user.target', $code);
    if ($code !== 0) {
        return null;
    }
    $deps = array_map('trim', preg_split('/\r\n|\r|\n/', $depsRaw));
    $service = $module . '.service';
    foreach ($deps as $dep) {
        if ($dep === $service) {
            return true;
        }
    }
    return false;
}

function serviceInfo(string $module): array
{
    $serviceArg = escapeshellarg($module . '.service');

    $active = serviceIsActive($module);
    $wanted = isAutostartWanted($module);

    $fragmentPath = trim(runCommand("systemctl show -p FragmentPath --value $serviceArg", $code1));
    if ($code1 !== 0) {
        $fragmentPath = '';
    }
    $execStart = trim(runCommand("systemctl show -p ExecStart --value $serviceArg", $code2));
    if ($code2 !== 0) {
        $execStart = '';
    }
    $standardOutput = trim(runCommand("systemctl show -p StandardOutput --value $serviceArg", $code3));
    if ($code3 !== 0) {
        $standardOutput = '';
    }

    $statusText = runCommand("systemctl status $serviceArg --no-pager --full", $code4);
    if ($code4 !== 0 && trim($statusText) === '') {
        $statusText = 'Service status is unavailable.';
    }

    return [
        'ok' => true,
        'active' => $active,
        'autostartWanted' => $wanted,
        'fragmentPath' => $fragmentPath,
        'execStart' => $execStart,
        'standardOutput' => $standardOutput,
        'statusText' => $statusText,
        'logFile' => getServiceLogFile($module),
    ];
}

function readServiceExecStart(string $module): ?string
{
    $serviceFile = '/opt/itarmy/services/' . $module . '.service';
    if (!is_readable($serviceFile)) {
        return null;
    }
    $handle = fopen($serviceFile, 'r');
    if ($handle === false) {
        return null;
    }
    $result = null;
    while (($line = fgets($handle)) !== false) {
        if (str_starts_with(trim($line), 'ExecStart=')) {
            $result = trim($line);
        }
    }
    fclose($handle);
    return $result;
}

function updateServiceExecStart(string $module, string $execStartLine): bool
{
    if (!str_starts_with($execStartLine, 'ExecStart=')) {
        return false;
    }
    $serviceFile = '/opt/itarmy/services/' . $module . '.service';
    $content = @file_get_contents($serviceFile);
    if (!is_string($content) || $content === '') {
        return false;
    }
    if (preg_match('/^ExecStart=.*/m', $content) !== 1) {
        return false;
    }
    $updated = preg_replace('/^ExecStart=.*/m', $execStartLine, $content, 1);
    if (!is_string($updated)) {
        return false;
    }
    if ($updated === $content) {
        return true;
    }
    $written = @file_put_contents($serviceFile, $updated);
    return $written !== false;
}

function getX100Config(): ?string
{
    $envFile = '/opt/itarmy/x100-for-docker/put-your-ovpn-files-here/x100-config.txt';
    $content = @file_get_contents($envFile);
    return is_string($content) ? $content : null;
}

function setX100Config(string $content): bool
{
    $envFile = '/opt/itarmy/x100-for-docker/put-your-ovpn-files-here/x100-config.txt';
    $written = @file_put_contents($envFile, $content);
    return $written !== false;
}

function getSchedule(array $modules): array
{
    $raw = runCommand('crontab -l', $code);
    if ($code !== 0) {
        return ['ok' => true, 'entries' => []];
    }

    $entries = [];
    $newPattern = '/^#\s*ITARMYBOX\s+ENTRY\s+MODULE=(?<module>[a-zA-Z0-9_-]+)\s+DOW=(?<dow>\*|[0-6](?:,[0-6])*)\s+START=(?<start>[0-2][0-9]:[0-5][0-9])\s+STOP=(?<stop>[0-2][0-9]:[0-5][0-9])(?:\s+POWER=(?<power>[0-9]{2,3}))?$/m';
    if (preg_match_all($newPattern, $raw, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $m) {
            $module = $m['module'];
            if (!in_array($module, $modules, true)) {
                continue;
            }
            $days = parseDowField($m['dow']);
            if ($days === null || $days === []) {
                continue;
            }
            $entries[] = [
                'module' => $module,
                'days' => $days,
                'start' => $m['start'],
                'stop' => $m['stop'],
                'powerPercent' => normalizeTrafficPercentValue($m['power'] ?? null) ?? trafficLimitMbitToPercent(50),
            ];
            if (count($entries) >= MAX_SCHEDULE_ENTRIES) {
                break;
            }
        }
        return ['ok' => true, 'entries' => $entries];
    }

    // Backward compatibility with old single-entry format.
    $oldPattern = '/^#\s*ITARMYBOX\s+MODULE=(?<module>[a-zA-Z0-9_-]+)\s+DOW=(?<dow>\*|[0-6](?:,[0-6])*)\s+START=(?<start>[0-2][0-9]:[0-5][0-9])\s+STOP=(?<stop>[0-2][0-9]:[0-5][0-9])$/m';
    if (preg_match($oldPattern, $raw, $m)) {
        $module = $m['module'];
        if (in_array($module, $modules, true)) {
            $days = parseDowField($m['dow']);
            if ($days !== null && $days !== []) {
                $entries[] = [
                    'module' => $module,
                    'days' => $days,
                    'start' => $m['start'],
                    'stop' => $m['stop'],
                    'powerPercent' => trafficLimitMbitToPercent(50),
                ];
            }
        }
    }

    return [
        'ok' => true,
        'entries' => $entries,
    ];
}

function buildRootHelperCliCommand(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return '';
    }
    return '/usr/bin/env php ' . escapeshellarg(ROOT_HELPER_SCRIPT_PATH) . ' ' . escapeshellarg($json);
}

function setScheduleEntries(array $modules, array $entries): array
{
    $raw = runCommand('crontab -l', $code);
    if ($code !== 0) {
        $raw = '';
    }
    $base = stripScheduleBlock($raw);
    $new = $base;

    if ($entries !== []) {
        if (count($entries) > MAX_SCHEDULE_ENTRIES) {
            return ['ok' => false, 'error' => 'too_many_entries'];
        }
        if (scheduleEntriesOverlap($entries)) {
            return ['ok' => false, 'error' => 'invalid_schedule_overlap'];
        }

        $block = [SCHEDULE_BEGIN_MARKER];
        $bootCommand = buildRootHelperCliCommand([
            'action' => 'schedule_boot_sync',
            'modules' => $modules,
        ]);
        if ($bootCommand === '') {
            return ['ok' => false, 'error' => 'schedule_boot_command_failed'];
        }
        $block[] = "@reboot $bootCommand >/dev/null 2>&1";
        foreach ($entries as $entry) {
            $module = (string)($entry['module'] ?? '');
            $days = normalizeDays((array)($entry['days'] ?? []));
            $start = (string)($entry['start'] ?? '');
            $stop = (string)($entry['stop'] ?? '');
            $powerPercent = normalizeTrafficPercentValue($entry['powerPercent'] ?? null);
            if (
                $module === '' ||
                $days === [] ||
                preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) !== 1 ||
                preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $stop) !== 1 ||
                $powerPercent === null
            ) {
                return ['ok' => false, 'error' => 'invalid_entry'];
            }

            $startDow = count($days) === 7 ? '*' : implode(',', $days);
            $stopDays = ($start < $stop) ? $days : shiftDays($days, 1);
            $stopDow = count($stopDays) === 7 ? '*' : implode(',', $stopDays);
            [$startH, $startM] = parseTimeParts($start);
            [$stopH, $stopM] = parseTimeParts($stop);
            $startCommand = buildRootHelperCliCommand([
                'action' => 'schedule_activate',
                'modules' => $modules,
                'module' => $module,
                'percent' => $powerPercent,
            ]);
            $stopCommand = buildRootHelperCliCommand([
                'action' => 'schedule_deactivate',
                'modules' => $modules,
                'module' => $module,
            ]);
            if ($startCommand === '' || $stopCommand === '') {
                return ['ok' => false, 'error' => 'schedule_command_failed'];
            }
            $block[] = "# ITARMYBOX ENTRY MODULE=$module DOW=$startDow START=$start STOP=$stop POWER=$powerPercent";
            $block[] = "$startM $startH * * $startDow $startCommand >/dev/null 2>&1";
            $block[] = "$stopM $stopH * * $stopDow $stopCommand >/dev/null 2>&1";
        }

        foreach ($modules as $module) {
            removeAutostartLinks($module);
        }
        runCommand('systemctl daemon-reload', $reloadCode);

        $block[] = SCHEDULE_END_MARKER;
        $new .= ($new === '' ? '' : "\n") . implode("\n", $block);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'itarmybox-cron-');
    if ($tmp === false) {
        return ['ok' => false, 'error' => 'tmp_failed'];
    }
    $saved = file_put_contents($tmp, $new === '' ? "\n" : ($new . "\n"));
    if ($saved === false) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'write_failed'];
    }

    runCommand('crontab ' . escapeshellarg($tmp), $applyCode);
    @unlink($tmp);
    if ($applyCode !== 0) {
        return ['ok' => false, 'error' => 'crontab_apply_failed'];
    }

    // Re-enable scheduler decisions after explicit schedule save.
    clearScheduleManualOverride();
    return ['ok' => true];
}

function resolveScheduleEntryForCurrentTime(array $entries, ?DateTimeImmutable $now = null): ?array
{
    $now = $now ?? new DateTimeImmutable('now');
    $weekday = (int)$now->format('w');
    $currentTime = $now->format('H:i');

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $module = (string)($entry['module'] ?? '');
        $days = normalizeDays((array)($entry['days'] ?? []));
        $start = (string)($entry['start'] ?? '');
        $stop = (string)($entry['stop'] ?? '');
        $powerPercent = normalizeTrafficPercentValue($entry['powerPercent'] ?? null);
        if (
            $module === '' ||
            $days === [] ||
            preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) !== 1 ||
            preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $stop) !== 1 ||
            $powerPercent === null
        ) {
            continue;
        }

        if ($start === $stop) {
            continue;
        }

        $isActive = false;
        if ($start < $stop) {
            $isActive = in_array($weekday, $days, true) && $currentTime >= $start && $currentTime < $stop;
        } else {
            $previousWeekday = ($weekday + 6) % 7;
            $isActive =
                (in_array($weekday, $days, true) && $currentTime >= $start) ||
                (in_array($previousWeekday, $days, true) && $currentTime < $stop);
        }

        if ($isActive) {
            return [
                'module' => $module,
                'powerPercent' => $powerPercent,
            ];
        }
    }

    return null;
}

function scheduleBootSync(array $modules): array
{
    waitForTimeSyncReady();
    $schedule = getSchedule($modules);
    if (($schedule['ok'] ?? false) !== true) {
        return $schedule;
    }

    $entries = (array)($schedule['entries'] ?? []);
    if ($entries === []) {
        return applyExclusiveModuleState($modules, null);
    }

    $selectedEntry = resolveScheduleEntryForCurrentTime($entries);
    if ($selectedEntry === null) {
        return applyExclusiveModuleState($modules, null);
    }

    return applyExclusiveModuleState(
        $modules,
        (string)$selectedEntry['module'],
        (int)$selectedEntry['powerPercent']
    );
}

$rawRequest = '';
if (isset($argv[1]) && is_string($argv[1]) && trim($argv[1]) !== '') {
    $rawRequest = $argv[1];
} else {
    $rawRequest = stream_get_contents(STDIN);
}
if (!is_string($rawRequest) || trim($rawRequest) === '') {
    fail('empty_request');
}

$request = json_decode($rawRequest, true);
if (!is_array($request)) {
    fail('invalid_json');
}

$action = (string)($request['action'] ?? '');
$modules = $request['modules'] ?? [];
if (!is_array($modules) || $modules === []) {
    fail('invalid_modules');
}
foreach ($modules as $module) {
    if (!is_string($module) || !isValidModule($module)) {
        fail('invalid_module_name');
    }
}
$response = dispatchRootHelperAction($action, $request, $modules);
respond($response);
exit(0);
