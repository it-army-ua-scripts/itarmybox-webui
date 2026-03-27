<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/root_helper_client.php';

$config = require 'config/config.php';
$pageLang = app_lang();
$message = '';
$messageClass = '';
$defaultWifiName = 'Artline';
$resetWifiNameWarning = $pageLang === 'uk'
    ? "\u{0423}\u{0432}\u{0430}\u{0433}\u{0430}: \u{043F}\u{0456}\u{0434} \u{0447}\u{0430}\u{0441} \u{0441}\u{043A}\u{0438}\u{0434}\u{0430}\u{043D}\u{043D}\u{044F} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430} Wi-Fi \u{0431}\u{0443}\u{0434}\u{0435} \u{0437}\u{043C}\u{0456}\u{043D}\u{0435}\u{043D}\u{0430} \u{043D}\u{0430} \u{0441}\u{0442}\u{0430}\u{043D}\u{0434}\u{0430}\u{0440}\u{0442}\u{043D}\u{0435} \u{0437}\u{043D}\u{0430}\u{0447}\u{0435}\u{043D}\u{043D}\u{044F} \"" . $defaultWifiName . "\"."
    : 'Warning: reset will change the Wi-Fi name to the default value "' . $defaultWifiName . '".';
$resetConfirmMessage = t('reset_webui_defaults_confirm') . "\n\n" . $resetWifiNameWarning;

if (isset($_GET['flash']) && is_string($_GET['flash']) && $_GET['flash'] !== '') {
    $flash = (string)$_GET['flash'];
    if ($flash === 'reset_webui_defaults_done') {
        $message = t('reset_webui_defaults_done');
        $messageClass = 'status active';
    } elseif ($flash === 'reset_webui_defaults_failed') {
        $message = t('reset_webui_defaults_failed');
        $details = trim((string)($_GET['details'] ?? ''));
        if ($details !== '') {
            $message .= ' (' . $details . ')';
        }
        $messageClass = 'status inactive';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'reset_webui_defaults') {
    $result = root_helper_request([
        'action' => 'webui_reset_defaults',
        'modules' => $config['daemonNames'],
    ]);
    header('Location: ' . build_page_url('/settings.php', [
        'flash' => (($result['ok'] ?? false) === true) ? 'reset_webui_defaults_done' : 'reset_webui_defaults_failed',
        'details' => (($result['ok'] ?? false) === true) ? null : (string)($result['error'] ?? 'unknown'),
        'themeReset' => (($result['ok'] ?? false) === true) ? '1' : null,
    ]));
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($pageLang, ENT_QUOTES, 'UTF-8') ?>">
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
    <div class="content centered">
        <h1><?= htmlspecialchars(t('settings'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($message !== ''): ?>
            <div class="form-message <?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>" role="status" aria-live="polite">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <ul class="menu centered">
            <li><a href="<?= htmlspecialchars(url_with_lang('/user_id.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('user_id'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/autostart.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('autostart'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/wifi_settings.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('wifi_settings'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/time_sync.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('time_sync'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/update.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('update'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/reboot.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('reboot_system'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><?= render_back_link('/') ?></li>
        </ul>
        <div class="service" style="max-width: 760px; width: 100%; margin: 24px auto 0; text-align: left;">
            <div class="service-title"><?= htmlspecialchars(t('reset_webui_defaults'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint"><?= htmlspecialchars(t('reset_webui_defaults_help'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint" style="margin-top: 8px;"><?= htmlspecialchars(t('reset_webui_defaults_warning'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint" style="margin-top: 8px;"><?= htmlspecialchars($resetWifiNameWarning, ENT_QUOTES, 'UTF-8') ?></div>
            <form method="post" action="" style="margin-top: 18px;" onsubmit='return window.confirm(<?= json_encode($resetConfirmMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);'>
                <input type="hidden" name="action" value="reset_webui_defaults">
                <button class="submit-btn" type="submit"><?= htmlspecialchars(t('reset_webui_defaults_button'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        </div>
    </div>
</div>
<?= render_app_footer() ?>
<?php if (($_GET['themeReset'] ?? '') === '1'): ?>
<script>
(() => {
    try {
        window.localStorage.removeItem('itarmybox-theme');
        window.localStorage.removeItem('itarmybox-traffic-desired');
        if (window.ItArmyTheme && typeof window.ItArmyTheme.refresh === 'function') {
            window.ItArmyTheme.refresh();
        }
    } catch (e) {
    }
})();
</script>
<?php endif; ?>
<script src="/js/form_messages.js"></script>
</body>
</html>
