<?php

declare(strict_types=1);

const DISTRESS_AUTOTUNE_TIMER_NAME = 'itarmybox-distress-autotune.timer';
const DISTRESS_AUTOTUNE_STATE_FILE = '/opt/itarmy/distress-autotune.json';
const DISTRESS_AUTOTUNE_LOCK_FILE = '/tmp/itarmybox-distress-autotune.lock';
const DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY = 2048;
const DISTRESS_AUTOTUNE_MANUAL_DEFAULT_CONCURRENCY = 4096;
const DISTRESS_AUTOTUNE_MIN_CONCURRENCY = 64;
const DISTRESS_AUTOTUNE_MAX_CONCURRENCY = 30720;
const DISTRESS_AUTOTUNE_COOLDOWN_SECONDS = 300;
const DISTRESS_AUTOTUNE_CPU_PSI_HOLD_THRESHOLD = 8.0;
const DISTRESS_AUTOTUNE_CPU_PSI_REDUCE_THRESHOLD = 16.0;
const DISTRESS_AUTOTUNE_CPU_PSI_CRITICAL_THRESHOLD = 25.0;
const DISTRESS_AUTOTUNE_CPU_PSI_MEDIUM_INCREASE_THRESHOLD = 3.0;
const DISTRESS_AUTOTUNE_CPU_PSI_LARGE_INCREASE_THRESHOLD = 1.2;
const DISTRESS_AUTOTUNE_MEMORY_PSI_SOME_HOLD_THRESHOLD = 2.0;
const DISTRESS_AUTOTUNE_MEMORY_PSI_SOME_REDUCE_THRESHOLD = 4.0;
const DISTRESS_AUTOTUNE_MEMORY_PSI_SOME_CRITICAL_THRESHOLD = 8.0;
const DISTRESS_AUTOTUNE_MEMORY_PSI_FULL_HOLD_THRESHOLD = 0.3;
const DISTRESS_AUTOTUNE_MEMORY_PSI_FULL_REDUCE_THRESHOLD = 0.8;
const DISTRESS_AUTOTUNE_MEMORY_PSI_FULL_CRITICAL_THRESHOLD = 1.5;
const DISTRESS_AUTOTUNE_IO_PSI_SOME_HOLD_THRESHOLD = 3.0;
const DISTRESS_AUTOTUNE_IO_PSI_SOME_REDUCE_THRESHOLD = 6.0;
const DISTRESS_AUTOTUNE_IO_PSI_SOME_CRITICAL_THRESHOLD = 10.0;
const DISTRESS_AUTOTUNE_IO_PSI_FULL_HOLD_THRESHOLD = 0.3;
const DISTRESS_AUTOTUNE_IO_PSI_FULL_REDUCE_THRESHOLD = 0.8;
const DISTRESS_AUTOTUNE_IO_PSI_FULL_CRITICAL_THRESHOLD = 1.5;

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

function normalizeDistressPsiMetric($value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }

    return max(0.0, min(100.0, (float)$value));
}

function normalizeDistressPsiSnapshot($snapshot, bool $fullOptional = false): ?array
{
    if (!is_array($snapshot)) {
        return null;
    }

    $someAvg10 = normalizeDistressPsiMetric($snapshot['someAvg10'] ?? null);
    $fullAvg10 = normalizeDistressPsiMetric($snapshot['fullAvg10'] ?? null);
    if ($someAvg10 === null) {
        return null;
    }
    if (!$fullOptional && $fullAvg10 === null) {
        return null;
    }

    return [
        'someAvg10' => $someAvg10,
        'fullAvg10' => $fullAvg10,
    ];
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
        'lastCpuPsiSomeAvg10' => null,
        'lastMemoryPsiSomeAvg10' => null,
        'lastMemoryPsiFullAvg10' => null,
        'lastIoPsiSomeAvg10' => null,
        'lastIoPsiFullAvg10' => null,
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
        'lastCpuPsiSomeAvg10' => normalizeDistressPsiMetric($data['lastCpuPsiSomeAvg10'] ?? null),
        'lastMemoryPsiSomeAvg10' => normalizeDistressPsiMetric($data['lastMemoryPsiSomeAvg10'] ?? null),
        'lastMemoryPsiFullAvg10' => normalizeDistressPsiMetric($data['lastMemoryPsiFullAvg10'] ?? null),
        'lastIoPsiSomeAvg10' => normalizeDistressPsiMetric($data['lastIoPsiSomeAvg10'] ?? null),
        'lastIoPsiFullAvg10' => normalizeDistressPsiMetric($data['lastIoPsiFullAvg10'] ?? null),
    ];
}

