<?php
require_once 'lib/root_helper_client.php';

function read_pressure_snapshot(string $path): ?array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $snapshot = [];
    if (preg_match('/^some\s+avg10=([0-9]+(?:\.[0-9]+)?)/mi', $raw, $someMatches) === 1) {
        $snapshot['someAvg10'] = (float)$someMatches[1];
    }
    if (preg_match('/^full\s+avg10=([0-9]+(?:\.[0-9]+)?)/mi', $raw, $fullMatches) === 1) {
        $snapshot['fullAvg10'] = (float)$fullMatches[1];
    }

    return isset($snapshot['someAvg10']) ? $snapshot : null;
}

$cpuPressure = read_pressure_snapshot('/proc/pressure/cpu');
if ($cpuPressure === null) {
    fwrite(STDERR, "cpu pressure unavailable\n");
    exit(1);
}

$memoryPressure = read_pressure_snapshot('/proc/pressure/memory');
if ($memoryPressure === null) {
    fwrite(STDERR, "memory pressure unavailable\n");
    exit(1);
}

$ioPressure = read_pressure_snapshot('/proc/pressure/io');
if ($ioPressure === null) {
    fwrite(STDERR, "io pressure unavailable\n");
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
    'cpuPressure' => $cpuPressure,
    'memoryPressure' => $memoryPressure,
    'ioPressure' => $ioPressure,
]);

exit((($response['ok'] ?? false) === true) ? 0 : 1);
