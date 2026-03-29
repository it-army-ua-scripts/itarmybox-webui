<?php

declare(strict_types=1);

const DISTRESS_AUTOTUNE_TIMER_NAME = 'itarmybox-distress-autotune.timer';
const DISTRESS_AUTOTUNE_STATE_FILE = ROOT_HELPER_STATE_DIR . '/distress-autotune.json';
defined('DISTRESS_AUTOTUNE_LOCK_FILE') || define('DISTRESS_AUTOTUNE_LOCK_FILE', '/tmp/itarmybox-distress-autotune.lock');
const DISTRESS_AUTOTUNE_DEBUG_LOG_FILE = ROOT_HELPER_LOG_DIR . '/distress-autotune-debug.log';
const DISTRESS_AUTOTUNE_SERVICE_PRESTART_SKIP_FILE = ROOT_HELPER_STATE_DIR . '/distress-upload-cap-prestart-skip';
const DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY = 2048;
const DISTRESS_AUTOTUNE_MANUAL_DEFAULT_CONCURRENCY = 4096;
const DISTRESS_AUTOTUNE_MIN_CONCURRENCY = 64;
const DISTRESS_AUTOTUNE_MAX_CONCURRENCY = 30720;
const DISTRESS_AUTOTUNE_UPLOAD_CAP_URL = 'https://speed.cloudflare.com/__up';
const DISTRESS_AUTOTUNE_UPLOAD_CAP_SAMPLE_BYTES = 67108864;
const DISTRESS_AUTOTUNE_UPLOAD_CAP_SAMPLE_COUNT = 3;
const DISTRESS_AUTOTUNE_UPLOAD_CAP_TIMEOUT_SECONDS = 15;
const DISTRESS_AUTOTUNE_BIG_CORE_COUNT = 4;
const DISTRESS_AUTOTUNE_BIG_CORE_WEIGHT = 1.0;
const DISTRESS_AUTOTUNE_LITTLE_CORE_COUNT = 2;
const DISTRESS_AUTOTUNE_LITTLE_CORE_WEIGHT = 0.6;
const DISTRESS_AUTOTUNE_SYSTEM_CPU_RESERVE = 1.0;
const DISTRESS_AUTOTUNE_CPU_TARGET_UTILIZATION = 0.85;
const DISTRESS_AUTOTUNE_TARGET_LOAD = 3.1;
const DISTRESS_AUTOTUNE_TARGET_LOAD_MIN = 1.0;
const DISTRESS_AUTOTUNE_DEAD_ZONE_HALF_WIDTH = 0.4;
const DISTRESS_AUTOTUNE_DEAD_ZONE_UPPER_LIMIT = 3.5;
const DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT = 10.0;
const DISTRESS_AUTOTUNE_RAM_RECOVERY_PERCENT = 15.0;
const DISTRESS_AUTOTUNE_COOLDOWN_SECONDS = 360;
const DISTRESS_AUTOTUNE_BPS_STATE_FILE = ROOT_HELPER_STATE_DIR . '/distress-bps.json';
const DISTRESS_AUTOTUNE_BPS_BEST_IMPROVEMENT_RATIO = 1.01;
const DISTRESS_AUTOTUNE_BPS_DEAD_ZONE_RATIO = 0.10;
const DISTRESS_AUTOTUNE_SPEED_TOLERANCE_RATIO = 0.10;
const DISTRESS_AUTOTUNE_BPS_SETTLE_CYCLES = 0;
const DISTRESS_AUTOTUNE_PROBE_WINDOWS_REQUIRED = 2;
const DISTRESS_AUTOTUNE_DROP_WINDOWS_REQUIRED = 4;
const DISTRESS_AUTOTUNE_CONFIRMED_BEST_WINDOWS_REQUIRED = 4;
const DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE = 'coarse';
const DISTRESS_AUTOTUNE_SEARCH_PHASE_REFINE = 'refine';
const DISTRESS_AUTOTUNE_SEARCH_PHASE_HOLD = 'hold';

function distressAutotuneDebugLog(string $event, array $context = []): void
{
    $parts = ['event=' . $event];
    foreach ($context as $key => $value) {
        if (is_bool($value)) {
            $normalized = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $normalized = 'null';
        } elseif (is_scalar($value)) {
            $normalized = (string)$value;
        } else {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES);
            $normalized = is_string($json) ? $json : 'unserializable';
        }
        $parts[] = $key . '=' . $normalized;
    }

    writeDebugLogLine(DISTRESS_AUTOTUNE_DEBUG_LOG_FILE, implode(' ', $parts));
}

function ensureDistressAutotuneTimerInstalled(): bool
{
    if (isSystemdUnitKnown(DISTRESS_AUTOTUNE_TIMER_NAME)) {
        return true;
    }

    if (!repairRootHelperAccess()) {
        return false;
    }

    $systemctl = findSystemctl();
    if ($systemctl !== null) {
        runCommand(escapeshellarg($systemctl) . ' daemon-reload', $reloadCode);
        runCommand(escapeshellarg($systemctl) . ' enable --now ' . escapeshellarg(DISTRESS_AUTOTUNE_TIMER_NAME), $enableCode);
    }

    return isSystemdUnitKnown(DISTRESS_AUTOTUNE_TIMER_NAME);
}

function normalizeDistressConcurrency($value): ?int
{
    if (is_int($value)) {
        $concurrency = $value;
    } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
        $concurrency = (int)trim($value);
    } else {
        return null;
    }

    if ($concurrency < DISTRESS_AUTOTUNE_MIN_CONCURRENCY) {
        return null;
    }

    return min(DISTRESS_AUTOTUNE_MAX_CONCURRENCY, $concurrency);
}

function normalizeDistressAutotuneEnabled($value): bool
{
    return $value === true || $value === '1' || $value === 1 || $value === 'true';
}

function distressAutotuneError(string $error, array $extra = []): array
{
    return ['ok' => false, 'error' => $error] + $extra;
}

function getDistressAutotuneCpuCount(): int
{
    $cpuCount = 0;

    $rawCpuInfo = @file_get_contents('/proc/cpuinfo');
    if (is_string($rawCpuInfo) && $rawCpuInfo !== '') {
        if (preg_match_all('/^processor\s*:/mi', $rawCpuInfo, $matches) > 0) {
            $cpuCount = count($matches[0]);
        }
    }

    if ($cpuCount <= 0) {
        $nproc = trim(runCommand('/usr/bin/env nproc', $code));
        if ($code === 0 && preg_match('/^\d+$/', $nproc) === 1) {
            $cpuCount = (int)$nproc;
        }
    }

    return max(1, $cpuCount);
}

function getDistressAutotuneTargetLoad(): float
{
    return max(DISTRESS_AUTOTUNE_TARGET_LOAD_MIN, DISTRESS_AUTOTUNE_TARGET_LOAD);
}

function getDistressAutotuneDeadZoneLower(float $targetLoad): float
{
    return max(0.7, $targetLoad - DISTRESS_AUTOTUNE_DEAD_ZONE_HALF_WIDTH);
}

function getDistressAutotuneDeadZoneUpper(float $targetLoad): float
{
    return min(DISTRESS_AUTOTUNE_DEAD_ZONE_UPPER_LIMIT, $targetLoad + DISTRESS_AUTOTUNE_DEAD_ZONE_HALF_WIDTH);
}

function getDistressAutotuneMediumIncreaseThreshold(float $targetLoad): float
{
    return max(0.8, $targetLoad - 1.2);
}

function getDistressAutotuneLargeIncreaseThreshold(float $targetLoad): float
{
    return max(0.5, $targetLoad - 3.2);
}

function getDistressConfigConcurrency(): int
{
    $execStart = readServiceExecStart('distress');
    if (!is_string($execStart) || $execStart === '') {
        return DISTRESS_AUTOTUNE_MANUAL_DEFAULT_CONCURRENCY;
    }

    $value = getExecStartOptionValue($execStart, 'concurrency');
    $concurrency = normalizeDistressConcurrency($value);
    return $concurrency ?? DISTRESS_AUTOTUNE_MANUAL_DEFAULT_CONCURRENCY;
}

function setDistressConfigConcurrency(int $concurrency): bool
{
    $execStart = readServiceExecStart('distress');
    if (!is_string($execStart) || $execStart === '') {
        return false;
    }

    $updatedExecStart = replaceExecStartOptionValue($execStart, 'concurrency', (string)$concurrency);
    if ($updatedExecStart === '') {
        return false;
    }

    return updateServiceExecStart('distress', $updatedExecStart);
}

function getDistressLiveAppliedConcurrency(): ?int
{
    if (!serviceIsActive('distress')) {
        return null;
    }

    $serviceArg = escapeshellarg('distress.service');
    $pidRaw = trim(runCommand("systemctl show -p MainPID --value $serviceArg", $code));
    if ($code !== 0 || preg_match('/^\d+$/', $pidRaw) !== 1) {
        return null;
    }

    $pid = (int)$pidRaw;
    if ($pid <= 0) {
        return null;
    }

    $cmdlineRaw = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($cmdlineRaw) || $cmdlineRaw === '') {
        return null;
    }

    $tokens = preg_split('/\0+/', rtrim($cmdlineRaw, "\0"));
    if (!is_array($tokens) || $tokens === []) {
        return null;
    }

    foreach ($tokens as $idx => $token) {
        if ($token !== '--concurrency') {
            continue;
        }
        $next = $tokens[$idx + 1] ?? null;
        $concurrency = normalizeDistressConcurrency($next);
        if ($concurrency !== null) {
            return $concurrency;
        }
    }

    return null;
}

