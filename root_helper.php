<?php

declare(strict_types=1);

const SCHEDULE_BEGIN_MARKER = '# ITARMYBOX-SCHEDULE-BEGIN';
const SCHEDULE_END_MARKER = '# ITARMYBOX-SCHEDULE-END';

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

function getAutostart(array $modules): array
{
    $depsRaw = runCommand('systemctl list-dependencies --plain --no-legend multi-user.target', $code);
    if ($code !== 0) {
        return ['ok' => false, 'error' => 'failed_to_read_dependencies'];
    }
    $deps = array_map('trim', preg_split('/\r\n|\r|\n/', $depsRaw));
    $depSet = [];
    foreach ($deps as $dep) {
        if ($dep !== '') {
            $depSet[$dep] = true;
        }
    }

    foreach ($modules as $module) {
        $service = $module . '.service';
        if (isset($depSet[$service])) {
            return ['ok' => true, 'active' => $module];
        }
    }

    return ['ok' => true, 'active' => null];
}

function setAutostart(array $modules, ?string $selected): array
{
    foreach ($modules as $module) {
        $service = escapeshellarg($module . '.service');
        runCommand("systemctl remove-wants multi-user.target $service", $code);
        if ($code !== 0 && $selected === $module) {
            return ['ok' => false, 'error' => 'remove_wants_failed'];
        }
    }

    if ($selected !== null) {
        $service = escapeshellarg($selected . '.service');
        runCommand("systemctl add-wants multi-user.target $service", $code);
        if ($code !== 0) {
            return ['ok' => false, 'error' => 'add_wants_failed'];
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
    return ['ok' => true];
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

function getSchedule(array $modules): array
{
    $raw = runCommand('crontab -l', $code);
    if ($code !== 0) {
        return ['ok' => true, 'schedule' => null];
    }

    $pattern = '/^#\s*ITARMYBOX\s+MODULE=(?<module>[a-zA-Z0-9_-]+)\s+DOW=(?<dow>\*|[0-6](?:,[0-6])*)\s+START=(?<start>[0-2][0-9]:[0-5][0-9])\s+STOP=(?<stop>[0-2][0-9]:[0-5][0-9])$/m';
    if (!preg_match($pattern, $raw, $m)) {
        return ['ok' => true, 'schedule' => null];
    }
    if (!in_array($m['module'], $modules, true)) {
        return ['ok' => true, 'schedule' => null];
    }

    return [
        'ok' => true,
        'schedule' => [
            'module' => $m['module'],
            'dow' => $m['dow'],
            'start' => $m['start'],
            'stop' => $m['stop'],
        ],
    ];
}

function setSchedule(?string $module, ?array $days, ?string $start, ?string $stop): array
{
    $raw = runCommand('crontab -l', $code);
    if ($code !== 0) {
        $raw = '';
    }
    $base = stripScheduleBlock($raw);
    $new = $base;

    if ($module !== null && $days !== null && $start !== null && $stop !== null) {
        $days = normalizeDays($days);
        if ($days === []) {
            return ['ok' => false, 'error' => 'invalid_days'];
        }
        $dow = count($days) === 7 ? '*' : implode(',', $days);
        [$startH, $startM] = parseTimeParts($start);
        [$stopH, $stopM] = parseTimeParts($stop);
        $service = $module . '.service';
        $block = [
            SCHEDULE_BEGIN_MARKER,
            "# ITARMYBOX MODULE=$module DOW=$dow START=$start STOP=$stop",
            "$startM $startH * * $dow systemctl start $service >/dev/null 2>&1",
            "$stopM $stopH * * $dow systemctl stop $service >/dev/null 2>&1",
            SCHEDULE_END_MARKER,
        ];
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
    $module = $request['module'] ?? null;
    $days = $request['days'] ?? null;
    $start = $request['start'] ?? null;
    $stop = $request['stop'] ?? null;

    if ($module !== null && (!is_string($module) || !in_array($module, $modules, true))) {
        fail('invalid_schedule_module');
    }
    if ($days !== null && !is_array($days)) {
        fail('invalid_days');
    }
    if ($start !== null && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$start) !== 1) {
        fail('invalid_start');
    }
    if ($stop !== null && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$stop) !== 1) {
        fail('invalid_stop');
    }

    respond(setSchedule($module, $days, $start, $stop));
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

fail('unknown_action');
