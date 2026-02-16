<?php
require_once 'lib/navigation.php';
$config = require 'config/config.php';

function stopService(string $serviceName): void
{
    $serviceSafe = escapeshellarg($serviceName);
    shell_exec("sudo systemctl daemon-reload && sudo systemctl stop $serviceSafe 2>&1");
}

if (isset($_GET['daemon']) && in_array($_GET['daemon'], $config['daemonNames'], true)) {
    stopService($_GET['daemon']);
}

redirect_back_or_default(['/tool.php', '/tools_list.php', '/status.php', '/index.html', '/'], '/tools_list.php');