function readDistressAutotuneState(): array
{
    ensureWebuiVarLayout();
    migrateLegacyFileIfNeeded(DISTRESS_AUTOTUNE_LEGACY_STATE_FILE, DISTRESS_AUTOTUNE_STATE_FILE);

    $defaults = [
        'enabled' => true,
        'desiredConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
        'lastAdjustedAt' => 0,
        'lastLoadAverage' => null,
        'lastRamFreePercent' => null,
        'lastBpsMbps' => null,
        'bestBpsMbps' => null,
        'bestBpsConcurrency' => null,
        'confirmedBestBpsMbps' => null,
        'confirmedBestBpsConcurrency' => null,
        'uploadCapMbps' => null,
        'uploadCapMeasuredAt' => null,
        'lastAutostartBootId' => null,
        'lastTargetCount' => null,
        'lastBpsCycleId' => null,
        'bpsSettleCyclesRemaining' => 0,
        'searchPhase' => DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
        'probeConcurrency' => null,
        'probeWindowBps' => [],
        'lastProbeSampleAt' => null,
        'refineLowConcurrency' => null,
        'refineHighConcurrency' => null,
    ];

    $raw = @file_get_contents(DISTRESS_AUTOTUNE_STATE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaults;
    }

    $desiredConcurrency = normalizeDistressConcurrency($data['desiredConcurrency'] ?? null)
        ?? normalizeDistressConcurrency($data['currentConcurrency'] ?? null)
        ?? getDistressConfigConcurrency();
    $lastAdjustedAt = isset($data['lastAdjustedAt']) && is_int($data['lastAdjustedAt']) ? $data['lastAdjustedAt'] : 0;
    return [
        'enabled' => ($data['enabled'] ?? true) === true,
        'desiredConcurrency' => $desiredConcurrency,
        'lastAdjustedAt' => $lastAdjustedAt,
        'lastLoadAverage' => isset($data['lastLoadAverage']) && is_numeric($data['lastLoadAverage'])
            ? (float)$data['lastLoadAverage']
            : null,
        'lastRamFreePercent' => isset($data['lastRamFreePercent']) && is_numeric($data['lastRamFreePercent'])
            ? (float)$data['lastRamFreePercent']
            : null,
        'lastBpsMbps' => isset($data['lastBpsMbps']) && is_numeric($data['lastBpsMbps'])
            ? (float)$data['lastBpsMbps']
            : null,
        'bestBpsMbps' => isset($data['bestBpsMbps']) && is_numeric($data['bestBpsMbps'])
            ? (float)$data['bestBpsMbps']
            : null,
        'bestBpsConcurrency' => normalizeDistressConcurrency($data['bestBpsConcurrency'] ?? null),
        'confirmedBestBpsMbps' => isset($data['confirmedBestBpsMbps']) && is_numeric($data['confirmedBestBpsMbps'])
            ? (float)$data['confirmedBestBpsMbps']
            : null,
        'confirmedBestBpsConcurrency' => normalizeDistressConcurrency($data['confirmedBestBpsConcurrency'] ?? null),
        'uploadCapMbps' => isset($data['uploadCapMbps']) && is_numeric($data['uploadCapMbps']) && (float)$data['uploadCapMbps'] > 0.0
            ? (float)$data['uploadCapMbps']
            : null,
        'uploadCapMeasuredAt' => isset($data['uploadCapMeasuredAt']) && is_numeric($data['uploadCapMeasuredAt'])
            ? (int)$data['uploadCapMeasuredAt']
            : null,
        'lastAutostartBootId' => isset($data['lastAutostartBootId']) && is_string($data['lastAutostartBootId']) && $data['lastAutostartBootId'] !== ''
            ? $data['lastAutostartBootId']
            : null,
        'lastTargetCount' => isset($data['lastTargetCount']) && is_numeric($data['lastTargetCount'])
            ? max(0, (int)$data['lastTargetCount'])
            : null,
        'lastBpsCycleId' => isset($data['lastBpsCycleId']) && is_string($data['lastBpsCycleId']) && $data['lastBpsCycleId'] !== ''
            ? $data['lastBpsCycleId']
            : null,
        'bpsSettleCyclesRemaining' => isset($data['bpsSettleCyclesRemaining']) && is_numeric($data['bpsSettleCyclesRemaining'])
            ? max(0, (int)$data['bpsSettleCyclesRemaining'])
            : 0,
        'searchPhase' => normalizeDistressSearchPhase($data['searchPhase'] ?? null),
        'probeConcurrency' => normalizeDistressConcurrency($data['probeConcurrency'] ?? null),
        'probeWindowBps' => normalizeDistressProbeWindowBps($data['probeWindowBps'] ?? []),
        'lastProbeSampleAt' => isset($data['lastProbeSampleAt']) && is_numeric($data['lastProbeSampleAt'])
            ? (int)$data['lastProbeSampleAt']
            : null,
        'refineLowConcurrency' => normalizeDistressConcurrency($data['refineLowConcurrency'] ?? null),
        'refineHighConcurrency' => normalizeDistressConcurrency($data['refineHighConcurrency'] ?? null),
    ];
}

