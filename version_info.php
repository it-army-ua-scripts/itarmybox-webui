<?php
require_once 'lib/version.php';

header('Content-Type: application/json; charset=UTF-8');
$versions = webui_versions();
echo json_encode(
    [
        'ok' => true,
        'current' => (string)$versions['current'],
        'github' => (string)$versions['github'],
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
