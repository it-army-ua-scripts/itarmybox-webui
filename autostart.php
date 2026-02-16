<?php
require_once 'i18n.php';
require_once 'lib/root_helper_client.php';
$config = require 'config/config.php';
$daemonNames = $config['daemonNames'];
const MAX_SCHEDULE_INTERVALS = 5;

function getCurrentAutostartDaemon(array $daemonNames): ?string
{
    $response = root_helper_request([
        'action' => 'autostart_get',
        'modules' => $daemonNames,
    ]);
    if (($response['ok'] ?? false) !== true) {
        return null;
    }
    $active = $response['active'] ?? null;
    if (is_string($active) && in_array($active, $daemonNames, true)) {
        return $active;
    }
    return null;
}

function setAutostartDaemon(array $daemonNames, ?string $selectedDaemon): bool
{
    $response = root_helper_request([
        'action' => 'autostart_set',
        'modules' => $daemonNames,
        'selected' => $selectedDaemon,
    ]);
    return ($response['ok'] ?? false) === true;
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

function getCurrentSchedules(array $daemonNames): array
{
    $response = root_helper_request([
        'action' => 'schedule_get',
        'modules' => $daemonNames,
    ]);
    if (($response['ok'] ?? false) !== true || !isset($response['entries']) || !is_array($response['entries'])) {
        return [];
    }

    $entries = [];
    foreach ($response['entries'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $module = $entry['module'] ?? '';
        $start = $entry['start'] ?? '';
        $stop = $entry['stop'] ?? '';
        $days = normalizeScheduleDays((array)($entry['days'] ?? []));
        if (
            is_string($module) &&
            in_array($module, $daemonNames, true) &&
            preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$start) === 1 &&
            preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$stop) === 1 &&
            $days !== []
        ) {
            $entries[] = [
                'module' => $module,
                'days' => $days,
                'start' => (string)$start,
                'stop' => (string)$stop,
                'day_mode' => (count($days) === 7) ? 'all' : 'specific',
            ];
        }
        if (count($entries) >= MAX_SCHEDULE_INTERVALS) {
            break;
        }
    }
    return $entries;
}

function saveScheduleEntries(array $daemonNames, array $entries): bool
{
    $response = root_helper_request([
        'action' => 'schedule_set',
        'modules' => $daemonNames,
        'entries' => $entries,
    ]);
    return ($response['ok'] ?? false) === true;
}

function buildDaySummary(array $days, array $dayLabels): string
{
    if (count($days) === 7) {
        return t('all_days');
    }
    $labels = [];
    foreach ($days as $day) {
        if (isset($dayLabels[$day])) {
            $labels[] = $dayLabels[$day];
        }
    }
    return implode(', ', $labels);
}

$days = [
    0 => t('day_sunday'),
    1 => t('day_monday'),
    2 => t('day_tuesday'),
    3 => t('day_wednesday'),
    4 => t('day_thursday'),
    5 => t('day_friday'),
    6 => t('day_saturday'),
];

