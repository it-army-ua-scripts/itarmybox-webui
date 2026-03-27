<?php

declare(strict_types=1);

const DISTRESS_AUTOTUNE_TIMER_NAME = 'itarmybox-distress-autotune.timer';
const DISTRESS_AUTOTUNE_STATE_FILE = '/opt/itarmy/distress-autotune.json';
const DISTRESS_AUTOTUNE_LOCK_FILE = '/tmp/itarmybox-distress-autotune.lock';
const DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY = 2048;
const DISTRESS_AUTOTUNE_MANUAL_DEFAULT_CONCURRENCY = 4096;
const DISTRESS_AUTOTUNE_MIN_CONCURRENCY = 64;
const DISTRESS_AUTOTUNE_MAX_CONCURRENCY = 30720;
const DISTRESS_AUTOTUNE_BIG_CORE_COUNT = 4;
const DISTRESS_AUTOTUNE_BIG_CORE_WEIGHT = 1.0;
const DISTRESS_AUTOTUNE_LITTLE_CORE_COUNT = 2;
const DISTRESS_AUTOTUNE_LITTLE_CORE_WEIGHT = 0.6;
const DISTRESS_AUTOTUNE_SYSTEM_CPU_RESERVE = 1.0;
const DISTRESS_AUTOTUNE_CPU_TARGET_UTILIZATION = 0.85;
const DISTRESS_AUTOTUNE_TARGET_LOAD_MIN = 1.0;
const DISTRESS_AUTOTUNE_DEAD_ZONE_HALF_WIDTH = 0.4;
const DISTRESS_AUTOTUNE_MIN_FREE_RAM_PERCENT = 10.0;
const DISTRESS_AUTOTUNE_RAM_RECOVERY_PERCENT = 15.0;
const DISTRESS_AUTOTUNE_COOLDOWN_SECONDS = 300;

function ensureDistressAutotuneTimerInstalled(): bool
{
    if (!repairRootHelperAccess()) {
        return isSystemdUnitKnown(DISTRESS_AUTOTUNE_TIMER_NAME);
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
    $effectiveCpuCapacity =
        (DISTRESS_AUTOTUNE_BIG_CORE_COUNT * DISTRESS_AUTOTUNE_BIG_CORE_WEIGHT) +
        (DISTRESS_AUTOTUNE_LITTLE_CORE_COUNT * DISTRESS_AUTOTUNE_LITTLE_CORE_WEIGHT);
    $usableLoad = max(
        DISTRESS_AUTOTUNE_TARGET_LOAD_MIN,
        $effectiveCpuCapacity - DISTRESS_AUTOTUNE_SYSTEM_CPU_RESERVE
    );

    return max(
        DISTRESS_AUTOTUNE_TARGET_LOAD_MIN,
        $usableLoad * DISTRESS_AUTOTUNE_CPU_TARGET_UTILIZATION
    );
}

function getDistressAutotuneDeadZoneLower(float $targetLoad): float
{
    return max(0.7, $targetLoad - DISTRESS_AUTOTUNE_DEAD_ZONE_HALF_WIDTH);
}

function getDistressAutotuneDeadZoneUpper(float $targetLoad): float
{
    return $targetLoad + DISTRESS_AUTOTUNE_DEAD_ZONE_HALF_WIDTH;
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
    $defaults = [
        'enabled' => true,
        'desiredConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
        'lastAdjustedAt' => 0,
        'lastLoadAverage' => null,
        'lastRamFreePercent' => null,
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
    $lastLoadAverage = isset($data['lastLoadAverage']) && is_numeric($data['lastLoadAverage'])
        ? (float)$data['lastLoadAverage']
        : null;
    $lastRamFreePercent = isset($data['lastRamFreePercent']) && is_numeric($data['lastRamFreePercent'])
        ? (float)$data['lastRamFreePercent']
        : null;

    return [
        'enabled' => ($data['enabled'] ?? true) === true,
        'desiredConcurrency' => $desiredConcurrency,
        'lastAdjustedAt' => $lastAdjustedAt,
        'lastLoadAverage' => $lastLoadAverage,
        'lastRamFreePercent' => $lastRamFreePercent,
    ];
}

function writeDistressAutotuneState(array $state): bool
{
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
    ], JSON_UNESCAPED_SLASHES);

    return is_string($payload) && @file_put_contents(DISTRESS_AUTOTUNE_STATE_FILE, $payload) !== false;
}

function acquireDistressAutotuneLock()
{
    $handle = @fopen(DISTRESS_AUTOTUNE_LOCK_FILE, 'c+');
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }
    return $handle;
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

    if (!setDistressConfigConcurrency(DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY)) {
        return false;
    }

    $state['desiredConcurrency'] = DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY;
    $state['lastAdjustedAt'] = 0;
    $state['lastLoadAverage'] = null;
    $state['lastRamFreePercent'] = null;
    return writeDistressAutotuneState($state);
}

function getDistressAutotuneStatus(): array
{
    $state = readDistressAutotuneState();
    $targetLoad = getDistressAutotuneTargetLoad();
    $desiredConcurrency = (int)($state['desiredConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY);
    $configConcurrency = getDistressConfigConcurrency();
    if ($configConcurrency !== $desiredConcurrency) {
        $state['desiredConcurrency'] = $configConcurrency;
        writeDistressAutotuneState($state);
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
    ];
}

function setDistressAutotuneMode($enabledValue, $concurrencyValue): array
{
    ensureDistressAutotuneTimerInstalled();

    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        return ['ok' => false, 'error' => 'distress_autotune_lock_failed'];
    }

    $enabled = ($enabledValue === true || $enabledValue === '1' || $enabledValue === 1 || $enabledValue === 'true');
    $concurrency = normalizeDistressConcurrency($concurrencyValue);
    if ($concurrency === null) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'invalid_concurrency'];
    }

    if (!setDistressConfigConcurrency($concurrency)) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'distress_concurrency_write_failed'];
    }

    $state = readDistressAutotuneState();
    $state['enabled'] = $enabled;
    $state['desiredConcurrency'] = $concurrency;
    $state['lastAdjustedAt'] = 0;
    $state['lastLoadAverage'] = null;
    $state['lastRamFreePercent'] = null;
    if (!writeDistressAutotuneState($state)) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'distress_autotune_state_write_failed'];
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

