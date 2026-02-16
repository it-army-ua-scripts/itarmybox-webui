<?php
require_once 'i18n.php';
$config = require 'config/config.php';
$daemonNames = $config['daemonNames'];
const SCHEDULE_BEGIN_MARKER = '# ITARMYBOX-SCHEDULE-BEGIN';
const SCHEDULE_END_MARKER = '# ITARMYBOX-SCHEDULE-END';

function getCurrentAutostartDaemon(array $daemonNames): ?string
{
    foreach ($daemonNames as $daemon) {
        $daemonSafe = escapeshellarg($daemon . '.service');
        $state = trim((string)shell_exec("sudo -n systemctl is-enabled $daemonSafe 2>/dev/null"));
        if ($state === 'enabled') {
            return $daemon;
        }
    }
    return null;
}

function setAutostartDaemon(array $daemonNames, ?string $selectedDaemon): bool
{
    $ok = true;
    foreach ($daemonNames as $daemon) {
        $daemonSafe = escapeshellarg($daemon . '.service');
        $result = shell_exec("sudo -n systemctl disable $daemonSafe 2>/dev/null");
        if ($result === null) {
            $ok = false;
        }
    }

    if ($selectedDaemon !== null) {
        $selectedSafe = escapeshellarg($selectedDaemon . '.service');
        $result = shell_exec("sudo -n systemctl enable $selectedSafe 2>/dev/null");
        if ($result === null) {
            $ok = false;
        }
    }

    return $ok;
}

function parseHmToParts(string $hm): array
{
    [$h, $m] = explode(':', $hm, 2);
    return [(int)$h, (int)$m];
}

function normalizeScheduleDays(array $rawDays): array
{
    $valid = [];
    foreach ($rawDays as $day) {
        if (preg_match('/^[0-6]$/', (string)$day) === 1) {
            $valid[(int)$day] = true;
        }
    }
    $days = array_keys($valid);
    sort($days);
    return $days;
}

function parseDowField(string $dowField): ?array
{
    if ($dowField === '*') {
        return [0, 1, 2, 3, 4, 5, 6];
    }
    if (preg_match('/^[0-6](?:,[0-6])*$/', $dowField) !== 1) {
        return null;
    }
    $parts = explode(',', $dowField);
    return normalizeScheduleDays($parts);
}

function getRawCrontab(): string
{
    $raw = shell_exec('sudo -n crontab -l 2>/dev/null');
    return is_string($raw) ? $raw : '';
}

function stripScheduleBlock(string $crontab): string
{
    $lines = preg_split('/\r\n|\r|\n/', $crontab);
    $clean = [];
    $inside = false;
    foreach ($lines as $line) {
        if ($line === SCHEDULE_BEGIN_MARKER) {
            $inside = true;
            continue;
        }
        if ($line === SCHEDULE_END_MARKER) {
            $inside = false;
            continue;
        }
        if (!$inside && trim($line) !== '') {
            $clean[] = $line;
        }
    }
    return implode("\n", $clean);
}

function getCurrentSchedule(array $daemonNames): ?array
{
    $crontab = getRawCrontab();
    if ($crontab === '') {
        return null;
    }

    $pattern = '/^#\s*ITARMYBOX\s+MODULE=(?<module>[a-zA-Z0-9_-]+)\s+DOW=(?<dow>\*|[0-6](?:,[0-6])*)\s+START=(?<start>[0-2][0-9]:[0-5][0-9])\s+STOP=(?<stop>[0-2][0-9]:[0-5][0-9])$/m';
    if (!preg_match($pattern, $crontab, $m)) {
        return null;
    }

    $module = $m['module'];
    if (!in_array($module, $daemonNames, true)) {
        return null;
    }

    $days = parseDowField($m['dow']);
    if ($days === null || $days === []) {
        return null;
    }

    return [
        'module' => $module,
        'days' => $days,
        'start' => $m['start'],
        'stop' => $m['stop'],
    ];
}