function writeDistressAutotuneState(array $state): bool
{
    if (!ensureWebuiVarLayout() || !ensureParentDirectoryExists(DISTRESS_AUTOTUNE_STATE_FILE)) {
        return false;
    }

    $payload = json_encode([
        'enabled' => ($state['enabled'] ?? false) === true,
        'desiredConcurrency' => (int)($state['desiredConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY),
        'lastAdjustedAt' => (int)($state['lastAdjustedAt'] ?? 0),
        'lastLoadAverage' => isset($state['lastLoadAverage']) && is_numeric($state['lastLoadAverage'])
            ? (float)$state['lastLoadAverage']
            : null,
        'lastRamFreePercent' => isset($state['lastRamFreePercent']) && is_numeric($state['lastRamFreePercent'])
            ? (float)$state['lastRamFreePercent']
            : null,
        'lastBpsMbps' => isset($state['lastBpsMbps']) && is_numeric($state['lastBpsMbps'])
            ? (float)$state['lastBpsMbps']
            : null,
        'bestBpsMbps' => isset($state['bestBpsMbps']) && is_numeric($state['bestBpsMbps'])
            ? (float)$state['bestBpsMbps']
            : null,
        'bestBpsConcurrency' => normalizeDistressConcurrency($state['bestBpsConcurrency'] ?? null),
        'confirmedBestBpsMbps' => isset($state['confirmedBestBpsMbps']) && is_numeric($state['confirmedBestBpsMbps'])
            ? (float)$state['confirmedBestBpsMbps']
            : null,
        'confirmedBestBpsConcurrency' => normalizeDistressConcurrency($state['confirmedBestBpsConcurrency'] ?? null),
        'uploadCapMbps' => isset($state['uploadCapMbps']) && is_numeric($state['uploadCapMbps']) && (float)$state['uploadCapMbps'] > 0.0
            ? (float)$state['uploadCapMbps']
            : null,
        'uploadCapMeasuredAt' => isset($state['uploadCapMeasuredAt']) && is_numeric($state['uploadCapMeasuredAt'])
            ? (int)$state['uploadCapMeasuredAt']
            : null,
        'lastAutostartBootId' => isset($state['lastAutostartBootId']) && is_string($state['lastAutostartBootId']) && $state['lastAutostartBootId'] !== ''
            ? $state['lastAutostartBootId']
            : null,
        'lastTargetCount' => isset($state['lastTargetCount']) && is_numeric($state['lastTargetCount'])
            ? max(0, (int)$state['lastTargetCount'])
            : null,
        'lastBpsCycleId' => isset($state['lastBpsCycleId']) && is_string($state['lastBpsCycleId']) && $state['lastBpsCycleId'] !== ''
            ? $state['lastBpsCycleId']
            : null,
        'bpsSettleCyclesRemaining' => isset($state['bpsSettleCyclesRemaining']) && is_numeric($state['bpsSettleCyclesRemaining'])
            ? max(0, (int)$state['bpsSettleCyclesRemaining'])
            : 0,
        'searchPhase' => normalizeDistressSearchPhase($state['searchPhase'] ?? null),
        'probeConcurrency' => normalizeDistressConcurrency($state['probeConcurrency'] ?? null),
        'probeWindowBps' => normalizeDistressProbeWindowBps($state['probeWindowBps'] ?? []),
        'lastProbeSampleAt' => isset($state['lastProbeSampleAt']) && is_numeric($state['lastProbeSampleAt'])
            ? (int)$state['lastProbeSampleAt']
            : null,
        'refineLowConcurrency' => normalizeDistressConcurrency($state['refineLowConcurrency'] ?? null),
        'refineHighConcurrency' => normalizeDistressConcurrency($state['refineHighConcurrency'] ?? null),
    ], JSON_UNESCAPED_SLASHES);

    if (!is_string($payload)) {
        return false;
    }

    $tmpPath = DISTRESS_AUTOTUNE_STATE_FILE . '.tmp';
    if (@file_put_contents($tmpPath, $payload) === false) {
        return false;
    }

    if (!@rename($tmpPath, DISTRESS_AUTOTUNE_STATE_FILE)) {
        @unlink($tmpPath);
        return false;
    }

    return true;
}

function normalizeDistressSearchPhase($value): string
{
    return match ($value) {
        DISTRESS_AUTOTUNE_SEARCH_PHASE_REFINE => DISTRESS_AUTOTUNE_SEARCH_PHASE_REFINE,
        DISTRESS_AUTOTUNE_SEARCH_PHASE_HOLD => DISTRESS_AUTOTUNE_SEARCH_PHASE_HOLD,
        default => DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
    };
}

function normalizeDistressProbeWindowBps($values): array
{
    if (!is_array($values)) {
        return [];
    }

    $normalized = [];
    foreach ($values as $value) {
        if (is_numeric($value)) {
            $floatValue = (float)$value;
            if ($floatValue > 0.0) {
                $normalized[] = $floatValue;
            }
        }
    }

    $maxWindows = max(DISTRESS_AUTOTUNE_PROBE_WINDOWS_REQUIRED, DISTRESS_AUTOTUNE_DROP_WINDOWS_REQUIRED);
    if (count($normalized) > $maxWindows) {
        $normalized = array_slice($normalized, -$maxWindows);
    }

    return $normalized;
}

function resetDistressAutotuneLearnedState(array &$state, bool $resetUploadCap = false): void
{
    $state['lastAdjustedAt'] = 0;
    $state['lastLoadAverage'] = null;
    $state['lastRamFreePercent'] = null;
    $state['lastBpsMbps'] = null;
    $state['bestBpsMbps'] = null;
    $state['bestBpsConcurrency'] = null;
    $state['confirmedBestBpsMbps'] = null;
    $state['confirmedBestBpsConcurrency'] = null;
    if ($resetUploadCap) {
        $state['uploadCapMbps'] = null;
        $state['uploadCapMeasuredAt'] = null;
    }
    $state['lastTargetCount'] = null;
    $state['lastBpsCycleId'] = null;
    $state['bpsSettleCyclesRemaining'] = 0;
    resetDistressProbeState($state, DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE);
}

function applyDistressAutotuneModeState(array &$state, bool $enabled, int $concurrency, bool $resetUploadCap = false): void
{
    $state['enabled'] = $enabled;
    $state['desiredConcurrency'] = $concurrency;
    resetDistressAutotuneLearnedState($state, $resetUploadCap);
}

function persistDistressAutotuneStateAndReturnStatus(array $state, $lockHandle, bool $changed, string $reason, array $extra = []): array
{
    if (!writeDistressAutotuneState($state)) {
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('distress_autotune_state_write_failed');
    }

    $status = getDistressAutotuneStatus();
    releaseDistressAutotuneLock($lockHandle);
    return $status + ['changed' => $changed, 'reason' => $reason] + $extra;
}

function resetDistressProbeState(array &$state, string $phase = DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE): void
{
    $state['searchPhase'] = $phase;
    $state['probeConcurrency'] = null;
    $state['probeWindowBps'] = [];
    $state['lastProbeSampleAt'] = null;
    $state['refineLowConcurrency'] = null;
    $state['refineHighConcurrency'] = null;
}

function ensureDistressProbeForCurrentConcurrency(array &$state, int $currentConcurrency): void
{
    $probeConcurrency = normalizeDistressConcurrency($state['probeConcurrency'] ?? null);
    if ($probeConcurrency === $currentConcurrency) {
        return;
    }

    $state['probeConcurrency'] = $currentConcurrency;
    $state['probeWindowBps'] = [];
    $state['lastProbeSampleAt'] = null;
}

function appendDistressProbeWindow(array &$state, float $bpsMbps, int $sampleAt): bool
{
    $lastProbeSampleAt = isset($state['lastProbeSampleAt']) && is_numeric($state['lastProbeSampleAt'])
        ? (int)$state['lastProbeSampleAt']
        : null;
    if ($lastProbeSampleAt !== null && $sampleAt <= $lastProbeSampleAt) {
        return false;
    }

    $windows = normalizeDistressProbeWindowBps($state['probeWindowBps'] ?? []);
    $windows[] = $bpsMbps;
    $maxWindows = max(DISTRESS_AUTOTUNE_PROBE_WINDOWS_REQUIRED, DISTRESS_AUTOTUNE_DROP_WINDOWS_REQUIRED);
    if (count($windows) > $maxWindows) {
        $windows = array_slice($windows, -$maxWindows);
    }

    $state['probeWindowBps'] = $windows;
    $state['lastProbeSampleAt'] = $sampleAt;
    return true;
}

function getDistressProbeWindowCount(array $state): int
{
    return count(normalizeDistressProbeWindowBps($state['probeWindowBps'] ?? []));
}

function calculateDistressProbeScore(array $state, ?int $requiredWindows = null): ?float
{
    $required = $requiredWindows ?? DISTRESS_AUTOTUNE_PROBE_WINDOWS_REQUIRED;
    $required = max(1, $required);
    $windows = normalizeDistressProbeWindowBps($state['probeWindowBps'] ?? []);
    if (count($windows) < $required) {
        return null;
    }

    if (count($windows) > $required) {
        $windows = array_slice($windows, -$required);
    }

    sort($windows, SORT_NUMERIC);
    $count = count($windows);
    $middle = (int)floor($count / 2);
    if (($count % 2) === 1) {
        return $windows[$middle];
    }

    return ($windows[$middle - 1] + $windows[$middle]) / 2.0;
}

function calculateDistressRefineMidpoint(int $lowerConcurrency, int $upperConcurrency): ?int
{
    if ($upperConcurrency <= $lowerConcurrency) {
        return null;
    }

    $midpoint = roundDistressConcurrency((int)floor(($lowerConcurrency + $upperConcurrency) / 2));
    if ($midpoint <= $lowerConcurrency || $midpoint >= $upperConcurrency) {
        return null;
    }

    return $midpoint;
}

function acquireDistressAutotuneLock(int $timeoutMilliseconds = 5000, int $retrySleepMicroseconds = 100000)
{
    $handle = @fopen(DISTRESS_AUTOTUNE_LOCK_FILE, 'c+');
    if ($handle === false) {
        return false;
    }

    $deadline = microtime(true) + (max(0, $timeoutMilliseconds) / 1000);
    do {
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return $handle;
        }

        if (microtime(true) >= $deadline) {
            fclose($handle);
            distressAutotuneDebugLog('lock_timeout', [
                'timeoutMilliseconds' => $timeoutMilliseconds,
            ]);
            return false;
        }

        usleep(max(1000, $retrySleepMicroseconds));
    } while (true);
}

function releaseDistressAutotuneLock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function resetDistressAutotuneBaseline(): bool
{
    $state = readDistressAutotuneState();
    if (($state['enabled'] ?? false) !== true) {
        return true;
    }

    $previousConfigConcurrency = getDistressConfigConcurrency();
    if (!setDistressConfigConcurrency(DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY)) {
        return false;
    }

    $state['desiredConcurrency'] = DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY;
    resetDistressAutotuneLearnedState($state, true);
    if (writeDistressAutotuneState($state)) {
        return true;
    }

    setDistressConfigConcurrency($previousConfigConcurrency);
    return false;
}

function getDistressAutotuneStatus(): array
{
    $state = readDistressAutotuneState();
    $targetLoad = getDistressAutotuneTargetLoad();
    $desiredConcurrency = (int)($state['desiredConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY);
    $configConcurrency = getDistressConfigConcurrency();
    if ($configConcurrency !== $desiredConcurrency) {
        $desiredConcurrency = $configConcurrency;
    }
    $liveAppliedConcurrency = getDistressLiveAppliedConcurrency();

    $enabled = ($state['enabled'] ?? false) === true;
    $serviceActive = serviceIsActive('distress');
    $cooldownRemaining = 0;
    $statusKey = 'distress_autotune_status_active';
    if (!$enabled) {
        $statusKey = 'distress_autotune_status_manual';
    } elseif (!$serviceActive) {
        $statusKey = 'distress_autotune_status_inactive';
    } else {
        $cooldownRemaining = max(0, DISTRESS_AUTOTUNE_COOLDOWN_SECONDS - (time() - (int)($state['lastAdjustedAt'] ?? 0)));
        if ($cooldownRemaining > 0) {
            $statusKey = 'distress_autotune_status_cooldown';
        }
    }

    return [
        'ok' => true,
        'enabled' => $enabled,
        'serviceActive' => $serviceActive,
        'desiredConcurrency' => $desiredConcurrency,
        'configConcurrency' => $configConcurrency,
        'liveAppliedConcurrency' => $liveAppliedConcurrency,
        'currentConcurrency' => $configConcurrency,
        'defaultConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
        'targetLoad' => $targetLoad,
        'cpuCount' => getDistressAutotuneCpuCount(),
        'cpuEffectiveCapacity' => (
            DISTRESS_AUTOTUNE_BIG_CORE_COUNT * DISTRESS_AUTOTUNE_BIG_CORE_WEIGHT
        ) + (
            DISTRESS_AUTOTUNE_LITTLE_CORE_COUNT * DISTRESS_AUTOTUNE_LITTLE_CORE_WEIGHT
        ),
        'systemCpuReserve' => DISTRESS_AUTOTUNE_SYSTEM_CPU_RESERVE,
        'minFreeRamPercent' => DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT,
        'cooldownSeconds' => DISTRESS_AUTOTUNE_COOLDOWN_SECONDS,
        'cooldownRemaining' => $cooldownRemaining,
        'statusKey' => $statusKey,
        'lastAdjustedAt' => (int)($state['lastAdjustedAt'] ?? 0),
        'lastLoadAverage' => $state['lastLoadAverage'] ?? null,
        'lastRamFreePercent' => $state['lastRamFreePercent'] ?? null,
        'lastBpsMbps' => $state['lastBpsMbps'] ?? null,
        'bestBpsMbps' => $state['bestBpsMbps'] ?? null,
        'bestBpsConcurrency' => $state['bestBpsConcurrency'] ?? null,
        'confirmedBestBpsMbps' => $state['confirmedBestBpsMbps'] ?? null,
        'confirmedBestBpsConcurrency' => $state['confirmedBestBpsConcurrency'] ?? null,
        'uploadCapMbps' => $state['uploadCapMbps'] ?? null,
        'uploadCapMeasuredAt' => $state['uploadCapMeasuredAt'] ?? null,
        'lastTargetCount' => $state['lastTargetCount'] ?? null,
        'lastBpsCycleId' => $state['lastBpsCycleId'] ?? null,
        'bpsSettleCyclesRemaining' => (int)($state['bpsSettleCyclesRemaining'] ?? 0),
    ];
}

function setDistressAutotuneMode($enabledValue, $concurrencyValue): array
{
    $enabled = normalizeDistressAutotuneEnabled($enabledValue);
    if ($enabled && !ensureDistressAutotuneTimerInstalled()) {
        return distressAutotuneError('distress_autotune_timer_install_failed');
    }

    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        return distressAutotuneError('distress_autotune_lock_failed');
    }

    $concurrency = normalizeDistressConcurrency($concurrencyValue);
    if ($concurrency === null) {
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('invalid_concurrency');
    }

    $previousConfigConcurrency = getDistressConfigConcurrency();
    $previousState = readDistressAutotuneState();
    $configChanged = $previousConfigConcurrency !== $concurrency;
    if ($configChanged && !setDistressConfigConcurrency($concurrency)) {
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('distress_concurrency_write_failed');
    }

    $state = $previousState;
    applyDistressAutotuneModeState($state, $enabled, $concurrency, true);
    if (!writeDistressAutotuneState($state)) {
        $rollbackConfigOk = !$configChanged || setDistressConfigConcurrency($previousConfigConcurrency);
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('distress_autotune_state_write_failed', [
            'rollbackConfigOk' => $rollbackConfigOk,
            'configConcurrencyAfterRollback' => getDistressConfigConcurrency(),
        ]);
    }

    releaseDistressAutotuneLock($lockHandle);
    return getDistressAutotuneStatus();
}

function saveDistressSettings(string $execStartLine, $enabledValue, $concurrencyValue): array
{
    $enabled = normalizeDistressAutotuneEnabled($enabledValue);
    if ($enabled && !ensureDistressAutotuneTimerInstalled()) {
        return distressAutotuneError('distress_autotune_timer_install_failed');
    }

    if (!str_starts_with($execStartLine, 'ExecStart=')) {
        return distressAutotuneError('invalid_execstart');
    }

    $concurrency = normalizeDistressConcurrency($concurrencyValue);
    if ($concurrency === null) {
        return distressAutotuneError('invalid_concurrency');
    }

    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        return distressAutotuneError('distress_autotune_lock_failed');
    }

    $previousExecStart = readServiceExecStart('distress');
    if (!is_string($previousExecStart) || $previousExecStart === '') {
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('execstart_read_failed');
    }

    $previousState = readDistressAutotuneState();
    $configChanged = $previousExecStart !== $execStartLine;
    if ($configChanged && !updateServiceExecStart('distress', $execStartLine)) {
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('distress_concurrency_write_failed');
    }

    $state = $previousState;
    applyDistressAutotuneModeState($state, $enabled, $concurrency);
    if (!writeDistressAutotuneState($state)) {
        $rollbackConfigOk = !$configChanged || updateServiceExecStart('distress', $previousExecStart);
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('distress_autotune_state_write_failed', [
            'rollbackConfigOk' => $rollbackConfigOk,
        ]);
    }

    releaseDistressAutotuneLock($lockHandle);
    return getDistressAutotuneStatus();
}

function roundDistressConcurrency(int $value): int
{
    $value = max(DISTRESS_AUTOTUNE_MIN_CONCURRENCY, min(DISTRESS_AUTOTUNE_MAX_CONCURRENCY, $value));
    $step = 64;
    $rounded = (int)(round($value / $step) * $step);
    return max(DISTRESS_AUTOTUNE_MIN_CONCURRENCY, min(DISTRESS_AUTOTUNE_MAX_CONCURRENCY, $rounded));
}

function findCurlBinary(): ?string
{
    return findExecutable([
        '/usr/bin/curl',
        '/usr/local/bin/curl',
        '/bin/curl',
    ]);
}

function measureDistressUploadCapWithPhpCurl(string $url, int $bytes, int $timeoutSeconds): ?float
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $payload = str_repeat('A', $bytes);
    if ($payload === '') {
        return null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream'],
    ]);

    $startedAt = microtime(true);
    $result = curl_exec($ch);
    $durationSeconds = microtime(true) - $startedAt;
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($result === false || $durationSeconds <= 0.0 || $httpCode < 200 || $httpCode >= 400) {
        return null;
    }

    return ($bytes * 8.0) / $durationSeconds / 1000000.0;
}

