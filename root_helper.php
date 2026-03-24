<?php

declare(strict_types=1);

const SCHEDULE_BEGIN_MARKER = '# ITARMYBOX-SCHEDULE-BEGIN';
const SCHEDULE_END_MARKER = '# ITARMYBOX-SCHEDULE-END';
const MAX_SCHEDULE_ENTRIES = 2;
const SCHEDULE_MANUAL_OVERRIDE_FILE = '/tmp/itarmybox-schedule-manual-override';
const TRAFFIC_LIMIT_STATE_FILE = '/tmp/itarmybox-traffic-limit.json';

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

function findAptGet(): ?string
{
    return findExecutable(['/usr/bin/apt-get', '/bin/apt-get', '/usr/bin/apt', '/bin/apt']);
}

function isVnstatInstalled(): bool
{
    return is_executable('/usr/bin/vnstat') || is_executable('/bin/vnstat');
}

function installVnstat(): array
{
    if (isVnstatInstalled()) {
        return ['ok' => true, 'installed' => true, 'already' => true];
    }

    $apt = findAptGet();
    if ($apt === null) {
        return ['ok' => false, 'error' => 'apt_not_found'];
    }

    $cmd = 'DEBIAN_FRONTEND=noninteractive ' . escapeshellarg($apt) . ' install -y vnstat';
    $output = runCommand($cmd, $code);
    if ($code !== 0) {
        return ['ok' => false, 'error' => 'vnstat_install_failed', 'output' => $output];
    }

    return ['ok' => true, 'installed' => isVnstatInstalled()];
}

function parseBoolString(string $value): ?bool
{
    $normalized = strtolower(trim($value));
    return match ($normalized) {
        'yes', 'true', '1' => true,
        'no', 'false', '0' => false,
        default => null,
    };
}

function findTimedatectl(): ?string
{
    return findExecutable(['/usr/bin/timedatectl', '/bin/timedatectl']);
}

function getTimeSyncStatus(): array
{
    $timedatectl = findTimedatectl();
    if ($timedatectl === null) {
        return ['ok' => false, 'error' => 'timedatectl_not_found'];
    }

    $output = runCommand(escapeshellarg($timedatectl) . ' show --property=Timezone --property=NTP --property=NTPSynchronized --property=NTPService --value', $code);
    if ($code !== 0) {
        return ['ok' => false, 'error' => 'timedatectl_show_failed'];
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($output));
    $timezone = trim((string)($lines[0] ?? ''));
    $ntpEnabled = parseBoolString((string)($lines[1] ?? ''));
    $ntpSynced = parseBoolString((string)($lines[2] ?? ''));
    $ntpService = trim((string)($lines[3] ?? ''));

    return [
        'ok' => true,
        'timezone' => $timezone,
        'ntpEnabled' => $ntpEnabled,
        'ntpSynchronized' => $ntpSynced,
        'ntpService' => $ntpService !== '' ? $ntpService : null,
        'timezoneOk' => $timezone === 'Europe/Kyiv',
        'ntpOk' => $ntpEnabled === true,
    ];
}

function ensureTimeSync(): array
{
    return ensureTimeSyncForTimezone('Europe/Kyiv');
}

