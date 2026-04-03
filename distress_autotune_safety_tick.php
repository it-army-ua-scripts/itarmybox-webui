<?php
require_once 'lib/root_helper_client.php';

function read_load_average_1m(): ?float
{
    $raw = @file_get_contents('/proc/loadavg');
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    if (preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)/', $raw, $matches) !== 1) {
        return null;
    }
    return (float)$matches[1];
}

function read_free_ram_percent(): ?float
{
    $raw = @file_get_contents('/proc/meminfo');
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    if (
        preg_match('/^MemTotal:\s+(\d+)\s+kB$/mi', $raw, $totalMatches) !== 1
        || preg_match('/^MemAvailable:\s+(\d+)\s+kB$/mi', $raw, $availableMatches) !== 1
    ) {
        return null;
    }

    $memTotalKb = (float)$totalMatches[1];
    $memAvailableKb = (float)$availableMatches[1];
    if ($memTotalKb <= 0.0 || $memAvailableKb < 0.0) {
        return null;
    }

    return max(0.0, min(100.0, ($memAvailableKb / $memTotalKb) * 100.0));
}

$loadAverage = read_load_average_1m();
if ($loadAverage === null) {
    fwrite(STDERR, "load average unavailable\n");
    exit(1);
}

$ramFreePercent = read_free_ram_percent();
if ($ramFreePercent === null) {
    fwrite(STDERR, "free RAM percent unavailable\n");
    exit(1);
}

$modules = (require 'config/config.php')['daemonNames'];
if (!is_array($modules) || $modules === []) {
    fwrite(STDERR, "invalid modules\n");
    exit(1);
}

$response = root_helper_request([
    'action' => 'distress_autotune_safety_tick',
    'modules' => $modules,
    'loadAverage' => $loadAverage,
    'ramFreePercent' => $ramFreePercent,
]);

exit((($response['ok'] ?? false) === true) ? 0 : 1);
