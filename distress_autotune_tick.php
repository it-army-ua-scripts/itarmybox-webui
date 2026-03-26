<?php
require_once 'lib/root_helper_client.php';

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

$cpuPercent = read_cpu_usage_percent();
if ($cpuPercent === null) {
    fwrite(STDERR, "cpu unavailable\n");
    exit(1);
}

$modules = (require 'config/config.php')['daemonNames'];
if (!is_array($modules) || $modules === []) {
    fwrite(STDERR, "invalid modules\n");
    exit(1);
}

$response = root_helper_request([
    'action' => 'distress_autotune_tick',
    'modules' => $modules,
    'cpuPercent' => $cpuPercent,
]);

exit((($response['ok'] ?? false) === true) ? 0 : 1);
