<?php
$config = require 'config/config.php';
function startService($serviceName): void
{
    shell_exec("sudo systemctl daemon-reload && sudo systemctl start $serviceName 2>&1");
}

if (isset($_GET['daemon']) && in_array($_GET['daemon'], $config['daemonNames'])) {
    startService($_GET['daemon']);
}

if (!empty($_SERVER['HTTP_REFERER'])) {
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit();
} else {
    header("Location: index.php"); // Перенаправлення на головну, якщо HTTP_REFERER недоступний
    exit();
}
