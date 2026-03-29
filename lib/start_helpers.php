<?php

if (!function_exists('root_helper_request')) {
    require_once __DIR__ . '/root_helper_client.php';
}
if (!function_exists('getConfigStringFromServiceFile') || !function_exists('updateServiceFile')) {
    require_once __DIR__ . '/tool_helpers.php';
}
const START_DEBUG_LOG_FILE = __DIR__ . '/../var/log/start-debug.log';
const START_DEBUG_STATE_LOG_FILE = __DIR__ . '/../var/state/start-debug.log';
const START_DEBUG_TMP_LOG_FILE = '/tmp/itarmybox-start-debug.log';

function ensure_start_debug_directory(string $filePath = START_DEBUG_LOG_FILE): bool
{
    $dir = dirname($filePath);
    return is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir);
}

function write_start_debug_log(string $event, array $context = []): void
{
    $payload = [
        'ts' => date('c'),
        'event' => $event,
    ] + $context;

    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        return;
    }

    foreach ([START_DEBUG_LOG_FILE, START_DEBUG_STATE_LOG_FILE, START_DEBUG_TMP_LOG_FILE] as $target) {
        if (!ensure_start_debug_directory($target)) {
            continue;
        }
        @file_put_contents($target, $line . PHP_EOL, FILE_APPEND);
    }
}

function start_module_request(string $daemon, array $config): array
{
    write_start_debug_log('start_module_request_begin', [
        'daemon' => $daemon,
        'modules' => array_values((array)($config['daemonNames'] ?? [])),
    ]);

    $payload = [
        'action' => 'service_activate_exclusive',
        'modules' => $config['daemonNames'],
        'selected' => $daemon,
    ];
    $response = root_helper_request($payload);
    write_start_debug_log('start_module_request_end', [
        'daemon' => $daemon,
        'payload' => $payload,
        'response' => $response,
    ]);

    return [
        'ok' => (($response['ok'] ?? false) === true),
        'messageKey' => (($response['ok'] ?? false) === true) ? 'start_requested' : 'start_failed',
        'error' => $response['error'] ?? null,
    ];
}
