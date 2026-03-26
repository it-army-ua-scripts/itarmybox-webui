<?php
require_once 'i18n.php';
require_once 'lib/tool_helpers.php';
require_once 'lib/root_helper_client.php';
require_once 'lib/footer.php';
$config = require 'config/config.php';

$daemonName = $_GET['daemon'] ?? '';

function render_module_action_form(string $path, string $daemonName, string $label): string
{
    return '<div class="menu"><form method="post" action="' . htmlspecialchars(url_with_lang($path), ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="daemon" value="' . htmlspecialchars($daemonName, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>'
        . '</form></div>';
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_lang(), ENT_QUOTES, 'UTF-8') ?>">
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
            $info = root_helper_request([
                'action' => 'service_info',
                'modules' => $config['daemonNames'],
                'module' => $daemonName,
            ]);
            $wasActiveBeforeSave = (($info['ok'] ?? false) === true) ? (bool)($info['active'] ?? false) : false;
            if (!empty($_POST)) {
                $saveOk = false;
                $saveError = '';
                $restartError = '';
                $feedbackClass = '';
                $feedbackText = '';
                $feedbackSecondary = '';
                if ($daemonName === 'x100') {
                    $saveOk = setX100ConfigValues($_POST);
                } else {
                    $allowedParamKeys = array_flip($config['adjustableParams'][$daemonName] ?? []);
                    $paramsToSave = array_intersect_key($_POST, $allowedParamKeys);
                    if ($daemonName === 'distress') {
                        $distressValidation = normalizeAndValidateDistressPostParams($paramsToSave);
                        if (($distressValidation['ok'] ?? false) !== true) {
                            $saveError = (string)($distressValidation['error'] ?? 'invalid_distress_settings');
                        } else {
                            $paramsToSave = (array)$distressValidation['params'];
                        }
                    }
                    if ($daemonName === 'mhddos') {
                        $mhddosValidation = normalizeAndValidateMhddosPostParams($paramsToSave);
                        if (($mhddosValidation['ok'] ?? false) !== true) {
                            $saveError = (string)($mhddosValidation['error'] ?? 'invalid_mhddos_settings');
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
                if ($saveOk && $wasActiveBeforeSave) {
                    $restartResponse = root_helper_request([
                        'action' => 'service_restart',
                        'modules' => $config['daemonNames'],
                        'module' => $daemonName,
                    ]);
                    if (($restartResponse['ok'] ?? false) !== true) {
                        $restartError = 'settings_saved_restart_failed';
                    }
                }
                if ($saveOk) {
                    $feedbackClass = 'status active';
                    if ($wasActiveBeforeSave && $restartError === '') {
                        $feedbackText = t('settings_saved_and_restarted');
                    } else {
                        $feedbackText = t('settings_saved');
                    }
                    if ($restartError !== '') {
                        $feedbackSecondary = t($restartError);
                    }
                } else {
                    if ($saveError !== '') {
                        $feedbackClass = 'status inactive';
                        $feedbackText = t($saveError);
                    } else {
                        $feedbackClass = 'status inactive';
                        $feedbackText = t('error') . ': ' . t('settings_not_saved');
                    }
                }
                if ($feedbackText !== '') {
                    echo '<div class="form-message ' . htmlspecialchars($feedbackClass, ENT_QUOTES, 'UTF-8') . '">'
                        . htmlspecialchars($feedbackText, ENT_QUOTES, 'UTF-8')
                        . '</div>';
                }
                if ($feedbackSecondary !== '') {
                    echo '<div class="form-message status inactive">' . htmlspecialchars($feedbackSecondary, ENT_QUOTES, 'UTF-8') . '</div>';
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
            $isActive = (($info['ok'] ?? false) === true) ? (bool)($info['active'] ?? false) : false;
            if ($isActive) {
                echo htmlspecialchars(t('module_running', ['module' => $daemonName]), ENT_QUOTES, 'UTF-8');
                echo render_module_action_form('/stop.php', $daemonName, t('stop'));
            } else {
                echo htmlspecialchars(t('module_not_running', ['module' => $daemonName]), ENT_QUOTES, 'UTF-8');
                echo render_module_action_form('/start.php', $daemonName, t('start'));
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
            <?= render_back_link('/tools_list.php') ?>
        </div>
    </div>
</div>
<?= render_app_footer() ?>
<script src="/js/form_messages.js"></script>
</body>
</html>
