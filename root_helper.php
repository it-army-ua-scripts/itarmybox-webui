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
const DISTRESS_AUTOTUNE_TIMER_NAME = 'itarmybox-distress-autotune.timer';
const DISTRESS_AUTOTUNE_STATE_FILE = '/opt/itarmy/distress-autotune.json';
const DISTRESS_AUTOTUNE_LOCK_FILE = '/tmp/itarmybox-distress-autotune.lock';
const DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY = 1024;
const DISTRESS_AUTOTUNE_MANUAL_DEFAULT_CONCURRENCY = 4096;
const DISTRESS_AUTOTUNE_MIN_CONCURRENCY = 64;
const DISTRESS_AUTOTUNE_MAX_CONCURRENCY = 40960;
const DISTRESS_AUTOTUNE_TARGET_LOAD = 4.2;
const DISTRESS_AUTOTUNE_DEAD_ZONE_LOWER = 3.8;
const DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT = 10.0;
const DISTRESS_AUTOTUNE_RAM_RECOVERY_PERCENT = 12.0;
const DISTRESS_AUTOTUNE_COOLDOWN_SECONDS = 300;

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

function ensureDistressAutotuneTimerInstalled(): bool
{
    if (!repairRootHelperAccess()) {
        return isSystemdUnitKnown(DISTRESS_AUTOTUNE_TIMER_NAME);
    }

    $systemctl = findSystemctl();
    if ($systemctl !== null) {
        runCommand(escapeshellarg($systemctl) . ' daemon-reload', $reloadCode);
        runCommand(escapeshellarg($systemctl) . ' enable --now ' . escapeshellarg(DISTRESS_AUTOTUNE_TIMER_NAME), $enableCode);
    }

    return isSystemdUnitKnown(DISTRESS_AUTOTUNE_TIMER_NAME);
}

function findAptGet(): ?string
{
    return findExecutable(['/usr/bin/apt-get', '/bin/apt-get', '/usr/bin/apt', '/bin/apt']);
}

function findVnstatBinary(): ?string
{
    return findExecutable(['/usr/bin/vnstat', '/bin/vnstat']);
}

function detectPrimaryNetworkInterface(): string
{
    $ip = findExecutable(['/usr/sbin/ip', '/usr/bin/ip', '/sbin/ip', '/bin/ip']);
    if ($ip !== null) {
        $output = runCommand(escapeshellarg($ip) . ' route show default', $code);
        if ($code === 0 && preg_match('/\bdev\s+([a-zA-Z0-9._:-]+)/', $output, $matches) === 1) {
            $iface = trim((string)($matches[1] ?? ''));
            if ($iface !== '' && $iface !== 'lo') {
                return $iface;
            }
        }
    }

    $netPaths = glob('/sys/class/net/*');
    if (is_array($netPaths)) {
        foreach ($netPaths as $path) {
            $iface = basename($path);
            if ($iface !== '' && $iface !== 'lo') {
                return $iface;
            }
        }
    }

    return VNSTAT_INTERFACE;
}

function isVnstatInstalled(): bool
{
    return findVnstatBinary() !== null;
}

function isVnstatInterfaceReady(string $iface = VNSTAT_INTERFACE): bool
{
    $vnstat = findVnstatBinary();
    if ($vnstat === null) {
        return false;
    }

    $output = runCommand(
        escapeshellarg($vnstat) . ' --oneline -i ' . escapeshellarg($iface),
        $code
    );
    return $code === 0 && trim($output) !== '';
}

function getVnstatStatus(): array
{
    $iface = detectPrimaryNetworkInterface();
    $installed = isVnstatInstalled();
    $serviceEnabled = false;
    $serviceActive = false;
    $databaseReady = false;

    if ($installed) {
        $systemctl = findSystemctl();
        if ($systemctl !== null) {
            runCommand(escapeshellarg($systemctl) . ' is-enabled vnstat.service', $enabledCode);
            runCommand(escapeshellarg($systemctl) . ' is-active vnstat.service', $activeCode);
            $serviceEnabled = $enabledCode === 0;
            $serviceActive = $activeCode === 0;
        }
        $databaseReady = isVnstatInterfaceReady($iface);
    }

    return [
        'ok' => true,
        'installed' => $installed,
        'iface' => $iface,
        'serviceEnabled' => $serviceEnabled,
        'serviceActive' => $serviceActive,
        'databaseReady' => $databaseReady,
        'ready' => $installed && $databaseReady,
    ];
}