$message = '';
$messageClass = '';
$currentAutostart = getCurrentAutostartDaemon($daemonNames);
$currentSchedules = getCurrentSchedules($daemonNames);
$scheduleEnabled = count($currentSchedules) > 0;
$scheduleEntriesForForm = $currentSchedules;
if ($scheduleEntriesForForm === []) {
    $scheduleEntriesForForm[] = [
        'module' => $daemonNames[0] ?? '',
        'day_mode' => 'all',
        'days' => [0, 1, 2, 3, 4, 5, 6],
        'start' => '09:00',
        'stop' => '21:00',
    ];
}

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
        $currentAutostart = getCurrentAutostartDaemon($daemonNames);
    } elseif ($action === 'schedule_save') {
        $scheduleEnabled = ($_POST['schedule_enabled'] ?? '0') === '1';
        $rawEntries = $_POST['schedule_entries'] ?? [];
        $normalizedEntries = [];
        if (is_array($rawEntries)) {
            foreach ($rawEntries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $module = (string)($entry['module'] ?? '');
                $dayMode = (string)($entry['day_mode'] ?? 'all');
                $daysRaw = $entry['days'] ?? [];
                $start = (string)($entry['start'] ?? '');
                $stop = (string)($entry['stop'] ?? '');
                $daysNorm = ($dayMode === 'specific' && is_array($daysRaw))
                    ? normalizeScheduleDays($daysRaw)
                    : [0, 1, 2, 3, 4, 5, 6];
                if (
                    in_array($module, $daemonNames, true) &&
                    $daysNorm !== [] &&
                    preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) === 1 &&
                    preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $stop) === 1
                ) {
                    $normalizedEntries[] = [
                        'module' => $module,
                        'days' => $daysNorm,
                        'start' => $start,
                        'stop' => $stop,
                    ];
                }
                if (count($normalizedEntries) >= MAX_SCHEDULE_INTERVALS) {
                    break;
                }
            }
        }

        if (!$scheduleEnabled) {
            $ok = saveScheduleEntries($daemonNames, []);
        } elseif ($normalizedEntries === []) {
            $ok = false;
        } else {
            $ok = saveScheduleEntries($daemonNames, $normalizedEntries);
        }

        $message = $ok ? t('schedule_updated') : t('schedule_update_failed');
        $messageClass = $ok ? 'status active' : 'status inactive';
        $currentSchedules = getCurrentSchedules($daemonNames);
        $scheduleEnabled = count($currentSchedules) > 0;
        $scheduleEntriesForForm = ($currentSchedules === []) ? [[
            'module' => $daemonNames[0] ?? '',
            'day_mode' => 'all',
            'days' => [0, 1, 2, 3, 4, 5, 6],
            'start' => '09:00',
            'stop' => '21:00',
        ]] : $currentSchedules;
    }
}

