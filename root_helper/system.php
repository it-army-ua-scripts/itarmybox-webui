<?php

declare(strict_types=1);

const SYSTEM_HEALTH_LOG_FILE = ROOT_HELPER_LOG_DIR . '/system-health.log';

function readSystemHealthTextFile(string $path): ?string
{
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return null;
    }

    $value = trim($raw);
    return $value !== '' ? $value : null;
}

function readSystemHealthUptimeSeconds(): ?int
{
    $raw = readSystemHealthTextFile('/proc/uptime');
    if ($raw === null || preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)/', $raw, $matches) !== 1) {
        return null;
    }

    return max(0, (int)floor((float)$matches[1]));
}

function readSystemHealthLoadAverage(): array
{
    $raw = readSystemHealthTextFile('/proc/loadavg');
    if ($raw === null || preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s+([0-9]+(?:\.[0-9]+)?)\s+([0-9]+(?:\.[0-9]+)?)/', $raw, $matches) !== 1) {
        return ['load1' => null, 'load5' => null, 'load15' => null];
    }

    return [
        'load1' => (float)$matches[1],
        'load5' => (float)$matches[2],
        'load15' => (float)$matches[3],
    ];
}

function readSystemHealthMemoryInfo(): array
{
    $raw = @file_get_contents('/proc/meminfo');
    if (!is_string($raw) || trim($raw) === '') {
        return [
            'memTotalKb' => null,
            'memAvailableKb' => null,
            'memAvailablePercent' => null,
            'swapTotalKb' => null,
            'swapFreeKb' => null,
            'swapFreePercent' => null,
        ];
    }

    $readValue = static function (string $pattern) use ($raw): ?int {
        return preg_match($pattern, $raw, $matches) === 1 ? max(0, (int)$matches[1]) : null;
    };

    $memTotalKb = $readValue('/^MemTotal:\s+(\d+)\s+kB$/mi');
    $memAvailableKb = $readValue('/^MemAvailable:\s+(\d+)\s+kB$/mi');
    $swapTotalKb = $readValue('/^SwapTotal:\s+(\d+)\s+kB$/mi');
    $swapFreeKb = $readValue('/^SwapFree:\s+(\d+)\s+kB$/mi');

    return [
        'memTotalKb' => $memTotalKb,
        'memAvailableKb' => $memAvailableKb,
        'memAvailablePercent' => ($memTotalKb !== null && $memTotalKb > 0 && $memAvailableKb !== null)
            ? max(0.0, min(100.0, ($memAvailableKb / $memTotalKb) * 100.0))
            : null,
        'swapTotalKb' => $swapTotalKb,
        'swapFreeKb' => $swapFreeKb,
        'swapFreePercent' => ($swapTotalKb !== null && $swapTotalKb > 0 && $swapFreeKb !== null)
            ? max(0.0, min(100.0, ($swapFreeKb / $swapTotalKb) * 100.0))
            : null,
    ];
}

function readSystemHealthPsiResource(string $resource): array
{
    $path = '/proc/pressure/' . $resource;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return ['someAvg10' => null, 'fullAvg10' => null];
    }

    $someAvg10 = preg_match('/^some\s+avg10=([0-9]+(?:\.[0-9]+)?)/mi', $raw, $someMatches) === 1
        ? (float)$someMatches[1]
        : null;
    $fullAvg10 = preg_match('/^full\s+avg10=([0-9]+(?:\.[0-9]+)?)/mi', $raw, $fullMatches) === 1
        ? (float)$fullMatches[1]
        : null;

    return ['someAvg10' => $someAvg10, 'fullAvg10' => $fullAvg10];
}

function readSystemHealthThermalZones(int $limit = 4): array
{
    $paths = glob('/sys/class/thermal/thermal_zone*');
    if (!is_array($paths) || $paths === []) {
        return [];
    }

    $zones = [];
    foreach ($paths as $path) {
        $type = readSystemHealthTextFile($path . '/type') ?? basename($path);
        $tempRaw = readSystemHealthTextFile($path . '/temp');
        if ($tempRaw === null || !is_numeric($tempRaw)) {
            continue;
        }

        $tempValue = (float)$tempRaw;
        $tempC = $tempValue > 1000.0 ? ($tempValue / 1000.0) : $tempValue;
        $zones[] = [
            'type' => $type,
            'tempC' => round($tempC, 1),
        ];
    }

    usort($zones, static fn(array $a, array $b): int => ($b['tempC'] <=> $a['tempC']));
    if (count($zones) > $limit) {
        $zones = array_slice($zones, 0, $limit);
    }
    return $zones;
}

