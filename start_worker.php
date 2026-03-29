<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/start_helpers.php';

$daemon = isset($argv[1]) && is_string($argv[1]) ? trim($argv[1]) : '';
$config = require __DIR__ . '/config/config.php';

if ($daemon === '' || !in_array($daemon, (array)($config['daemonNames'] ?? []), true)) {
    exit(1);
}

write_start_task_state([
    'status' => 'running',
    'daemon' => $daemon,
    'messageKey' => null,
    'error' => null,
    'startedAt' => (int)(read_start_task_state()['startedAt'] ?? time()),
    'finishedAt' => null,
]);

$result = start_module_request($daemon, $config);
complete_start_task_state($daemon, (($result['ok'] ?? false) === true), isset($result['error']) ? (string)$result['error'] : null);

exit((($result['ok'] ?? false) === true) ? 0 : 1);