function measureDistressUploadCapWithCurlBinary(string $url, int $bytes, int $timeoutSeconds): ?float
{
    $curlBinary = findCurlBinary();
    if ($curlBinary === null) {
        return null;
    }

    $byteCount = max(1, $bytes);
    $command = '/usr/bin/env bash -lc ' . escapeshellarg(
        'head -c ' . $byteCount . ' /dev/zero | ' .
        escapeshellarg($curlBinary) . ' --silent --show-error --output /dev/null ' .
        '--max-time ' . max(1, $timeoutSeconds) . ' --write-out "%{time_total}" ' .
        '--request POST --data-binary @- ' . escapeshellarg($url)
    );
    $output = trim(runCommand($command, $code));
    if ($code !== 0 || !is_numeric($output)) {
        return null;
    }

    $durationSeconds = (float)$output;
    if ($durationSeconds <= 0.0) {
        return null;
    }

    return ($byteCount * 8.0) / $durationSeconds / 1000000.0;
}

if (!function_exists('measureDistressCloudflareUploadCap')) {
    function measureDistressCloudflareUploadCap(): ?float
    {
        $samples = [];
        for ($i = 0; $i < DISTRESS_AUTOTUNE_UPLOAD_CAP_SAMPLE_COUNT; $i++) {
            $sample = measureDistressUploadCapWithPhpCurl(
                DISTRESS_AUTOTUNE_UPLOAD_CAP_URL,
                DISTRESS_AUTOTUNE_UPLOAD_CAP_SAMPLE_BYTES,
                DISTRESS_AUTOTUNE_UPLOAD_CAP_TIMEOUT_SECONDS
            );
            if ($sample === null) {
                $sample = measureDistressUploadCapWithCurlBinary(
                    DISTRESS_AUTOTUNE_UPLOAD_CAP_URL,
                    DISTRESS_AUTOTUNE_UPLOAD_CAP_SAMPLE_BYTES,
                    DISTRESS_AUTOTUNE_UPLOAD_CAP_TIMEOUT_SECONDS
                );
            }
            if ($sample !== null && $sample > 0.0) {
                $samples[] = $sample;
            }
        }

        if ($samples === []) {
            distressAutotuneDebugLog('upload_cap_measure_failed', [
                'url' => DISTRESS_AUTOTUNE_UPLOAD_CAP_URL,
                'sampleBytes' => DISTRESS_AUTOTUNE_UPLOAD_CAP_SAMPLE_BYTES,
                'sampleCount' => DISTRESS_AUTOTUNE_UPLOAD_CAP_SAMPLE_COUNT,
            ]);
            return null;
        }

        sort($samples, SORT_NUMERIC);
        $sampleCount = count($samples);
        $middle = (int)floor($sampleCount / 2);
        if (($sampleCount % 2) === 1) {
            $measuredMbps = $samples[$middle];
        } else {
            $measuredMbps = ($samples[$middle - 1] + $samples[$middle]) / 2.0;
        }
        distressAutotuneDebugLog('upload_cap_measured', [
            'url' => DISTRESS_AUTOTUNE_UPLOAD_CAP_URL,
            'sampleBytes' => DISTRESS_AUTOTUNE_UPLOAD_CAP_SAMPLE_BYTES,
            'sampleCount' => DISTRESS_AUTOTUNE_UPLOAD_CAP_SAMPLE_COUNT,
            'samplesMbps' => $samples,
            'measuredMbps' => $measuredMbps,
            'aggregation' => 'median',
        ]);

        return $measuredMbps;
    }
}

function refreshDistressUploadCap(array &$state): ?float
{
    $measuredMbps = measureDistressCloudflareUploadCap();
    if ($measuredMbps === null || $measuredMbps <= 0.0) {
        return null;
    }

    $state['uploadCapMbps'] = $measuredMbps;
    $state['uploadCapMeasuredAt'] = time();
    return $measuredMbps;
}

function markDistressUploadCapServicePrestartSkip(): bool
{
    if (!ensureWebuiVarLayout() || !ensureParentDirectoryExists(DISTRESS_AUTOTUNE_SERVICE_PRESTART_SKIP_FILE)) {
        return false;
    }

    return @file_put_contents(DISTRESS_AUTOTUNE_SERVICE_PRESTART_SKIP_FILE, (string)time()) !== false;
}

function consumeDistressUploadCapServicePrestartSkip(): bool
{
    if (!is_file(DISTRESS_AUTOTUNE_SERVICE_PRESTART_SKIP_FILE)) {
        return false;
    }

    @unlink(DISTRESS_AUTOTUNE_SERVICE_PRESTART_SKIP_FILE);
    return true;
}

if (!function_exists('readCurrentSystemBootId')) {
    function readCurrentSystemBootId(): ?string
    {
        $raw = @file_get_contents('/proc/sys/kernel/random/boot_id');
        if (!is_string($raw)) {
            return null;
        }

        $bootId = trim($raw);
        return $bootId !== '' ? $bootId : null;
    }
}

function shouldForceDistressUploadCapRefreshForAutostart(array $state): bool
{
    if (!isModuleAutostartEnabled('distress')) {
        return false;
    }

    $bootId = readCurrentSystemBootId();
    if (!is_string($bootId) || $bootId === '') {
        return false;
    }

    return (string)($state['lastAutostartBootId'] ?? '') !== $bootId;
}

function shouldRefreshDistressUploadCapOnStart(array $state, bool $forceRefresh): bool
{
    if ($forceRefresh) {
        return true;
    }

    return !isset($state['uploadCapMeasuredAt']) || !is_numeric($state['uploadCapMeasuredAt']) || (int)$state['uploadCapMeasuredAt'] <= 0
        || !isset($state['uploadCapMbps']) || !is_numeric($state['uploadCapMbps']) || (float)$state['uploadCapMbps'] <= 0.0;
}

function prepareDistressUploadCapBeforeStart(bool $forceRefresh = false): bool
{
    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        distressAutotuneDebugLog('upload_cap_lock_failed_before_start');
        return false;
    }

    $state = readDistressAutotuneState();
    if (($state['enabled'] ?? false) !== true) {
        releaseDistressAutotuneLock($lockHandle);
        return true;
    }

    if (shouldRefreshDistressUploadCapOnStart($state, $forceRefresh)) {
        refreshDistressUploadCap($state);
        if (!writeDistressAutotuneState($state)) {
            distressAutotuneDebugLog('upload_cap_state_write_failed_before_start');
            releaseDistressAutotuneLock($lockHandle);
            return false;
        }
    }

    releaseDistressAutotuneLock($lockHandle);
    return true;
}

function prepareDistressUploadCapForServiceStart(): bool
{
    // When WebUI already prepared upload-cap state before `systemctl start`,
    // the service pre-start hook must not try to reacquire the same lock.
    if (consumeDistressUploadCapServicePrestartSkip()) {
        distressAutotuneDebugLog('upload_cap_service_start_skip_consumed');
        return true;
    }

    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        distressAutotuneDebugLog('upload_cap_lock_failed_before_service_start');
        return false;
    }

    $state = readDistressAutotuneState();
    if (($state['enabled'] ?? false) !== true) {
        releaseDistressAutotuneLock($lockHandle);
        return true;
    }

    $bootId = readCurrentSystemBootId();
    $forceRefresh = is_string($bootId) && $bootId !== ''
        && shouldForceDistressUploadCapRefreshForAutostart($state);

    if (shouldRefreshDistressUploadCapOnStart($state, $forceRefresh)) {
        if (refreshDistressUploadCap($state) === null) {
            distressAutotuneDebugLog('upload_cap_measure_failed_before_service_start', [
                'forceRefresh' => $forceRefresh,
                'bootId' => $bootId,
            ]);
            releaseDistressAutotuneLock($lockHandle);
            return false;
        }
    }

    if (is_string($bootId) && $bootId !== '' && isModuleAutostartEnabled('distress')) {
        $state['lastAutostartBootId'] = $bootId;
    }

    if (!writeDistressAutotuneState($state)) {
        distressAutotuneDebugLog('upload_cap_state_write_failed_before_service_start', [
            'forceRefresh' => $forceRefresh,
            'bootId' => $bootId,
        ]);
        releaseDistressAutotuneLock($lockHandle);
        return false;
    }

    releaseDistressAutotuneLock($lockHandle);
    return true;
}

function adjustDistressConcurrencyByPercent(int $currentConcurrency, int $percent): int
{
    $next = (int)round($currentConcurrency * (100 + $percent) / 100);
    return roundDistressConcurrency($next);
}

function adjustDistressConcurrencyForRamFloor(int $currentConcurrency, float $ramFreePercent): int
{
    if ($ramFreePercent >= DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT) {
        return $currentConcurrency;
    }

    if ($ramFreePercent <= 0.0) {
        return DISTRESS_AUTOTUNE_MIN_CONCURRENCY;
    }

    $deficitRatio = (DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT - $ramFreePercent) / DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT;
    $percentDrop = (int)ceil($deficitRatio * 20.0);
    $percentDrop = max(5, min(20, $percentDrop));

    $target = adjustDistressConcurrencyByPercent($currentConcurrency, -$percentDrop);
    if ($target >= $currentConcurrency) {
        $target = roundDistressConcurrency($currentConcurrency - 64);
    }

    return max(DISTRESS_AUTOTUNE_MIN_CONCURRENCY, min($target, $currentConcurrency));
}

