<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/root_helper_client.php';

$modules = (require __DIR__ . '/config/config.php')['daemonNames'] ?? null;
if (!is_array($modules) || $modules === []) {
    fwrite(STDERR, "invalid modules\n");
    exit(1);
}

$response = root_helper_request([
    'action' => 'distress_service_prepare',
    'modules' => $modules,
]);

exit((($response['ok'] ?? false) === true) ? 0 : 1);
