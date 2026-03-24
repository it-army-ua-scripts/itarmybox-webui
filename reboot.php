<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/root_helper_client.php';
$message = '';
$messageClass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = root_helper_request([
        'action' => 'system_reboot',
        'modules' => (require 'config/config.php')['daemonNames'],
    ]);
    $ok = (($response['ok'] ?? false) === true);
    $message = $ok ? t('reboot_requested') : t('reboot_failed');
    $messageClass = $ok ? 'status active' : 'status inactive';
    if (!$ok && isset($response['error'])) {
        $message .= ' (' . $response['error'] . ')';
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_lang(), ENT_QUOTES, 'UTF-8') ?>">
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
        <h1><?= htmlspecialchars(t('reboot_system_title'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($message !== ''): ?>
            <div class="form-message <?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="service" style="max-width: 760px; text-align: left;">
            <?= htmlspecialchars(t('reboot_system_warning'), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="form-container">
            <form method="post" action="">
                <button class="submit-btn" type="submit"><?= htmlspecialchars(t('reboot_now'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        </div>
        <div class="menu centered">
            <a href="<?= htmlspecialchars(url_with_lang('/settings.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
    </div>
</div>
<?= render_app_footer() ?>
<script src="/js/form_messages.js"></script>
</body>
</html>