function calculateDistressSafetyTargetConcurrency(int $currentConcurrency, float $loadAverage, float $ramFreePercent): int
{
    $targetLoad = getDistressAutotuneTargetLoad();
    $deadZoneLower = getDistressAutotuneDeadZoneLower($targetLoad);
    $deadZoneUpper = getDistressAutotuneDeadZoneUpper($targetLoad);

    if ($ramFreePercent < DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT) {
        return adjustDistressConcurrencyForRamFloor($currentConcurrency, $ramFreePercent);
    }
    if ($ramFreePercent < DISTRESS_AUTOTUNE_RAM_RECOVERY_PERCENT) {
        return $currentConcurrency;
    }
    if ($loadAverage > $deadZoneUpper) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, -20);
    }
    if ($loadAverage > $targetLoad) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, -10);
    }
    if ($loadAverage >= $deadZoneLower) {
        return $currentConcurrency;
    }
    return $currentConcurrency;
}

function calculateDistressExplorationTargetConcurrency(int $currentConcurrency, float $loadAverage, float $ramFreePercent): int
{
    $targetLoad = getDistressAutotuneTargetLoad();
    $mediumIncreaseThreshold = getDistressAutotuneMediumIncreaseThreshold($targetLoad);
    $largeIncreaseThreshold = getDistressAutotuneLargeIncreaseThreshold($targetLoad);

    if ($ramFreePercent < DISTRESS_AUTOTUNE_RAM_RECOVERY_PERCENT) {
        return $currentConcurrency;
    }
    if ($loadAverage >= $mediumIncreaseThreshold) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, 8);
    }
    if ($loadAverage >= $largeIncreaseThreshold) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, 12);
    }
    return adjustDistressConcurrencyByPercent($currentConcurrency, 100);
}

function isWithinDistressBpsDeadZone(float $currentBpsMbps, float $bestBpsMbps): bool
{
    if ($bestBpsMbps <= 0.0) {
        return false;
    }

    $ratioDelta = abs($currentBpsMbps - $bestBpsMbps) / $bestBpsMbps;
    return $ratioDelta <= DISTRESS_AUTOTUNE_BPS_DEAD_ZONE_RATIO;
}

function normalizeDistressBpsAgainstSpeed(?float $bpsMbps, ?float $speedMbps): ?float
{
    if ($bpsMbps === null || $speedMbps === null || $speedMbps <= 0.0) {
        return $bpsMbps;
    }

    $lowerBoundMbps = $speedMbps * (1.0 - DISTRESS_AUTOTUNE_SPEED_TOLERANCE_RATIO);
    $upperBoundMbps = $speedMbps * (1.0 + DISTRESS_AUTOTUNE_SPEED_TOLERANCE_RATIO);

    if ($bpsMbps >= $lowerBoundMbps && $bpsMbps <= $upperBoundMbps) {
        return $speedMbps;
    }

    return min($bpsMbps, $upperBoundMbps);
}

function readDistressBpsState(): array
{
    $defaults = [
        'movingAverageMbps' => null,
        'latestBpsMbps' => null,
        'latestSampleAt' => null,
        'latestTargetCount' => null,
        'sampleCount' => 0,
        'staleAfterSeconds' => null,
        'minSamples' => 0,
        'updatedAt' => null,
        'cycleId' => null,
        'cycleStartedAt' => null,
        'hasFreshSamples' => false,
    ];

    $raw = @file_get_contents(DISTRESS_AUTOTUNE_BPS_STATE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaults;
    }

    return [
        'movingAverageMbps' => isset($data['movingAverageMbps']) && is_numeric($data['movingAverageMbps'])
            ? (float)$data['movingAverageMbps']
            : null,
        'latestBpsMbps' => isset($data['latestBpsMbps']) && is_numeric($data['latestBpsMbps'])
            ? (float)$data['latestBpsMbps']
            : null,
        'latestSampleAt' => isset($data['latestSampleAt']) && is_numeric($data['latestSampleAt'])
            ? (int)$data['latestSampleAt']
            : null,
        'latestTargetCount' => isset($data['latestTargetCount']) && is_numeric($data['latestTargetCount'])
            ? max(0, (int)$data['latestTargetCount'])
            : null,
        'sampleCount' => isset($data['sampleCount']) && is_numeric($data['sampleCount'])
            ? max(0, (int)$data['sampleCount'])
            : 0,
        'staleAfterSeconds' => isset($data['staleAfterSeconds']) && is_numeric($data['staleAfterSeconds'])
            ? max(0, (int)$data['staleAfterSeconds'])
            : null,
        'minSamples' => isset($data['minSamples']) && is_numeric($data['minSamples'])
            ? max(0, (int)$data['minSamples'])
            : 0,
        'updatedAt' => isset($data['updatedAt']) && is_numeric($data['updatedAt'])
            ? (int)$data['updatedAt']
            : null,
        'cycleId' => isset($data['cycleId']) && is_string($data['cycleId']) && $data['cycleId'] !== ''
            ? $data['cycleId']
            : null,
        'cycleStartedAt' => isset($data['cycleStartedAt']) && is_numeric($data['cycleStartedAt'])
            ? (int)$data['cycleStartedAt']
            : null,
        'hasFreshSamples' => ($data['hasFreshSamples'] ?? false) === true,
    ];
}

function getDistressFreshBpsMetrics(int $now): ?array
{
    $bpsState = readDistressBpsState();
    $movingAverageMbps = $bpsState['movingAverageMbps'] ?? null;
    $latestSampleAt = $bpsState['latestSampleAt'] ?? null;
    $sampleCount = (int)($bpsState['sampleCount'] ?? 0);
    $staleAfterSeconds = $bpsState['staleAfterSeconds'] ?? null;
    $minSamples = (int)($bpsState['minSamples'] ?? 0);
    $hasFreshSamples = ($bpsState['hasFreshSamples'] ?? false) === true;
    $autotuneState = readDistressAutotuneState();
    $uploadCapMbps = isset($autotuneState['uploadCapMbps']) && is_numeric($autotuneState['uploadCapMbps']) && (float)$autotuneState['uploadCapMbps'] > 0.0
        ? (float)$autotuneState['uploadCapMbps']
        : null;

    if (
        !is_float($movingAverageMbps) ||
        $movingAverageMbps <= 0.0 ||
        !is_int($latestSampleAt) ||
        $latestSampleAt <= 0 ||
        $sampleCount <= 0 ||
        !$hasFreshSamples ||
        !is_int($staleAfterSeconds) ||
        $staleAfterSeconds <= 0
    ) {
        distressAutotuneDebugLog('bps_state_invalid', [
            'movingAverageMbps' => $movingAverageMbps,
            'latestSampleAt' => $latestSampleAt,
            'sampleCount' => $sampleCount,
            'staleAfterSeconds' => $staleAfterSeconds,
            'minSamples' => $minSamples,
            'hasFreshSamples' => $hasFreshSamples,
        ]);
        return null;
    }

    if ($sampleCount < max(1, $minSamples)) {
        distressAutotuneDebugLog('bps_state_too_few_samples', [
            'sampleCount' => $sampleCount,
            'minSamples' => $minSamples,
        ]);
        return null;
    }

    if (($now - $latestSampleAt) > $staleAfterSeconds) {
        distressAutotuneDebugLog('bps_state_stale', [
            'now' => $now,
            'latestSampleAt' => $latestSampleAt,
            'ageSeconds' => $now - $latestSampleAt,
            'staleAfterSeconds' => $staleAfterSeconds,
        ]);
        return null;
    }

    $effectiveMovingAverageMbps = normalizeDistressBpsAgainstSpeed($movingAverageMbps, $uploadCapMbps);
    $effectiveLatestBpsMbps = isset($bpsState['latestBpsMbps']) && is_numeric($bpsState['latestBpsMbps'])
        ? (float)$bpsState['latestBpsMbps']
        : null;
    $effectiveLatestBpsMbps = normalizeDistressBpsAgainstSpeed($effectiveLatestBpsMbps, $uploadCapMbps);

    if ($uploadCapMbps !== null && $effectiveMovingAverageMbps !== null && abs($effectiveMovingAverageMbps - $movingAverageMbps) > 0.0001) {
        distressAutotuneDebugLog('bps_normalized_against_speed', [
            'rawMovingAverageMbps' => $movingAverageMbps,
            'speedMbps' => $uploadCapMbps,
            'effectiveMovingAverageMbps' => $effectiveMovingAverageMbps,
            'speedToleranceRatio' => DISTRESS_AUTOTUNE_SPEED_TOLERANCE_RATIO,
        ]);
    }

    return [
        'movingAverageMbps' => $effectiveMovingAverageMbps,
        'latestBpsMbps' => $effectiveLatestBpsMbps,
        'latestSampleAt' => $latestSampleAt,
        'latestTargetCount' => isset($bpsState['latestTargetCount']) && is_numeric($bpsState['latestTargetCount'])
            ? max(0, (int)$bpsState['latestTargetCount'])
            : null,
        'sampleCount' => $sampleCount,
        'staleAfterSeconds' => $staleAfterSeconds,
        'minSamples' => $minSamples,
        'cycleId' => isset($bpsState['cycleId']) && is_string($bpsState['cycleId']) ? $bpsState['cycleId'] : null,
        'cycleStartedAt' => isset($bpsState['cycleStartedAt']) && is_numeric($bpsState['cycleStartedAt'])
            ? (int)$bpsState['cycleStartedAt']
            : null,
        'startedConcurrency' => isset($bpsState['startedConcurrency']) && is_numeric($bpsState['startedConcurrency'])
            ? max(DISTRESS_AUTOTUNE_MIN_CONCURRENCY, (int)$bpsState['startedConcurrency'])
            : null,
        'runStartedAt' => isset($bpsState['runStartedAt']) && is_numeric($bpsState['runStartedAt'])
            ? (int)$bpsState['runStartedAt']
            : null,
        'runEndedAt' => isset($bpsState['runEndedAt']) && is_numeric($bpsState['runEndedAt'])
            ? (int)$bpsState['runEndedAt']
            : null,
        'scoreMethod' => isset($bpsState['scoreMethod']) && is_string($bpsState['scoreMethod']) && $bpsState['scoreMethod'] !== ''
            ? $bpsState['scoreMethod']
            : null,
        'uploadCapMbps' => $uploadCapMbps,
    ];
}

