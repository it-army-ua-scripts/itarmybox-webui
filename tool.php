<?php
require_once 'i18n.php';
require_once 'lib/tool_helpers.php';
$config = require 'config/config.php';

$daemonName = $_GET['daemon'] ?? '';

if (isset($_GET['ajax_logs']) && $_GET['ajax_logs'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!in_array($daemonName, $config['daemonNames'], true)) {
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $daemonSafe = escapeshellarg($daemonName);
    $status = trim((string)shell_exec("systemctl is-active -- $daemonSafe"));
    echo json_encode(
        [
            'ok' => true,
            'status' => $status,
            'logs' => getServiceLogs($daemonName)
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
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet" />
    <link href="/styles.css" rel="stylesheet" />
</head>
<body class="padded">

<div class="container">
    <div class="content">
        <?php
        if (in_array($daemonName, $config['daemonNames'], true)) {
            if (!empty($_POST)) {
                if ($daemonName === 'x100') {
                    setX100ConfigValues($_POST);
                } else {
                    $paramsToSave = $_POST;
                    if ($daemonName === 'distress') {
                        $paramsToSave = normalizeDistressPostParams($paramsToSave);
                    }
                    updateServiceFile(
                        $daemonName,
                        updateServiceConfigParams(
                            getConfigStringFromServiceFile($daemonName),
                            $paramsToSave,
                            $daemonName
                        )
                    );
                }
                echo "<span style='color: green;'>" . htmlspecialchars(t('service_updated'), ENT_QUOTES, 'UTF-8') . "</span>";
            }

            if ($daemonName === 'x100') {
                $currentAdjustableParams = getX100ConfigValues();
            } else {
                $currentAdjustableParams = getCurrentAdjustableParams(
                    getConfigStringFromServiceFile($daemonName),
                    $config['adjustableParams'][$daemonName],
                    $daemonName
                );
            }
            ?>
            <h1><?= htmlspecialchars(t('settings_for', ['module' => $daemonName]), ENT_QUOTES, 'UTF-8') ?></h1>
            <h2><?= htmlspecialchars(t('status_label'), ENT_QUOTES, 'UTF-8') ?></h2>
            <?php
            $status = trim((string)shell_exec("systemctl is-active " . escapeshellarg($daemonName)));
            if ($status === 'active') {
                echo htmlspecialchars(t('module_running', ['module' => $daemonName]), ENT_QUOTES, 'UTF-8');
                echo '<div class="menu"><a href="' . htmlspecialchars(url_with_lang('/stop.php?daemon=' . rawurlencode($daemonName)), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('stop'), ENT_QUOTES, 'UTF-8') . '</a></div>';
            } else {
                echo htmlspecialchars(t('module_not_running', ['module' => $daemonName]), ENT_QUOTES, 'UTF-8');
                echo '<div class="menu"><a href="' . htmlspecialchars(url_with_lang('/start.php?daemon=' . rawurlencode($daemonName)), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('start'), ENT_QUOTES, 'UTF-8') . '</a></div>';
            }

            echo "<br/>" . htmlspecialchars(t('fetching_logs'), ENT_QUOTES, 'UTF-8') . "<br/>";
            $initialLogs = getServiceLogs($daemonName);
            echo '<div class="log-box tool-log-box" id="daemon-log">' . htmlspecialchars($initialLogs, ENT_QUOTES, 'UTF-8') . '</div>';
            echo "<br/><h2>" . htmlspecialchars(t('settings'), ENT_QUOTES, 'UTF-8') . "</h2>";
            echo '<div class="form-container">';
            include "forms/" . $daemonName . ".php";
            echo '</div>';
        } else {
            echo htmlspecialchars(t('error'), ENT_QUOTES, 'UTF-8') . '<br/><a href="' . htmlspecialchars(url_with_lang('/'), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('return_to_main_menu'), ENT_QUOTES, 'UTF-8') . '</a>';
        }
        ?>
        <div class="menu">
            <a href="<?= htmlspecialchars(url_with_lang('/tools_list.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
    </div>
</div>
<?php if (in_array($daemonName, $config['daemonNames'], true)): ?>
<script>
    const daemonLogState = { text: "" };
    const daemonLogEl = document.getElementById("daemon-log");
    const daemonName = <?= json_encode($daemonName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const ajaxUrl = <?= json_encode(url_with_lang('/tool.php?ajax_logs=1&daemon=' . rawurlencode($daemonName)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function appendOrReplace(el, newText) {
        const oldText = daemonLogState.text || "";
        if (newText.startsWith(oldText)) {
            el.textContent += newText.slice(oldText.length);
        } else {
            el.textContent = newText;
        }
        daemonLogState.text = newText;
        el.scrollTop = el.scrollHeight;
    }

    async function refreshLogs() {
        try {
            const response = await fetch(ajaxUrl, { cache: "no-store" });
            const data = await response.json();
            if (!data || !data.ok) {
                return;
            }
            appendOrReplace(daemonLogEl, data.logs || "");
        } catch (e) {
        }
    }

    appendOrReplace(daemonLogEl, daemonLogEl.textContent || "");
    setInterval(refreshLogs, 2000);
</script>
<?php endif; ?>
<footer class="app-footer">&copy; 2022-<?= date('Y') ?> IT Army of Ukraine. <?= htmlspecialchars(t('footer_slogan'), ENT_QUOTES, 'UTF-8') ?>.</footer>
</body>
</html>
