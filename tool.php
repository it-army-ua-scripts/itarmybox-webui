<?php
require_once 'i18n.php';
$config = require 'config/config.php';

function getServiceLogs(string $serviceName): string
{
    $serviceSafe = escapeshellarg($serviceName);
    $logs = (string)shell_exec("sudo -n journalctl -u $serviceSafe --no-pager -n 5 2>/dev/null");
    if (trim($logs) === '') {
        $logs = (string)shell_exec("journalctl -u $serviceSafe --no-pager -n 5 2>/dev/null");
    }
    if (trim($logs) === '') {
        $logs = "No journal entries available for this service.";
    }
    $logs = preg_replace('/^[ \t]+/m', '', $logs) ?? $logs;
    return $logs;
}

if (isset($_GET['ajax_logs']) && $_GET['ajax_logs'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    $daemon = $_GET['daemon'] ?? '';
    if (!in_array($daemon, $config['daemonNames'], true)) {
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $daemonSafe = escapeshellarg($daemon);
    $status = trim((string)shell_exec("systemctl is-active -- $daemonSafe"));
    echo json_encode(
        [
            'ok' => true,
            'status' => $status,
            'logs' => getServiceLogs($daemon)
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
    <!-- Import Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet" />
    <link href="/styles.css" rel="stylesheet" />
</head>
<body class="padded">

<div class="container">
    <div class="content">
        <?php
        function getConfigStringFromServiceFile(string $serviceName): string
        {
            $pattern = '/ExecStart=/';
            $handle = fopen('/opt/itarmy/services/' . $serviceName . '.service', "r");

            $result = '';
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    if (preg_match($pattern, $line)) {
                        $result = trim($line);
                    }
                }
                fclose($handle);
            } else {
                echo "Could not open the file!";
            }
            return $result;
        }

        function getCurrentAdjustableParams(string $configString, array $adjustableParams): array
        {
            $configAsArray = str_getcsv($configString, ' ');
            $currentAdjustableParams = [];
            $aliases = [
                'ifaces' => ['bind'],
                'bind' => ['ifaces']
            ];
            foreach ($adjustableParams as $adjustableParam) {
                foreach ($configAsArray as $key => $param) {
                    if ('--' . $adjustableParam == $param) {
                        $currentAdjustableParams[$adjustableParam] = $configAsArray[$key + 1];
                    }
                }
                if (!isset($currentAdjustableParams[$adjustableParam]) && isset($aliases[$adjustableParam])) {
                    foreach ($aliases[$adjustableParam] as $alias) {
                        foreach ($configAsArray as $key => $param) {
                            if ('--' . $alias == $param) {
                                $currentAdjustableParams[$adjustableParam] = $configAsArray[$key + 1];
                            }
                        }
                    }
                }
            }
            return $currentAdjustableParams;
        }

        function updateServiceConfigParams(string $configString, array $updatedParams): array
        {
            $configAsArray = str_getcsv($configString, ' ');
            $aliases = [
                'ifaces' => ['bind'],
                'bind' => ['ifaces']
            ];
            foreach ($updatedParams as $updatedParamKey => $updatedParam) {
                $updatedParam = trim((string)$updatedParam);
                $allKeys = array_merge([$updatedParamKey], $aliases[$updatedParamKey] ?? []);
                $found = false;

                foreach ($configAsArray as $key => $param) {
                    foreach ($allKeys as $optionKey) {
                        if ('--' . $optionKey === $param) {
                            $found = true;
                            if ($updatedParam === '') {
                                unset($configAsArray[$key], $configAsArray[$key + 1]);
                            } else {
                                $configAsArray[$key] = '--' . $updatedParamKey;
                                $configAsArray[$key + 1] = $updatedParam;
                            }
                        }
                    }
                }

                $configAsArray = array_values($configAsArray);
                if ($updatedParam !== '' && !$found) {
                    $configAsArray[] = '--' . $updatedParamKey;
                    $configAsArray[] = $updatedParam;
                }
            }
            return $configAsArray;
        }

        function updateServiceFile(string $serviceName, array $updatedConfigParams): void
        {
            $pattern = "/ExecStart=.*/";
            $serviceFile = '/opt/itarmy/services/' . $serviceName . '.service';
            $content = file_get_contents($serviceFile);
            $content = preg_replace($pattern, implode(" ", $updatedConfigParams), $content, 1);
            file_put_contents($serviceFile, $content);
        }

        function setUserIdForX100EnvFil(array $updatedConfig): void
        {
            $pattern = "/itArmyUserId=.*/";
            $envFile = '/opt/itarmy/x100-for-docker/put-your-ovpn-files-here/x100-config.txt';

            $content = file_get_contents($envFile);
            $content = preg_replace($pattern, "itArmyUserId=" . $updatedConfig['itArmyUserId'], $content, 1);
            file_put_contents($envFile, $content);
        }

        function getUserIdFromX100EnvFile(): array
        {
            $pattern = "/itArmyUserId=.*/";
            $handle = fopen('/opt/itarmy/x100-for-docker/put-your-ovpn-files-here/x100-config.txt', "r");
            $result=[];
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    if (preg_match($pattern, $line)) {
                        $result['itArmyUserId'] = str_replace('itArmyUserId=', '', trim($line));
                    }
                }
                fclose($handle);
            }
            return $result;
        }

        function restartService($serviceName): void
        {
            shell_exec("sudo systemctl daemon-reload && sudo systemctl restart {$serviceName}.service 2>&1");
        }

        if (isset($_GET['daemon']) && in_array($_GET['daemon'], $config['daemonNames'])) {
            $daemonName = $_GET['daemon'];
            if (!empty($_POST)) {
                if ($daemonName == 'x100') {
                    setUserIdForX100EnvFil($_POST);
                } else {
                    updateServiceFile($daemonName, updateServiceConfigParams(getConfigStringFromServiceFile($daemonName), $_POST));
                }
                echo "<span style='color: green;'>" . htmlspecialchars(t('service_updated'), ENT_QUOTES, 'UTF-8') . "</span>";

            }
            if ($daemonName == 'x100') {
                $currentAdjustableParams = getUserIdFromX100EnvFile();
            } else {
                $currentAdjustableParams = getCurrentAdjustableParams(getConfigStringFromServiceFile($daemonName), $config['adjustableParams'][$daemonName]);
            }

            ?>
            <h1><?= htmlspecialchars(t('settings_for', ['module' => $daemonName]), ENT_QUOTES, 'UTF-8') ?></h1>
            <h2><?= htmlspecialchars(t('status_label'), ENT_QUOTES, 'UTF-8') ?></h2>
            <?php
            // Get the status of the daemon
            $status = shell_exec("systemctl is-active " . escapeshellarg($daemonName));
            if (!is_null($status) && trim($status) === 'active') {
                echo htmlspecialchars(t('module_running', ['module' => $daemonName]), ENT_QUOTES, 'UTF-8');
                echo '<div class="menu"><a href="' . htmlspecialchars(url_with_lang('/stop.php?daemon=' . rawurlencode($daemonName)), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('stop'), ENT_QUOTES, 'UTF-8') . '</a></div>';
            } else {
                echo htmlspecialchars(t('module_not_running', ['module' => $daemonName]), ENT_QUOTES, 'UTF-8');
                echo '<div class="menu"><a href="' . htmlspecialchars(url_with_lang('/start.php?daemon=' . rawurlencode($daemonName)), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('start'), ENT_QUOTES, 'UTF-8') . '</a></div>';
            }
            // Fetch recent logs
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
<?php if (isset($daemonName) && in_array($daemonName, $config['daemonNames'], true)): ?>
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
</body>
</html>
