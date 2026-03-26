<?php
require_once 'lib/root_helper_client.php';
header('Content-Type: application/json; charset=UTF-8');

function read_meminfo(): array
{
    $raw = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $result = [];
    if (!is_array($raw)) {
        return $result;
    }
    foreach ($raw as $line) {
        if (preg_match('/^([A-Za-z_]+):\s+(\d+)/', $line, $matches) === 1) {
            $result[$matches[1]] = (int)$matches[2];
        }
    }
    return $result;
}

function read_cpu_sample(): ?array
{
    $raw = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($raw) || !isset($raw[0])) {
        return null;
    }
    if (preg_match('/^cpu\s+(.+)$/', $raw[0], $matches) !== 1) {
        return null;
    }
    $parts = preg_split('/\s+/', trim($matches[1]));
    if (!is_array($parts) || count($parts) < 4) {
        return null;
    }
    $values = array_map('intval', $parts);
    $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
    $total = array_sum($values);
    return ['idle' => $idle, 'total' => $total];
}

function read_cpu_usage_percent(): ?float
{
    $first = read_cpu_sample();
    if ($first === null) {
        return null;
    }
    usleep(120000);
    $second = read_cpu_sample();
    if ($second === null) {
        return null;
    }
    $totalDelta = $second['total'] - $first['total'];
    $idleDelta = $second['idle'] - $first['idle'];
    if ($totalDelta <= 0) {
        return null;
    }
    return max(0, min(100, 100 * ($totalDelta - $idleDelta) / $totalDelta));
}

function read_temperature_celsius(): ?float
{
    $paths = glob('/sys/class/thermal/thermal_zone*/temp');
    if (!is_array($paths)) {
        return null;
    }
    foreach ($paths as $path) {
        $raw = trim((string)@file_get_contents($path));
        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            continue;
        }
        $value = (int)$raw;
        if ($value > 1000) {
            return $value / 1000;
        }
        if ($value > 0) {
            return (float)$value;
        }
    }
    return null;
}

function read_memory_temperature_celsius(): ?float
{
    $paths = glob('/sys/class/hwmon/hwmon*/temp*_input');
    if (!is_array($paths)) {
        $paths = [];
    }

    foreach ($paths as $inputPath) {
        $hwmonDir = dirname($inputPath);
        $labelPath = preg_replace('/_input$/', '_label', $inputPath);
        $typePath = preg_replace('/_input$/', '_type', $inputPath);
        $namePath = $hwmonDir . '/name';
        $label = is_string($labelPath) ? strtolower(trim((string)@file_get_contents($labelPath))) : '';
        $type = is_string($typePath) ? strtolower(trim((string)@file_get_contents($typePath))) : '';
        $name = strtolower(trim((string)@file_get_contents($namePath)));
        $sensorMeta = $label . ' ' . $type . ' ' . $name;
        if (
            $sensorMeta === '' ||
            (
                strpos($sensorMeta, 'mem') === false &&
                strpos($sensorMeta, 'ddr') === false &&
                strpos($sensorMeta, 'ram') === false &&
                strpos($sensorMeta, 'dram') === false &&
                strpos($sensorMeta, 'memory') === false
            )
        ) {
            continue;
        }
        $raw = trim((string)@file_get_contents($inputPath));
        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            continue;
        }
        $value = (int)$raw;
        if ($value > 1000) {
            return $value / 1000;
        }
        if ($value > 0) {
            return (float)$value;
        }
    }

    $zonePaths = glob('/sys/class/thermal/thermal_zone*/temp');
    if (!is_array($zonePaths)) {
        return null;
    }
    foreach ($zonePaths as $tempPath) {
        $zoneDir = dirname($tempPath);
        $type = strtolower(trim((string)@file_get_contents($zoneDir . '/type')));
        if (
            $type === '' ||
            (
                strpos($type, 'mem') === false &&
                strpos($type, 'ddr') === false &&
                strpos($type, 'ram') === false &&
                strpos($type, 'dram') === false &&
                strpos($type, 'memory') === false
            )
        ) {
            continue;
        }
        $raw = trim((string)@file_get_contents($tempPath));
        if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
            continue;
        }
        $value = (int)$raw;
        if ($value > 1000) {
            return $value / 1000;
        }
        if ($value > 0) {
            return (float)$value;
        }
    }

    return null;
}

function read_interface_ipv4(string $iface): ?string
{
    $ipPath = null;
    foreach (['/usr/sbin/ip', '/usr/bin/ip', '/sbin/ip', '/bin/ip'] as $candidate) {
        if (is_executable($candidate)) {
            $ipPath = $candidate;
            break;
        }
    }
    if ($ipPath === null) {
        return null;
    }

    $output = @shell_exec($ipPath . ' -4 -o addr show dev ' . escapeshellarg($iface) . ' 2>/dev/null');
    if (!is_string($output) || trim($output) === '') {
        return null;
    }
    if (preg_match('/inet\s+([0-9.]+)\//', $output, $matches) === 1) {
        return $matches[1];
    }
    return null;
}

