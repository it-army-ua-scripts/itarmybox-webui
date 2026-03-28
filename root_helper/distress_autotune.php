<?php

declare(strict_types=1);

const DISTRESS_AUTOTUNE_TIMER_NAME = 'itarmybox-distress-autotune.timer';
const DISTRESS_AUTOTUNE_STATE_FILE = ROOT_HELPER_STATE_DIR . '/distress-autotune.json';
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
const DISTRESS_AUTOTUNE_BPS_STATE_FILE = ROOT_HELPER_STATE_DIR . '/distress-bps.json';
const DISTRESS_AUTOTUNE_BPS_BEST_IMPROVEMENT_RATIO = 1.01;
const DISTRESS_AUTOTUNE_BPS_DEAD_ZONE_RATIO = 0.04;
const DISTRESS_AUTOTUNE_BPS_HOLD_RATIO = 0.985;
const DISTRESS_AUTOTUNE_BPS_DROP_RATIO = 0.95;
const DISTRESS_AUTOTUNE_BPS_DROP_RESTORE_RATIO = 0.97;
const DISTRESS_AUTOTUNE_BPS_SETTLE_CYCLES = 2;

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
        'lastTargetCount' => null,
        'lastBpsCycleId' => null,
        'bpsSettleCyclesRemaining' => 0,
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
        'lastTargetCount' => isset($data['lastTargetCount']) && is_numeric($data['lastTargetCount'])
            ? max(0, (int)$data['lastTargetCount'])
            : null,
        'lastBpsCycleId' => isset($data['lastBpsCycleId']) && is_string($data['lastBpsCycleId']) && $data['lastBpsCycleId'] !== ''
            ? $data['lastBpsCycleId']
            : null,
        'bpsSettleCyclesRemaining' => isset($data['bpsSettleCyclesRemaining']) && is_numeric($data['bpsSettleCyclesRemaining'])
            ? max(0, (int)$data['bpsSettleCyclesRemaining'])
            : 0,
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
        'lastTargetCount' => isset($state['lastTargetCount']) && is_numeric($state['lastTargetCount'])
            ? max(0, (int)$state['lastTargetCount'])
            : null,
        'lastBpsCycleId' => isset($state['lastBpsCycleId']) && is_string($state['lastBpsCycleId']) && $state['lastBpsCycleId'] !== ''
            ? $state['lastBpsCycleId']
            : null,
        'bpsSettleCyclesRemaining' => isset($state['bpsSettleCyclesRemaining']) && is_numeric($state['bpsSettleCyclesRemaining'])
            ? max(0, (int)$state['bpsSettleCyclesRemaining'])
            : 0,
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
    $state['lastLoadAverage'] = null;
    $state['lastRamFreePercent'] = null;
    $state['lastBpsMbps'] = null;
    $state['bestBpsMbps'] = null;
    $state['bestBpsConcurrency'] = null;
    $state['lastTargetCount'] = null;
    $state['lastBpsCycleId'] = null;
    $state['bpsSettleCyclesRemaining'] = 0;
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
        'lastBpsMbps' => $state['lastBpsMbps'] ?? null,
        'bestBpsMbps' => $state['bestBpsMbps'] ?? null,
        'bestBpsConcurrency' => $state['bestBpsConcurrency'] ?? null,
        'lastTargetCount' => $state['lastTargetCount'] ?? null,
        'lastBpsCycleId' => $state['lastBpsCycleId'] ?? null,
        'bpsSettleCyclesRemaining' => (int)($state['bpsSettleCyclesRemaining'] ?? 0),
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
    $state['lastLoadAverage'] = null;
    $state['lastRamFreePercent'] = null;
    $state['lastBpsMbps'] = null;
    $state['bestBpsMbps'] = null;
    $state['bestBpsConcurrency'] = null;
    $state['lastTargetCount'] = null;
    $state['lastBpsCycleId'] = null;
    $state['bpsSettleCyclesRemaining'] = 0;
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
    return adjustDistressConcurrencyByPercent($currentConcurrency, 16);
}