function ensureVnstatInterfaceDatabase(string $iface = VNSTAT_INTERFACE): bool
{
    if (isVnstatInterfaceReady($iface)) {
        return true;
    }

    $vnstat = findVnstatBinary();
    if ($vnstat === null) {
        return false;
    }

    $ifaceArg = escapeshellarg($iface);
    $commands = [
        escapeshellarg($vnstat) . ' --add -i ' . $ifaceArg,
        escapeshellarg($vnstat) . ' --create -i ' . $ifaceArg,
    ];
    foreach ($commands as $command) {
        runCommand($command, $commandCode);
        if (isVnstatInterfaceReady($iface)) {
            return true;
        }
    }

    return isVnstatInterfaceReady($iface);
}

function installVnstat(): array
{
    $iface = detectPrimaryNetworkInterface();
    $alreadyInstalled = isVnstatInstalled();
    if (!$alreadyInstalled) {
        $apt = findAptGet();
        if ($apt === null) {
            return ['ok' => false, 'error' => 'apt_not_found'];
        }

        $cmd = 'DEBIAN_FRONTEND=noninteractive ' . escapeshellarg($apt) . ' install -y vnstat';
        $output = runCommand($cmd, $code);
        if ($code !== 0) {
            return ['ok' => false, 'error' => 'vnstat_install_failed', 'output' => $output];
        }
    }

    if (!isVnstatInstalled()) {
        return ['ok' => false, 'error' => 'vnstat_not_found_after_install'];
    }

    $systemctl = findSystemctl();
    if ($systemctl !== null) {
        runCommand(escapeshellarg($systemctl) . ' enable vnstat.service', $enableCode);
        runCommand(escapeshellarg($systemctl) . ' restart vnstat.service', $restartCode);
    }

    if (!ensureVnstatInterfaceDatabase($iface)) {
        return getVnstatStatus() + ['ok' => false, 'error' => 'vnstat_interface_init_failed'];
    }

    $status = getVnstatStatus();
    return $status + ['already' => $alreadyInstalled];
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

function findSystemctl(): ?string
{
    return findExecutable(['/usr/bin/systemctl', '/bin/systemctl']);
}

function getTimeSyncStatus(): array
{
    $timedatectl = findTimedatectl();
    $systemctl = findSystemctl();
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
    if ($ntpService === '' && $systemctl !== null) {
        $services = ['systemd-timesyncd', 'chronyd', 'ntp', 'ntpd'];
        foreach ($services as $service) {
            $serviceSafe = escapeshellarg($service . '.service');
            $state = trim(runCommand(escapeshellarg($systemctl) . " is-active $serviceSafe", $svcCode));
            if ($svcCode === 0 && $state !== '') {
                $ntpService = $service;
                break;
            }
        }
    }

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
    $systemctl = findSystemctl();
    if ($timedatectl === null) {
        return ['ok' => false, 'error' => 'timedatectl_not_found'];
    }

    $timesyncd = findExecutable(['/lib/systemd/systemd-timesyncd', '/usr/lib/systemd/systemd-timesyncd']);
    if ($timesyncd !== null && $systemctl !== null) {
        runCommand(escapeshellarg($systemctl) . ' enable systemd-timesyncd.service', $enableCode);
        runCommand(escapeshellarg($systemctl) . ' start systemd-timesyncd.service', $startCode);
    }

    runCommand(escapeshellarg($timedatectl) . ' set-timezone ' . escapeshellarg($timezone), $timezoneCode);
    if ($timezoneCode !== 0) {
        return ['ok' => false, 'error' => 'set_timezone_failed'];
    }

    runCommand(escapeshellarg($timedatectl) . ' set-ntp true', $ntpCode);
    if ($ntpCode !== 0) {
        return ['ok' => false, 'error' => 'set_ntp_failed'];
    }

    if ($timesyncd !== null && $systemctl !== null) {
        runCommand(escapeshellarg($systemctl) . ' restart systemd-timesyncd.service', $restartCode);
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

function findIwBinary(): ?string
{
    return findExecutable(['/usr/sbin/iw', '/usr/bin/iw', '/sbin/iw', '/bin/iw']);
}

function getWifiApInterface(): string
{
    return WIFI_AP_INTERFACE;
}

function normalizeDistressConcurrency($value): ?int
{
    if (is_int($value)) {
        $concurrency = $value;
    } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
        $concurrency = (int)trim($value);
    } else {
        return null;
    }

    if ($concurrency < DISTRESS_AUTOTUNE_MIN_CONCURRENCY) {
        return null;
    }

    return min(DISTRESS_AUTOTUNE_MAX_CONCURRENCY, $concurrency);
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

function getDistressCurrentConcurrency(): int
{
    $execStart = readServiceExecStart('distress');
    if (!is_string($execStart) || $execStart === '') {
        return DISTRESS_AUTOTUNE_MANUAL_DEFAULT_CONCURRENCY;
    }

    $value = getExecStartOptionValue($execStart, 'concurrency');
    $concurrency = normalizeDistressConcurrency($value);
    return $concurrency ?? DISTRESS_AUTOTUNE_MANUAL_DEFAULT_CONCURRENCY;
}

function setDistressCurrentConcurrency(int $concurrency): bool
{
    $execStart = readServiceExecStart('distress');
    if (!is_string($execStart) || $execStart === '') {
        return false;
    }

    $updatedExecStart = replaceExecStartOptionValue($execStart, 'concurrency', (string)$concurrency);
    if ($updatedExecStart === '') {
        return false;
    }

    return updateServiceExecStart('distress', $updatedExecStart);
}

function readDistressAutotuneState(): array
{
        $defaults = [
        'enabled' => true,
        'currentConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
        'lastAdjustedAt' => 0,
        'lastLoadAverage' => null,
        'lastRamFreePercent' => null,
    ];

    $raw = @file_get_contents(DISTRESS_AUTOTUNE_STATE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaults;
    }

    $currentConcurrency = normalizeDistressConcurrency($data['currentConcurrency'] ?? null)
        ?? getDistressCurrentConcurrency();
    $lastAdjustedAt = isset($data['lastAdjustedAt']) && is_int($data['lastAdjustedAt']) ? $data['lastAdjustedAt'] : 0;
    $lastLoadAverage = isset($data['lastLoadAverage']) && is_numeric($data['lastLoadAverage'])
        ? (float)$data['lastLoadAverage']
        : null;
    $lastRamFreePercent = isset($data['lastRamFreePercent']) && is_numeric($data['lastRamFreePercent'])
        ? (float)$data['lastRamFreePercent']
        : null;

    return [
        'enabled' => ($data['enabled'] ?? true) === true,
        'currentConcurrency' => $currentConcurrency,
        'lastAdjustedAt' => $lastAdjustedAt,
        'lastLoadAverage' => $lastLoadAverage,
        'lastRamFreePercent' => $lastRamFreePercent,
    ];
}

function writeDistressAutotuneState(array $state): bool
{
        $payload = json_encode([
            'enabled' => ($state['enabled'] ?? false) === true,
        'currentConcurrency' => (int)($state['currentConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY),
        'lastAdjustedAt' => (int)($state['lastAdjustedAt'] ?? 0),
        'lastLoadAverage' => isset($state['lastLoadAverage']) && is_numeric($state['lastLoadAverage'])
            ? (float)$state['lastLoadAverage']
            : null,
        'lastRamFreePercent' => isset($state['lastRamFreePercent']) && is_numeric($state['lastRamFreePercent'])
            ? (float)$state['lastRamFreePercent']
            : null,
    ], JSON_UNESCAPED_SLASHES);

    return is_string($payload) && @file_put_contents(DISTRESS_AUTOTUNE_STATE_FILE, $payload) !== false;
}

function acquireDistressAutotuneLock()
{
    $handle = @fopen(DISTRESS_AUTOTUNE_LOCK_FILE, 'c+');
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

function releaseDistressAutotuneLock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function resetDistressAutotuneBaseline(): bool
{
    $state = readDistressAutotuneState();
    if (($state['enabled'] ?? false) !== true) {
        return true;
    }

    if (!setDistressCurrentConcurrency(DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY)) {
        return false;
    }

    $state['currentConcurrency'] = DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY;
    $state['lastAdjustedAt'] = 0;
    $state['lastLoadAverage'] = null;
    $state['lastRamFreePercent'] = null;
    return writeDistressAutotuneState($state);
}

function getDistressAutotuneStatus(): array
{
    $state = readDistressAutotuneState();
    $currentConcurrency = getDistressCurrentConcurrency();
    if ($currentConcurrency !== (int)$state['currentConcurrency']) {
        $state['currentConcurrency'] = $currentConcurrency;
        writeDistressAutotuneState($state);
    }

    $enabled = ($state['enabled'] ?? false) === true;
    $serviceActive = serviceIsActive('distress');
    $cooldownRemaining = 0;
    $statusKey = 'distress_autotune_status_active';
    if (!$enabled) {
        $statusKey = 'distress_autotune_status_manual';
    } elseif (!$serviceActive) {
        $statusKey = 'distress_autotune_status_inactive';
    } else {
        $cooldownRemaining = max(0, DISTRESS_AUTOTUNE_COOLDOWN_SECONDS - (time() - (int)($state['lastAdjustedAt'] ?? 0)));
        if ($cooldownRemaining > 0) {
            $statusKey = 'distress_autotune_status_cooldown';
        }
    }

    return [
        'ok' => true,
        'enabled' => $enabled,
        'serviceActive' => $serviceActive,
        'currentConcurrency' => $currentConcurrency,
        'defaultConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
        'targetLoad' => DISTRESS_AUTOTUNE_TARGET_LOAD,
        'minFreeRamPercent' => DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT,
        'cooldownSeconds' => DISTRESS_AUTOTUNE_COOLDOWN_SECONDS,
        'cooldownRemaining' => $cooldownRemaining,
        'statusKey' => $statusKey,
        'lastAdjustedAt' => (int)($state['lastAdjustedAt'] ?? 0),
        'lastLoadAverage' => $state['lastLoadAverage'] ?? null,
        'lastRamFreePercent' => $state['lastRamFreePercent'] ?? null,
    ];
}

function setDistressAutotuneMode($enabledValue, $concurrencyValue): array
{
    ensureDistressAutotuneTimerInstalled();

    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        return ['ok' => false, 'error' => 'distress_autotune_lock_failed'];
    }

    $enabled = ($enabledValue === true || $enabledValue === '1' || $enabledValue === 1 || $enabledValue === 'true');
    $concurrency = normalizeDistressConcurrency($concurrencyValue);
    if ($concurrency === null) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'invalid_concurrency'];
    }

    if (!setDistressCurrentConcurrency($concurrency)) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'distress_concurrency_write_failed'];
    }

    $state = readDistressAutotuneState();
    $state['enabled'] = $enabled;
    $state['currentConcurrency'] = $concurrency;
    $state['lastAdjustedAt'] = 0;
    $state['lastLoadAverage'] = null;
    $state['lastRamFreePercent'] = null;
    if (!writeDistressAutotuneState($state)) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'distress_autotune_state_write_failed'];
    }

    releaseDistressAutotuneLock($lockHandle);
    return getDistressAutotuneStatus();
}

