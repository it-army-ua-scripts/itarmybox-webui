<?php
require_once 'i18n.php';
$config = require 'config/config.php';
$daemonNames = $config['daemonNames'];

function getCurrentAutostartDaemon(array $daemonNames): ?string
{
    foreach ($daemonNames as $daemon) {
        $daemonSafe = escapeshellarg($daemon . '.service');
        $state = trim((string)shell_exec("sudo -n systemctl is-enabled $daemonSafe 2>/dev/null"));
        if ($state === 'enabled') {
            return $daemon;
        }
    }
    return null;
}

function setAutostartDaemon(array $daemonNames, ?string $selectedDaemon): bool
{
    $ok = true;
    foreach ($daemonNames as $daemon) {
        $daemonSafe = escapeshellarg($daemon . '.service');
        $result = shell_exec("sudo -n systemctl disable $daemonSafe 2>/dev/null");
        if ($result === null) {
            $ok = false;
        }
    }

    if ($selectedDaemon !== null) {
        $selectedSafe = escapeshellarg($selectedDaemon . '.service');
        $result = shell_exec("sudo -n systemctl enable $selectedSafe 2>/dev/null");
        if ($result === null) {
            $ok = false;
        }
    }

    return $ok;
}

$message = '';
$messageClass = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requested = $_POST['autostart_daemon'] ?? '';
    $selectedDaemon = null;
    if ($requested !== 'none' && in_array($requested, $daemonNames, true)) {
        $selectedDaemon = $requested;
    }

    $ok = setAutostartDaemon($daemonNames, $selectedDaemon);
    $message = $ok ? t('autostart_updated') : t('autostart_update_failed');
    $messageClass = $ok ? 'status active' : 'status inactive';
}

$currentAutostart = getCurrentAutostartDaemon($daemonNames);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/image/ukraine.png" rel="icon">
    <title>ITUA</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet" />
    <link href="/styles.css" rel="stylesheet" />
</head>
<body class="padded">
<div class="container">
    <div class="content">
        <h1><?= htmlspecialchars(t('autostart_settings'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($message !== ''): ?>
            <div class="<?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="service">
            <div class="service-title"><?= htmlspecialchars(t('current_autostart'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="status <?= $currentAutostart ? 'active' : 'inactive' ?>">
                <?php
                if ($currentAutostart) {
                    echo htmlspecialchars(t('autostart_for', ['module' => strtoupper($currentAutostart)]), ENT_QUOTES, 'UTF-8');
                } else {
                    echo htmlspecialchars(t('autostart_none'), ENT_QUOTES, 'UTF-8');
                }
                ?>
            </div>
        </div>

        <div class="form-container">
            <form method="post" action="">
                <div class="form-group">
                    <label for="autostart_daemon"><?= htmlspecialchars(t('select_autostart_module'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="autostart_daemon" name="autostart_daemon">
                        <option value="none"><?= htmlspecialchars(t('autostart_disable'), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php foreach ($daemonNames as $daemon): ?>
                            <option value="<?= htmlspecialchars($daemon, ENT_QUOTES, 'UTF-8') ?>"<?= $currentAutostart === $daemon ? ' selected' : '' ?>>
                                <?= htmlspecialchars(strtoupper($daemon), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        </div>

        <div class="menu">
            <a href="<?= htmlspecialchars(url_with_lang('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
    </div>
</div>
<footer class="app-footer">&copy; 2022-<?= date('Y') ?> IT Army of Ukraine. <?= htmlspecialchars(t('footer_slogan'), ENT_QUOTES, 'UTF-8') ?>.</footer>
</body>
</html>