function saveSchedule(?string $module, ?array $days, ?string $startTime, ?string $stopTime): bool
{
    $base = stripScheduleBlock(getRawCrontab());
    $new = $base;

    if ($module !== null && $days !== null && $startTime !== null && $stopTime !== null) {
        $days = normalizeScheduleDays($days);
        if ($days === []) {
            return false;
        }
        [$startH, $startM] = parseHmToParts($startTime);
        [$stopH, $stopM] = parseHmToParts($stopTime);
        $service = $module . '.service';
        $dowCron = (count($days) === 7) ? '*' : implode(',', $days);
        $block = [
            SCHEDULE_BEGIN_MARKER,
            "# ITARMYBOX MODULE=$module DOW=$dowCron START=$startTime STOP=$stopTime",
            "$startM $startH * * $dowCron sudo -n systemctl start $service >/dev/null 2>&1",
            "$stopM $stopH * * $dowCron sudo -n systemctl stop $service >/dev/null 2>&1",
            SCHEDULE_END_MARKER,
        ];
        $new .= ($new === '' ? '' : "\n") . implode("\n", $block);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'itarmybox-cron-');
    if ($tmp === false) {
        return false;
    }

    $bytes = file_put_contents($tmp, $new === '' ? "\n" : ($new . "\n"));
    if ($bytes === false) {
        @unlink($tmp);
        return false;
    }

    $exitCode = 1;
    exec('sudo -n crontab ' . escapeshellarg($tmp) . ' 2>/dev/null', $out, $exitCode);
    @unlink($tmp);

    return $exitCode === 0;
}

$message = '';
$messageClass = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'autostart_save';
    if ($action === 'autostart_save') {
        $requested = $_POST['autostart_daemon'] ?? '';
        $selectedDaemon = null;
        if ($requested !== 'none' && in_array($requested, $daemonNames, true)) {
            $selectedDaemon = $requested;
        }

        $ok = setAutostartDaemon($daemonNames, $selectedDaemon);
        $message = $ok ? t('autostart_updated') : t('autostart_update_failed');
        $messageClass = $ok ? 'status active' : 'status inactive';
    } elseif ($action === 'schedule_save') {
        $enabled = ($_POST['schedule_enabled'] ?? '0') === '1';
        $ok = false;
        if (!$enabled) {
            $ok = saveSchedule(null, null, null, null);
        } else {
            $module = $_POST['schedule_module'] ?? '';
            $dayMode = $_POST['schedule_day_mode'] ?? 'all';
            $rawDays = $_POST['schedule_days'] ?? [];
            $days = [];
            if ($dayMode === 'all') {
                $days = [0, 1, 2, 3, 4, 5, 6];
            } elseif ($dayMode === 'specific' && is_array($rawDays)) {
                $days = normalizeScheduleDays($rawDays);
            }
            $startTime = $_POST['schedule_start'] ?? '';
            $stopTime = $_POST['schedule_stop'] ?? '';
            $validTime = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
            if (
                in_array($module, $daemonNames, true) &&
                $days !== [] &&
                preg_match($validTime, $startTime) === 1 &&
                preg_match($validTime, $stopTime) === 1
            ) {
                $ok = saveSchedule($module, $days, $startTime, $stopTime);
            }
        }
        $message = $ok ? t('schedule_updated') : t('schedule_update_failed');
        $messageClass = $ok ? 'status active' : 'status inactive';
    }
}

