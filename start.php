<?php
require_once 'lib/navigation.php';
require_once 'lib/root_helper_client.php';
$config = require 'config/config.php';

if (isset($_GET['daemon']) && in_array($_GET['daemon'], $config['daemonNames'], true)) {
    root_helper_request([
        'action' => 'service_activate_exclusive',
        'modules' => $config['daemonNames'],
        'selected' => (string)$_GET['daemon'],
    ]);
}

redirect_back_or_default(['/tool.php', '/tools_list.php', '/status.php', '/index.html', '/'], '/tools_list.php');
