<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/root_helper_client.php';

$modules = (require 'config/config.php')['daemonNames'];
$message = '';
$messageClass = '';

$timezoneOptions = [
    'Europe/Kyiv',
    'Europe/Warsaw',
    'Europe/Berlin',
    'Europe/London',
    'Europe/Paris',
    'Europe/Riga',
    'Europe/Vilnius',
    'Europe/Tallinn',
    'UTC',
    'Asia/Tbilisi',
    'Asia/Yerevan',
    'Asia/Baku',
    'Asia/Jerusalem',
    'America/New_York',
];

$selectedTimezone = 'Europe/Kyiv';

$status = root_helper_request([
    'action' => 'time_sync_status',
    'modules' => $modules,
]);

$timezone = (string)($status['timezone'] ?? 'n/a');
if ($timezone !== '' && $timezone !== 'n/a') {
    $selectedTimezone = $timezone;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTimezone = trim((string)($_POST['timezone'] ?? 'Europe/Kyiv'));
    if ($postedTimezone !== '') {
        $selectedTimezone = $postedTimezone;
    }
    $response = root_helper_request([
        'action' => 'time_sync_ensure',
        'modules' => $modules,
        'timezone' => $selectedTimezone,
    ]);
    $ok = (($response['ok'] ?? false) === true);
    $message = $ok ? t('time_sync_applied') : t('time_sync_failed');
    $messageClass = $ok ? 'status active' : 'status inactive';
    if (!$ok && isset($response['error'])) {
        $message .= ' (' . $response['error'] . ')';
    }
    $status = $response;
}

$timezone = (string)($status['timezone'] ?? 'n/a');
$ntpEnabled = ($status['ntpEnabled'] ?? null) === true;
$ntpSynchronized = ($status['ntpSynchronized'] ?? null) === true;
$ntpService = (string)($status['ntpService'] ?? 'n/a');
$looksGood = ($timezone === $selectedTimezone) && (($status['ntpOk'] ?? false) === true);

if ($timezone !== '' && $timezone !== 'n/a' && !in_array($timezone, $timezoneOptions, true)) {
    array_unshift($timezoneOptions, $timezone);
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
        <h1><?= htmlspecialchars(t('time_sync_title'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($message !== ''): ?>
            <div class="form-message <?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="service" style="max-width: 760px; width: 100%; text-align: left;">
            <div class="service-title"><?= htmlspecialchars($looksGood ? t('time_sync_ok') : t('time_sync_fix_needed'), ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong><?= htmlspecialchars(t('time_sync_current_timezone'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($timezone, ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong><?= htmlspecialchars(t('time_sync_ntp_enabled'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($ntpEnabled ? t('yes') : t('no'), ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong><?= htmlspecialchars(t('time_sync_ntp_synced'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($ntpSynchronized ? t('yes') : t('no'), ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong><?= htmlspecialchars(t('time_sync_service'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($ntpService, ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <div class="form-container">
            <form method="post" action="">
                <div class="form-group" style="text-align: left;">
                    <label for="timezone"><?= htmlspecialchars(t('time_sync_select_timezone'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="timezone" name="timezone">
                        <?php foreach ($timezoneOptions as $timezoneOption): ?>
                            <option value="<?= htmlspecialchars($timezoneOption, ENT_QUOTES, 'UTF-8') ?>"<?= $timezoneOption === $selectedTimezone ? ' selected' : '' ?>>
                                <?= htmlspecialchars($timezoneOption, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="margin-top: 8px; color: #6b7d90; font-size: 0.92rem;">
                        <?= htmlspecialchars(t('time_sync_timezone_recommended'), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
                <button class="submit-btn" type="submit"><?= htmlspecialchars(t('time_sync_ensure'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        </div>

        <div class="menu centered">
            <?= render_back_link('/settings.php') ?>
        </div>
    </div>
</div>
<?= render_app_footer() ?>
<script src="/js/form_messages.js"></script>
</body>
</html>
