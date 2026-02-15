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
        <?php
        $config = require 'config/config.php';

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
            foreach ($adjustableParams as $adjustableParam) {
                foreach ($configAsArray as $key => $param) {
                    if ('--' . $adjustableParam == $param) {
                        $currentAdjustableParams[$adjustableParam] = $configAsArray[$key + 1];
                    }
                }
            }
            return $currentAdjustableParams;
        }

        function updateServiceConfigParams(string $configString, array $updatedParams): array
        {
            $configAsArray = str_getcsv($configString, ' ');
            foreach ($updatedParams as $updatedParamKey => $updatedParam) {
                foreach ($configAsArray as $key => $param) {
                    if ('--' . $updatedParamKey == $param) {
                        $configAsArray[$key + 1] = $updatedParam;
                    }
                }
                if (!in_array('--' . $updatedParamKey, $configAsArray)) {
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
            echo nl2br((string)shell_exec("sudo journalctl -u " . escapeshellarg($daemonName) . " --no-pager -n 5"));
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

</body>
</html>
