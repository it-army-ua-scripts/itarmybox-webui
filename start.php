<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/start_helpers.php';
$config = require 'config/config.php';

function build_start_status_target(array $result): string
{
    $messageKey = (string)($result['messageKey'] ?? 'start_failed');
    $messageOk = (($result['ok'] ?? false) === true);
    return '/status.php?msg=' . rawurlencode($messageKey) . '&ok=' . ($messageOk ? '1' : '0');
}

function redirect_after_start_attempt(string $daemon, array $result): void
{
    write_start_debug_log('start_php_result', [
        'daemon' => $daemon,
        'result' => $result,
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'source' => (string)($_GET['source'] ?? ''),
    ]);

    header('Location: ' . url_with_lang(build_start_status_target($result)));
    exit;
}

function render_distress_start_progress_page(string $daemon): void
{
    $statusUrl = url_with_lang('/status.php');
    $startAjaxUrl = url_with_lang('/start.php?ajax=1&daemon=' . rawurlencode($daemon) . '&source=distress_progress');
    $pollAjaxUrl = url_with_lang('/status.php?ajax=1&includeDistressAutotune=1');
    ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_lang(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/image/ukraine.png" rel="icon">
    <title>ITUA</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet"/>
    <link href="/styles.css" rel="stylesheet" />
</head>
<body class="padded status-page">
<div class="container status-container">
    <h1><?= htmlspecialchars(t('start_progress_title'), ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="service">
        <div class="service-title"><?= htmlspecialchars(t('start_progress_module', ['module' => strtoupper($daemon)]), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="status inactive" id="start-progress-state"><?= htmlspecialchars(t('start_progress_waiting'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="schedule-limit-hint" id="start-progress-speed-status"><?= htmlspecialchars(t('distress_upload_cap_status_label'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars(t('distress_upload_cap_status_idle'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="schedule-limit-hint" id="start-progress-speed-value" hidden></div>
        <div class="schedule-limit-hint" id="start-progress-speed-method" hidden></div>
        <div class="schedule-limit-hint" id="start-progress-speed-error" hidden></div>
    </div>
    <div class="menu">
        <?= render_back_link('/status.php', t('start_progress_back_to_status')) ?>
    </div>
</div>
<script id="start-progress-config" type="application/json"><?= json_encode([
    'daemon' => $daemon,
    'startAjaxUrl' => $startAjaxUrl,
    'pollAjaxUrl' => $pollAjaxUrl,
    'statusRedirectBase' => url_with_lang('/status.php'),
    'statusFailedUrl' => url_with_lang('/status.php?msg=start_failed&ok=0'),
    'text' => [
        'startWaiting' => t('start_progress_waiting'),
        'startRequested' => t('start_requested'),
        'startFailed' => t('start_failed'),
        'speedLabel' => t('distress_upload_cap_status_label'),
        'speedIdle' => t('distress_upload_cap_status_idle'),
        'speedRunning' => t('distress_upload_cap_status_running'),
        'speedSuccess' => t('distress_upload_cap_status_success'),
        'speedFailed' => t('distress_upload_cap_status_failed'),
        'speedSkipped' => t('distress_upload_cap_status_skipped'),
        'speedValue' => t('distress_upload_cap_value', ['value' => '{{value}}']),
        'speedMeasuredAt' => t('distress_upload_cap_measured_at', ['value' => '{{value}}']),
        'speedMethod' => t('distress_upload_cap_method', ['value' => '{{value}}']),
        'speedError' => t('distress_upload_cap_error', ['value' => '{{value}}']),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/js/app_shared.js"></script>
<script src="/js/start_progress.js"></script>
<?= render_app_footer() ?>
</body>
</html>
<?php
    exit;
}

$daemon = (string)($_POST['daemon'] ?? ($_GET['daemon'] ?? ''));
$source = (string)($_GET['source'] ?? '');
$isAjaxRequest = (($_GET['ajax'] ?? '') === '1');

write_start_debug_log('start_php_request_received', [
    'daemon' => $daemon,
    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    'postKeys' => array_keys($_POST),
    'getKeys' => array_keys($_GET),
    'source' => $source,
    'ajax' => $isAjaxRequest,
]);

if (!in_array($daemon, $config['daemonNames'], true)) {
    write_start_debug_log('start_php_invalid_daemon', [
        'daemon' => $daemon,
        'allowed' => array_values((array)$config['daemonNames']),
    ]);
    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => false,
            'messageKey' => 'start_failed',
            'error' => 'invalid_daemon',
            'redirectUrl' => url_with_lang('/status.php?msg=start_failed&ok=0'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: ' . url_with_lang('/status.php'));
    exit;
}

if (!$isAjaxRequest && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $daemon === 'distress') {
    render_distress_start_progress_page($daemon);
}

$result = start_module_request($daemon, $config);

if ($isAjaxRequest) {
    header('Content-Type: application/json; charset=UTF-8');
    write_start_debug_log('start_php_ajax_result', [
        'daemon' => $daemon,
        'result' => $result,
        'source' => $source,
    ]);
    echo json_encode($result + [
        'redirectUrl' => url_with_lang(build_start_status_target($result)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

redirect_after_start_attempt($daemon, $result);
