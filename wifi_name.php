<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/root_helper_client.php';

const WIFI_AP_INTERFACE = 'wlan0';
const WIFI_AP_DEFAULT_NAME = 'Artline';

$modules = (require 'config/config.php')['daemonNames'];
$message = '';
$messageClass = '';
if (isset($_GET['flash']) && is_string($_GET['flash']) && $_GET['flash'] !== '') {
    $message = (string)$_GET['flash'];
    $messageClass = ((string)($_GET['flashClass'] ?? '') === 'active') ? 'status active' : 'status inactive';
}

function wifi_name_status(array $modules): array
{
    return root_helper_request([
        'action' => 'wifi_ap_name_get',
        'modules' => $modules,
    ]);
}

function normalize_ssid_input($value): string
{
    return (string)$value;
}

$status = wifi_name_status($modules);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ssid = normalize_ssid_input($_POST['wifi_ap_name'] ?? '');
    if (
        $ssid === '' ||
        $ssid !== trim($ssid) ||
        strlen($ssid) > 32 ||
        preg_match('/^[\x20-\x7E]+$/', $ssid) !== 1
    ) {
        header('Location: ' . build_page_url('/wifi_name.php', [
            'flash' => t('wifi_ap_name_invalid'),
            'flashClass' => 'inactive',
        ]));
        exit;
    } else {
        $response = root_helper_request([
            'action' => 'wifi_ap_name_set',
            'modules' => $modules,
            'ssid' => $ssid,
        ]);
        if (($response['ok'] ?? false) !== true && (($response['error'] ?? '') === 'root_helper_reloaded_retry')) {
            $response = root_helper_request([
                'action' => 'wifi_ap_name_set',
                'modules' => $modules,
                'ssid' => $ssid,
            ]);
        }
        $ok = (($response['ok'] ?? false) === true);
        $message = $ok ? t('wifi_ap_name_saved') : t('wifi_ap_name_failed');
        if (!$ok && isset($response['error']) && is_string($response['error']) && $response['error'] !== '') {
            $message .= ' (' . $response['error'] . ')';
        }
        header('Location: ' . build_page_url('/wifi_name.php', [
            'flash' => $message,
            'flashClass' => $ok ? 'active' : 'inactive',
        ]));
        exit;
    }
}

$currentSsid = (($status['ok'] ?? false) === true) ? (string)($status['ssid'] ?? WIFI_AP_DEFAULT_NAME) : WIFI_AP_DEFAULT_NAME;
$iface = WIFI_AP_INTERFACE;
$inputValue = $currentSsid !== '' ? $currentSsid : WIFI_AP_DEFAULT_NAME;
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
    <div class="content centered">
        <h1><?= htmlspecialchars(t('wifi_ap_name_title'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($message !== ''): ?>
            <div class="form-message <?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="service" style="max-width: 760px; width: 100%; text-align: left;">
            <div class="service-title"><?= htmlspecialchars(strtoupper($iface), ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong><?= htmlspecialchars(t('wifi_ap_name_current'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($currentSsid, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint" style="margin-top: 10px;"><?= htmlspecialchars(t('wifi_ap_name_help'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint"><?= htmlspecialchars(t('wifi_ap_name_rules'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <div class="form-container">
            <form method="post" action="">
                <div class="form-group">
                    <label for="wifi_ap_name"><?= htmlspecialchars(t('wifi_ap_name_target'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input
                        type="text"
                        id="wifi_ap_name"
                        name="wifi_ap_name"
                        maxlength="32"
                        pattern="[\x20-\x7E]{1,32}"
                        value="<?= htmlspecialchars($inputValue, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="<?= htmlspecialchars(t('wifi_ap_name_placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>
                <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        </div>

        <div class="menu centered">
            <?= render_back_link('/wifi_settings.php') ?>
        </div>
    </div>
</div>
<?= render_app_footer() ?>
</body>
</html>