function format_rate_from_bytes(float $bytesPerSecond): string
{
    if ($bytesPerSecond < 0) {
        $bytesPerSecond = 0;
    }
    $bitsPerSecond = $bytesPerSecond * 8;
    $units = ['bit/s', 'Kbit/s', 'Mbit/s', 'Gbit/s'];
    $value = $bitsPerSecond;
    $unitIdx = 0;
    while ($value >= 1000 && $unitIdx < count($units) - 1) {
        $value /= 1000;
        $unitIdx++;
    }
    return number_format($value, $value >= 100 ? 0 : ($value >= 10 ? 1 : 2), '.', '') . ' ' . $units[$unitIdx];
}

function read_tx_rate_from_sysfs(string $iface): ?string
{
    $path = '/sys/class/net/' . $iface . '/statistics/tx_bytes';
    if (!is_readable($path)) {
        return null;
    }
    $rawBytes = trim((string)@file_get_contents($path));
    if ($rawBytes === '' || !preg_match('/^\d+$/', $rawBytes)) {
        return null;
    }
    $currentBytes = (float)$rawBytes;
    $statePath = '/tmp/itarmybox-monitor-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $iface) . '-tx.json';
    $previousRaw = @file_get_contents($statePath);
    $previous = is_string($previousRaw) ? json_decode($previousRaw, true) : null;
    $now = microtime(true);
    @file_put_contents($statePath, json_encode(['bytes' => $currentBytes, 'time' => $now], JSON_UNESCAPED_SLASHES));
    if (!is_array($previous)) {
        return null;
    }
    $previousBytes = (float)($previous['bytes'] ?? 0);
    $previousTime = (float)($previous['time'] ?? 0);
    $timeDelta = $now - $previousTime;
    if ($timeDelta <= 0) {
        return null;
    }
    return format_rate_from_bytes(($currentBytes - $previousBytes) / $timeDelta);
}

function read_tx_rate_vnstat(string $iface): ?string
{
    $vnstatPath = is_executable('/usr/bin/vnstat') ? '/usr/bin/vnstat' : (is_executable('/bin/vnstat') ? '/bin/vnstat' : null);
    if ($vnstatPath === null) {
        return null;
    }
    $ifaceArg = escapeshellarg($iface);
    $output = @shell_exec($vnstatPath . ' -tr 2 -i ' . $ifaceArg . ' 2>/dev/null');
    if (!is_string($output) || trim($output) === '') {
        return null;
    }
    if (preg_match('/^\s*tx\s+([0-9.]+\s+[kMGT]?bit\/s)/mi', $output, $matches) === 1) {
        return trim($matches[1]);
    }
    return null;
}

function read_today_tx_vnstat(string $iface): ?string
{
    $vnstatPath = is_executable('/usr/bin/vnstat') ? '/usr/bin/vnstat' : (is_executable('/bin/vnstat') ? '/bin/vnstat' : null);
    if ($vnstatPath === null) {
        return null;
    }
    $ifaceArg = escapeshellarg($iface);
    $output = @shell_exec($vnstatPath . ' -i ' . $ifaceArg . ' --oneline 2>/dev/null');
    if (!is_string($output) || trim($output) === '') {
        return null;
    }
    $parts = explode(';', trim($output));
    if (!isset($parts[4])) {
        return null;
    }
    $value = trim($parts[4]);
    return $value !== '' ? $value : null;
}

$iface = 'eth0';
$meminfo = read_meminfo();
$memTotal = (int)($meminfo['MemTotal'] ?? 0);
$memAvailable = (int)($meminfo['MemAvailable'] ?? 0);
$ramPercent = null;
if ($memTotal > 0) {
    $ramPercent = 100 * (($memTotal - $memAvailable) / $memTotal);
}

$txRate = read_tx_rate_vnstat($iface);
$txSource = 'vnstat';
if ($txRate === null) {
    $txRate = read_tx_rate_from_sysfs($iface);
    $txSource = 'sysfs';
}

$cpuPercent = read_cpu_usage_percent();

echo json_encode([
    'ok' => true,
    'iface' => $iface,
    'txRate' => $txRate,
    'todayTx' => read_today_tx_vnstat($iface),
    'txSource' => $txSource,
    'ramPercent' => $ramPercent,
    'cpuPercent' => $cpuPercent,
    'temperatureC' => read_temperature_celsius(),
    'memoryTemperatureC' => read_memory_temperature_celsius(),
    'ipv4' => read_interface_ipv4($iface),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