function collectSystemHealthSnapshot(array $modules): array
{
    $load = readSystemHealthLoadAverage();
    $memory = readSystemHealthMemoryInfo();
    $cpuPsi = readSystemHealthPsiResource('cpu');
    $memoryPsi = readSystemHealthPsiResource('memory');
    $ioPsi = readSystemHealthPsiResource('io');
    $activeModules = function_exists('getActiveModules') ? getActiveModules($modules) : [];
    $activeModulePids = [];
    if (function_exists('getServiceMainPid')) {
        foreach ($activeModules as $module) {
            if (!is_string($module) || $module === '') {
                continue;
            }
            $pid = getServiceMainPid($module);
            if ($pid !== null) {
                $activeModulePids[$module] = $pid;
            }
        }
    }

    return [
        'uptimeSeconds' => readSystemHealthUptimeSeconds(),
        'load1' => $load['load1'],
        'load5' => $load['load5'],
        'load15' => $load['load15'],
        'memTotalKb' => $memory['memTotalKb'],
        'memAvailableKb' => $memory['memAvailableKb'],
        'memAvailablePercent' => $memory['memAvailablePercent'],
        'swapTotalKb' => $memory['swapTotalKb'],
        'swapFreeKb' => $memory['swapFreeKb'],
        'swapFreePercent' => $memory['swapFreePercent'],
        'cpuPsiSomeAvg10' => $cpuPsi['someAvg10'],
        'memoryPsiSomeAvg10' => $memoryPsi['someAvg10'],
        'memoryPsiFullAvg10' => $memoryPsi['fullAvg10'],
        'ioPsiSomeAvg10' => $ioPsi['someAvg10'],
        'ioPsiFullAvg10' => $ioPsi['fullAvg10'],
        'thermalZones' => readSystemHealthThermalZones(),
        'activeModules' => $activeModules,
        'activeModulePids' => $activeModulePids,
        'distressPid' => in_array('distress', $activeModules, true) ? getServiceMainPid('distress') : null,
    ];
}

function logSystemHealthSnapshot(array $modules, string $event, array $extra = []): array
{
    $snapshot = collectSystemHealthSnapshot($modules) + ['event' => $event] + $extra;
    $payload = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($payload)) {
        writeDebugLogLine(SYSTEM_HEALTH_LOG_FILE, $payload);
    }

    return [
        'ok' => true,
        'logFile' => SYSTEM_HEALTH_LOG_FILE,
        'snapshot' => $snapshot,
    ];
}

function appendRebootLog(string $message): void
{
    $line = '[' . date('c') . '] ' . $message . "\n";
    @file_put_contents('/tmp/itarmybox-reboot.log', $line, FILE_APPEND);
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

function runSystemUpdate(?string $branch = null): array
{
    if (!is_file(UPDATE_SCRIPT_PATH) || !is_readable(UPDATE_SCRIPT_PATH)) {
        return ['ok' => false, 'error' => 'update_script_not_found'];
    }

    $envPrefix = '';
    if ($branch !== null) {
        $normalizedBranch = trim($branch);
        if ($normalizedBranch !== 'main' && $normalizedBranch !== 'dev') {
            return ['ok' => false, 'error' => 'invalid_branch'];
        }
        $envPrefix = 'ITARMYBOX_UPDATE_BRANCH=' . escapeshellarg($normalizedBranch) . ' ';
    }

    $output = runCommandVerbose(
        $envPrefix . 'ITARMYBOX_SKIP_ROOT_HELPER_REFRESH=1 /usr/bin/env bash ' . escapeshellarg(UPDATE_SCRIPT_PATH),
        $code
    );
    return [
        'ok' => $code === 0,
        'error' => $code === 0 ? null : 'update_failed',
        'output' => $output,
    ];
}
