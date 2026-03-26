<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
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
        <h1><?= htmlspecialchars(t('wifi_settings'), ENT_QUOTES, 'UTF-8') ?></h1>
        <ul class="menu centered">
            <li><a href="<?= htmlspecialchars(url_with_lang('/wifi_name.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('wifi_ap_name'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/wifi_power.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('wifi_ap_power'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><?= render_back_link('/settings.php') ?></li>
        </ul>
    </div>
</div>
<?= render_app_footer() ?>
</body>
</html>