function ensureTimeSyncForTimezone(string $timezone): array
{
    if (preg_match('/^[A-Za-z0-9._+-]+(?:\/[A-Za-z0-9._+\-]+)+$/', $timezone) !== 1) {
        return ['ok' => false, 'error' => 'invalid_timezone'];
    }

    $timedatectl = findTimedatectl();
    if ($timedatectl === null) {
        return ['ok' => false, 'error' => 'timedatectl_not_found'];
    }

    runCommand(escapeshellarg($timedatectl) . ' set-timezone ' . escapeshellarg($timezone), $timezoneCode);
    if ($timezoneCode !== 0) {
        return ['ok' => false, 'error' => 'set_timezone_failed'];
    }

    runCommand(escapeshellarg($timedatectl) . ' set-ntp true', $ntpCode);
    if ($ntpCode !== 0) {
        return ['ok' => false, 'error' => 'set_ntp_failed'];
    }

    $status = getTimeSyncStatus();
    if (($status['ok'] ?? false) !== true) {
        return $status;
    }

    if (($status['timezone'] ?? '') !== $timezone || ($status['ntpOk'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'time_sync_verification_failed'] + $status;
    }

    return $status;
}

function appendRebootLog(string $message): void
{
    $line = '[' . date('c') . '] ' . $message . "\n";
    @file_put_contents('/tmp/itarmybox-reboot.log', $line, FILE_APPEND);
}

function trafficLimitPercentToMbit(int $percent): int
{
    $percent = max(25, min(100, $percent));
    if ($percent <= 80) {
        return (int)round(20 + (($percent - 25) * (300 - 20) / (80 - 25)));
    }
    return (int)round(300 + (($percent - 80) * (750 - 300) / (100 - 80)));
}

function trafficLimitStateDefault(): array
{
    return [
        'ok' => true,
        'iface' => 'eth0',
        'percent' => trafficLimitMbitToPercent(50),
        'mbit' => 50,
    ];
}

function trafficLimitMbitToPercent(int $mbit): int
{
    $mbit = max(20, min(750, $mbit));
    if ($mbit <= 300) {
        return (int)round(25 + (($mbit - 20) * (80 - 25) / (300 - 20)));
    }
    return (int)round(80 + (($mbit - 300) * (100 - 80) / (750 - 300)));
}

function readTrafficLimitFromTc(): ?array
{
    $tc = findTcBinary();
    if ($tc === null) {
        return null;
    }
    $iface = 'eth0';
    $output = runCommand(escapeshellarg($tc) . ' qdisc show dev ' . escapeshellarg($iface), $code);
    if ($code !== 0 || trim($output) === '') {
        return null;
    }

    if (preg_match('/\brate\s+(\d+)([kmg])bit\b/i', $output, $matches) !== 1) {
        return null;
    }

    $value = (int)$matches[1];
    $unit = strtolower($matches[2]);
    $mbit = match ($unit) {
        'g' => $value * 1000,
        'm' => $value,
        'k' => max(1, (int)round($value / 1000)),
        default => $value,
    };

    return [
        'ok' => true,
        'iface' => $iface,
        'percent' => trafficLimitMbitToPercent($mbit),
        'mbit' => max(20, min(750, $mbit)),
        'source' => 'tc',
    ];
}

function getTrafficLimitState(): array
{
    $default = trafficLimitStateDefault();
    $tcState = readTrafficLimitFromTc();
    if ($tcState !== null) {
        return $tcState;
    }
    $raw = @file_get_contents(TRAFFIC_LIMIT_STATE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $default;
    }
    $percent = (int)($data['percent'] ?? $default['percent']);
    $iface = (string)($data['iface'] ?? $default['iface']);
    if ($iface !== 'eth0' || $percent < 25 || $percent > 100) {
        return $default;
    }
    return [
        'ok' => true,
        'iface' => 'eth0',
        'percent' => $percent,
        'mbit' => trafficLimitPercentToMbit($percent),
        'source' => 'state',
    ];
}

function setTrafficLimit(int $percent): array
{
    if ($percent < 25 || $percent > 100) {
        return ['ok' => false, 'error' => 'invalid_traffic_limit_percent'];
    }
    $tc = findTcBinary();
    if ($tc === null) {
        return ['ok' => false, 'error' => 'tc_not_found'];
    }
    $iface = 'eth0';
    $mbit = trafficLimitPercentToMbit($percent);
    $rate = $mbit . 'mbit';
    $burst = ($mbit >= 500) ? '1536kb' : (($mbit >= 200) ? '1024kb' : '384kb');
    runCommand(escapeshellarg($tc) . ' qdisc replace dev ' . escapeshellarg($iface) . ' root tbf rate ' . escapeshellarg($rate) . ' burst ' . escapeshellarg($burst) . ' latency 70ms', $code);
    if ($code !== 0) {
        return ['ok' => false, 'error' => 'traffic_limit_apply_failed'];
    }
    @file_put_contents(
        TRAFFIC_LIMIT_STATE_FILE,
        json_encode([
            'iface' => $iface,
            'percent' => $percent,
            'updated_at' => time(),
        ], JSON_UNESCAPED_SLASHES)
    );
    return [
        'ok' => true,
        'iface' => $iface,
        'percent' => $percent,
        'mbit' => $mbit,
        'source' => 'set',
    ];
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

function setScheduleManualOverride(string $module, string $action): void
{
    $payload = [
        'module' => $module,
        'action' => $action,
        'timestamp' => time(),
    ];
    @file_put_contents(SCHEDULE_MANUAL_OVERRIDE_FILE, json_encode($payload, JSON_UNESCAPED_SLASHES));
}

function clearScheduleManualOverride(): void
{
    if (is_file(SCHEDULE_MANUAL_OVERRIDE_FILE)) {
        @unlink(SCHEDULE_MANUAL_OVERRIDE_FILE);
    }
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
    runCommand('systemctl daemon-reload', $reloadCode);
    if ($reloadCode !== 0) {
        return ['ok' => false, 'error' => 'daemon_reload_failed'];
    }

    foreach ($modules as $module) {
        $service = escapeshellarg($module . '.service');
        if ($module === $selected) {
            runCommand("systemctl start $service", $code);
        } else {
            runCommand("systemctl stop $service", $code);
        }
        if ($code !== 0) {
            return ['ok' => false, 'error' => 'service_switch_failed'];
        }
    }

    setScheduleManualOverride($selected, 'start');
    return ['ok' => true];
}

function serviceStop(string $module): array
{
    runCommand('systemctl daemon-reload', $reloadCode);
    if ($reloadCode !== 0) {
        return ['ok' => false, 'error' => 'daemon_reload_failed'];
    }
    $service = escapeshellarg($module . '.service');
    runCommand("systemctl stop $service", $stopCode);
    if ($stopCode !== 0) {
        return ['ok' => false, 'error' => 'service_stop_failed'];
    }
    setScheduleManualOverride($module, 'stop');
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

function systemReboot(): array
{
    $systemctl = findExecutable(['/usr/bin/systemctl', '/bin/systemctl']);
    $shutdown = findExecutable(['/usr/sbin/shutdown', '/sbin/shutdown', '/usr/bin/shutdown', '/bin/shutdown']);
    $reboot = findExecutable(['/usr/sbin/reboot', '/sbin/reboot', '/usr/bin/reboot', '/bin/reboot']);

    $uid = function_exists('posix_geteuid') ? (string)posix_geteuid() : 'unknown';
    appendRebootLog('request: uid=' . $uid);

    $candidates = [];
    if ($systemctl !== null) {
        $candidates[] = ['name' => 'systemctl', 'cmd' => escapeshellarg($systemctl) . ' reboot'];
    }
    if ($shutdown !== null) {
        $candidates[] = ['name' => 'shutdown', 'cmd' => escapeshellarg($shutdown) . ' -r now'];
    }
    if ($reboot !== null) {
        $candidates[] = ['name' => 'reboot', 'cmd' => escapeshellarg($reboot)];
    }

    if ($candidates === []) {
        appendRebootLog('error: reboot_command_not_found');
        return ['ok' => false, 'error' => 'reboot_command_not_found'];
    }

    foreach ($candidates as $candidate) {
        $name = $candidate['name'];
        $cmd = $candidate['cmd'];
        $output = runCommand($cmd, $code);
        appendRebootLog('try ' . $name . ' exit=' . $code . ' output=' . str_replace("\n", ' ', $output));
        if ($code === 0) {
            return ['ok' => true, 'method' => $name];
        }
    }

    return ['ok' => false, 'error' => 'reboot_failed'];
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
    $newPattern = '/^#\s*ITARMYBOX\s+ENTRY\s+MODULE=(?<module>[a-zA-Z0-9_-]+)\s+DOW=(?<dow>\*|[0-6](?:,[0-6])*)\s+START=(?<start>[0-2][0-9]:[0-5][0-9])\s+STOP=(?<stop>[0-2][0-9]:[0-5][0-9])$/m';
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
                ];
            }
        }
    }

    return [
        'ok' => true,
        'entries' => $entries,
    ];
}

