<?php
require_once 'i18n.php';
require_once 'lib/root_helper_client.php';
require_once 'lib/tool_helpers.php';
$config = require 'config/config.php';

$messageKey = '';
$messageOk = false;

if (isset($_GET['daemon']) && in_array($_GET['daemon'], $config['daemonNames'], true)) {
    $daemon = (string)$_GET['daemon'];
    $canStart = true;
    if (in_array($daemon, ['mhddos', 'distress'], true)) {
        $currentConfig = getConfigStringFromServiceFile($daemon);
        if ($currentConfig !== '') {
            $canStart = updateServiceFile($daemon, updateServiceConfigParams($currentConfig, [], $daemon));
        } else {
            $canStart = false;
        }
    }
    if ($canStart) {
        $response = root_helper_request([
            'action' => 'service_activate_exclusive',
            'modules' => $config['daemonNames'],
            'selected' => $daemon,
        ]);
        $messageOk = (($response['ok'] ?? false) === true);
        $messageKey = $messageOk ? 'start_requested' : 'start_failed';
    } else {
        $messageKey = 'start_failed';
        $messageOk = false;
    }
}

if ($messageKey !== '') {
    $target = '/status.php?msg=' . rawurlencode($messageKey) . '&ok=' . ($messageOk ? '1' : '0');
    header('Location: ' . url_with_lang($target));
    exit;
}

header('Location: ' . url_with_lang('/status.php'));
exit;