function roundDistressConcurrency(int $value): int
{
    $value = max(DISTRESS_AUTOTUNE_MIN_CONCURRENCY, min(DISTRESS_AUTOTUNE_MAX_CONCURRENCY, $value));
    $step = 64;
    $rounded = (int)(round($value / $step) * $step);
    return max(DISTRESS_AUTOTUNE_MIN_CONCURRENCY, min(DISTRESS_AUTOTUNE_MAX_CONCURRENCY, $rounded));
}

function adjustDistressConcurrencyByPercent(int $currentConcurrency, int $percent): int
{
    $next = (int)round($currentConcurrency * (100 + $percent) / 100);
    return roundDistressConcurrency($next);
}

function adjustDistressConcurrencyForRamFloor(int $currentConcurrency, float $ramFreePercent): int
{
    if ($ramFreePercent >= DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT) {
        return $currentConcurrency;
    }

    if ($ramFreePercent <= 0.0) {
        return DISTRESS_AUTOTUNE_MIN_CONCURRENCY;
    }

    // Approximate the new concurrency proportionally to the free RAM deficit.
    $target = (int)floor($currentConcurrency * ($ramFreePercent / DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT));
    $target = roundDistressConcurrency($target);

    if ($target >= $currentConcurrency) {
        $target = roundDistressConcurrency($currentConcurrency - 64);
    }

    return max(DISTRESS_AUTOTUNE_MIN_CONCURRENCY, min($target, $currentConcurrency));
}

