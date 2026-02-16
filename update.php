<?php
require_once 'i18n.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/image/ukraine.png" rel="icon">
    <title>ITUA</title>
    <!-- Import Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet" />
    <link href="/styles.css" rel="stylesheet" />
</head>
<body class="padded">

<div class="container">
    <div class="content">
        <h1><?= htmlspecialchars(t('update_log'), ENT_QUOTES, 'UTF-8') ?>:</h1>
        <?php
        echo nl2br(shell_exec("/bin/bash update.sh 2>&1"));
        ?>
        <div class="menu">
            <a href="<?= htmlspecialchars(url_with_lang('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
    </div>
</div>
<footer class="app-footer">&copy; 2022-<?= date('Y') ?> IT Army of Ukraine. <?= htmlspecialchars(t('footer_slogan'), ENT_QUOTES, 'UTF-8') ?>.</footer>
</body>
</html>