function writeDistressAutotuneState(array $state): bool
{
    $payload = json_encode([
        'enabled' => ($state['enabled'] ?? false) === true,
        'desiredConcurrency' => (int)($state['desiredConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY),
        'lastAdjustedAt' => (int)($state['lastAdjustedAt'] ?? 0),
        'lastCpuPsiSomeAvg10' => normalizeDistressPsiMetric($state['lastCpuPsiSomeAvg10'] ?? null),
        'lastMemoryPsiSomeAvg10' => normalizeDistressPsiMetric($state['lastMemoryPsiSomeAvg10'] ?? null),
        'lastMemoryPsiFullAvg10' => normalizeDistressPsiMetric($state['lastMemoryPsiFullAvg10'] ?? null),
        'lastIoPsiSomeAvg10' => normalizeDistressPsiMetric($state['lastIoPsiSomeAvg10'] ?? null),
        'lastIoPsiFullAvg10' => normalizeDistressPsiMetric($state['lastIoPsiFullAvg10'] ?? null),
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

    $previousConfigConcurrency = getDistressConfigConcurrency();
    if (!setDistressConfigConcurrency(DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY)) {
        return false;
    }

    $state['desiredConcurrency'] = DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY;
    $state['lastAdjustedAt'] = 0;
    $state['lastCpuPsiSomeAvg10'] = null;
    $state['lastMemoryPsiSomeAvg10'] = null;
    $state['lastMemoryPsiFullAvg10'] = null;
    $state['lastIoPsiSomeAvg10'] = null;
    $state['lastIoPsiFullAvg10'] = null;
    if (writeDistressAutotuneState($state)) {
        return true;
    }

    setDistressConfigConcurrency($previousConfigConcurrency);
    return false;
}

function getDistressAutotuneStatus(): array
{
    $state = readDistressAutotuneState();
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
        'cooldownSeconds' => DISTRESS_AUTOTUNE_COOLDOWN_SECONDS,
        'cooldownRemaining' => $cooldownRemaining,
        'statusKey' => $statusKey,
        'lastAdjustedAt' => (int)($state['lastAdjustedAt'] ?? 0),
        'lastCpuPsiSomeAvg10' => $state['lastCpuPsiSomeAvg10'] ?? null,
        'lastMemoryPsiSomeAvg10' => $state['lastMemoryPsiSomeAvg10'] ?? null,
        'lastMemoryPsiFullAvg10' => $state['lastMemoryPsiFullAvg10'] ?? null,
        'lastIoPsiSomeAvg10' => $state['lastIoPsiSomeAvg10'] ?? null,
        'lastIoPsiFullAvg10' => $state['lastIoPsiFullAvg10'] ?? null,
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

    $previousConfigConcurrency = getDistressConfigConcurrency();
    $previousState = readDistressAutotuneState();
    if (!setDistressConfigConcurrency($concurrency)) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'distress_concurrency_write_failed'];
    }

    $state = $previousState;
    $state['enabled'] = $enabled;
    $state['desiredConcurrency'] = $concurrency;
    $state['lastAdjustedAt'] = 0;
    $state['lastCpuPsiSomeAvg10'] = null;
    $state['lastMemoryPsiSomeAvg10'] = null;
    $state['lastMemoryPsiFullAvg10'] = null;
    $state['lastIoPsiSomeAvg10'] = null;
    $state['lastIoPsiFullAvg10'] = null;
    if (!writeDistressAutotuneState($state)) {
        $rollbackConfigOk = setDistressConfigConcurrency($previousConfigConcurrency);
        releaseDistressAutotuneLock($lockHandle);
        return [
            'ok' => false,
            'error' => 'distress_autotune_state_write_failed',
            'rollbackConfigOk' => $rollbackConfigOk,
            'configConcurrencyAfterRollback' => getDistressConfigConcurrency(),
        ];
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

function calculateDistressTargetConcurrency(int $currentConcurrency, array $cpuPressure, array $memoryPressure, array $ioPressure): int
{
    $cpuSome = (float)$cpuPressure['someAvg10'];
    $memorySome = (float)$memoryPressure['someAvg10'];
    $memoryFull = (float)($memoryPressure['fullAvg10'] ?? 0.0);
    $ioSome = (float)$ioPressure['someAvg10'];
    $ioFull = (float)($ioPressure['fullAvg10'] ?? 0.0);

    if (
        $cpuSome >= DISTRESS_AUTOTUNE_CPU_PSI_CRITICAL_THRESHOLD
        || $memorySome >= DISTRESS_AUTOTUNE_MEMORY_PSI_SOME_CRITICAL_THRESHOLD
        || $memoryFull >= DISTRESS_AUTOTUNE_MEMORY_PSI_FULL_CRITICAL_THRESHOLD
        || $ioSome >= DISTRESS_AUTOTUNE_IO_PSI_SOME_CRITICAL_THRESHOLD
        || $ioFull >= DISTRESS_AUTOTUNE_IO_PSI_FULL_CRITICAL_THRESHOLD
    ) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, -20);
    }

    if (
        $cpuSome >= DISTRESS_AUTOTUNE_CPU_PSI_REDUCE_THRESHOLD
        || $memorySome >= DISTRESS_AUTOTUNE_MEMORY_PSI_SOME_REDUCE_THRESHOLD
        || $memoryFull >= DISTRESS_AUTOTUNE_MEMORY_PSI_FULL_REDUCE_THRESHOLD
        || $ioSome >= DISTRESS_AUTOTUNE_IO_PSI_SOME_REDUCE_THRESHOLD
        || $ioFull >= DISTRESS_AUTOTUNE_IO_PSI_FULL_REDUCE_THRESHOLD
    ) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, -10);
    }

    if (
        $cpuSome >= DISTRESS_AUTOTUNE_CPU_PSI_HOLD_THRESHOLD
        || $memorySome >= DISTRESS_AUTOTUNE_MEMORY_PSI_SOME_HOLD_THRESHOLD
        || $memoryFull >= DISTRESS_AUTOTUNE_MEMORY_PSI_FULL_HOLD_THRESHOLD
        || $ioSome >= DISTRESS_AUTOTUNE_IO_PSI_SOME_HOLD_THRESHOLD
        || $ioFull >= DISTRESS_AUTOTUNE_IO_PSI_FULL_HOLD_THRESHOLD
    ) {
        return $currentConcurrency;
    }

    if ($cpuSome >= DISTRESS_AUTOTUNE_CPU_PSI_MEDIUM_INCREASE_THRESHOLD) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, 12);
    }
    if ($cpuSome >= DISTRESS_AUTOTUNE_CPU_PSI_LARGE_INCREASE_THRESHOLD) {
        return adjustDistressConcurrencyByPercent($currentConcurrency, 18);
    }

    return adjustDistressConcurrencyByPercent($currentConcurrency, 24);
}