function calculateDistressTargetConcurrency(int $currentConcurrency, float $loadAverage, float $ramFreePercent): int
{
    if ($ramFreePercent < DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT) {
        return adjustDistressConcurrencyForRamFloor($currentConcurrency, $ramFreePercent);
    }
    if ($ramFreePercent < DISTRESS_AUTOTUNE_RAM_RECOVERY_PERCENT) {
        return $currentConcurrency;
    }
    if ($loadAverage > 4.6) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, -20);
    }
    if ($loadAverage > DISTRESS_AUTOTUNE_TARGET_LOAD) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, -10);
    }
    if ($loadAverage >= DISTRESS_AUTOTUNE_DEAD_ZONE_LOWER) {
        return $currentConcurrency;
    }
    if ($loadAverage >= 3.0) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, 10);
    }
    if ($loadAverage >= 1.0) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, 15);
    }
    return adjustDistressConcurrencyByPercent($currentConcurrency, 20);
}

function distressAutotuneTick($loadAverage, $ramFreePercent): array
{
    if (!is_numeric($loadAverage)) {
        return ['ok' => false, 'error' => 'invalid_load_average'];
    }
    if (!is_numeric($ramFreePercent)) {
        return ['ok' => false, 'error' => 'invalid_ram_free_percent'];
    }

    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        return ['ok' => false, 'error' => 'distress_autotune_lock_failed'];
    }

    $load = max(0.0, (float)$loadAverage);
    $freeRam = max(0.0, min(100.0, (float)$ramFreePercent));
    $state = readDistressAutotuneState();
    if (($state['enabled'] ?? false) !== true) {
        releaseDistressAutotuneLock($lockHandle);
        return getDistressAutotuneStatus() + ['changed' => false, 'reason' => 'manual_mode'];
    }

    if (!serviceIsActive('distress')) {
        releaseDistressAutotuneLock($lockHandle);
        return getDistressAutotuneStatus() + ['changed' => false, 'reason' => 'distress_inactive'];
    }

    $currentConcurrency = getDistressCurrentConcurrency();
    $state['currentConcurrency'] = $currentConcurrency;
    $state['lastLoadAverage'] = $load;
    $state['lastRamFreePercent'] = $freeRam;
    $now = time();
    if (($now - (int)($state['lastAdjustedAt'] ?? 0)) < DISTRESS_AUTOTUNE_COOLDOWN_SECONDS) {
        writeDistressAutotuneState($state);
        releaseDistressAutotuneLock($lockHandle);
        return getDistressAutotuneStatus() + ['changed' => false, 'reason' => 'cooldown'];
    }

    $targetConcurrency = calculateDistressTargetConcurrency($currentConcurrency, $load, $freeRam);

    if ($targetConcurrency === $currentConcurrency) {
        writeDistressAutotuneState($state);
        releaseDistressAutotuneLock($lockHandle);
        return getDistressAutotuneStatus() + ['changed' => false, 'reason' => 'within_range'];
    }

    if (!setDistressCurrentConcurrency($targetConcurrency)) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'distress_concurrency_write_failed'];
    }

    $restart = serviceRestart('distress');
    if (($restart['ok'] ?? false) !== true) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'service_restart_failed'];
    }

    $state['currentConcurrency'] = $targetConcurrency;
    $state['lastAdjustedAt'] = $now;
    $state['lastLoadAverage'] = $load;
    if (!writeDistressAutotuneState($state)) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'distress_autotune_state_write_failed'];
    }

    releaseDistressAutotuneLock($lockHandle);
    return getDistressAutotuneStatus() + [
        'changed' => true,
        'previousConcurrency' => $currentConcurrency,
        'loadAverage' => $load,
        'ramFreePercent' => $freeRam,
    ];
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