$scheduleSummaryLines = [];
foreach ($currentSchedules as $idx => $entry) {
    $scheduleSummaryLines[] = t('schedule_line', [
        'idx' => (string)($idx + 1),
        'module' => strtoupper((string)$entry['module']),
        'days' => buildDaySummary((array)$entry['days'], $days),
        'start' => (string)$entry['start'],
        'stop' => (string)$entry['stop'],
    ]);
}
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
                <?= htmlspecialchars($currentAutostart ? t('autostart_for', ['module' => strtoupper($currentAutostart)]) : t('autostart_none'), ENT_QUOTES, 'UTF-8') ?>
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
            <div class="status <?= count($scheduleSummaryLines) > 0 ? 'active' : 'inactive' ?>">
                <?php if (count($scheduleSummaryLines) > 0): ?>
                    <div class="schedule-summary">
                        <?= htmlspecialchars(implode("\n", $scheduleSummaryLines), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php else: ?>
                    <?= htmlspecialchars(t('schedule_disabled'), ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-container">
            <form method="post" action="" id="schedule-form">
                <input type="hidden" name="action" value="schedule_save">
                <div class="form-group">
                    <label for="schedule_enabled"><?= htmlspecialchars(t('schedule_enabled'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="schedule_enabled" name="schedule_enabled">
                        <option value="0"<?= $scheduleEnabled ? '' : ' selected' ?>><?= htmlspecialchars(t('no'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="1"<?= $scheduleEnabled ? ' selected' : '' ?>><?= htmlspecialchars(t('yes'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                </div>

                <div id="schedule-intervals">
                    <?php foreach ($scheduleEntriesForForm as $idx => $entry): ?>
                        <?php
                        $entryDays = normalizeScheduleDays((array)($entry['days'] ?? []));
                        $entryDayMode = (string)($entry['day_mode'] ?? ((count($entryDays) === 7) ? 'all' : 'specific'));
                        $entryModule = (string)($entry['module'] ?? ($daemonNames[0] ?? ''));
                        $entryStart = (string)($entry['start'] ?? '09:00');
                        $entryStop = (string)($entry['stop'] ?? '21:00');
                        ?>
                        <div class="schedule-interval-card" data-idx="<?= $idx ?>">
                            <div class="schedule-interval-head">
                                <div class="service-title schedule-interval-title"><?= htmlspecialchars(t('schedule_interval', ['num' => (string)($idx + 1)]), ENT_QUOTES, 'UTF-8') ?></div>
                                <button type="button" class="lang-btn remove-interval-btn"><?= htmlspecialchars(t('remove_interval'), ENT_QUOTES, 'UTF-8') ?></button>
                            </div>
                            <div class="form-group">
                                <label><?= htmlspecialchars(t('schedule_module'), ENT_QUOTES, 'UTF-8') ?></label>
                                <select name="schedule_entries[<?= $idx ?>][module]" class="interval-module">
                                    <?php foreach ($daemonNames as $daemon): ?>
                                        <option value="<?= htmlspecialchars($daemon, ENT_QUOTES, 'UTF-8') ?>"<?= $entryModule === $daemon ? ' selected' : '' ?>>
                                            <?= htmlspecialchars(strtoupper($daemon), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><?= htmlspecialchars(t('schedule_day_mode'), ENT_QUOTES, 'UTF-8') ?></label>
                                <select name="schedule_entries[<?= $idx ?>][day_mode]" class="interval-day-mode">
                                    <option value="all"<?= $entryDayMode === 'all' ? ' selected' : '' ?>><?= htmlspecialchars(t('schedule_all_days'), ENT_QUOTES, 'UTF-8') ?></option>
                                    <option value="specific"<?= $entryDayMode === 'specific' ? ' selected' : '' ?>><?= htmlspecialchars(t('schedule_specific_days'), ENT_QUOTES, 'UTF-8') ?></option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><?= htmlspecialchars(t('schedule_select_days'), ENT_QUOTES, 'UTF-8') ?></label>
                                <div class="schedule-days-grid interval-days-grid">
                                    <?php foreach ($days as $num => $label): ?>
                                        <label class="schedule-day-item" for="interval_<?= $idx ?>_day_<?= $num ?>">
                                            <input
                                                id="interval_<?= $idx ?>_day_<?= $num ?>"
                                                class="schedule-day-checkbox"
                                                type="checkbox"
                                                name="schedule_entries[<?= $idx ?>][days][]"
                                                value="<?= $num ?>"
                                                <?= in_array($num, $entryDays, true) ? 'checked' : '' ?>
                                            >
                                            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-row-two">
                                <div class="form-group">
                                    <label><?= htmlspecialchars(t('schedule_start_time'), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input type="time" name="schedule_entries[<?= $idx ?>][start]" value="<?= htmlspecialchars($entryStart, ENT_QUOTES, 'UTF-8') ?>" class="interval-start">
                                </div>
                                <div class="form-group">
                                    <label><?= htmlspecialchars(t('schedule_stop_time'), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input type="time" name="schedule_entries[<?= $idx ?>][stop]" value="<?= htmlspecialchars($entryStop, ENT_QUOTES, 'UTF-8') ?>" class="interval-stop">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="schedule-actions-row">
                    <div class="schedule-limit-hint"><?= htmlspecialchars(t('schedule_limit_hint'), ENT_QUOTES, 'UTF-8') ?></div>
                    <button type="button" id="add-interval-btn" class="lang-btn"><?= htmlspecialchars(t('add_interval'), ENT_QUOTES, 'UTF-8') ?></button>
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
    const maxIntervals = <?= MAX_SCHEDULE_INTERVALS ?>;
    const texts = {
        interval: <?= json_encode(t('schedule_interval', ['num' => '{{num}}']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
    const enabledEl = document.getElementById('schedule_enabled');
    const intervalsEl = document.getElementById('schedule-intervals');
    const addBtn = document.getElementById('add-interval-btn');
    if (!enabledEl || !intervalsEl || !addBtn) {
        return;
    }

    function reindexIntervals() {
        const cards = Array.from(intervalsEl.querySelectorAll('.schedule-interval-card'));
        cards.forEach((card, idx) => {
            card.dataset.idx = String(idx);
            const title = card.querySelector('.schedule-interval-title');
            if (title) {
                title.textContent = texts.interval.replace('{{num}}', String(idx + 1));
            }
            const elements = card.querySelectorAll('input, select, label');
            elements.forEach((el) => {
                if (el.name) {
                    el.name = el.name.replace(/schedule_entries\[\d+\]/, `schedule_entries[${idx}]`);
                }
                if (el.id) {
                    el.id = el.id
                        .replace(/interval_\d+_day_/g, `interval_${idx}_day_`);
                }
                if (el.htmlFor) {
                    el.htmlFor = el.htmlFor
                        .replace(/interval_\d+_day_/g, `interval_${idx}_day_`);
                }
            });
        });
    }

    function updateIntervalCardState(card, enabledGlobal) {
        const dayModeEl = card.querySelector('.interval-day-mode');
        const daysGrid = card.querySelector('.interval-days-grid');
        const dayChecks = card.querySelectorAll('.schedule-day-checkbox');
        const controls = card.querySelectorAll('.interval-module, .interval-day-mode, .interval-start, .interval-stop');
        controls.forEach((el) => {
            el.disabled = !enabledGlobal;
        });
        const specific = dayModeEl && dayModeEl.value === 'specific';
        dayChecks.forEach((el) => {
            el.disabled = !enabledGlobal || !specific;
        });
        if (daysGrid) {
            daysGrid.classList.toggle('is-disabled', !enabledGlobal || !specific);
        }
    }

    function bindCard(card) {
        const removeBtn = card.querySelector('.remove-interval-btn');
        const dayModeEl = card.querySelector('.interval-day-mode');
        if (removeBtn) {
            removeBtn.addEventListener('click', () => {
                const cards = intervalsEl.querySelectorAll('.schedule-interval-card');
                if (cards.length <= 1) {
                    return;
                }
                card.remove();
                reindexIntervals();
                refreshState();
            });
        }
        if (dayModeEl) {
            dayModeEl.addEventListener('change', refreshState);
        }
    }

    function refreshState() {
        const enabled = enabledEl.value === '1';
        const cards = Array.from(intervalsEl.querySelectorAll('.schedule-interval-card'));
        cards.forEach((card) => updateIntervalCardState(card, enabled));
        addBtn.disabled = !enabled || cards.length >= maxIntervals;
        const removeButtons = intervalsEl.querySelectorAll('.remove-interval-btn');
        removeButtons.forEach((btn) => {
            btn.disabled = !enabled || cards.length <= 1;
        });
    }

    function addInterval() {
        const cards = Array.from(intervalsEl.querySelectorAll('.schedule-interval-card'));
        if (cards.length >= maxIntervals) {
            return;
        }
        const clone = cards[0].cloneNode(true);
        clone.querySelectorAll('input').forEach((el) => {
            if (el.type === 'checkbox') {
                el.checked = false;
            } else if (el.type === 'time') {
                el.value = (el.classList.contains('interval-start')) ? '09:00' : '21:00';
            }
        });
        clone.querySelectorAll('select').forEach((el) => {
            if (el.classList.contains('interval-day-mode')) {
                el.value = 'all';
            } else if (el.classList.contains('interval-module')) {
                el.selectedIndex = 0;
            }
        });
        clone.querySelectorAll('.schedule-day-checkbox').forEach((el) => {
            el.checked = true;
        });
        intervalsEl.appendChild(clone);
        bindCard(clone);
        reindexIntervals();
        refreshState();
    }

    Array.from(intervalsEl.querySelectorAll('.schedule-interval-card')).forEach(bindCard);
    enabledEl.addEventListener('change', refreshState);
    addBtn.addEventListener('click', addInterval);
    reindexIntervals();
    refreshState();
})();
</script>
</body>
</html>
