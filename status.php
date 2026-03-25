<?php
require_once 'i18n.php';
require_once 'lib/root_helper_client.php';
require_once 'lib/footer.php';
$config = require 'config/config.php';
$daemonNames = $config['daemonNames'];

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');

    $response = root_helper_request([
        'action' => 'status_snapshot',
        'modules' => $daemonNames,
        'lines' => 80,
    ]);
    $autostartResponse = root_helper_request([
        'action' => 'autostart_get',
        'modules' => $daemonNames,
    ]);
    $trafficLimitResponse = root_helper_request([
        'action' => 'traffic_limit_get',
        'modules' => $daemonNames,
    ]);
    $activeModule = ($response['ok'] ?? false) ? ($response['activeModule'] ?? null) : null;
    $commonLogs = ($response['ok'] ?? false) ? (string)($response['commonLogs'] ?? '') : '';
    $selectedModule = (($autostartResponse['ok'] ?? false) === true) ? ($autostartResponse['active'] ?? null) : null;
    $scheduleLocked = (($trafficLimitResponse['scheduleLocked'] ?? false) === true);
    $scheduleModule = $scheduleLocked ? ($trafficLimitResponse['scheduleModule'] ?? null) : null;
    $schedulePercent = $scheduleLocked ? ($trafficLimitResponse['schedulePercent'] ?? null) : null;
    if (!is_string($selectedModule) || !in_array($selectedModule, $daemonNames, true)) {
        $selectedModule = null;
    }
    if (!is_string($scheduleModule) || !in_array($scheduleModule, $daemonNames, true)) {
        $scheduleModule = null;
    }
    if (!is_int($schedulePercent) && !(is_string($schedulePercent) && preg_match('/^\d+$/', $schedulePercent) === 1)) {
        $schedulePercent = null;
    }

    if (trim($commonLogs) === '') {
        $commonLogs = (string)shell_exec("tail -n80 /var/log/adss.log 2>/dev/null");
    }

    $statusOk = (($response['ok'] ?? false) === true);
    $autostartOk = (($autostartResponse['ok'] ?? false) === true);

    echo json_encode(
        [
            'ok' => $statusOk,
            'activeModule' => $activeModule,
            'selectedModule' => $selectedModule,
            'commonLogs' => $commonLogs,
            'autostartOk' => $autostartOk,
            'scheduleLocked' => $scheduleLocked,
            'scheduleModule' => $scheduleModule,
            'schedulePercent' => $schedulePercent !== null ? (int)$schedulePercent : null,
            'error' => $statusOk ? null : (string)($response['error'] ?? 'status_unavailable'),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
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
    <h1><?= htmlspecialchars(t('tools_status'), ENT_QUOTES, 'UTF-8') ?></h1>
    <?php
    $messageKey = $_GET['msg'] ?? '';
    $messageOk = ($_GET['ok'] ?? '') === '1';
    $allowedMessages = ['start_requested', 'start_failed', 'stop_requested', 'stop_failed'];
    if (is_string($messageKey) && in_array($messageKey, $allowedMessages, true)) {
        $messageClass = $messageOk ? 'status active' : 'status inactive';
        echo '<div class="form-message ' . htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars(t($messageKey), ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
    ?>

    <div class="service">
        <div class="service-title" id="active-module-name"><?= htmlspecialchars(t('active_module'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="status inactive" id="active-module-status"><?= htmlspecialchars(t('checking'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="service">
        <div class="service-title"><?= htmlspecialchars(t('current_autostart'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="status inactive" id="autostart-status"><?= htmlspecialchars(t('checking'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="service">
        <div class="service-title"><?= htmlspecialchars(t('current_control'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="status inactive" id="control-status"><?= htmlspecialchars(t('checking'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="service">
        <div class="log-box" id="common-log"></div>
        <div class="menu" id="active-module-actions"></div>
    </div>

    <div class="menu">
        <a href="<?= htmlspecialchars(url_with_lang('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a>
    </div>
</div>
<script id="status-config" type="application/json"><?= json_encode([
    'text' => [
        'activeModule' => t('active_module'),
        'noModuleRunning' => t('no_module_running'),
        'statusUnavailable' => t('status_unavailable_short'),
        'autostartFor' => t('autostart_for', ['module' => '{{module}}']),
        'autostartNone' => t('autostart_none'),
        'controlManual' => t('control_manual'),
        'controlSchedule' => t('control_schedule', ['module' => '{{module}}', 'power' => '{{power}}']),
        'controlScheduleGeneric' => t('control_schedule_generic'),
        'start' => t('start'),
        'stop' => t('stop'),
    ],
    'lang' => app_lang(),
    'ajaxUrl' => url_with_lang('/status.php?ajax=1'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/js/app_shared.js"></script>
<script src="/js/status.js"></script>
<script src="/js/form_messages.js"></script>
<?= render_app_footer() ?>
</body>
</html>
