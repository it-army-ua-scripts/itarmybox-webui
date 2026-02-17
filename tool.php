<?php
require_once 'i18n.php';
require_once 'lib/tool_helpers.php';
require_once 'lib/root_helper_client.php';
$config = require 'config/config.php';

$daemonName = $_GET['daemon'] ?? '';

if (isset($_GET['ajax_info']) && $_GET['ajax_info'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!in_array($daemonName, $config['daemonNames'], true)) {
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $info = root_helper_request([
        'action' => 'service_info',
        'modules' => $config['daemonNames'],
        'module' => $daemonName,
    ]);
    echo json_encode(
        [
            'ok' => (bool)($info['ok'] ?? false),
            'info' => $info,
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
                $saveOk = false;
                if ($daemonName === 'x100') {
                    $saveOk = setX100ConfigValues($_POST);
                } else {
                    $paramsToSave = $_POST;
                    if ($daemonName === 'distress') {
                        $paramsToSave = normalizeDistressPostParams($paramsToSave);
                    }
                    $currentConfigString = getConfigStringFromServiceFile($daemonName);
                    if ($currentConfigString !== '') {
                        $saveOk = updateServiceFile(
                            $daemonName,
                            updateServiceConfigParams(
                                $currentConfigString,
                                $paramsToSave,
                                $daemonName
                            )
                        )
                        ;
                    }
                }
                if ($saveOk) {
                    echo "<span style='color: green;'>" . htmlspecialchars(t('service_updated'), ENT_QUOTES, 'UTF-8') . "</span>";
                } else {
                    echo "<span style='color: red;'>" . htmlspecialchars(t('error'), ENT_QUOTES, 'UTF-8') . ": settings were not saved</span>";
                }
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
            $info = root_helper_request([
                'action' => 'service_info',
                'modules' => $config['daemonNames'],
                'module' => $daemonName,
            ]);
            $isActive = (($info['ok'] ?? false) === true) ? (bool)($info['active'] ?? false) : false;
            if ($isActive) {
                echo htmlspecialchars(t('module_running', ['module' => $daemonName]), ENT_QUOTES, 'UTF-8');
                echo '<div class="menu"><a href="' . htmlspecialchars(url_with_lang('/stop.php?daemon=' . rawurlencode($daemonName)), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('stop'), ENT_QUOTES, 'UTF-8') . '</a></div>';
            } else {
                echo htmlspecialchars(t('module_not_running', ['module' => $daemonName]), ENT_QUOTES, 'UTF-8');
                echo '<div class="menu"><a href="' . htmlspecialchars(url_with_lang('/start.php?daemon=' . rawurlencode($daemonName)), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('start'), ENT_QUOTES, 'UTF-8') . '</a></div>';
            }

            echo "<br/><h2>" . htmlspecialchars(t('service_info'), ENT_QUOTES, 'UTF-8') . "</h2>";
            $statusText = (string)($info['statusText'] ?? '');
            $fragmentPath = (string)($info['fragmentPath'] ?? '');
            $execStart = (string)($info['execStart'] ?? '');
            $standardOutput = (string)($info['standardOutput'] ?? '');
            $autostartWanted = $info['autostartWanted'] ?? null;
            $logFile = (string)($info['logFile'] ?? '');
            $infoLines = [];
            if ($fragmentPath !== '') {
                $infoLines[] = "Unit: " . $fragmentPath;
            }
            if ($execStart !== '') {
                $infoLines[] = "ExecStart: " . $execStart;
            }
            if ($standardOutput !== '') {
                $infoLines[] = "StandardOutput: " . $standardOutput;
            }
            if ($logFile !== '') {
                $infoLines[] = "Log file: " . $logFile;
            }
            if ($autostartWanted === true) {
                $infoLines[] = "Autostart: enabled";
            } elseif ($autostartWanted === false) {
                $infoLines[] = "Autostart: disabled";
            }
            $serviceInfoText = implode("\n", $infoLines);
            if ($serviceInfoText !== '') {
                $serviceInfoText .= "\n\n";
            }
            $serviceInfoText .= $statusText;
            echo '<div class="log-box tool-log-box" id="service-info">' . htmlspecialchars($serviceInfoText, ENT_QUOTES, 'UTF-8') . '</div>';
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
    const serviceInfoState = { text: "" };
    const serviceInfoEl = document.getElementById("service-info");
    const daemonName = <?= json_encode($daemonName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const ajaxUrl = <?= json_encode(url_with_lang('/tool.php?ajax_info=1&daemon=' . rawurlencode($daemonName)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function appendOrReplace(el, newText) {
        const oldText = serviceInfoState.text || "";
        if (newText.startsWith(oldText)) {
            el.textContent += newText.slice(oldText.length);
        } else {
            el.textContent = newText;
        }
        serviceInfoState.text = newText;
        el.scrollTop = el.scrollHeight;
    }

    async function refreshInfo() {
        try {
            const response = await fetch(ajaxUrl, { cache: "no-store" });
            const data = await response.json();
            if (!data || !data.ok) {
                return;
            }
            const info = data.info || {};
            const lines = [];
            if (info.fragmentPath) lines.push("Unit: " + info.fragmentPath);
            if (info.execStart) lines.push("ExecStart: " + info.execStart);
            if (info.standardOutput) lines.push("StandardOutput: " + info.standardOutput);
            if (info.logFile) lines.push("Log file: " + info.logFile);
            if (info.autostartWanted === true) lines.push("Autostart: enabled");
            if (info.autostartWanted === false) lines.push("Autostart: disabled");
            let text = lines.join("\n");
            if (text) text += "\n\n";
            text += (info.statusText || "");
            appendOrReplace(serviceInfoEl, text);
        } catch (e) {
        }
    }

    appendOrReplace(serviceInfoEl, serviceInfoEl.textContent || "");
    setInterval(refreshInfo, 4000);
</script>
<?php endif; ?>
<footer class="app-footer">&copy; 2022-<?= date('Y') ?> IT Army of Ukraine. <?= htmlspecialchars(t('footer_slogan'), ENT_QUOTES, 'UTF-8') ?>.</footer>
</body>
</html>
