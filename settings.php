<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/root_helper_client.php';

function reset_encode_state_param($value): ?string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return null;
    }

    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

function reset_decode_state_param($value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $normalized = strtr($value, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($normalized, true);
    if (!is_string($decoded) || $decoded === '') {
        return [];
    }

    $data = json_decode($decoded, true);
    return is_array($data) ? $data : [];
}

function reset_ui_catalog(string $lang): array
{
    if ($lang === 'uk') {
        return [
            'journalTitle' => 'Журнал скидання',
            'rollbackOk' => 'Відкат успішно завершено.',
            'rollbackFailed' => 'Відкат виконано частково або з помилками.',
            'rollbackCounts' => 'Відкотити вдалося: {{completed}}. Помилок під час відкату: {{failed}}.',
            'applyOk' => 'Виконано',
            'applyFailed' => 'Помилка',
            'rollbackStepOk' => 'Відкат виконано',
            'rollbackStepFailed' => 'Відкат не вдався',
            'details' => [
                'none_active' => 'активних модулів не було',
                'handler_missing' => 'обробник відкату відсутній',
                'add_wants_failed' => 'не вдалося відновити systemd wants для автостарту',
                'autostart_verification_failed' => 'перевірка стану автостарту не пройшла',
                'crontab_apply_failed' => 'не вдалося застосувати crontab',
                'traffic_limit_apply_failed' => 'не вдалося застосувати новий ліміт трафіку',
                'hostapd_config_unavailable' => 'конфігурація hostapd недоступна',
                'hostapd_restart_failed' => 'не вдалося перезапустити hostapd',
                'wifi_txpower_apply_failed' => 'не вдалося застосувати потужність Wi‑Fi',
                'timedatectl_not_found' => 'timedatectl недоступний у системі',
                'set_timezone_failed' => 'не вдалося змінити таймзону',
                'set_ntp_failed' => 'не вдалося змінити стан NTP',
                'daemon_reload_failed' => 'не вдалося виконати systemctl daemon-reload',
                'service_switch_failed' => 'не вдалося змінити стан одного або кількох сервісів',
                'service_state_verification_failed' => 'перевірка фактичного стану сервісів не пройшла',
                'distress_autotune_state_write_failed' => 'не вдалося записати стан distress autotune',
            ],
            'errors' => [
                'socket_unavailable' => 'Не вдалося зв’язатися з root helper.',
                'socket_timeout' => 'Скидання триває занадто довго або root helper не відповів вчасно.',
                'empty_response' => 'Root helper повернув порожню відповідь.',
                'invalid_response' => 'Отримано некоректну відповідь від root helper.',
                'reset_active_stop_failed' => 'Не вдалося зупинити активні модулі перед скиданням.',
                'reset_mhddos_failed' => 'Не вдалося скинути параметри mhddos.',
                'reset_distress_failed' => 'Не вдалося скинути параметри distress.',
                'reset_x100_failed' => 'Не вдалося скинути параметри x100.',
                'reset_autostart_failed' => 'Не вдалося вимкнути автостарт.',
                'reset_schedule_failed' => 'Не вдалося очистити розклад.',
                'reset_traffic_failed' => 'Не вдалося повернути ліміт трафіку до стандартного значення.',
                'reset_wifi_name_failed' => 'Не вдалося повернути стандартну назву Wi‑Fi.',
                'reset_wifi_power_failed' => 'Не вдалося повернути стандартну потужність Wi‑Fi.',
                'reset_time_sync_failed' => 'Не вдалося повернути стандартні налаштування таймзони та NTP.',
                'reset_update_branch_failed' => 'Не вдалося повернути стандартну гілку оновлення.',
            ],
            'steps' => [
                'stop_active_modules' => 'Зупинка активних модулів',
                'reset_mhddos' => 'Скидання параметрів mhddos',
                'reset_distress' => 'Скидання параметрів distress',
                'reset_x100' => 'Скидання параметрів x100',
                'clear_autostart' => 'Вимкнення автостарту',
                'clear_schedule' => 'Очищення розкладу',
                'reset_traffic_limit' => 'Повернення стандартного ліміту трафіку',
                'reset_wifi_name' => 'Повернення стандартної назви Wi‑Fi',
                'reset_wifi_power' => 'Повернення стандартної потужності Wi‑Fi',
                'reset_time_sync' => 'Повернення стандартних налаштувань часу',
                'reset_update_branch' => 'Повернення стандартної гілки оновлення',
                'restore_update_branch' => 'Відновлення попередньої гілки оновлення',
                'restore_time_sync' => 'Відновлення попередніх налаштувань часу',
                'restore_wifi_power' => 'Відновлення попередньої потужності Wi‑Fi',
                'restore_wifi_name' => 'Відновлення попередньої назви Wi‑Fi',
                'restore_traffic_limit' => 'Відновлення попереднього ліміту трафіку',
                'restore_schedule' => 'Відновлення попереднього розкладу',
                'restore_autostart' => 'Відновлення попереднього автостарту',
                'restore_x100' => 'Відновлення попередніх параметрів x100',
                'restore_distress' => 'Відновлення попередніх параметрів distress',
                'restore_mhddos' => 'Відновлення попередніх параметрів mhddos',
                'restore_active_modules' => 'Повторний запуск модулів, що були активні до скидання',
            ],
        ];
    }

    return [
        'journalTitle' => 'Reset Journal',
        'rollbackOk' => 'Rollback completed successfully.',
        'rollbackFailed' => 'Rollback completed only partially or with errors.',
        'rollbackCounts' => 'Rollback steps completed: {{completed}}. Rollback failures: {{failed}}.',
        'applyOk' => 'Done',
        'applyFailed' => 'Failed',
        'rollbackStepOk' => 'Rolled back',
        'rollbackStepFailed' => 'Rollback failed',
        'details' => [
            'none_active' => 'there were no active modules',
            'handler_missing' => 'the rollback handler is missing',
            'add_wants_failed' => 'could not restore the systemd wants entries for autostart',
            'autostart_verification_failed' => 'autostart verification did not pass',
            'crontab_apply_failed' => 'could not apply the crontab',
            'traffic_limit_apply_failed' => 'could not apply the traffic limit',
            'hostapd_config_unavailable' => 'the hostapd config is unavailable',
            'hostapd_restart_failed' => 'could not restart hostapd',
            'wifi_txpower_apply_failed' => 'could not apply the Wi-Fi power value',
            'timedatectl_not_found' => 'timedatectl is unavailable on the system',
            'set_timezone_failed' => 'could not change the timezone',
            'set_ntp_failed' => 'could not change the NTP state',
            'daemon_reload_failed' => 'could not run systemctl daemon-reload',
            'service_switch_failed' => 'could not change the state of one or more services',
            'service_state_verification_failed' => 'service state verification did not pass',
            'distress_autotune_state_write_failed' => 'could not write the distress autotune state',
        ],
        'errors' => [
            'socket_unavailable' => 'Could not reach the root helper.',
            'socket_timeout' => 'The reset took too long or the root helper did not respond in time.',
            'empty_response' => 'The root helper returned an empty response.',
            'invalid_response' => 'The root helper returned an invalid response.',
            'reset_active_stop_failed' => 'Could not stop the active modules before the reset.',
            'reset_mhddos_failed' => 'Could not reset mhddos settings.',
            'reset_distress_failed' => 'Could not reset distress settings.',
            'reset_x100_failed' => 'Could not reset x100 settings.',
            'reset_autostart_failed' => 'Could not disable autostart.',
            'reset_schedule_failed' => 'Could not clear the schedule.',
            'reset_traffic_failed' => 'Could not restore the default traffic limit.',
            'reset_wifi_name_failed' => 'Could not restore the default Wi-Fi name.',
            'reset_wifi_power_failed' => 'Could not restore the default Wi-Fi power.',
            'reset_time_sync_failed' => 'Could not restore the default timezone and NTP settings.',
            'reset_update_branch_failed' => 'Could not restore the default update branch.',
        ],
        'steps' => [
            'stop_active_modules' => 'Stop active modules',
            'reset_mhddos' => 'Reset mhddos settings',
            'reset_distress' => 'Reset distress settings',
            'reset_x100' => 'Reset x100 settings',
            'clear_autostart' => 'Disable autostart',
            'clear_schedule' => 'Clear schedule',
            'reset_traffic_limit' => 'Restore the default traffic limit',
            'reset_wifi_name' => 'Restore the default Wi-Fi name',
            'reset_wifi_power' => 'Restore the default Wi-Fi power',
            'reset_time_sync' => 'Restore the default time settings',
            'reset_update_branch' => 'Restore the default update branch',
            'restore_update_branch' => 'Restore the previous update branch',
            'restore_time_sync' => 'Restore the previous time settings',
            'restore_wifi_power' => 'Restore the previous Wi-Fi power',
            'restore_wifi_name' => 'Restore the previous Wi-Fi name',
            'restore_traffic_limit' => 'Restore the previous traffic limit',
            'restore_schedule' => 'Restore the previous schedule',
            'restore_autostart' => 'Restore the previous autostart settings',
            'restore_x100' => 'Restore the previous x100 settings',
            'restore_distress' => 'Restore the previous distress settings',
            'restore_mhddos' => 'Restore the previous mhddos settings',
            'restore_active_modules' => 'Restart the modules that were active before reset',
        ],
    ];
}

function reset_render_counts(string $template, int $completed, int $failed): string
{
    return strtr($template, [
        '{{completed}}' => (string)$completed,
        '{{failed}}' => (string)$failed,
    ]);
}

function reset_format_detail(string $detail, array $resetUi): string
{
    return $resetUi['details'][$detail] ?? $detail;
}

$config = require 'config/config.php';
$pageLang = app_lang();
$resetUi = reset_ui_catalog($pageLang);
$message = '';
$messageClass = '';
$resetJournal = reset_decode_state_param($_GET['resetLog'] ?? null);
$resetRollback = reset_decode_state_param($_GET['resetRollback'] ?? null);
$defaultWifiName = 'Artline';
$resetWifiNameWarning = $pageLang === 'uk'
    ? "\u{0423}\u{0432}\u{0430}\u{0433}\u{0430}: \u{043F}\u{0456}\u{0434} \u{0447}\u{0430}\u{0441} \u{0441}\u{043A}\u{0438}\u{0434}\u{0430}\u{043D}\u{043D}\u{044F} \u{043D}\u{0430}\u{0437}\u{0432}\u{0430} Wi-Fi \u{0431}\u{0443}\u{0434}\u{0435} \u{0437}\u{043C}\u{0456}\u{043D}\u{0435}\u{043D}\u{0430} \u{043D}\u{0430} \u{0441}\u{0442}\u{0430}\u{043D}\u{0434}\u{0430}\u{0440}\u{0442}\u{043D}\u{0435} \u{0437}\u{043D}\u{0430}\u{0447}\u{0435}\u{043D}\u{043D}\u{044F} \"" . $defaultWifiName . "\"."
    : 'Warning: reset will change the Wi-Fi name to the default value "' . $defaultWifiName . '".';
$resetConfirmMessage = t('reset_webui_defaults_confirm') . "\n\n" . $resetWifiNameWarning;

if (isset($_GET['flash']) && is_string($_GET['flash']) && $_GET['flash'] !== '') {
    $flash = (string)$_GET['flash'];
    if ($flash === 'reset_webui_defaults_done') {
        $message = t('reset_webui_defaults_done');
        $messageClass = 'status active';
    } elseif ($flash === 'reset_webui_defaults_failed') {
        $errorCode = trim((string)($_GET['errorCode'] ?? ''));
        $message = $resetUi['errors'][$errorCode] ?? t('reset_webui_defaults_failed');
        if (($resetRollback['attempted'] ?? false) === true) {
            $rollbackCompleted = (int)($resetRollback['completed'] ?? 0);
            $rollbackFailed = (int)($resetRollback['failed'] ?? 0);
            $message .= ' ' . ((($resetRollback['ok'] ?? false) === true) ? $resetUi['rollbackOk'] : $resetUi['rollbackFailed']);
            $message .= ' ' . reset_render_counts($resetUi['rollbackCounts'], $rollbackCompleted, $rollbackFailed);
        }
        $messageClass = 'status inactive';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'reset_webui_defaults') {
    $result = root_helper_request([
        'action' => 'webui_reset_defaults',
        'modules' => $config['daemonNames'],
    ]);
    header('Location: ' . build_page_url('/settings.php', [
        'flash' => (($result['ok'] ?? false) === true) ? 'reset_webui_defaults_done' : 'reset_webui_defaults_failed',
        'errorCode' => (($result['ok'] ?? false) === true) ? null : (string)($result['error'] ?? 'unknown'),
        'resetLog' => isset($result['steps']) ? reset_encode_state_param($result['steps']) : null,
        'resetRollback' => isset($result['rollback']) ? reset_encode_state_param($result['rollback']) : null,
        'themeReset' => (($result['ok'] ?? false) === true) ? '1' : null,
    ]));
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($pageLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/image/ukraine.png" rel="icon">
    <title>ITUA</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet" />
    <link href="/styles.css" rel="stylesheet" />
    <style>
        .reset-journal {
            margin: 14px 0 0;
            padding-left: 20px;
            line-height: 1.5;
        }

        .reset-journal li + li {
            margin-top: 8px;
        }

        .reset-journal-summary {
            margin-top: 12px;
        }
    </style>
</head>
<body class="padded">
<div class="container">
    <div class="content centered">
        <h1><?= htmlspecialchars(t('settings'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($message !== ''): ?>
            <div class="form-message <?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>" role="status" aria-live="polite">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <ul class="menu centered">
            <li><a href="<?= htmlspecialchars(url_with_lang('/user_id.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('user_id'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/autostart.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('autostart'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/wifi_settings.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('wifi_settings'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/time_sync.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('time_sync'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/update.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('update'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/reboot.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('reboot_system'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><?= render_back_link('/') ?></li>
        </ul>
        <div class="service" style="max-width: 760px; width: 100%; margin: 24px auto 0; text-align: left;">
            <div class="service-title"><?= htmlspecialchars(t('reset_webui_defaults'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint"><?= htmlspecialchars(t('reset_webui_defaults_help'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint" style="margin-top: 8px;"><?= htmlspecialchars(t('reset_webui_defaults_warning'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="schedule-limit-hint" style="margin-top: 8px;"><?= htmlspecialchars($resetWifiNameWarning, ENT_QUOTES, 'UTF-8') ?></div>
            <form method="post" action="" style="margin-top: 18px;" onsubmit='return window.confirm(<?= json_encode($resetConfirmMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);'>
                <input type="hidden" name="action" value="reset_webui_defaults">
                <button class="submit-btn" type="submit"><?= htmlspecialchars(t('reset_webui_defaults_button'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
            <?php if ($resetJournal !== []): ?>
                <div class="service-title" style="margin-top: 22px;"><?= htmlspecialchars($resetUi['journalTitle'], ENT_QUOTES, 'UTF-8') ?></div>
                <ul class="reset-journal">
                    <?php foreach ($resetJournal as $entry): ?>
                        <?php
                        $phase = (string)($entry['phase'] ?? 'apply');
                        $step = (string)($entry['step'] ?? '');
                        $ok = (($entry['ok'] ?? false) === true);
                        $details = trim((string)($entry['details'] ?? ''));
                        $details = $details !== '' ? reset_format_detail($details, $resetUi) : '';
                        $statusLabel = $phase === 'rollback'
                            ? ($ok ? $resetUi['rollbackStepOk'] : $resetUi['rollbackStepFailed'])
                            : ($ok ? $resetUi['applyOk'] : $resetUi['applyFailed']);
                        $stepLabel = $resetUi['steps'][$step] ?? $step;
                        ?>
                        <li>
                            <strong><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>:</strong>
                            <?= htmlspecialchars($stepLabel, ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($details !== ''): ?>
                                (<?= htmlspecialchars($details, ENT_QUOTES, 'UTF-8') ?>)
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (($resetRollback['attempted'] ?? false) === true): ?>
                    <div class="schedule-limit-hint reset-journal-summary">
                        <?= htmlspecialchars((($resetRollback['ok'] ?? false) === true) ? $resetUi['rollbackOk'] : $resetUi['rollbackFailed'], ENT_QUOTES, 'UTF-8') ?>
                        <?= htmlspecialchars(reset_render_counts($resetUi['rollbackCounts'], (int)($resetRollback['completed'] ?? 0), (int)($resetRollback['failed'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?= render_app_footer() ?>
<?php if (($_GET['themeReset'] ?? '') === '1'): ?>
<script>
(() => {
    try {
        window.localStorage.removeItem('itarmybox-theme');
        window.localStorage.removeItem('itarmybox-traffic-desired');
        if (window.ItArmyTheme && typeof window.ItArmyTheme.refresh === 'function') {
            window.ItArmyTheme.refresh();
        }
    } catch (e) {
    }
})();
</script>
<?php endif; ?>
<script src="/js/form_messages.js"></script>
</body>
</html>
