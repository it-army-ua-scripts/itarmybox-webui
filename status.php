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
    $activeModule = ($response['ok'] ?? false) ? ($response['activeModule'] ?? null) : null;
    $commonLogs = ($response['ok'] ?? false) ? (string)($response['commonLogs'] ?? '') : '';
    $logSource = ($response['ok'] ?? false) ? ($response['logSource'] ?? null) : null;
    $logPath = ($response['ok'] ?? false) ? ($response['logPath'] ?? null) : null;
    $selectedModule = (($autostartResponse['ok'] ?? false) === true) ? ($autostartResponse['active'] ?? null) : null;
    if (!is_string($selectedModule) || !in_array($selectedModule, $daemonNames, true)) {
        $selectedModule = null;
    }

    if (trim($commonLogs) === '') {
        $commonLogs = (string)shell_exec("tail -n80 /var/log/adss.log 2>/dev/null");
    }

    echo json_encode(
        [
            'ok' => true,
            'activeModule' => $activeModule,
            'selectedModule' => $selectedModule,
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

    <div class="service">
        <div class="service-title" id="active-module-name"><?= htmlspecialchars(t('active_module'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="status inactive" id="active-module-status"><?= htmlspecialchars(t('checking'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="service">
        <div class="service-title"><?= htmlspecialchars(t('current_autostart'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="status inactive" id="autostart-status"><?= htmlspecialchars(t('checking'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="service">
        <div class="log-box" id="common-log"></div>
        <div class="menu" id="active-module-actions"></div>
    </div>

    <div class="menu">
        <a href="<?= htmlspecialchars(url_with_lang('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a>
    </div>
</div>

<script>
    const serviceState = {};
    const commonLogEl = document.getElementById("common-log");
    const activeModuleActionsEl = document.getElementById("active-module-actions");
    const activeModuleNameEl = document.getElementById("active-module-name");
    const activeModuleStatusEl = document.getElementById("active-module-status");
    const autostartStatusEl = document.getElementById("autostart-status");
    const text = <?= json_encode([
        'activeModule' => t('active_module'),
        'noModuleRunning' => t('no_module_running'),
        'autostartFor' => t('autostart_for', ['module' => '{{module}}']),
        'autostartNone' => t('autostart_none'),
        'start' => t('start'),
        'stop' => t('stop')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const lang = <?= json_encode(app_lang(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const ajaxUrl = <?= json_encode(url_with_lang('/status.php?ajax=1'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function appendOrReplace(el, newText, key) {
        const oldText = serviceState[key] || "";
        const shouldStickToBottom =
            oldText === "" ||
            Math.abs(el.scrollHeight - el.clientHeight - el.scrollTop) < 24;
        if (newText.startsWith(oldText)) {
            el.textContent += newText.slice(oldText.length);
        } else {
            el.textContent = newText;
        }
        serviceState[key] = newText;
        if (shouldStickToBottom) {
            el.scrollTop = el.scrollHeight;
        }
    }

    function actionUrl(path, module) {
        return path + "?daemon=" + encodeURIComponent(module) + "&lang=" + encodeURIComponent(lang);
    }

    function renderActiveModuleActions(activeModuleName, selectedModuleName) {
        activeModuleActionsEl.innerHTML = "";
        if (activeModuleName) {
            const stopLink = document.createElement("a");
            stopLink.href = actionUrl("/stop.php", activeModuleName);
            stopLink.textContent = text.stop;
            activeModuleActionsEl.appendChild(stopLink);
        } else if (selectedModuleName) {
            const startLink = document.createElement("a");
            startLink.href = actionUrl("/start.php", selectedModuleName);
            startLink.textContent = text.start;
            activeModuleActionsEl.appendChild(startLink);
        }
    }

    function setStatusBadge(el, value, isActive) {
        el.textContent = value;
        el.classList.toggle("active", Boolean(isActive));
        el.classList.toggle("inactive", !isActive);
    }

    async function updateStatus() {
        try {
            const response = await fetch(ajaxUrl, { cache: "no-store" });
            const data = await response.json();
            if (!data || !data.ok) {
                return;
            }

            const selectedModuleName = typeof data.selectedModule === "string" ? data.selectedModule : "";
            if (selectedModuleName) {
                setStatusBadge(autostartStatusEl, text.autostartFor.replace("{{module}}", selectedModuleName), true);
            } else {
                setStatusBadge(autostartStatusEl, text.autostartNone, false);
            }

            if (data.activeModule) {
                activeModuleNameEl.textContent = text.activeModule;
                setStatusBadge(activeModuleStatusEl, data.activeModule, true);
                renderActiveModuleActions(String(data.activeModule), "");
            } else {
                activeModuleNameEl.textContent = text.activeModule;
                setStatusBadge(activeModuleStatusEl, text.noModuleRunning, false);
                renderActiveModuleActions("", selectedModuleName);
            }
            appendOrReplace(commonLogEl, data.commonLogs || "", "common");
        } catch (e) {
        }
    }

    updateStatus();
    setInterval(updateStatus, 2000);
</script>
<?= render_app_footer() ?>
</body>
</html>
