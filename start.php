<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/root_helper_client.php';
require_once 'lib/tool_helpers.php';
$config = require 'config/config.php';

$message = '';
$messageClass = '';

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
        $response = root_helper_request([
            'action' => 'service_activate_exclusive',
            'modules' => $config['daemonNames'],
            'selected' => $daemon,
        ]);
        $ok = (($response['ok'] ?? false) === true);
        $message = $ok ? t('start_requested') : t('start_failed');
        $messageClass = $ok ? 'status active' : 'status inactive';
    } else {
        $message = t('start_failed');
        $messageClass = 'status inactive';
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
        <h1><?= htmlspecialchars(t('status'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($message !== ''): ?>
            <div class="form-message <?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="menu centered">
            <a href="<?= htmlspecialchars(url_with_lang('/tools_list.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
    </div>
</div>
<?= render_app_footer() ?>
</body>
</html>
