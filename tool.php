<?php
require_once 'i18n.php';
require_once 'lib/tool_page_helpers.php';
require_once 'lib/footer.php';
$config = require 'config/config.php';

$daemonName = $_GET['daemon'] ?? '';
$isAjaxRequest = (($_GET['ajax'] ?? '') === '1');
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
            $allowedFlashKeys = tool_allowed_flash_keys();
            $info = tool_service_info($config['daemonNames'], $daemonName);
            $wasActiveBeforeSave = (($info['ok'] ?? false) === true) ? (bool)($info['active'] ?? false) : false;
            if (!empty($_POST)) {
                if ($isAjaxRequest && $daemonName === 'distress' && (string)($_POST['distress-action'] ?? '') === 'measure-upload-cap') {
                    $measureResponse = measureDistressUploadCap();
                    $measureOk = (($measureResponse['ok'] ?? false) === true);
                    $flashKey = $measureOk ? 'distress_upload_cap_measure_success' : 'distress_upload_cap_measure_failed';
                    $secondaryKey = $measureOk
                        ? ''
                        : ((string)($measureResponse['error'] ?? '') === 'distress_upload_cap_measure_failed'
                            ? ''
                            : (string)($measureResponse['error'] ?? ''));
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode([
                        'ok' => $measureOk,
                        'flashKey' => $flashKey,
                        'flashText' => t($flashKey),
                        'flashClass' => $measureOk ? 'status active' : 'status inactive',
                        'secondaryKey' => $secondaryKey,
                        'secondaryText' => ($secondaryKey !== '' && in_array($secondaryKey, $allowedFlashKeys, true)) ? t($secondaryKey) : '',
                        'uploadCapStatus' => (string)($measureResponse['uploadCapStatus'] ?? 'idle'),
                        'uploadCapMbps' => isset($measureResponse['uploadCapMbps']) && is_numeric($measureResponse['uploadCapMbps'])
                            ? (float)$measureResponse['uploadCapMbps']
                            : null,
                        'uploadCapMeasuredAt' => isset($measureResponse['uploadCapMeasuredAt']) && is_numeric($measureResponse['uploadCapMeasuredAt'])
                            ? (int)$measureResponse['uploadCapMeasuredAt']
                            : null,
                        'uploadCapLastMethod' => isset($measureResponse['uploadCapLastMethod']) && is_string($measureResponse['uploadCapLastMethod'])
                            ? $measureResponse['uploadCapLastMethod']
                            : '',
                        'uploadCapLastError' => isset($measureResponse['uploadCapLastError']) && is_string($measureResponse['uploadCapLastError'])
                            ? trim($measureResponse['uploadCapLastError'])
                            : '',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }
                $redirectParams = tool_handle_post($config, $daemonName, $_POST, $wasActiveBeforeSave);
                header('Location: ' . build_tool_url($daemonName, $redirectParams));
                exit;
            }

            $flashKey = (string)($_GET['flash'] ?? '');
            $flashClass = ((string)($_GET['flashClass'] ?? '') === 'active') ? 'status active' : 'status inactive';
            $flashSecondaryKey = (string)($_GET['flashSecondary'] ?? '');
            if (in_array($flashKey, $allowedFlashKeys, true)) {
                echo '<div class="form-message ' . htmlspecialchars($flashClass, ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars(t($flashKey), ENT_QUOTES, 'UTF-8')
                    . '</div>';
            }
            if (in_array($flashSecondaryKey, $allowedFlashKeys, true)) {
                echo '<div class="form-message status inactive">' . htmlspecialchars(t($flashSecondaryKey), ENT_QUOTES, 'UTF-8') . '</div>';
            }

            $currentAdjustableParams = tool_current_adjustable_params($config, $daemonName);
            $distressStartMode = 'manual';
            $distressHasUploadCapMeasurement = false;
            if ($daemonName === 'distress') {
                $distressStartSettings = getDistressAutotuneSettings();
                $distressStartMode = (($distressStartSettings['enabled'] ?? false) === true) ? 'auto' : 'manual';
                $distressHasUploadCapMeasurement =
                    isset($distressStartSettings['uploadCapMeasuredAt']) && is_numeric($distressStartSettings['uploadCapMeasuredAt']) && (int)$distressStartSettings['uploadCapMeasuredAt'] > 0
                    && isset($distressStartSettings['uploadCapMbps']) && is_numeric($distressStartSettings['uploadCapMbps']) && (float)$distressStartSettings['uploadCapMbps'] > 0.0;
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
                if ($daemonName === 'distress') {
                    $startHref = url_with_lang('/start.php?daemon=' . rawurlencode($daemonName) . '&source=tool_action');
                    $startLinkClasses = ['distress-start-link'];
                    $startHintText = '';
                    if ($distressStartMode === 'auto' && !$distressHasUploadCapMeasurement) {
                        $startLinkClasses[] = 'is-blocked';
                        $startHintText = t('distress_start_auto_blocked');
                    } elseif ($distressStartMode === 'auto') {
                        $startLinkClasses[] = 'is-ready';
                        $startHintText = t('distress_start_auto_ready');
                    } else {
                        $startLinkClasses[] = 'is-manual';
                    }

                    echo '<div class="menu distress-start-menu"><a'
                        . ' id="distress-start-link"'
                        . ' class="' . htmlspecialchars(implode(' ', $startLinkClasses), ENT_QUOTES, 'UTF-8') . '"'
                        . ' href="' . htmlspecialchars($startHref, ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-start-href="' . htmlspecialchars($startHref, ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-mode="' . htmlspecialchars($distressStartMode, ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-has-measurement="' . ($distressHasUploadCapMeasurement ? '1' : '0') . '"'
                        . ' aria-disabled="' . (($distressStartMode === 'auto' && !$distressHasUploadCapMeasurement) ? 'true' : 'false') . '"'
                        . '>'
                        . htmlspecialchars(t('start'), ENT_QUOTES, 'UTF-8')
                        . '</a></div>';
                    echo '<div'
                        . ' class="schedule-limit-hint distress-start-gate-hint'
                        . (($distressStartMode === 'auto' && !$distressHasUploadCapMeasurement) ? ' is-blocked' : (($distressStartMode === 'auto') ? ' is-ready' : ''))
                        . '" id="distress-start-gate-hint"'
                        . ($startHintText === '' ? ' hidden' : '')
                        . '>'
                        . htmlspecialchars($startHintText, ENT_QUOTES, 'UTF-8')
                        . '</div>';
                } else {
                    echo render_module_action_link('/start.php', $daemonName, t('start'));
                }
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