function syncDistressBpsCycleState(array &$state, ?array $bpsMetrics): array
{
    $currentCycleId = isset($bpsMetrics['cycleId']) && is_string($bpsMetrics['cycleId']) && $bpsMetrics['cycleId'] !== ''
        ? $bpsMetrics['cycleId']
        : null;
    $latestTargetCount = isset($bpsMetrics['latestTargetCount']) && is_numeric($bpsMetrics['latestTargetCount'])
        ? max(0, (int)$bpsMetrics['latestTargetCount'])
        : null;
    $previousTargetCount = isset($state['lastTargetCount']) && is_numeric($state['lastTargetCount'])
        ? max(0, (int)$state['lastTargetCount'])
        : null;
    $previousCycleId = isset($state['lastBpsCycleId']) && is_string($state['lastBpsCycleId']) && $state['lastBpsCycleId'] !== ''
        ? $state['lastBpsCycleId']
        : null;

    if (
        ($currentCycleId !== null && $previousCycleId !== null && $currentCycleId !== $previousCycleId) ||
        ($currentCycleId === null && $latestTargetCount !== null && $previousTargetCount !== null && $latestTargetCount !== $previousTargetCount)
    ) {
        distressAutotuneDebugLog('bps_cycle_reset', [
            'previousCycleId' => $previousCycleId,
            'currentCycleId' => $currentCycleId,
            'previousTargetCount' => $previousTargetCount,
            'latestTargetCount' => $latestTargetCount,
        ]);
        resetDistressAutotuneBpsCycle($state);
        refreshDistressUploadCap($state);
    }

    if ($latestTargetCount !== null) {
        $state['lastTargetCount'] = $latestTargetCount;
    }
    if ($currentCycleId !== null) {
        $state['lastBpsCycleId'] = $currentCycleId;
    }

    return [
        'currentCycleId' => $currentCycleId,
        'latestTargetCount' => $latestTargetCount,
    ];
}

function updateDistressBestProbeState(array &$state, int $currentConcurrency, float $probeScoreMbps): array
{
    $bestBpsMbps = isset($state['bestBpsMbps']) && is_numeric($state['bestBpsMbps']) ? (float)$state['bestBpsMbps'] : null;
    $bestBpsConcurrency = normalizeDistressConcurrency($state['bestBpsConcurrency'] ?? null);
    $confirmedBestBpsMbps = isset($state['confirmedBestBpsMbps']) && is_numeric($state['confirmedBestBpsMbps'])
        ? (float)$state['confirmedBestBpsMbps']
        : null;
    $confirmedBestBpsConcurrency = normalizeDistressConcurrency($state['confirmedBestBpsConcurrency'] ?? null);

    if (
        $bestBpsMbps === null ||
        $bestBpsMbps <= 0.0 ||
        $bestBpsConcurrency === null ||
        $probeScoreMbps >= ($bestBpsMbps * DISTRESS_AUTOTUNE_BPS_BEST_IMPROVEMENT_RATIO)
    ) {
        $state['bestBpsMbps'] = $probeScoreMbps;
        $state['bestBpsConcurrency'] = $currentConcurrency;
        $bestBpsMbps = $probeScoreMbps;
        $bestBpsConcurrency = $currentConcurrency;
        distressAutotuneDebugLog('bps_new_best', [
            'bestBpsMbps' => $state['bestBpsMbps'],
            'bestBpsConcurrency' => $state['bestBpsConcurrency'],
            'searchPhase' => $state['searchPhase'] ?? DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
        ]);
    }

    $confirmedProbeScoreMbps = calculateDistressProbeScore($state, DISTRESS_AUTOTUNE_CONFIRMED_BEST_WINDOWS_REQUIRED);
    if (
        $confirmedProbeScoreMbps !== null &&
        $currentConcurrency === $bestBpsConcurrency &&
        (
            $confirmedBestBpsMbps === null ||
            $confirmedBestBpsConcurrency === null ||
            $currentConcurrency === $confirmedBestBpsConcurrency ||
            $confirmedProbeScoreMbps >= ($confirmedBestBpsMbps * DISTRESS_AUTOTUNE_BPS_BEST_IMPROVEMENT_RATIO)
        )
    ) {
        $state['confirmedBestBpsMbps'] = $confirmedProbeScoreMbps;
        $state['confirmedBestBpsConcurrency'] = $currentConcurrency;
        $confirmedBestBpsMbps = $confirmedProbeScoreMbps;
        $confirmedBestBpsConcurrency = $currentConcurrency;
        distressAutotuneDebugLog('bps_confirmed_best', [
            'confirmedBestBpsMbps' => $confirmedBestBpsMbps,
            'confirmedBestBpsConcurrency' => $confirmedBestBpsConcurrency,
            'windowsRequired' => DISTRESS_AUTOTUNE_CONFIRMED_BEST_WINDOWS_REQUIRED,
        ]);
    }

    return [
        'bestBpsMbps' => $bestBpsMbps,
        'bestBpsConcurrency' => $bestBpsConcurrency,
        'confirmedBestBpsMbps' => $confirmedBestBpsMbps,
        'confirmedBestBpsConcurrency' => $confirmedBestBpsConcurrency,
        'decisionBestBpsMbps' => $confirmedBestBpsMbps ?? $bestBpsMbps,
        'decisionBestBpsConcurrency' => $confirmedBestBpsConcurrency ?? $bestBpsConcurrency,
    ];
}

function determineDistressCoarseTarget(
    array &$state,
    int $currentConcurrency,
    int $explorationTargetConcurrency,
    ?float $decisionBestBpsMbps,
    ?int $decisionBestBpsConcurrency,
    ?float $probeScoreMbps,
    ?float $dropProbeScoreMbps,
    bool $dropWindowReady
): int {
    if (
        $decisionBestBpsMbps !== null &&
        $decisionBestBpsConcurrency !== null &&
        $currentConcurrency > $decisionBestBpsConcurrency &&
        $dropWindowReady &&
        !isWithinDistressBpsDeadZone((float)$dropProbeScoreMbps, $decisionBestBpsMbps) &&
        (float)$dropProbeScoreMbps < $decisionBestBpsMbps
    ) {
        $state['searchPhase'] = DISTRESS_AUTOTUNE_SEARCH_PHASE_REFINE;
        $state['refineLowConcurrency'] = $decisionBestBpsConcurrency;
        $state['refineHighConcurrency'] = $currentConcurrency;
        $midpoint = calculateDistressRefineMidpoint($decisionBestBpsConcurrency, $currentConcurrency);
        if ($midpoint === null) {
            $state['searchPhase'] = DISTRESS_AUTOTUNE_SEARCH_PHASE_HOLD;
            return $decisionBestBpsConcurrency;
        }
        return $midpoint;
    }

    if (
        $decisionBestBpsMbps !== null &&
        $decisionBestBpsConcurrency !== null &&
        $currentConcurrency > $decisionBestBpsConcurrency &&
        !$dropWindowReady
    ) {
        return $currentConcurrency;
    }

    if ($explorationTargetConcurrency > $currentConcurrency) {
        return $explorationTargetConcurrency;
    }

    $state['searchPhase'] = DISTRESS_AUTOTUNE_SEARCH_PHASE_HOLD;
    return $decisionBestBpsConcurrency ?? $currentConcurrency;
}

function determineDistressRefineTarget(
    array &$state,
    int $currentConcurrency,
    ?int $decisionBestBpsConcurrency,
    ?float $decisionBestBpsMbps,
    float $probeScoreMbps
): int {
    $refineLowConcurrency = normalizeDistressConcurrency($state['refineLowConcurrency'] ?? null);
    $refineHighConcurrency = normalizeDistressConcurrency($state['refineHighConcurrency'] ?? null);
    if ($refineLowConcurrency === null || $refineHighConcurrency === null) {
        $state['searchPhase'] = DISTRESS_AUTOTUNE_SEARCH_PHASE_HOLD;
        return $decisionBestBpsConcurrency ?? $currentConcurrency;
    }

    if ($currentConcurrency > ($decisionBestBpsConcurrency ?? $currentConcurrency)) {
        if (isWithinDistressBpsDeadZone($probeScoreMbps, $decisionBestBpsMbps ?? $probeScoreMbps)) {
            $state['refineLowConcurrency'] = $currentConcurrency;
        } else {
            $state['refineHighConcurrency'] = $currentConcurrency;
        }
    }

    $refineLowConcurrency = normalizeDistressConcurrency($state['refineLowConcurrency'] ?? null) ?? $decisionBestBpsConcurrency ?? $currentConcurrency;
    $refineHighConcurrency = normalizeDistressConcurrency($state['refineHighConcurrency'] ?? null) ?? $currentConcurrency;
    $midpoint = calculateDistressRefineMidpoint($refineLowConcurrency, $refineHighConcurrency);
    if ($midpoint === null) {
        $state['searchPhase'] = DISTRESS_AUTOTUNE_SEARCH_PHASE_HOLD;
        return $decisionBestBpsConcurrency ?? $currentConcurrency;
    }

    return $midpoint;
}

function determineDistressSearchTarget(
    array &$state,
    int $currentConcurrency,
    int $explorationTargetConcurrency,
    ?float $decisionBestBpsMbps,
    ?int $decisionBestBpsConcurrency,
    float $probeScoreMbps,
    ?float $dropProbeScoreMbps,
    bool $dropWindowReady
): int {
    $searchPhase = normalizeDistressSearchPhase($state['searchPhase'] ?? null);

    return match ($searchPhase) {
        DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE => determineDistressCoarseTarget(
            $state,
            $currentConcurrency,
            $explorationTargetConcurrency,
            $decisionBestBpsMbps,
            $decisionBestBpsConcurrency,
            $probeScoreMbps,
            $dropProbeScoreMbps,
            $dropWindowReady
        ),
        DISTRESS_AUTOTUNE_SEARCH_PHASE_REFINE => determineDistressRefineTarget(
            $state,
            $currentConcurrency,
            $decisionBestBpsConcurrency,
            $decisionBestBpsMbps,
            $probeScoreMbps
        ),
        default => $decisionBestBpsConcurrency ?? $currentConcurrency,
    };
}

function resetDistressAutotuneBpsCycle(array &$state): void
{
    $state['lastAdjustedAt'] = 0;
    $state['lastBpsMbps'] = null;
    $state['bestBpsMbps'] = null;
    $state['bestBpsConcurrency'] = null;
    $state['confirmedBestBpsMbps'] = null;
    $state['confirmedBestBpsConcurrency'] = null;
    $state['uploadCapMbps'] = null;
    $state['uploadCapMeasuredAt'] = null;
    $state['lastBpsCycleId'] = null;
    $state['bpsSettleCyclesRemaining'] = 0;
    resetDistressProbeState($state, DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE);
}

