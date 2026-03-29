<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/start_helpers.php';
$config = require 'config/config.php';

function build_start_status_target(array $result): string
{
    $messageKey = (string)($result['messageKey'] ?? 'start_failed');
    $messageOk = (($result['ok'] ?? false) === true);
    return '/status.php?msg=' . rawurlencode($messageKey) . '&ok=' . ($messageOk ? '1' : '0');
}

function redirect_after_start_attempt(string $daemon, array $result): void
{
    write_start_debug_log('start_php_result', [
        'daemon' => $daemon,
        'result' => $result,
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'source' => (string)($_GET['source'] ?? ''),
    ]);

    header('Location: ' . url_with_lang(build_start_status_target($result)));
    exit;
}

$daemon = (string)($_POST['daemon'] ?? ($_GET['daemon'] ?? ''));
$source = (string)($_GET['source'] ?? '');

write_start_debug_log('start_php_request_received', [
    'daemon' => $daemon,
    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    'postKeys' => array_keys($_POST),
    'getKeys' => array_keys($_GET),
    'source' => $source,
]);

if (!in_array($daemon, $config['daemonNames'], true)) {
    write_start_debug_log('start_php_invalid_daemon', [
        'daemon' => $daemon,
        'allowed' => array_values((array)$config['daemonNames']),
    ]);
    header('Location: ' . url_with_lang('/status.php'));
    exit;
}

$result = start_module_request($daemon, $config);

redirect_after_start_attempt($daemon, $result);
