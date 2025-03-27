<?php
$config = require 'config/config.php';
function stopService($serviceName): void
{
    echo shell_exec("sudo systemctl daemon-reload && sudo systemctl stop {$serviceName} 2>&1");
}

if (isset($_GET['daemon']) && in_array($_GET['daemon'], $config['daemonNames'])) {
    stopService($_GET['daemon']);
}

if (!empty($_SERVER['HTTP_REFERER'])) {
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
} else {
    header("Location: index.html"); // Перенаправлення на головну, якщо HTTP_REFERER недоступний
    exit();
}