function setScheduleEntries(array $entries): array
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
        $block = [SCHEDULE_BEGIN_MARKER];
        foreach ($entries as $entry) {
            $module = (string)($entry['module'] ?? '');
            $days = normalizeDays((array)($entry['days'] ?? []));
            $start = (string)($entry['start'] ?? '');
            $stop = (string)($entry['stop'] ?? '');
            if (
                $module === '' ||
                $days === [] ||
                preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) !== 1 ||
                preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $stop) !== 1
            ) {
                return ['ok' => false, 'error' => 'invalid_entry'];
            }

            $dow = count($days) === 7 ? '*' : implode(',', $days);
            [$startH, $startM] = parseTimeParts($start);
            [$stopH, $stopM] = parseTimeParts($stop);
            $service = $module . '.service';
            $overrideSafe = escapeshellarg(SCHEDULE_MANUAL_OVERRIDE_FILE);
            $block[] = "# ITARMYBOX ENTRY MODULE=$module DOW=$dow START=$start STOP=$stop";
            $block[] = "$startM $startH * * $dow test ! -s $overrideSafe && systemctl start $service >/dev/null 2>&1";
            $block[] = "$stopM $stopH * * $dow test ! -s $overrideSafe && systemctl stop $service >/dev/null 2>&1";
        }
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

$rawRequest = stream_get_contents(STDIN);
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

