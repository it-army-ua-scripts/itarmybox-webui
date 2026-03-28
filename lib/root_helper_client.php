<?php

if (!defined('ROOT_HELPER_SOCKET')) {
    define('ROOT_HELPER_SOCKET', '/run/itarmybox-root-helper.sock');
}

function root_helper_request_timeouts(?string $action): array
{
    $connectTimeout = 2;
    $readTimeout = match ($action) {
        'system_update_run' => 600,
        'webui_reset_defaults' => 60,
        'time_sync_ensure' => 30,
        'distress_autotune_set', 'distress_settings_set' => 15,
        'system_reboot' => 15,
        default => 3,
    };

    return [
        'connect' => $connectTimeout,
        'read' => $readTimeout,
    ];
}

function root_helper_request(array $payload): array
{
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson)) {
        return ['ok' => false, 'error' => 'encode_failed'];
    }

    $action = isset($payload['action']) && is_string($payload['action']) ? $payload['action'] : null;
    $timeouts = root_helper_request_timeouts($action);
    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_client('unix://' . ROOT_HELPER_SOCKET, $errno, $errstr, $timeouts['connect']);
    if ($sock === false) {
        return ['ok' => false, 'error' => 'socket_unavailable'];
    }

    stream_set_timeout($sock, $timeouts['read']);
    fwrite($sock, $payloadJson . "\n");
    stream_socket_shutdown($sock, STREAM_SHUT_WR);
    $responseRaw = stream_get_contents($sock);
    $meta = stream_get_meta_data($sock);
    fclose($sock);

    if (($meta['timed_out'] ?? false) === true) {
        return ['ok' => false, 'error' => 'socket_timeout'];
    }

    if (!is_string($responseRaw) || trim($responseRaw) === '') {
        return ['ok' => false, 'error' => 'empty_response'];
    }

    $response = json_decode($responseRaw, true);
    if (!is_array($response)) {
        return ['ok' => false, 'error' => 'invalid_response'];
    }

    return $response;
}
