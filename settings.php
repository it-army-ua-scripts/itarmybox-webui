<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
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
    <div class="content centered">
        <h1><?= htmlspecialchars(t('settings'), ENT_QUOTES, 'UTF-8') ?></h1>
        <ul class="menu centered">
            <li><a href="<?= htmlspecialchars(url_with_lang('/autostart.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('autostart'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/update.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('update'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a></li>
        </ul>
    </div>
</div>
<?= render_app_footer() ?>
</body>
</html>