function shouldSkipDistressBpsEvaluationForSettle(array &$state): bool
{
    $remaining = isset($state['bpsSettleCyclesRemaining']) && is_numeric($state['bpsSettleCyclesRemaining'])
        ? max(0, (int)$state['bpsSettleCyclesRemaining'])
        : 0;
    if ($remaining <= 0) {
        return false;
    }

    $state['bpsSettleCyclesRemaining'] = $remaining - 1;
    distressAutotuneDebugLog('bps_settle_skip', [
        'remainingBefore' => $remaining,
        'remainingAfter' => $state['bpsSettleCyclesRemaining'],
    ]);
    return true;
}

function distressAutotuneSafetyTick($loadAverage, $ramFreePercent): array
{
    if (!is_numeric($loadAverage)) {
        return distressAutotuneError('invalid_load_average');
    }
    if (!is_numeric($ramFreePercent)) {
        return distressAutotuneError('invalid_ram_free_percent');
    }

    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        return distressAutotuneError('distress_autotune_lock_failed');
    }

    $load = max(0.0, (float)$loadAverage);
    $freeRam = max(0.0, min(100.0, (float)$ramFreePercent));
    distressAutotuneDebugLog('safety_tick_start', [
        'loadAverage' => $load,
        'ramFreePercent' => $freeRam,
    ]);

    $state = readDistressAutotuneState();
    $state['lastLoadAverage'] = $load;
    $state['lastRamFreePercent'] = $freeRam;

    if (($state['enabled'] ?? false) !== true) {
        distressAutotuneDebugLog('safety_tick_skip_manual_mode');
        return persistDistressAutotuneStateAndReturnStatus($state, $lockHandle, false, 'manual_mode');
    }

    if (!serviceIsActive('distress')) {
        distressAutotuneDebugLog('safety_tick_skip_service_inactive');
        return persistDistressAutotuneStateAndReturnStatus($state, $lockHandle, false, 'distress_inactive');
    }

    $configConcurrency = getDistressConfigConcurrency();
    $liveAppliedConcurrency = getDistressLiveAppliedConcurrency();
    $currentConcurrency = $liveAppliedConcurrency ?? $configConcurrency;
    $state['desiredConcurrency'] = $configConcurrency;

    $targetConcurrency = calculateDistressSafetyTargetConcurrency($currentConcurrency, $load, $freeRam);
    if ($targetConcurrency >= $currentConcurrency) {
        distressAutotuneDebugLog('safety_tick_hold', [
            'currentConcurrency' => $currentConcurrency,
            'targetConcurrency' => $targetConcurrency,
            'loadAverage' => $load,
            'ramFreePercent' => $freeRam,
        ]);
        return persistDistressAutotuneStateAndReturnStatus($state, $lockHandle, false, 'within_safe_range');
    }

    if (!setDistressConfigConcurrency($targetConcurrency)) {
        distressAutotuneDebugLog('safety_tick_write_failed', [
            'currentConcurrency' => $currentConcurrency,
            'targetConcurrency' => $targetConcurrency,
            'loadAverage' => $load,
            'ramFreePercent' => $freeRam,
        ]);
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('distress_concurrency_write_failed');
    }

    $restart = restartDistressForAutotune($state);
    if (($restart['ok'] ?? false) !== true) {
        $rollbackConfigOk = setDistressConfigConcurrency($configConcurrency);
        distressAutotuneDebugLog('safety_tick_restart_failed', [
            'currentConcurrency' => $currentConcurrency,
            'targetConcurrency' => $targetConcurrency,
            'rollbackConfigOk' => $rollbackConfigOk,
        ]);
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('service_restart_failed');
    }

    $state['desiredConcurrency'] = $targetConcurrency;
    $state['lastAdjustedAt'] = time();
    $state['lastBpsMbps'] = null;
    $state['bpsSettleCyclesRemaining'] = DISTRESS_AUTOTUNE_BPS_SETTLE_CYCLES;
    resetDistressProbeState($state, DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE);
    if (!writeDistressAutotuneState($state)) {
        distressAutotuneDebugLog('safety_tick_state_write_failed', [
            'targetConcurrency' => $targetConcurrency,
        ]);
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('distress_autotune_state_write_failed');
    }

    distressAutotuneDebugLog('safety_tick_changed', [
        'previousConcurrency' => $currentConcurrency,
        'targetConcurrency' => $targetConcurrency,
        'loadAverage' => $load,
        'ramFreePercent' => $freeRam,
    ]);

    releaseDistressAutotuneLock($lockHandle);
    return getDistressAutotuneStatus() + ['changed' => true, 'reason' => 'safety_reduce'];
}

function restartDistressForAutotune(array &$state): array
{
    $expectedExecStart = readServiceExecStart('distress');
    $expectedConcurrency = getExecStartOptionValue((string)$expectedExecStart, 'concurrency');

    runCommand('systemctl daemon-reload', $reloadCode);
    if ($reloadCode !== 0) {
        return distressAutotuneError('daemon_reload_failed');
    }

    $service = escapeshellarg('distress.service');
    $previousPid = getServiceMainPid('distress');
    runCommand("systemctl stop $service", $stopCode);
    if ($stopCode !== 0 || !waitForServiceInactive('distress')) {
        return distressAutotuneError('service_stop_failed');
    }

    runCommand("systemctl start $service", $startCode);
    if ($startCode !== 0) {
        return distressAutotuneError('service_start_failed');
    }

    if (!waitForServiceActive('distress')) {
        return distressAutotuneError('service_restart_activation_failed');
    }

    $currentPid = getServiceMainPid('distress');
    if ($previousPid !== null && $currentPid !== null && $currentPid === $previousPid) {
        return [
            'ok' => false,
            'error' => 'service_restart_pid_unchanged',
            'previousPid' => $previousPid,
            'currentPid' => $currentPid,
        ];
    }

    if (is_string($expectedConcurrency) && $expectedConcurrency !== '') {
        if (!verifyServiceExecStartOptionValue('distress', 'concurrency', $expectedConcurrency)) {
            return [
                'ok' => false,
                'error' => 'service_restart_concurrency_mismatch',
                'expectedConcurrency' => $expectedConcurrency,
                'currentPid' => $currentPid,
            ];
        }
    }

    return [
        'ok' => true,
        'restarted' => true,
        'previousPid' => $previousPid,
        'currentPid' => $currentPid,
    ];
}

