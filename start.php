<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/start_helpers.php';
$config = require 'config/config.php';

function redirect_after_start_attempt(string $daemon, array $result): void
{
    write_start_debug_log('start_php_result', [
        'daemon' => $daemon,
        'result' => $result,
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'source' => (string)($_GET['source'] ?? ''),
    ]);

    $messageKey = (string)($result['messageKey'] ?? 'start_failed');
    $messageOk = (($result['ok'] ?? false) === true);
    $target = '/status.php?msg=' . rawurlencode($messageKey) . '&ok=' . ($messageOk ? '1' : '0');
    header('Location: ' . url_with_lang($target));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    $daemon = (string)($_POST['daemon'] ?? ($_GET['daemon'] ?? ''));
    write_start_debug_log('start_php_request_received', [
        'daemon' => $daemon,
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'postKeys' => array_keys($_POST),
        'getKeys' => array_keys($_GET),
        'source' => (string)($_GET['source'] ?? ''),
    ]);

    if (in_array($daemon, $config['daemonNames'], true)) {
        $result = start_module_request($daemon, $config);
        redirect_after_start_attempt($daemon, $result);
    }

    write_start_debug_log('start_php_invalid_daemon', [
        'daemon' => $daemon,
        'allowed' => array_values((array)$config['daemonNames']),
    ]);
}

header('Location: ' . url_with_lang('/status.php'));
exit;
