<?php
require_once 'lib/root_helper_client.php';
$config = require 'config/config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    $percent = is_array($payload) ? (int)($payload['percent'] ?? 0) : 0;
    echo json_encode(
        root_helper_request([
            'action' => 'traffic_limit_set',
            'modules' => $config['daemonNames'],
            'percent' => $percent,
        ]),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

echo json_encode(
    root_helper_request([
        'action' => 'traffic_limit_get',
        'modules' => $config['daemonNames'],
    ]),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