function distressAutotuneTick($loadAverage, $ramFreePercent): array
{
    if (!is_numeric($loadAverage)) {
        return distressAutotuneError('invalid_load_average');
    }
    if (!is_numeric($ramFreePercent)) {
        return distressAutotuneError('invalid_ram_free_percent');
    }

    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        return distressAutotuneError('distress_autotune_lock_failed');
    }

    $load = max(0.0, (float)$loadAverage);
    $freeRam = max(0.0, min(100.0, (float)$ramFreePercent));
    distressAutotuneDebugLog('tick_start', [
        'loadAverage' => $load,
        'ramFreePercent' => $freeRam,
    ]);
    $state = readDistressAutotuneState();
    $state['lastLoadAverage'] = $load;
    $state['lastRamFreePercent'] = $freeRam;
    if (($state['enabled'] ?? false) !== true) {
        distressAutotuneDebugLog('tick_skip_manual_mode');
        return persistDistressAutotuneStateAndReturnStatus($state, $lockHandle, false, 'manual_mode');
    }

    if (!serviceIsActive('distress')) {
        distressAutotuneDebugLog('tick_skip_service_inactive');
        return persistDistressAutotuneStateAndReturnStatus($state, $lockHandle, false, 'distress_inactive');
    }

    $configConcurrency = getDistressConfigConcurrency();
    $liveAppliedConcurrency = getDistressLiveAppliedConcurrency();
    $currentConcurrency = $liveAppliedConcurrency ?? $configConcurrency;
    $state['desiredConcurrency'] = $configConcurrency;
    $now = time();
    $bpsMetrics = getDistressFreshBpsMetrics($now);
    $cycleState = syncDistressBpsCycleState($state, $bpsMetrics);
    $currentCycleId = $cycleState['currentCycleId'] ?? null;
    $latestTargetCount = $cycleState['latestTargetCount'] ?? null;
    $safetyTargetConcurrency = calculateDistressSafetyTargetConcurrency($currentConcurrency, $load, $freeRam);
    $explorationTargetConcurrency = $currentConcurrency;
    if ($safetyTargetConcurrency >= $currentConcurrency) {
        $explorationTargetConcurrency = calculateDistressExplorationTargetConcurrency($currentConcurrency, $load, $freeRam);
    }

    if ($safetyTargetConcurrency < $currentConcurrency) {
        distressAutotuneDebugLog('tick_safety_reduce', [
            'currentConcurrency' => $currentConcurrency,
            'safetyTargetConcurrency' => $safetyTargetConcurrency,
            'loadAverage' => $load,
            'ramFreePercent' => $freeRam,
        ]);
        resetDistressProbeState($state, DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE);
        $targetConcurrency = $safetyTargetConcurrency;
    } else {
        $skipBpsEvaluation = shouldSkipDistressBpsEvaluationForSettle($state);
        $evaluatedBpsMbps = null;
        $probeScoreMbps = null;

        if ($skipBpsEvaluation) {
            $state['lastBpsMbps'] = null;
            distressAutotuneDebugLog('tick_hold_settle', [
                'currentConcurrency' => $currentConcurrency,
                'searchPhase' => $state['searchPhase'] ?? DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
            ]);
            return persistDistressAutotuneStateAndReturnStatus($state, $lockHandle, false, 'bps_settle');
        }

        if (($now - (int)($state['lastAdjustedAt'] ?? 0)) < DISTRESS_AUTOTUNE_COOLDOWN_SECONDS) {
            distressAutotuneDebugLog('tick_skip_cooldown', [
                'lastAdjustedAt' => (int)($state['lastAdjustedAt'] ?? 0),
                'cooldownSeconds' => DISTRESS_AUTOTUNE_COOLDOWN_SECONDS,
                'cooldownRemaining' => DISTRESS_AUTOTUNE_COOLDOWN_SECONDS - ($now - (int)($state['lastAdjustedAt'] ?? 0)),
                'currentConcurrency' => $currentConcurrency,
                'bestBpsMbps' => $state['bestBpsMbps'] ?? null,
            ]);
            return persistDistressAutotuneStateAndReturnStatus($state, $lockHandle, false, 'cooldown');
        }

        if ($bpsMetrics === null) {
            $state['lastBpsMbps'] = null;
            distressAutotuneDebugLog('tick_hold_no_bps', [
                'currentConcurrency' => $currentConcurrency,
                'searchPhase' => $state['searchPhase'] ?? DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
            ]);
            return persistDistressAutotuneStateAndReturnStatus($state, $lockHandle, false, 'bps_unavailable');
        }

        $bpsConcurrency = isset($bpsMetrics['startedConcurrency']) && is_numeric($bpsMetrics['startedConcurrency'])
            ? max(DISTRESS_AUTOTUNE_MIN_CONCURRENCY, (int)$bpsMetrics['startedConcurrency'])
            : null;
        if ($bpsConcurrency !== null && $bpsConcurrency !== $currentConcurrency) {
            $state['lastBpsMbps'] = null;
            distressAutotuneDebugLog('tick_hold_bps_concurrency_mismatch', [
                'currentConcurrency' => $currentConcurrency,
                'bpsConcurrency' => $bpsConcurrency,
                'runStartedAt' => $bpsMetrics['runStartedAt'] ?? null,
                'runEndedAt' => $bpsMetrics['runEndedAt'] ?? null,
                'scoreMethod' => $bpsMetrics['scoreMethod'] ?? null,
            ]);
            return persistDistressAutotuneStateAndReturnStatus($state, $lockHandle, false, 'bps_concurrency_mismatch');
        }

        ensureDistressProbeForCurrentConcurrency($state, $currentConcurrency);
        $evaluatedBpsMbps = (float)$bpsMetrics['movingAverageMbps'];
        $state['lastBpsMbps'] = $evaluatedBpsMbps;
        $probeRunEndedAt = isset($bpsMetrics['runEndedAt']) && is_numeric($bpsMetrics['runEndedAt'])
            ? (int)$bpsMetrics['runEndedAt']
            : (int)($bpsMetrics['latestSampleAt'] ?? 0);
        $windowAdded = appendDistressProbeWindow($state, $evaluatedBpsMbps, $probeRunEndedAt);
        $probeWindowCount = getDistressProbeWindowCount($state);
        $probeScoreMbps = calculateDistressProbeScore($state);
        $dropProbeScoreMbps = calculateDistressProbeScore($state, DISTRESS_AUTOTUNE_DROP_WINDOWS_REQUIRED);
        $dropWindowReady = $dropProbeScoreMbps !== null;

        distressAutotuneDebugLog('probe_window', [
            'currentConcurrency' => $currentConcurrency,
            'windowAdded' => $windowAdded,
            'probeWindowCount' => $probeWindowCount,
            'windowsRequired' => DISTRESS_AUTOTUNE_PROBE_WINDOWS_REQUIRED,
            'dropWindowsRequired' => DISTRESS_AUTOTUNE_DROP_WINDOWS_REQUIRED,
            'evaluatedBpsMbps' => $evaluatedBpsMbps,
            'uploadCapMbps' => $bpsMetrics['uploadCapMbps'] ?? null,
            'probeScoreMbps' => $probeScoreMbps,
            'dropProbeScoreMbps' => $dropProbeScoreMbps,
            'dropWindowReady' => $dropWindowReady,
            'runEndedAt' => $bpsMetrics['runEndedAt'] ?? null,
            'searchPhase' => $state['searchPhase'] ?? DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
        ]);

        if ($probeScoreMbps === null) {
            distressAutotuneDebugLog('tick_hold_probe_windows', [
                'currentConcurrency' => $currentConcurrency,
                'probeWindowCount' => $probeWindowCount,
                'windowsRequired' => DISTRESS_AUTOTUNE_PROBE_WINDOWS_REQUIRED,
            ]);
            return persistDistressAutotuneStateAndReturnStatus($state, $lockHandle, false, 'collecting_probe_windows');
        }

        $decisionMetrics = updateDistressBestProbeState($state, $currentConcurrency, $probeScoreMbps);
        $decisionBestBpsMbps = $decisionMetrics['decisionBestBpsMbps'] ?? null;
        $decisionBestBpsConcurrency = $decisionMetrics['decisionBestBpsConcurrency'] ?? null;
        $targetConcurrency = determineDistressSearchTarget(
            $state,
            $currentConcurrency,
            $explorationTargetConcurrency,
            $decisionBestBpsMbps,
            $decisionBestBpsConcurrency,
            $probeScoreMbps,
            $dropProbeScoreMbps,
            $dropWindowReady
        );

        if (
            $decisionBestBpsMbps !== null &&
            $decisionBestBpsConcurrency !== null &&
            $targetConcurrency > $decisionBestBpsConcurrency &&
            isWithinDistressBpsDeadZone($probeScoreMbps, $decisionBestBpsMbps)
        ) {
            $targetConcurrency = $currentConcurrency;
        }

        $targetConcurrency = min($targetConcurrency, $explorationTargetConcurrency);
        $targetConcurrency = max(DISTRESS_AUTOTUNE_MIN_CONCURRENCY, min(DISTRESS_AUTOTUNE_MAX_CONCURRENCY, $targetConcurrency));

        distressAutotuneDebugLog('tick_decision', [
            'currentConcurrency' => $currentConcurrency,
            'configConcurrency' => $configConcurrency,
            'liveAppliedConcurrency' => $liveAppliedConcurrency,
            'safetyTargetConcurrency' => $safetyTargetConcurrency,
            'explorationTargetConcurrency' => $explorationTargetConcurrency,
            'finalTargetConcurrency' => $targetConcurrency,
            'loadAverage' => $load,
            'ramFreePercent' => $freeRam,
            'evaluatedBpsMbps' => $evaluatedBpsMbps,
            'probeScoreMbps' => $probeScoreMbps,
            'bestBpsMbps' => $state['bestBpsMbps'] ?? null,
            'bestBpsConcurrency' => $state['bestBpsConcurrency'] ?? null,
            'confirmedBestBpsMbps' => $state['confirmedBestBpsMbps'] ?? null,
            'confirmedBestBpsConcurrency' => $state['confirmedBestBpsConcurrency'] ?? null,
            'searchPhase' => $state['searchPhase'] ?? DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
            'probeWindowCount' => getDistressProbeWindowCount($state),
            'currentCycleId' => $currentCycleId,
            'latestTargetCount' => $latestTargetCount,
        ]);
    }

    if ($targetConcurrency === $currentConcurrency) {
        distressAutotuneDebugLog('tick_skip_within_range', [
            'currentConcurrency' => $currentConcurrency,
            'evaluatedBpsMbps' => $state['lastBpsMbps'] ?? null,
            'bestBpsMbps' => $state['bestBpsMbps'] ?? null,
            'searchPhase' => $state['searchPhase'] ?? DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
        ]);
        return persistDistressAutotuneStateAndReturnStatus($state, $lockHandle, false, 'within_range');
    }

    if (!setDistressConfigConcurrency($targetConcurrency)) {
        distressAutotuneDebugLog('tick_write_failed', [
            'currentConcurrency' => $currentConcurrency,
            'targetConcurrency' => $targetConcurrency,
        ]);
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('distress_concurrency_write_failed');
    }

    $restart = restartDistressForAutotune($state);
    if (($restart['ok'] ?? false) !== true) {
        $serviceActiveAfterFailure = serviceIsActive('distress');
        $liveAppliedAfterFailure = getDistressLiveAppliedConcurrency();
        $configConcurrencyAfterFailure = getDistressConfigConcurrency();
        $rollbackConfigOk = setDistressConfigConcurrency($configConcurrency);
        $rollbackStateOk = false;
        if ($rollbackConfigOk) {
            $state['desiredConcurrency'] = $configConcurrency;
            $state['bpsSettleCyclesRemaining'] = 0;
            $rollbackStateOk = writeDistressAutotuneState($state);
        }
        distressAutotuneDebugLog('tick_restart_failed', [
            'currentConcurrency' => $currentConcurrency,
            'targetConcurrency' => $targetConcurrency,
            'rollbackConfigOk' => $rollbackConfigOk,
            'rollbackStateOk' => $rollbackStateOk,
            'configConcurrencyAfterFailure' => $configConcurrencyAfterFailure,
            'liveAppliedConcurrencyAfterFailure' => $liveAppliedAfterFailure,
            'serviceActiveAfterFailure' => $serviceActiveAfterFailure,
        ]);
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('service_restart_failed', [
            'attemptedConcurrency' => $targetConcurrency,
            'previousConfigConcurrency' => $configConcurrency,
            'configConcurrencyAfterFailure' => $configConcurrencyAfterFailure,
            'liveAppliedConcurrencyAfterFailure' => $liveAppliedAfterFailure,
            'serviceActiveAfterFailure' => $serviceActiveAfterFailure,
            'rollbackConfigOk' => $rollbackConfigOk,
            'rollbackStateOk' => $rollbackStateOk,
            'configConcurrencyAfterRollback' => getDistressConfigConcurrency(),
        ]);
    }

    $state['desiredConcurrency'] = $targetConcurrency;
    $state['lastAdjustedAt'] = $now;
    $state['bpsSettleCyclesRemaining'] = DISTRESS_AUTOTUNE_BPS_SETTLE_CYCLES;
    $state['lastBpsMbps'] = null;
    $state['probeConcurrency'] = $targetConcurrency;
    $state['probeWindowBps'] = [];
    $state['lastProbeSampleAt'] = null;
    if (!writeDistressAutotuneState($state)) {
        distressAutotuneDebugLog('tick_state_write_failed', [
            'targetConcurrency' => $targetConcurrency,
        ]);
        releaseDistressAutotuneLock($lockHandle);
        return distressAutotuneError('distress_autotune_state_write_failed');
    }

    distressAutotuneDebugLog('tick_changed', [
        'previousConcurrency' => $currentConcurrency,
        'targetConcurrency' => $targetConcurrency,
        'loadAverage' => $load,
        'ramFreePercent' => $freeRam,
        'bestBpsMbps' => $state['bestBpsMbps'] ?? null,
        'bestBpsConcurrency' => $state['bestBpsConcurrency'] ?? null,
        'settleCyclesRemaining' => $state['bpsSettleCyclesRemaining'],
        'searchPhase' => $state['searchPhase'] ?? DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
    ]);
    releaseDistressAutotuneLock($lockHandle);
    return getDistressAutotuneStatus() + [
        'changed' => true,
        'previousConcurrency' => $currentConcurrency,
        'loadAverage' => $load,
        'ramFreePercent' => $freeRam,
    ];
}