function distressAutotuneTick($cpuPressure, $memoryPressure, $ioPressure): array
{
    $cpuPressureNormalized = normalizeDistressPsiSnapshot($cpuPressure, true);
    if ($cpuPressureNormalized === null) {
        return ['ok' => false, 'error' => 'invalid_cpu_pressure'];
    }
    $memoryPressureNormalized = normalizeDistressPsiSnapshot($memoryPressure);
    if ($memoryPressureNormalized === null) {
        return ['ok' => false, 'error' => 'invalid_memory_pressure'];
    }
    $ioPressureNormalized = normalizeDistressPsiSnapshot($ioPressure);
    if ($ioPressureNormalized === null) {
        return ['ok' => false, 'error' => 'invalid_io_pressure'];
    }

    $lockHandle = acquireDistressAutotuneLock();
    if ($lockHandle === false) {
        return ['ok' => false, 'error' => 'distress_autotune_lock_failed'];
    }

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
    $state['lastCpuPsiSomeAvg10'] = $cpuPressureNormalized['someAvg10'];
    $state['lastMemoryPsiSomeAvg10'] = $memoryPressureNormalized['someAvg10'];
    $state['lastMemoryPsiFullAvg10'] = $memoryPressureNormalized['fullAvg10'];
    $state['lastIoPsiSomeAvg10'] = $ioPressureNormalized['someAvg10'];
    $state['lastIoPsiFullAvg10'] = $ioPressureNormalized['fullAvg10'];
    $now = time();
    if (($now - (int)($state['lastAdjustedAt'] ?? 0)) < DISTRESS_AUTOTUNE_COOLDOWN_SECONDS) {
        writeDistressAutotuneState($state);
        releaseDistressAutotuneLock($lockHandle);
        return getDistressAutotuneStatus() + ['changed' => false, 'reason' => 'cooldown'];
    }

    $targetConcurrency = calculateDistressTargetConcurrency(
        $currentConcurrency,
        $cpuPressureNormalized,
        $memoryPressureNormalized,
        $ioPressureNormalized
    );

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
    if (!writeDistressAutotuneState($state)) {
        releaseDistressAutotuneLock($lockHandle);
        return ['ok' => false, 'error' => 'distress_autotune_state_write_failed'];
    }

    releaseDistressAutotuneLock($lockHandle);
    return getDistressAutotuneStatus() + [
        'changed' => true,
        'previousConcurrency' => $currentConcurrency,
        'cpuPressure' => $cpuPressureNormalized,
        'memoryPressure' => $memoryPressureNormalized,
        'ioPressure' => $ioPressureNormalized,
    ];
}
