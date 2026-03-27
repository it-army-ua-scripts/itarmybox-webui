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

$loadAverage = read_load_average_1m();
if ($loadAverage === null) {
    fwrite(STDERR, "load average unavailable\n");
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
    'loadAverage' => $loadAverage,
]);

exit((($response['ok'] ?? false) === true) ? 0 : 1);
