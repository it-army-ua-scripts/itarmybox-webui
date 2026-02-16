<?php
require_once 'lib/navigation.php';
$config = require 'config/config.php';

function startService(string $serviceName): void
{
    $serviceSafe = escapeshellarg($serviceName);
    shell_exec("systemctl daemon-reload && systemctl start $serviceSafe 2>&1");
}

function stopService(string $serviceName): void
{
    $serviceSafe = escapeshellarg($serviceName);
    shell_exec("systemctl stop $serviceSafe 2>&1");
}

if (isset($_GET['daemon']) && in_array($_GET['daemon'], $config['daemonNames'], true)) {
    foreach ($config['daemonNames'] as $daemon) {
        if ($_GET['daemon'] === $daemon) {
            startService($daemon);
        } else {
            stopService($daemon);
        }
    }
}

redirect_back_or_default(['/tool.php', '/tools_list.php', '/status.php', '/index.html', '/'], '/tools_list.php');
