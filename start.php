<?php
require_once 'lib/navigation.php';
require_once 'lib/root_helper_client.php';
require_once 'lib/tool_helpers.php';
$config = require 'config/config.php';

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
        root_helper_request([
            'action' => 'service_activate_exclusive',
            'modules' => $config['daemonNames'],
            'selected' => $daemon,
        ]);
    }
}

redirect_back_or_default(['/tool.php', '/tools_list.php', '/status.php', '/index.html', '/'], '/tools_list.php');
