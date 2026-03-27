<?php

require_once __DIR__ . '/root_helper_client.php';

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

function normalizeSchedulePowerPercent($value): ?int
{
    $raw = trim((string)$value);
    if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
        return null;
    }
    $percent = (int)$raw;
    if ($percent < 25 || $percent > 100) {
        return null;
    }
    return $percent;
}

function scheduleTimeToMinutes(string $hhmm): int
{
    [$hours, $minutes] = explode(':', $hhmm, 2);
    return ((int)$hours * 60) + (int)$minutes;
}

function expandScheduleEntrySegments(array $entry): array
{
    $days = normalizeScheduleDays((array)($entry['days'] ?? []));
    $start = (string)($entry['start'] ?? '');
    $stop = (string)($entry['stop'] ?? '');
    if (
        $days === [] ||
        preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) !== 1 ||
        preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $stop) !== 1 ||
        $start === $stop
    ) {
        return [];
    }

    $startMinutes = scheduleTimeToMinutes($start);
    $stopMinutes = scheduleTimeToMinutes($stop);
    $segments = [];
    foreach ($days as $day) {
        if ($startMinutes < $stopMinutes) {
            $segments[] = ['day' => $day, 'start' => $startMinutes, 'stop' => $stopMinutes];
            continue;
        }

        $segments[] = ['day' => $day, 'start' => $startMinutes, 'stop' => 1440];
        $segments[] = ['day' => (($day + 1) % 7), 'start' => 0, 'stop' => $stopMinutes];
    }
    return $segments;
}

function scheduleEntriesOverlap(array $entries): bool
{
    $segmentsByDay = [];
    foreach ($entries as $entry) {
        foreach (expandScheduleEntrySegments($entry) as $segment) {
            $segmentsByDay[(int)$segment['day']][] = $segment;
        }
    }

    foreach ($segmentsByDay as $segments) {
        usort($segments, static function (array $a, array $b): int {
            return ($a['start'] <=> $b['start']) ?: ($a['stop'] <=> $b['stop']);
        });

        $previousStop = null;
        foreach ($segments as $segment) {
            if ($previousStop !== null && (int)$segment['start'] < $previousStop) {
                return true;
            }
            $previousStop = max($previousStop ?? 0, (int)$segment['stop']);
        }
    }

    return false;
}

function getCurrentSchedules(array $daemonNames, int $maxScheduleIntervals, int $defaultSchedulePowerPercent): array
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
        $powerPercent = normalizeSchedulePowerPercent($entry['powerPercent'] ?? null);
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
                'powerPercent' => $powerPercent ?? $defaultSchedulePowerPercent,
                'day_mode' => (count($days) === 7) ? 'all' : 'specific',
            ];
        }
        if (count($entries) >= $maxScheduleIntervals) {
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

function autostart_default_schedule_entry(array $daemonNames, int $defaultSchedulePowerPercent): array
{
    return [
        'module' => $daemonNames[0] ?? '',
        'day_mode' => 'all',
        'days' => [0, 1, 2, 3, 4, 5, 6],
        'start' => '09:00',
        'stop' => '21:00',
        'powerPercent' => $defaultSchedulePowerPercent,
    ];
}

function autostart_handle_autostart_save(array $daemonNames, array $post): array
{
    $requested = $post['autostart_daemon'] ?? '';
    $selectedDaemon = null;
    if ($requested !== 'none' && in_array($requested, $daemonNames, true)) {
        $selectedDaemon = $requested;
    }
    $ok = setAutostartDaemon($daemonNames, $selectedDaemon);
    return [
        'flash' => $ok ? t('autostart_updated') : t('autostart_update_failed'),
        'flashClass' => $ok ? 'active' : 'inactive',
    ];
}

function autostart_normalize_schedule_entries(
    array $daemonNames,
    array $rawEntries,
    int $maxScheduleIntervals
): array {
    $normalizedEntries = [];
    $scheduleError = '';
    foreach ($rawEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $module = (string)($entry['module'] ?? '');
        $dayMode = (string)($entry['day_mode'] ?? 'all');
        $daysRaw = $entry['days'] ?? [];
        $start = (string)($entry['start'] ?? '');
        $stop = (string)($entry['stop'] ?? '');
        $powerPercent = normalizeSchedulePowerPercent($entry['powerPercent'] ?? null);
        $daysNorm = ($dayMode === 'specific' && is_array($daysRaw))
            ? normalizeScheduleDays($daysRaw)
            : [0, 1, 2, 3, 4, 5, 6];
        if ($powerPercent === null) {
            $scheduleError = 'invalid_schedule_power_percent';
            break;
        }
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
                'powerPercent' => $powerPercent,
            ];
        }
        if (count($normalizedEntries) >= $maxScheduleIntervals) {
            break;
        }
    }

    return [
        'entries' => $normalizedEntries,
        'error' => $scheduleError,
    ];
}

function autostart_handle_schedule_save(
    array $daemonNames,
    array $post,
    int $maxScheduleIntervals
): array {
    $scheduleEnabled = ($post['schedule_enabled'] ?? '0') === '1';
    $rawEntries = $post['schedule_entries'] ?? [];
    $normalized = is_array($rawEntries)
        ? autostart_normalize_schedule_entries($daemonNames, $rawEntries, $maxScheduleIntervals)
        : ['entries' => [], 'error' => ''];
    $normalizedEntries = $normalized['entries'];
    $scheduleError = $normalized['error'];

    if (!$scheduleEnabled) {
        $ok = saveScheduleEntries($daemonNames, []);
    } elseif ($scheduleError !== '') {
        $ok = false;
    } elseif (scheduleEntriesOverlap($normalizedEntries)) {
        $scheduleError = 'invalid_schedule_overlap';
        $ok = false;
    } elseif ($normalizedEntries === []) {
        $ok = false;
    } else {
        $ok = saveScheduleEntries($daemonNames, $normalizedEntries);
    }

    return [
        'flash' => $ok ? t('schedule_updated') : ($scheduleError !== '' ? t($scheduleError) : t('schedule_update_failed')),
        'flashClass' => $ok ? 'active' : 'inactive',
    ];
}

function buildScheduleSummaryLines(array $currentSchedules, array $days, int $defaultSchedulePowerPercent): array
{
    $scheduleSummaryLines = [];
    foreach ($currentSchedules as $idx => $entry) {
        $scheduleSummaryLines[] = t('schedule_line', [
            'idx' => (string)($idx + 1),
            'module' => strtoupper((string)$entry['module']),
            'days' => buildDaySummary((array)$entry['days'], $days),
            'start' => (string)$entry['start'],
            'stop' => (string)$entry['stop'],
            'power' => (string)((int)($entry['powerPercent'] ?? $defaultSchedulePowerPercent)) . '%',
        ]);
    }
    return $scheduleSummaryLines;
}
