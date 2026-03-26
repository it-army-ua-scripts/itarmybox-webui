<?php
require_once 'i18n.php';
require_once 'lib/root_helper_client.php';
$config = require 'config/config.php';

$messageKey = '';
$messageOk = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $daemon = (string)($_POST['daemon'] ?? '');
    if (in_array($daemon, $config['daemonNames'], true)) {
        $response = root_helper_request([
            'action' => 'service_stop',
            'modules' => $config['daemonNames'],
            'module' => $daemon,
        ]);
        $messageOk = (($response['ok'] ?? false) === true);
        $messageKey = $messageOk ? 'stop_requested' : 'stop_failed';
    }
}

if ($messageKey !== '') {
    $target = '/status.php?msg=' . rawurlencode($messageKey) . '&ok=' . ($messageOk ? '1' : '0');
    header('Location: ' . url_with_lang($target));
    exit;
}

header('Location: ' . url_with_lang('/status.php'));
exit;
