<?php

if (!function_exists('root_helper_request')) {
    require_once __DIR__ . '/root_helper_client.php';
}
if (!function_exists('getConfigStringFromServiceFile') || !function_exists('updateServiceFile')) {
    require_once __DIR__ . '/tool_helpers.php';
}
if (!function_exists('getDistressAutotuneSettings')) {
    require_once __DIR__ . '/tool_distress_helpers.php';
}

const DISTRESS_START_TASK_FILE = __DIR__ . '/../var/state/distress-start-task.json';

function ensure_start_task_directory(): bool
{
    $dir = dirname(DISTRESS_START_TASK_FILE);
    return is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir);
}

function read_start_task_state(): array
{
    $raw = @file_get_contents(DISTRESS_START_TASK_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return [
            'status' => 'idle',
            'daemon' => null,
            'messageKey' => null,
            'error' => null,
            'startedAt' => null,
            'finishedAt' => null,
        ];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [
            'status' => 'idle',
            'daemon' => null,
            'messageKey' => null,
            'error' => null,
            'startedAt' => null,
            'finishedAt' => null,
        ];
    }

    return $data;
}

function write_start_task_state(array $state): bool
{
    if (!ensure_start_task_directory()) {
        return false;
    }

    $payload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return false;
    }

    $tmpPath = DISTRESS_START_TASK_FILE . '.tmp';
    if (@file_put_contents($tmpPath, $payload) === false) {
        return false;
    }

    if (!@rename($tmpPath, DISTRESS_START_TASK_FILE)) {
        @unlink($tmpPath);
        return false;
    }

    return true;
}

function reset_start_task_state(string $daemon): bool
{
    return write_start_task_state([
        'status' => 'pending',
        'daemon' => $daemon,
        'messageKey' => null,
        'error' => null,
        'startedAt' => time(),
        'finishedAt' => null,
    ]);
}

function complete_start_task_state(string $daemon, bool $ok, ?string $error = null): bool
{
    return write_start_task_state([
        'status' => $ok ? 'success' : 'failed',
        'daemon' => $daemon,
        'messageKey' => $ok ? 'start_requested' : 'start_failed',
        'error' => $error,
        'startedAt' => (int)(read_start_task_state()['startedAt'] ?? time()),
        'finishedAt' => time(),
    ]);
}

function start_module_request(string $daemon, array $config): array
{
    $response = root_helper_request([
        'action' => 'service_activate_exclusive',
        'modules' => $config['daemonNames'],
        'selected' => $daemon,
    ]);

    return [
        'ok' => (($response['ok'] ?? false) === true),
        'messageKey' => (($response['ok'] ?? false) === true) ? 'start_requested' : 'start_failed',
        'error' => $response['error'] ?? null,
    ];
}

function is_distress_auto_start(string $daemon): bool
{
    if ($daemon !== 'distress') {
        return false;
    }

    $autotuneSettings = getDistressAutotuneSettings();
    return (($autotuneSettings['ok'] ?? false) === true) && (($autotuneSettings['enabled'] ?? false) === true);
}

function find_php_cli_for_background_start(): ?string
{
    $candidates = [];
    if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }
    array_push($candidates, '/usr/bin/php', '/usr/local/bin/php', '/bin/php');

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function spawn_distress_start_worker(): bool
{
    $phpCli = find_php_cli_for_background_start();
    if ($phpCli === null) {
        return false;
    }

    $workerPath = realpath(__DIR__ . '/../start_worker.php');
    if (!is_string($workerPath) || $workerPath === '') {
        return false;
    }

    $command = sprintf(
        'nohup %s %s distress > /dev/null 2>&1 &',
        escapeshellarg($phpCli),
        escapeshellarg($workerPath)
    );

    @exec('/bin/sh -c ' . escapeshellarg($command), $output, $code);
    return $code === 0;
}
