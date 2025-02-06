<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/image/ukraine.png" rel="icon">
    <title>ITUA</title>
    <!-- Import Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet" />
    <style>
        /* Reset some default browser styles */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Roboto', sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fa, #c3cfe2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
            padding: 20px;
        }

        .container {
            background: #fff;
            max-width: 960px;
            width: 100%;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            color: #2c3e50;
        }

        .menu {
            display: flex;
            justify-content: center;
            list-style: none;
            padding: 0;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }

        .menu li { }

        .menu a {
            display: inline-block;
            padding: 12px 20px;
            font-size: 1.1rem;
            font-weight: 500;
            text-decoration: none;
            color: #2c3e50;
            background: #ecf0f1;
            border-radius: 8px;
            transition: background 0.3s ease, transform 0.3s ease, box-shadow 0.3s ease;
        }

        .menu a:hover {
            background: #bdc3c7;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .form-container {
            max-width: 500px;
            margin: 0 auto;
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        .form-container h2 {
            text-align: center;
            margin-bottom: 20px;
            color: #2c3e50;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #2c3e50;
            outline: none;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .submit-btn {
            display: block;
            width: 100%;
            padding: 12px;
            background: #2c3e50;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 1.1rem;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .submit-btn:hover {
            background: #1a252f;
        }

        @media (max-width: 600px) {
            .menu {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

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
            $daemonName = htmlspecialchars($_GET['daemon']);
            if (!empty($_POST)) {
                if ($daemonName == 'x100') {
                    setUserIdForX100EnvFil($_POST);
                } else {
                    updateServiceFile($daemonName, updateServiceConfigParams(getConfigStringFromServiceFile($daemonName), $_POST));
                }
                echo "<span style='color: green;'>Service updated!</span>";

            }
            if ($daemonName == 'x100') {
                $currentAdjustableParams = getUserIdFromX100EnvFile($_POST);
            } else {
                $currentAdjustableParams = getCurrentAdjustableParams(getConfigStringFromServiceFile($daemonName), $config['adjustableParams'][$daemonName]);
            }

            ?>
            <h1><?= $daemonName ?> settings</h1>
            <h2>Status: </h2>
            <?php
            // Get the status of the daemon
            $status = shell_exec("systemctl is-active $daemonName");
            if (!is_null($status) && trim($status) === 'active') {
                echo "$daemonName is running.";
                echo '<div class="menu"><a href="/stop.php?daemon=' . $daemonName . '">Stop</a></div>';
            } else {
                echo "$daemonName is not running.";
                echo '<div class="menu"><a href="/start.php?daemon=' . $daemonName . '">Start</a></div>';
            }
            // Fetch recent logs
            echo "<br/>Fetching logs from journalctl:\n";
            echo nl2br(shell_exec("sudo journalctl -u $daemonName --no-pager -n 5"));
            echo "<br/><h2>Settings</h2>";
            echo '<div class="form-container">';
            include "forms/" . $daemonName . ".php";
            echo '</div>';
        } else {
            echo 'Error<br/><a href="/">Return to main menu</a>';
        }
        ?>
        <div class="menu">
            <a href="/tools_list.php">Back</a>
        </div
    </div>
</div>

</body>
</html>
