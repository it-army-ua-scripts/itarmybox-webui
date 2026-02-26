<?php
require_once 'i18n.php';
require_once 'lib/tool_helpers.php';
require_once 'lib/root_helper_client.php';
require_once 'lib/footer.php';
$config = require 'config/config.php';

$daemonName = $_GET['daemon'] ?? '';
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
                $saveError = '';
                $restartError = '';
                if ($daemonName === 'x100') {
                    $saveOk = setX100ConfigValues($_POST);
                } else {
                    $allowedParamKeys = array_flip($config['adjustableParams'][$daemonName] ?? []);
                    $paramsToSave = array_intersect_key($_POST, $allowedParamKeys);
                    if ($daemonName === 'distress') {
                        $distressValidation = normalizeAndValidateDistressPostParams($paramsToSave);
                        if (($distressValidation['ok'] ?? false) !== true) {
                            $saveError = (string)($distressValidation['error'] ?? 'Invalid distress settings.');
                        } else {
                            $paramsToSave = (array)$distressValidation['params'];
                        }
                    }
                    if ($daemonName === 'mhddos') {
                        $mhddosValidation = normalizeAndValidateMhddosPostParams($paramsToSave);
                        if (($mhddosValidation['ok'] ?? false) !== true) {
                            $saveError = (string)($mhddosValidation['error'] ?? 'Invalid mhddos settings.');
                        } else {
                            $paramsToSave = (array)$mhddosValidation['params'];
                        }
                    }
                    $currentConfigString = getConfigStringFromServiceFile($daemonName);
                    if ($saveError === '' && $currentConfigString !== '') {
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
                    $restartResponse = root_helper_request([
                        'action' => 'service_restart',
                        'modules' => $config['daemonNames'],
                        'module' => $daemonName,
                    ]);
                    if (($restartResponse['ok'] ?? false) !== true) {
                        $restartError = 'Settings saved, but module restart failed.';
                    }
                }
                if ($saveOk) {
                    echo "<span style='color: green;'>" . htmlspecialchars(t('service_updated'), ENT_QUOTES, 'UTF-8') . "</span>";
                    if ($restartError !== '') {
                        echo "<br><span style='color: red;'>" . htmlspecialchars($restartError, ENT_QUOTES, 'UTF-8') . "</span>";
                    }
                } else {
                    if ($saveError !== '') {
                        echo "<span style='color: red;'>" . htmlspecialchars($saveError, ENT_QUOTES, 'UTF-8') . "</span>";
                    } else {
                        echo "<span style='color: red;'>" . htmlspecialchars(t('error'), ENT_QUOTES, 'UTF-8') . ": settings were not saved</span>";
                    }
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

            $statusText = trim((string)($info['statusText'] ?? ''));
            if ($statusText !== '') {
                echo '<br/><h2>systemctl status ' . htmlspecialchars($daemonName, ENT_QUOTES, 'UTF-8') . '</h2>';
                echo '<div class="log-box tool-log-box">' . htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') . '</div>';
            }

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
<?= render_app_footer() ?>
</body>
</html>