function normalizeTrafficPercentValue($value): ?int
{
    if (is_int($value)) {
        $percent = $value;
    } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
        $percent = (int)trim($value);
    } else {
        return null;
    }

    if ($percent < 25 || $percent > 100) {
        return null;
    }

    return $percent;
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

function readTrafficLimitStateFile(): ?array
{
    $default = trafficLimitStateDefault();
    $raw = @file_get_contents(TRAFFIC_LIMIT_STATE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $percent = normalizeTrafficPercentValue($data['percent'] ?? null);
    $iface = (string)($data['iface'] ?? $default['iface']);
    if ($iface !== 'eth0' || $percent === null) {
        return null;
    }
    return [
        'ok' => true,
        'iface' => 'eth0',
        'percent' => $percent,
        'mbit' => trafficLimitPercentToMbit($percent),
        'source' => 'state',
    ];
}

function getTrafficLimitState(): array
{
    $tcState = readTrafficLimitFromTc();
    if ($tcState !== null) {
        return $tcState;
    }

    $stateFile = readTrafficLimitStateFile();
    if ($stateFile !== null) {
        $restored = setTrafficLimit((int)$stateFile['percent']);
        if (($restored['ok'] ?? false) === true) {
            return $restored + ['source' => 'state'];
        }
        return $restored + [
            'desiredPercent' => (int)$stateFile['percent'],
            'desiredMbit' => trafficLimitPercentToMbit((int)$stateFile['percent']),
            'source' => 'state',
        ];
    }

    $default = trafficLimitStateDefault();
    $initialized = setTrafficLimit((int)$default['percent']);
    if (($initialized['ok'] ?? false) === true) {
        return $initialized + ['source' => 'default'];
    }

    return $initialized + [
        'desiredPercent' => (int)$default['percent'],
        'desiredMbit' => (int)$default['mbit'],
        'source' => 'default',
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

function getTrafficLimitRollbackSnapshot(): ?array
{
    $state = readTrafficLimitFromTc();
    if ($state === null) {
        $state = readTrafficLimitStateFile();
    }

    $source = (string)($state['source'] ?? '');
    $percent = normalizeTrafficPercentValue($state['percent'] ?? null);
    if (($source !== 'tc' && $source !== 'state') || $percent === null) {
        return null;
    }

    return [
        'percent' => $percent,
        'source' => $source,
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

function waitForTimeSyncReady(int $maxWaitSeconds = 180): void
{
    $deadline = time() + max(0, $maxWaitSeconds);
    do {
        $status = getTimeSyncStatus();
        if (($status['ok'] ?? false) === true && ($status['ntpSynchronized'] ?? false) === true) {
            return;
        }
        sleep(5);
    } while (time() < $deadline);
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
            'powerPercent' => normalizeTrafficPercentValue($entry['powerPercent'] ?? null),
        ];
    }

    respond(setScheduleEntries($modules, $normalized));
    exit(0);
}

if ($action === 'schedule_activate') {
    $module = $request['module'] ?? null;
    $percent = normalizeTrafficPercentValue($request['percent'] ?? null);
    if (!is_string($module) || !in_array($module, $modules, true)) {
        fail('invalid_module');
    }
    if ($percent === null) {
        fail('invalid_traffic_limit_percent');
    }
    if (hasScheduleManualOverride()) {
        respond(['ok' => true, 'skipped' => true, 'manualOverride' => true]);
        exit(0);
    }
    respond(applyExclusiveModuleState($modules, $module, $percent));
    exit(0);
}

if ($action === 'schedule_deactivate') {
    $module = $request['module'] ?? null;
    if (!is_string($module) || !in_array($module, $modules, true)) {
        fail('invalid_module');
    }
    if (hasScheduleManualOverride()) {
        respond(['ok' => true, 'skipped' => true, 'manualOverride' => true]);
        exit(0);
    }
    if (serviceIsActive($module)) {
        respond(applyExclusiveModuleState($modules, null));
    } else {
        respond(['ok' => true, 'stopped' => false]);
    }
    exit(0);
}

if ($action === 'schedule_boot_sync') {
    respond(scheduleBootSync($modules));
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
    respond(serviceStop($modules, $module));
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
    $scheduleEntry = getActiveScheduleControlEntry($modules);
    if ($scheduleEntry !== null) {
        $scheduledPercent = (int)$scheduleEntry['powerPercent'];
        $state = getTrafficLimitState();
        if (($state['ok'] ?? false) !== true || normalizeTrafficPercentValue($state['percent'] ?? null) !== $scheduledPercent) {
            $state = setTrafficLimit($scheduledPercent);
        }
        respond($state + [
            'scheduleLocked' => true,
            'schedulePercent' => $scheduledPercent,
            'scheduleModule' => (string)$scheduleEntry['module'],
        ]);
        exit(0);
    }

    respond(getTrafficLimitState() + ['scheduleLocked' => false]);
    exit(0);
}

if ($action === 'traffic_limit_set') {
    $percent = (int)($request['percent'] ?? 0);
    $scheduleEntry = getActiveScheduleControlEntry($modules);
    if ($scheduleEntry !== null) {
        $scheduledPercent = (int)$scheduleEntry['powerPercent'];
        $state = getTrafficLimitState();
        if (($state['ok'] ?? false) !== true || normalizeTrafficPercentValue($state['percent'] ?? null) !== $scheduledPercent) {
            $state = setTrafficLimit($scheduledPercent);
        }
        respond([
            'ok' => false,
            'error' => 'schedule_power_locked',
            'scheduleLocked' => true,
            'schedulePercent' => $scheduledPercent,
            'scheduleModule' => (string)$scheduleEntry['module'],
            'currentPercent' => (int)($state['percent'] ?? $scheduledPercent),
            'currentMbit' => (int)($state['mbit'] ?? trafficLimitPercentToMbit($scheduledPercent)),
        ]);
        exit(0);
    }

    respond(setTrafficLimit($percent));
    exit(0);
}

if ($action === 'vnstat_status') {
    respond(getVnstatStatus());
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

if ($action === 'wifi_txpower_get') {
    respond(readWifiTxPower());
    exit(0);
}

if ($action === 'wifi_txpower_set') {
    respond(setWifiTxPower($request['dbm'] ?? null));
    exit(0);
}

if ($action === 'wifi_ap_name_get') {
    respond(readWifiApName());
    exit(0);
}

if ($action === 'wifi_ap_name_set') {
    respond(setWifiApName($request['ssid'] ?? null));
    exit(0);
}

if ($action === 'distress_autotune_get') {
    respond(getDistressAutotuneStatus());
    exit(0);
}

if ($action === 'distress_autotune_set') {
    respond(setDistressAutotuneMode($request['enabled'] ?? null, $request['concurrency'] ?? null));
    exit(0);
}

if ($action === 'distress_autotune_tick') {
    respond(distressAutotuneTick($request['loadAverage'] ?? null, $request['ramFreePercent'] ?? null));
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
