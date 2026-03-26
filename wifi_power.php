<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/root_helper_client.php';

const WIFI_AP_INTERFACE = 'wlan0';
const WIFI_TXPOWER_MIN_DBM = '0.50';
const WIFI_TXPOWER_MAX_DBM = '31.00';

$modules = (require 'config/config.php')['daemonNames'];
$message = '';
$messageClass = '';

function wifi_power_status(array $modules): array
{
    return root_helper_request([
        'action' => 'wifi_txpower_get',
        'modules' => $modules,
    ]);
}

function format_dbm_value($value): string
{
    return is_string($value) && preg_match('/^\d+(?:\.\d+)?$/', $value) === 1
        ? number_format((float)$value, 2, '.', '')
        : WIFI_TXPOWER_MAX_DBM;
}

$status = wifi_power_status($modules);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbmRaw = trim((string)($_POST['wifi_txpower_dbm'] ?? ''));
    if (
        $dbmRaw === '' ||
        preg_match('/^\d+(?:\.\d{1,2})?$/', $dbmRaw) !== 1 ||
        (float)$dbmRaw < (float)WIFI_TXPOWER_MIN_DBM ||
        (float)$dbmRaw > (float)WIFI_TXPOWER_MAX_DBM
    ) {
        $message = t('wifi_ap_power_invalid');
        $messageClass = 'status inactive';
    } else {
        $response = root_helper_request([
            'action' => 'wifi_txpower_set',
            'modules' => $modules,
            'dbm' => $dbmRaw,
        ]);
        $ok = (($response['ok'] ?? false) === true);
        $message = $ok ? t('wifi_ap_power_saved') : t('wifi_ap_power_failed');
        $messageClass = $ok ? 'status active' : 'status inactive';
        $status = wifi_power_status($modules);
    }
}

$currentDbm = (($status['ok'] ?? false) === true) ? format_dbm_value((string)($status['currentDbm'] ?? WIFI_TXPOWER_MAX_DBM)) : null;
$defaultDbm = format_dbm_value((string)($status['defaultDbm'] ?? WIFI_TXPOWER_MAX_DBM));
$maxDbm = format_dbm_value((string)($status['maxDbm'] ?? WIFI_TXPOWER_MAX_DBM));
$iface = WIFI_AP_INTERFACE;
$inputValue = $currentDbm ?? $defaultDbm;
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
        <h1><?= htmlspecialchars(t('wifi_ap_power_title'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($message !== ''): ?>
            <div class="form-message <?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="service" style="max-width: 760px; width: 100%; text-align: left;">
            <div class="service-title"><?= htmlspecialchars(strtoupper($iface), ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong><?= htmlspecialchars(t('wifi_ap_power_current'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($currentDbm !== null ? ($currentDbm . ' dBm') : t('wifi_ap_power_unavailable'), ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong><?= htmlspecialchars(t('wifi_ap_power_default'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($defaultDbm . ' dBm', ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong><?= htmlspecialchars(t('wifi_ap_power_max'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($maxDbm . ' dBm', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint" style="margin-top: 10px;"><?= htmlspecialchars(t('wifi_ap_power_help'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint"><?= htmlspecialchars(t('wifi_ap_power_persist_hint'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <div class="form-container">
            <form method="post" action="">
                <div class="form-group">
                    <label for="wifi_txpower_dbm"><?= htmlspecialchars(t('wifi_ap_power_target'), ENT_QUOTES, 'UTF-8') ?></label>
                    <div class="power-lever-head schedule-power-head">
                        <div class="power-lever-value schedule-power-value">
                            <span id="wifi-power-value"><?= htmlspecialchars($inputValue, ENT_QUOTES, 'UTF-8') ?> dBm</span>
                        </div>
                    </div>
                    <input
                        type="range"
                        min="<?= htmlspecialchars(WIFI_TXPOWER_MIN_DBM, ENT_QUOTES, 'UTF-8') ?>"
                        max="31"
                        step="0.01"
                        value="<?= htmlspecialchars($inputValue, ENT_QUOTES, 'UTF-8') ?>"
                        id="wifi_txpower_slider"
                        class="power-slider schedule-power-slider"
                    >
                    <div class="power-scale schedule-power-scale">
                        <span><?= htmlspecialchars(WIFI_TXPOWER_MIN_DBM, ENT_QUOTES, 'UTF-8') ?></span>
                        <span>10.00</span>
                        <span>20.00</span>
                        <span><?= htmlspecialchars(WIFI_TXPOWER_MAX_DBM, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <input
                        type="number"
                        min="<?= htmlspecialchars(WIFI_TXPOWER_MIN_DBM, ENT_QUOTES, 'UTF-8') ?>"
                        max="31"
                        step="0.01"
                        id="wifi_txpower_dbm"
                        name="wifi_txpower_dbm"
                        value="<?= htmlspecialchars($inputValue, ENT_QUOTES, 'UTF-8') ?>"
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
<script>
(() => {
    const slider = document.getElementById('wifi_txpower_slider');
    const input = document.getElementById('wifi_txpower_dbm');
    const valueEl = document.getElementById('wifi-power-value');
    if (!slider || !input || !valueEl) {
        return;
    }

    function normalize(value) {
        const numeric = Math.max(0.5, Math.min(31, Number(value) || 0.5));
        return numeric.toFixed(2);
    }

    function render(value) {
        const normalized = normalize(value);
        slider.value = normalized;
        input.value = normalized;
        valueEl.textContent = normalized + ' dBm';
        slider.style.setProperty('--power-fill', (Number(normalized) / 31 * 100).toFixed(2) + '%');
    }

    slider.addEventListener('input', () => render(slider.value));
    input.addEventListener('input', () => render(input.value));
    render(slider.value);
})();
</script>
</body>
</html>
