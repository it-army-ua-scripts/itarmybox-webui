<?php
require_once 'lib/root_helper_client.php';
header('Content-Type: application/json; charset=UTF-8');

$modules = (require 'config/config.php')['daemonNames'];
if (!is_array($modules) || $modules === []) {
    echo json_encode(['ok' => false, 'error' => 'invalid_modules'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = root_helper_request([
        'action' => 'vnstat_install',
        'modules' => $modules,
    ]);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$response = root_helper_request([
    'action' => 'vnstat_status',
    'modules' => $modules,
]);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