function isWithinDistressBpsDeadZone(float $currentBpsMbps, float $bestBpsMbps): bool
{
    if ($bestBpsMbps <= 0.0) {
        return false;
    }

    $ratioDelta = abs($currentBpsMbps - $bestBpsMbps) / $bestBpsMbps;
    return $ratioDelta <= DISTRESS_AUTOTUNE_BPS_DEAD_ZONE_RATIO;
}

function applyDistressBpsStabilityTarget(
    int $currentConcurrency,
    int $explorationTargetConcurrency,
    ?float $currentBpsMbps,
    ?float $bestBpsMbps,
    ?int $bestBpsConcurrency
): int {
    if (
        $currentBpsMbps === null ||
        $bestBpsMbps === null ||
        $bestBpsMbps <= 0.0 ||
        $bestBpsConcurrency === null
    ) {
        return $explorationTargetConcurrency;
    }

    if (isWithinDistressBpsDeadZone($currentBpsMbps, $bestBpsMbps)) {
        return $currentConcurrency;
    }

    if ($currentConcurrency > $bestBpsConcurrency && $currentBpsMbps < $bestBpsMbps) {
        return max($bestBpsConcurrency, adjustDistressConcurrencyByPercent($currentConcurrency, -12));
    }

    if ($currentConcurrency < $bestBpsConcurrency && $currentBpsMbps < $bestBpsMbps) {
        return min($bestBpsConcurrency, max($currentConcurrency, $explorationTargetConcurrency));
    }

    return $explorationTargetConcurrency;
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
        return null;
    }

    if ($sampleCount < max(1, $minSamples)) {
        return null;
    }

    if (($now - $latestSampleAt) > $staleAfterSeconds) {
        return null;
    }

    return [
        'movingAverageMbps' => $movingAverageMbps,
        'latestBpsMbps' => isset($bpsState['latestBpsMbps']) && is_numeric($bpsState['latestBpsMbps'])
            ? (float)$bpsState['latestBpsMbps']
            : null,
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
    ];
}

function applyDistressBpsAutotuneGuard(
    int $currentConcurrency,
    int $targetConcurrency,
    ?float $lastBpsMbps,
    ?float $bestBpsMbps,
    ?int $bestBpsConcurrency
): int {
    if ($targetConcurrency <= $currentConcurrency) {
        return $targetConcurrency;
    }

    if (
        $lastBpsMbps === null ||
        $bestBpsMbps === null ||
        $bestBpsMbps <= 0.0 ||
        $bestBpsConcurrency === null ||
        $currentConcurrency < $bestBpsConcurrency
    ) {
        return $targetConcurrency;
    }

    if ($lastBpsMbps < ($bestBpsMbps * DISTRESS_AUTOTUNE_BPS_HOLD_RATIO)) {
        return $currentConcurrency;
    }

    return $targetConcurrency;
}

function applyDistressBpsAutotuneDrop(
    int $currentConcurrency,
    int $targetConcurrency,
    ?float $lastBpsMbps,
    ?float $bestBpsMbps,
    ?int $bestBpsConcurrency
): int {
    if (
        $lastBpsMbps === null ||
        $bestBpsMbps === null ||
        $bestBpsMbps <= 0.0 ||
        $bestBpsConcurrency === null ||
        $currentConcurrency <= $bestBpsConcurrency
    ) {
        return $targetConcurrency;
    }

    if ($lastBpsMbps >= ($bestBpsMbps * DISTRESS_AUTOTUNE_BPS_DROP_RESTORE_RATIO)) {
        return $targetConcurrency;
    }

    if ($lastBpsMbps >= ($bestBpsMbps * DISTRESS_AUTOTUNE_BPS_DROP_RATIO)) {
        return $targetConcurrency;
    }

    if ($targetConcurrency < $currentConcurrency) {
        return min($targetConcurrency, $bestBpsConcurrency);
    }

    return max($bestBpsConcurrency, adjustDistressConcurrencyByPercent($currentConcurrency, -12));
}