$currentAutostart = getCurrentAutostartDaemon($daemonNames);
$currentSchedule = getCurrentSchedule($daemonNames);
$days = [
    0 => t('day_sunday'),
    1 => t('day_monday'),
    2 => t('day_tuesday'),
    3 => t('day_wednesday'),
    4 => t('day_thursday'),
    5 => t('day_friday'),
    6 => t('day_saturday'),
];
$currentScheduleDays = $currentSchedule['days'] ?? [1];
$isAllDays = count($currentScheduleDays) === 7;
$scheduleDayMode = $isAllDays ? 'all' : 'specific';
$dayLabels = [];
foreach ($currentScheduleDays as $dayNum) {
    if (isset($days[$dayNum])) {
        $dayLabels[] = $days[$dayNum];
    }
}
$daySummary = $isAllDays ? t('all_days') : implode(', ', $dayLabels);
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
        <h1><?= htmlspecialchars(t('autostart_settings'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($message !== ''): ?>
            <div class="<?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="service">
            <div class="service-title"><?= htmlspecialchars(t('current_autostart'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="status <?= $currentAutostart ? 'active' : 'inactive' ?>">
                <?php
                if ($currentAutostart) {
                    echo htmlspecialchars(t('autostart_for', ['module' => strtoupper($currentAutostart)]), ENT_QUOTES, 'UTF-8');
                } else {
                    echo htmlspecialchars(t('autostart_none'), ENT_QUOTES, 'UTF-8');
                }
                ?>
            </div>
        </div>

        <div class="form-container">
            <form method="post" action="">
                <input type="hidden" name="action" value="autostart_save">
                <div class="form-group">
                    <label for="autostart_daemon"><?= htmlspecialchars(t('select_autostart_module'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="autostart_daemon" name="autostart_daemon">
                        <option value="none"><?= htmlspecialchars(t('autostart_disable'), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php foreach ($daemonNames as $daemon): ?>
                            <option value="<?= htmlspecialchars($daemon, ENT_QUOTES, 'UTF-8') ?>"<?= $currentAutostart === $daemon ? ' selected' : '' ?>>
                                <?= htmlspecialchars(strtoupper($daemon), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        </div>
        <div class="service">
            <div class="service-title"><?= htmlspecialchars(t('schedule_settings'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="status <?= $currentSchedule ? 'active' : 'inactive' ?>">
                <?php
                if ($currentSchedule) {
                    echo htmlspecialchars(
                        t('schedule_current', [
                            'module' => strtoupper($currentSchedule['module']),
                            'day' => $daySummary,
                            'start' => $currentSchedule['start'],
                            'stop' => $currentSchedule['stop'],
                        ]),
                        ENT_QUOTES,
                        'UTF-8'
                    );
                } else {
                    echo htmlspecialchars(t('schedule_disabled'), ENT_QUOTES, 'UTF-8');
                }
                ?>
            </div>
        </div>
        <div class="form-container">
            <form method="post" action="">
                <input type="hidden" name="action" value="schedule_save">
                <div class="form-group">
                    <label for="schedule_enabled"><?= htmlspecialchars(t('schedule_enabled'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="schedule_enabled" name="schedule_enabled">
                        <option value="0"<?= $currentSchedule ? '' : ' selected' ?>><?= htmlspecialchars(t('no'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="1"<?= $currentSchedule ? ' selected' : '' ?>><?= htmlspecialchars(t('yes'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="schedule_module"><?= htmlspecialchars(t('schedule_module'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="schedule_module" name="schedule_module">
                        <?php foreach ($daemonNames as $daemon): ?>
                            <option value="<?= htmlspecialchars($daemon, ENT_QUOTES, 'UTF-8') ?>"<?= ($currentSchedule['module'] ?? '') === $daemon ? ' selected' : '' ?>>
                                <?= htmlspecialchars(strtoupper($daemon), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="schedule_day_mode"><?= htmlspecialchars(t('schedule_day_mode'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="schedule_day_mode" name="schedule_day_mode">
                        <option value="all"<?= $scheduleDayMode === 'all' ? ' selected' : '' ?>><?= htmlspecialchars(t('schedule_all_days'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="specific"<?= $scheduleDayMode === 'specific' ? ' selected' : '' ?>><?= htmlspecialchars(t('schedule_specific_days'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                </div>
                <div class="form-group" id="schedule_days_group">
                    <label><?= htmlspecialchars(t('schedule_select_days'), ENT_QUOTES, 'UTF-8') ?></label>
                    <div class="schedule-days-grid">
                        <?php foreach ($days as $num => $label): ?>
                            <label class="schedule-day-item" for="schedule_day_<?= $num ?>">
                                <input
                                    id="schedule_day_<?= $num ?>"
                                    class="schedule-day-checkbox"
                                    type="checkbox"
                                    name="schedule_days[]"
                                    value="<?= $num ?>"
                                    <?= in_array($num, $currentScheduleDays, true) ? 'checked' : '' ?>
                                >
                                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-row-two">
                    <div class="form-group">
                        <label for="schedule_start"><?= htmlspecialchars(t('schedule_start_time'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="schedule_start" name="schedule_start" type="time" value="<?= htmlspecialchars($currentSchedule['start'] ?? '09:00', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="form-group">
                        <label for="schedule_stop"><?= htmlspecialchars(t('schedule_stop_time'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="schedule_stop" name="schedule_stop" type="time" value="<?= htmlspecialchars($currentSchedule['stop'] ?? '21:00', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        </div>

        <div class="menu">
            <a href="<?= htmlspecialchars(url_with_lang('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
    </div>
</div>
<footer class="app-footer">&copy; 2022-<?= date('Y') ?> IT Army of Ukraine. <?= htmlspecialchars(t('footer_slogan'), ENT_QUOTES, 'UTF-8') ?>.</footer>
<script>
(() => {
    const enabledEl = document.getElementById('schedule_enabled');
    const dayModeEl = document.getElementById('schedule_day_mode');
    const dayCheckboxes = Array.from(document.querySelectorAll('.schedule-day-checkbox'));
    const toggledIds = ['schedule_module', 'schedule_day_mode', 'schedule_start', 'schedule_stop'];
    if (!enabledEl) {
        return;
    }
    const update = () => {
        const enabled = enabledEl.value === '1';
        for (const id of toggledIds) {
            const el = document.getElementById(id);
            if (el) {
                el.disabled = !enabled;
            }
        }
        const specificMode = dayModeEl && dayModeEl.value === 'specific';
        for (const el of dayCheckboxes) {
            el.disabled = !enabled || !specificMode;
        }
    };
    enabledEl.addEventListener('change', update);
    if (dayModeEl) {
        dayModeEl.addEventListener('change', update);
    }
    update();
})();
</script>
</body>
</html>
