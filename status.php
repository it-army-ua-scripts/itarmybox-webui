<?php
require_once 'i18n.php';
require_once 'lib/root_helper_client.php';
$config = require 'config/config.php';
$daemonNames = $config['daemonNames'];

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');

    $response = root_helper_request([
        'action' => 'status_snapshot',
        'modules' => $daemonNames,
        'lines' => 80,
    ]);
    $activeModule = ($response['ok'] ?? false) ? ($response['activeModule'] ?? null) : null;
    $commonLogs = ($response['ok'] ?? false) ? (string)($response['commonLogs'] ?? '') : '';
    $logSource = ($response['ok'] ?? false) ? ($response['logSource'] ?? null) : null;
    $logPath = ($response['ok'] ?? false) ? ($response['logPath'] ?? null) : null;

    if (trim($commonLogs) === '') {
        $commonLogs = (string)shell_exec("tail -n80 /var/log/adss.log 2>/dev/null");
    }

    echo json_encode(
        [
            'ok' => true,
            'activeModule' => $activeModule,
            'commonLogs' => $commonLogs,
            'logSource' => $logSource,
            'logPath' => $logPath,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
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

    <div class="service">
        <div class="service-title" id="active-module-name"><?= htmlspecialchars(t('active_module'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="status inactive" id="active-module-status"><?= htmlspecialchars(t('checking'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="service">
        <div class="service-title" id="common-log-title"><?= htmlspecialchars(t('common_logs'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="log-box" id="common-log"></div>
    </div>

    <div class="menu">
        <a href="<?= htmlspecialchars(url_with_lang('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a>
    </div>
</div>

<script>
    const serviceState = {};
    const commonLogEl = document.getElementById("common-log");
    const commonLogTitleEl = document.getElementById("common-log-title");
    const activeModuleNameEl = document.getElementById("active-module-name");
    const activeModuleStatusEl = document.getElementById("active-module-status");
    const text = <?= json_encode([
        'activeModule' => t('active_module'),
        'noModuleRunning' => t('no_module_running'),
        'commonLogsFor' => t('common_logs_for', ['module' => '{{module}}']),
        'commonLogsNoActive' => t('common_logs_no_active')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const ajaxUrl = <?= json_encode(url_with_lang('/status.php?ajax=1'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function appendOrReplace(el, newText, key) {
        const oldText = serviceState[key] || "";
        if (newText.startsWith(oldText)) {
            el.textContent += newText.slice(oldText.length);
        } else {
            el.textContent = newText;
        }
        serviceState[key] = newText;
        el.scrollTop = el.scrollHeight;
    }

    async function updateStatus() {
        try {
            const response = await fetch(ajaxUrl, { cache: "no-store" });
            const data = await response.json();
            if (!data || !data.ok) {
                return;
            }

            if (data.activeModule) {
                activeModuleNameEl.textContent = text.activeModule;
                activeModuleStatusEl.textContent = data.activeModule;
                activeModuleStatusEl.classList.add("active");
                activeModuleStatusEl.classList.remove("inactive");
                const src = data.logSource ? String(data.logSource) : "";
                const path = data.logPath ? String(data.logPath) : "";
                const suffix = src ? ` [${src}${path ? ": " + path : ""}]` : "";
                commonLogTitleEl.textContent = text.commonLogsFor.replace("{{module}}", data.activeModule) + suffix;
            } else {
                activeModuleNameEl.textContent = text.activeModule;
                activeModuleStatusEl.textContent = text.noModuleRunning;
                activeModuleStatusEl.classList.add("inactive");
                activeModuleStatusEl.classList.remove("active");
                commonLogTitleEl.textContent = text.commonLogsNoActive;
            }
            appendOrReplace(commonLogEl, data.commonLogs || "", "common");
        } catch (e) {
        }
    }

    updateStatus();
    setInterval(updateStatus, 2000);
</script>
<footer class="app-footer">&copy; 2022-<?= date('Y') ?> IT Army of Ukraine. <?= htmlspecialchars(t('footer_slogan'), ENT_QUOTES, 'UTF-8') ?>.</footer>
</body>
</html>