function calculateDistressTargetConcurrency(int $currentConcurrency, float $loadAverage, float $ramFreePercent): int
{
    $targetLoad = getDistressAutotuneTargetLoad();
    $deadZoneLower = getDistressAutotuneDeadZoneLower($targetLoad);
    $deadZoneUpper = getDistressAutotuneDeadZoneUpper($targetLoad);
    $mediumIncreaseThreshold = getDistressAutotuneMediumIncreaseThreshold($targetLoad);
    $largeIncreaseThreshold = getDistressAutotuneLargeIncreaseThreshold($targetLoad);

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
    if ($loadAverage >= $mediumIncreaseThreshold) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, 12);
    }
    if ($loadAverage >= $largeIncreaseThreshold) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, 18);
    }
    return adjustDistressConcurrencyByPercent($currentConcurrency, 24);
}

function distressAutotuneTick($loadAverage, $ramFreePercent): array
{
    if (!is_numeric($loadAverage)) {
        return ['ok' => false, 'error' => 'invalid_load_average'];
    }
    if (!is_numeric($ramFreePercent)) {
        return ['ok' => false, 'error' => 'invalid_ram_free_percent'];
    }

    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        return ['ok' => false, 'error' => 'distress_autotune_lock_failed'];
    }

    $load = max(0.0, (float)$loadAverage);
    $freeRam = max(0.0, min(100.0, (float)$ramFreePercent));
    $state = readDistressAutotuneState();
    if (($state['enabled'] ?? false) !== true) {
        releaseDistressAutotuneLock($lockHandle);
        return getDistressAutotuneStatus() + ['changed' => false, 'reason' => 'manual_mode'];
    }

    if (!serviceIsActive('distress')) {
        releaseDistressAutotuneLock($lockHandle);
        return getDistressAutotuneStatus() + ['changed' => false, 'reason' => 'distress_inactive'];
    }

    $configConcurrency = getDistressConfigConcurrency();
    $liveAppliedConcurrency = getDistressLiveAppliedConcurrency();
    $currentConcurrency = $liveAppliedConcurrency ?? $configConcurrency;
    $previousDesiredConcurrency = (int)($state['desiredConcurrency'] ?? $configConcurrency);
    $state['desiredConcurrency'] = $configConcurrency;
    $state['lastLoadAverage'] = $load;
    $state['lastRamFreePercent'] = $freeRam;
    $now = time();
    if (($now - (int)($state['lastAdjustedAt'] ?? 0)) < DISTRESS_AUTOTUNE_COOLDOWN_SECONDS) {
        writeDistressAutotuneState($state);
        releaseDistressAutotuneLock($lockHandle);
        return getDistressAutotuneStatus() + ['changed' => false, 'reason' => 'cooldown'];
    }

    $targetConcurrency = calculateDistressTargetConcurrency($currentConcurrency, $load, $freeRam);

    if ($targetConcurrency === $currentConcurrency) {
        writeDistressAutotuneState($state);
        releaseDistressAutotuneLock($lockHandle);
        return getDistressAutotuneStatus() + ['changed' => false, 'reason' => 'within_range'];
    }

    if (!setDistressConfigConcurrency($targetConcurrency)) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'distress_concurrency_write_failed'];
    }

    $restart = serviceRestart('distress');
    if (($restart['ok'] ?? false) !== true) {
        $serviceActiveAfterFailure = serviceIsActive('distress');
        $liveAppliedAfterFailure = getDistressLiveAppliedConcurrency();
        $configConcurrencyAfterFailure = getDistressConfigConcurrency();
        $rollbackConfigOk = setDistressConfigConcurrency($configConcurrency);
        $rollbackStateOk = false;
        if ($rollbackConfigOk) {
            $state['desiredConcurrency'] = $previousDesiredConcurrency;
            $rollbackStateOk = writeDistressAutotuneState($state);
        }
        releaseDistressAutotuneLock($lockHandle);
        return [
            'ok' => false,
            'error' => 'service_restart_failed',
            'attemptedConcurrency' => $targetConcurrency,
            'previousDesiredConcurrency' => $previousDesiredConcurrency,
            'previousConfigConcurrency' => $configConcurrency,
            'configConcurrencyAfterFailure' => $configConcurrencyAfterFailure,
            'liveAppliedConcurrencyAfterFailure' => $liveAppliedAfterFailure,
            'serviceActiveAfterFailure' => $serviceActiveAfterFailure,
            'rollbackConfigOk' => $rollbackConfigOk,
            'rollbackStateOk' => $rollbackStateOk,
            'configConcurrencyAfterRollback' => getDistressConfigConcurrency(),
        ];
    }

    $state['desiredConcurrency'] = $targetConcurrency;
    $state['lastAdjustedAt'] = $now;
    $state['lastLoadAverage'] = $load;
    if (!writeDistressAutotuneState($state)) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'distress_autotune_state_write_failed'];
    }

    releaseDistressAutotuneLock($lockHandle);
    return getDistressAutotuneStatus() + [
        'changed' => true,
        'previousConcurrency' => $currentConcurrency,
        'loadAverage' => $load,
        'ramFreePercent' => $freeRam,
    ];
}