if ($action === 'autostart_get') {
    respond(getAutostart($modules));
    exit(0);
}

if ($action === 'autostart_set') {
    $selected = $request['selected'] ?? null;
    if ($selected !== null && (!is_string($selected) || !in_array($selected, $modules, true))) {
        fail('invalid_selected_module');
    }
    respond(setAutostart($modules, $selected));
    exit(0);
}

if ($action === 'schedule_get') {
    respond(getSchedule($modules));
    exit(0);
}

if ($action === 'schedule_set') {
    $entries = $request['entries'] ?? [];
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
        ];
    }

    respond(setScheduleEntries($normalized));
    exit(0);
}

if ($action === 'service_activate_exclusive') {
    $selected = $request['selected'] ?? null;
    if (!is_string($selected) || !in_array($selected, $modules, true)) {
        fail('invalid_selected_module');
    }
    respond(serviceActivateExclusive($modules, $selected));
    exit(0);
}

if ($action === 'service_stop') {
    $module = $request['module'] ?? null;
    if (!is_string($module) || !in_array($module, $modules, true)) {
        fail('invalid_module');
    }
    respond(serviceStop($module));
    exit(0);
}

if ($action === 'service_restart') {
    $module = $request['module'] ?? null;
    if (!is_string($module) || !in_array($module, $modules, true)) {
        fail('invalid_module');
    }
    respond(serviceRestart($module));
    exit(0);
}

if ($action === 'system_reboot') {
    respond(systemReboot());
    exit(0);
}

if ($action === 'traffic_limit_get') {
    respond(getTrafficLimitState());
    exit(0);
}

if ($action === 'traffic_limit_set') {
    $percent = (int)($request['percent'] ?? 0);
    respond(setTrafficLimit($percent));
    exit(0);
}

if ($action === 'vnstat_status') {
    respond(['ok' => true, 'installed' => isVnstatInstalled()]);
    exit(0);
}

if ($action === 'vnstat_install') {
    respond(installVnstat());
    exit(0);
}

if ($action === 'time_sync_status') {
    respond(getTimeSyncStatus());
    exit(0);
}

if ($action === 'time_sync_ensure') {
    $timezone = $request['timezone'] ?? 'Europe/Kyiv';
    if (!is_string($timezone) || trim($timezone) === '') {
        fail('invalid_timezone');
    }
    respond(ensureTimeSyncForTimezone(trim($timezone)));
    exit(0);
}

if ($action === 'service_logs') {
    $module = $request['module'] ?? null;
    $lines = (int)($request['lines'] ?? 80);
    if (!is_string($module) || !in_array($module, $modules, true)) {
        fail('invalid_module');
    }
    respond([
        'ok' => true,
        'logs' => getServiceLogsRaw($module, $lines),
    ]);
    exit(0);
}

if ($action === 'status_snapshot') {
    $lines = (int)($request['lines'] ?? 80);
    respond(statusSnapshot($modules, $lines));
    exit(0);
}

if ($action === 'service_info') {
    $module = $request['module'] ?? null;
    if (!is_string($module) || !in_array($module, $modules, true)) {
        fail('invalid_module');
    }
    respond(serviceInfo($module));
    exit(0);
}

if ($action === 'service_execstart_get') {
    $module = $request['module'] ?? null;
    if (!is_string($module) || !in_array($module, $modules, true)) {
        fail('invalid_module');
    }
    $execStart = readServiceExecStart($module);
    if ($execStart === null) {
        respond(['ok' => false, 'error' => 'execstart_read_failed']);
        exit(0);
    }
    respond(['ok' => true, 'execStart' => $execStart]);
    exit(0);
}

if ($action === 'service_execstart_set') {
    $module = $request['module'] ?? null;
    $execStart = $request['execStart'] ?? null;
    if (!is_string($module) || !in_array($module, $modules, true)) {
        fail('invalid_module');
    }
    if (!is_string($execStart) || trim($execStart) === '') {
        fail('invalid_execstart');
    }
    $ok = updateServiceExecStart($module, trim($execStart));
    respond(['ok' => $ok]);
    exit(0);
}

if ($action === 'x100_config_get') {
    $content = getX100Config();
    if ($content === null) {
        respond(['ok' => false, 'error' => 'x100_config_read_failed']);
        exit(0);
    }
    respond(['ok' => true, 'content' => $content]);
    exit(0);
}

if ($action === 'x100_config_set') {
    $content = $request['content'] ?? null;
    if (!is_string($content)) {
        fail('invalid_content');
    }
    $ok = setX100Config($content);
    respond(['ok' => $ok]);
    exit(0);
}

fail('unknown_action');
