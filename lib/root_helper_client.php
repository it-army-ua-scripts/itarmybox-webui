<?php

if (!defined('ROOT_HELPER_SOCKET')) {
    define('ROOT_HELPER_SOCKET', '/run/itarmybox-root-helper.sock');
}

function root_helper_request(array $payload): array
{
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson)) {
        return ['ok' => false, 'error' => 'encode_failed'];
    }

    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_client('unix://' . ROOT_HELPER_SOCKET, $errno, $errstr, 2);
    if ($sock === false) {
        return ['ok' => false, 'error' => 'socket_unavailable'];
    }

    stream_set_timeout($sock, 3);
    fwrite($sock, $payloadJson . "\n");
    stream_socket_shutdown($sock, STREAM_SHUT_WR);
    $responseRaw = stream_get_contents($sock);
    fclose($sock);

    if (!is_string($responseRaw) || trim($responseRaw) === '') {
        return ['ok' => false, 'error' => 'empty_response'];
    }

    $response = json_decode($responseRaw, true);
    if (!is_array($response)) {
        return ['ok' => false, 'error' => 'invalid_response'];
    }

    return $response;
}