function resetDistressAutotuneBpsCycle(array &$state): void
{
    $state['lastAdjustedAt'] = 0;
    $state['lastBpsMbps'] = null;
    $state['bestBpsMbps'] = null;
    $state['bestBpsConcurrency'] = null;
    $state['lastBpsCycleId'] = null;
    $state['bpsSettleCyclesRemaining'] = 0;
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
    return true;
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
    $previousSettleCyclesRemaining = (int)($state['bpsSettleCyclesRemaining'] ?? 0);
    $state['desiredConcurrency'] = $configConcurrency;
    $state['lastLoadAverage'] = $load;
    $state['lastRamFreePercent'] = $freeRam;
    $now = time();
    $bpsMetrics = getDistressFreshBpsMetrics($now);
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
        resetDistressAutotuneBpsCycle($state);
    }
    if ($latestTargetCount !== null) {
        $state['lastTargetCount'] = $latestTargetCount;
    }
    if ($currentCycleId !== null) {
        $state['lastBpsCycleId'] = $currentCycleId;
    }
    $skipBpsEvaluation = shouldSkipDistressBpsEvaluationForSettle($state);
    $evaluatedBpsMbps = null;
    if (!$skipBpsEvaluation && $bpsMetrics !== null) {
        $evaluatedBpsMbps = (float)$bpsMetrics['movingAverageMbps'];
    }
    $state['lastBpsMbps'] = $evaluatedBpsMbps;
    if (
        $evaluatedBpsMbps !== null &&
        $evaluatedBpsMbps > 0.0 &&
        (
            !isset($state['bestBpsMbps']) ||
            !is_numeric($state['bestBpsMbps']) ||
            (float)$state['bestBpsMbps'] <= 0.0 ||
            $evaluatedBpsMbps >= ((float)$state['bestBpsMbps'] * DISTRESS_AUTOTUNE_BPS_BEST_IMPROVEMENT_RATIO)
        )
    ) {
        $state['bestBpsMbps'] = $evaluatedBpsMbps;
        $state['bestBpsConcurrency'] = $currentConcurrency;
    }
    if (($now - (int)($state['lastAdjustedAt'] ?? 0)) < DISTRESS_AUTOTUNE_COOLDOWN_SECONDS) {
        writeDistressAutotuneState($state);
        releaseDistressAutotuneLock($lockHandle);
        return getDistressAutotuneStatus() + ['changed' => false, 'reason' => 'cooldown'];
    }

    $targetConcurrency = calculateDistressSafetyTargetConcurrency($currentConcurrency, $load, $freeRam);
    if ($targetConcurrency >= $currentConcurrency) {
        $targetConcurrency = calculateDistressExplorationTargetConcurrency($currentConcurrency, $load, $freeRam);
    }
    $targetConcurrency = applyDistressBpsStabilityTarget(
        $currentConcurrency,
        $targetConcurrency,
        $evaluatedBpsMbps,
        isset($state['bestBpsMbps']) && is_numeric($state['bestBpsMbps']) ? (float)$state['bestBpsMbps'] : null,
        normalizeDistressConcurrency($state['bestBpsConcurrency'] ?? null)
    );
    $targetConcurrency = applyDistressBpsAutotuneDrop(
        $currentConcurrency,
        $targetConcurrency,
        $evaluatedBpsMbps,
        isset($state['bestBpsMbps']) && is_numeric($state['bestBpsMbps']) ? (float)$state['bestBpsMbps'] : null,
        normalizeDistressConcurrency($state['bestBpsConcurrency'] ?? null)
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
            $state['bpsSettleCyclesRemaining'] = $previousSettleCyclesRemaining;
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
    $state['bpsSettleCyclesRemaining'] = DISTRESS_AUTOTUNE_BPS_SETTLE_CYCLES;
    $state['lastBpsMbps'] = null;
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
