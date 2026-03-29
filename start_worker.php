<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/start_helpers.php';

$daemon = isset($argv[1]) && is_string($argv[1]) ? trim($argv[1]) : '';
$config = require __DIR__ . '/config/config.php';

write_start_debug_log('start_worker_invoked', [
    'daemon' => $daemon,
    'argv' => $argv,
]);

if ($daemon === '' || !in_array($daemon, (array)($config['daemonNames'] ?? []), true)) {
    write_start_debug_log('start_worker_invalid_daemon', [
        'daemon' => $daemon,
        'allowed' => array_values((array)($config['daemonNames'] ?? [])),
    ]);
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
write_start_debug_log('start_worker_marked_running', [
    'daemon' => $daemon,
    'state' => read_start_task_state(),
]);

$result = start_module_request($daemon, $config);
write_start_debug_log('start_worker_result', [
    'daemon' => $daemon,
    'result' => $result,
]);
complete_start_task_state($daemon, (($result['ok'] ?? false) === true), isset($result['error']) ? (string)$result['error'] : null);

exit((($result['ok'] ?? false) === true) ? 0 : 1);
