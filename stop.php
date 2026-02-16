<?php
require_once 'lib/navigation.php';
require_once 'lib/root_helper_client.php';
$config = require 'config/config.php';

if (isset($_GET['daemon']) && in_array($_GET['daemon'], $config['daemonNames'], true)) {
    root_helper_request([
        'action' => 'service_stop',
        'modules' => $config['daemonNames'],
        'module' => (string)$_GET['daemon'],
    ]);
}

redirect_back_or_default(['/tool.php', '/tools_list.php', '/status.php', '/index.html', '/'], '/tools_list.php');
