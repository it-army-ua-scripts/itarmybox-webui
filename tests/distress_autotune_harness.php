<?php

declare(strict_types=1);

$workspaceRoot = dirname(__DIR__);
$runtimeRoot = $workspaceRoot . '/var/test-distress-autotune';
$stateDir = $runtimeRoot . '/state';
$logDir = $runtimeRoot . '/log';
@mkdir('/tmp', 0777, true);
@mkdir($stateDir, 0777, true);
@mkdir($logDir, 0777, true);

define('ROOT_HELPER_WEBUI_DIR', $workspaceRoot);
define('ROOT_HELPER_VAR_DIR', $runtimeRoot);
define('ROOT_HELPER_STATE_DIR', $stateDir);
define('ROOT_HELPER_LOG_DIR', $logDir);
define('DISTRESS_AUTOTUNE_LEGACY_STATE_FILE', $runtimeRoot . '/legacy-distress-autotune.json');
define('DISTRESS_AUTOTUNE_LOCK_FILE', $runtimeRoot . '/distress-autotune.lock');

$GLOBALS['distressHarness'] = [
    'timerInstalled' => true,
    'repairSucceeds' => true,
    'serviceActive' => false,
    'servicePid' => null,
    'nextPid' => 9001,
    'serviceExecStart' => 'ExecStart=/usr/bin/distress --concurrency 2048',
    'serviceStopShouldFail' => false,
    'serviceStartShouldFail' => false,
    'serviceActivationShouldFail' => false,
    'serviceStopVerificationShouldFail' => false,
    'daemonReloadShouldFail' => false,
    'uploadCapMeasureCount' => 0,
    'uploadCapMeasureValue' => 123.45,
    'autostartEnabled' => false,
    'bootId' => 'boot-a',
];

function writeDebugLogLine(string $filePath, string $message): void
{
    @file_put_contents($filePath, $message . "\n", FILE_APPEND);
}

function isSystemdUnitKnown(string $unitName): bool
{
    return ($GLOBALS['distressHarness']['timerInstalled'] ?? false) === true;
}

function repairRootHelperAccess(): bool
{
    if (($GLOBALS['distressHarness']['repairSucceeds'] ?? false) !== true) {
        return false;
    }

    $GLOBALS['distressHarness']['timerInstalled'] = true;
    return true;
}

function findSystemctl(): ?string
{
    return '/bin/systemctl';
}

function findExecutable(array $paths): ?string
{
    return $paths[0] ?? null;
}

function ensureDirectoryExists(string $path): bool
{
    return @mkdir($path, 0777, true) || is_dir($path);
}

function ensureParentDirectoryExists(string $path): bool
{
    return ensureDirectoryExists(dirname($path));
}

function ensureWebuiVarLayout(): bool
{
    return ensureDirectoryExists(ROOT_HELPER_STATE_DIR) && ensureDirectoryExists(ROOT_HELPER_LOG_DIR);
}

function migrateLegacyFileIfNeeded(string $legacyPath, string $targetPath): bool
{
    if (is_file($targetPath)) {
        return true;
    }
    if (!is_file($legacyPath)) {
        return false;
    }
    $content = @file_get_contents($legacyPath);
    if (!is_string($content)) {
        return false;
    }
    if (!ensureParentDirectoryExists($targetPath)) {
        return false;
    }
    return @file_put_contents($targetPath, $content) !== false;
}

function runCommand(string $command, ?int &$exitCode = null): string
{
    $h = &$GLOBALS['distressHarness'];
    $exitCode = 0;

    if (str_contains($command, 'daemon-reload')) {
        $exitCode = ($h['daemonReloadShouldFail'] ?? false) ? 1 : 0;
        return '';
    }

    if (str_contains($command, 'enable --now')) {
        $h['timerInstalled'] = true;
        return '';
    }

    if (str_contains($command, ' stop ')) {
        if (($h['serviceStopShouldFail'] ?? false) === true) {
            $exitCode = 1;
            return '';
        }
        $h['serviceActive'] = false;
        $h['servicePid'] = null;
        return '';
    }

    if (str_contains($command, ' start ')) {
        if (($h['serviceStartShouldFail'] ?? false) === true) {
            $exitCode = 1;
            return '';
        }
        $h['serviceActive'] = true;
        if (($h['serviceActivationShouldFail'] ?? false) !== true) {
            $h['servicePid'] = $h['nextPid'];
            $h['nextPid']++;
        }
        return '';
    }

    if (str_contains($command, 'nproc')) {
        return '8';
    }

    return '';
}

function readServiceExecStart(string $module): ?string
{
    return $module === 'distress' ? (string)$GLOBALS['distressHarness']['serviceExecStart'] : null;
}

function updateServiceExecStart(string $module, string $execStartLine): bool
{
    if ($module !== 'distress') {
        return false;
    }
    $GLOBALS['distressHarness']['serviceExecStart'] = $execStartLine;
    return true;
}

function getExecStartOptionValue(string $execStartLine, string $option): ?string
{
    $pattern = '/(?:^|\s)--' . preg_quote($option, '/') . '\s+([^\s]+)/';
    if (preg_match($pattern, $execStartLine, $matches) !== 1) {
        return null;
    }
    return trim((string)$matches[1], "\"'");
}

function replaceExecStartOptionValue(string $execStartLine, string $option, string $value): string
{
    $pattern = '/(^|\s)--' . preg_quote($option, '/') . '\s+[^\s]+/';
    if (preg_match($pattern, $execStartLine) !== 1) {
        return rtrim($execStartLine) . ' --' . $option . ' ' . $value;
    }
    return (string)preg_replace($pattern, '$1--' . $option . ' ' . $value, $execStartLine, 1);
}

function serviceIsActive(string $module): bool
{
    return $module === 'distress' && (($GLOBALS['distressHarness']['serviceActive'] ?? false) === true);
}

function getServiceMainPid(string $module): ?int
{
    if ($module !== 'distress') {
        return null;
    }
    $pid = $GLOBALS['distressHarness']['servicePid'] ?? null;
    return is_int($pid) ? $pid : null;
}

function waitForServiceInactive(string $module, int $attempts = 25, int $sleepMicroseconds = 200000): bool
{
    return ($GLOBALS['distressHarness']['serviceStopVerificationShouldFail'] ?? false) !== true
        && !serviceIsActive($module);
}

function waitForServiceActive(string $module, int $attempts = 25, int $sleepMicroseconds = 200000): bool
{
    return ($GLOBALS['distressHarness']['serviceActivationShouldFail'] ?? false) !== true
        && serviceIsActive($module);
}

function verifyServiceExecStartOptionValue(string $module, string $option, string $expectedValue): bool
{
    return getExecStartOptionValue((string)readServiceExecStart($module), $option) === $expectedValue;
}

function isModuleAutostartEnabled(string $module): bool
{
    return $module === 'distress' && (($GLOBALS['distressHarness']['autostartEnabled'] ?? false) === true);
}

function readCurrentSystemBootId(): ?string
{
    $bootId = $GLOBALS['distressHarness']['bootId'] ?? null;
    return is_string($bootId) && $bootId !== '' ? $bootId : null;
}

function measureDistressCloudflareUploadCap(): ?float
{
    $GLOBALS['distressHarness']['uploadCapMeasureCount'] = (int)($GLOBALS['distressHarness']['uploadCapMeasureCount'] ?? 0) + 1;
    return (float)($GLOBALS['distressHarness']['uploadCapMeasureValue'] ?? 123.45);
}

require_once __DIR__ . '/../root_helper/distress_autotune.php';

function harness_reset_runtime(): void
{
    $GLOBALS['distressHarness'] = [
        'timerInstalled' => true,
        'repairSucceeds' => true,
        'serviceActive' => false,
        'servicePid' => null,
        'nextPid' => 9001,
        'serviceExecStart' => 'ExecStart=/usr/bin/distress --concurrency 2048',
        'serviceStopShouldFail' => false,
        'serviceStartShouldFail' => false,
        'serviceActivationShouldFail' => false,
        'serviceStopVerificationShouldFail' => false,
        'daemonReloadShouldFail' => false,
        'uploadCapMeasureCount' => 0,
        'uploadCapMeasureValue' => 123.45,
        'autostartEnabled' => false,
        'bootId' => 'boot-a',
    ];

    @unlink(DISTRESS_AUTOTUNE_STATE_FILE);
    @unlink(DISTRESS_AUTOTUNE_STATE_FILE . '.tmp');
    @unlink(DISTRESS_AUTOTUNE_BPS_STATE_FILE);
    @unlink(DISTRESS_AUTOTUNE_DEBUG_LOG_FILE);
    @unlink(DISTRESS_AUTOTUNE_SERVICE_PRESTART_SKIP_FILE);
}

function harness_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function harness_test_state_write_roundtrip(): void
{
    harness_reset_runtime();
    $state = readDistressAutotuneState();
    $state['enabled'] = false;
    $state['desiredConcurrency'] = 4096;
    $state['probeWindowBps'] = [10.5, 12.5];

    harness_assert(writeDistressAutotuneState($state), 'state write should succeed');
    harness_assert(!is_file(DISTRESS_AUTOTUNE_STATE_FILE . '.tmp'), 'temporary state file should not remain after atomic write');

    $reloaded = readDistressAutotuneState();
    harness_assert(($reloaded['enabled'] ?? true) === false, 'enabled flag should round-trip');
    harness_assert((int)($reloaded['desiredConcurrency'] ?? 0) === 4096, 'desiredConcurrency should round-trip');
    harness_assert(count((array)($reloaded['probeWindowBps'] ?? [])) === 2, 'probeWindowBps should round-trip');
}

function harness_test_manual_mode_without_timer(): void
{
    harness_reset_runtime();
    $GLOBALS['distressHarness']['timerInstalled'] = false;
    $GLOBALS['distressHarness']['repairSucceeds'] = false;

    $result = setDistressAutotuneMode(false, 4096);
    harness_assert(($result['ok'] ?? false) === true, 'manual autotune mode should not require timer installation');
}

function harness_test_auto_mode_requires_timer(): void
{
    harness_reset_runtime();
    $GLOBALS['distressHarness']['timerInstalled'] = false;
    $GLOBALS['distressHarness']['repairSucceeds'] = false;

    $result = setDistressAutotuneMode(true, 4096);
    harness_assert(($result['ok'] ?? false) === false, 'auto mode should fail when timer installation is unavailable');
    harness_assert(($result['error'] ?? '') === 'distress_autotune_timer_install_failed', 'auto mode should report timer install failure');
}

function harness_test_tick_manual_persists_telemetry(): void
{
    harness_reset_runtime();
    writeDistressAutotuneState([
        'enabled' => false,
        'desiredConcurrency' => 2048,
    ]);

    $result = distressAutotuneTick(1.75, 42.0);
    harness_assert(($result['ok'] ?? false) === true, 'manual tick should succeed');
    harness_assert(($result['reason'] ?? '') === 'manual_mode', 'manual tick should report manual_mode');

    $state = readDistressAutotuneState();
    harness_assert(abs((float)($state['lastLoadAverage'] ?? 0.0) - 1.75) < 0.0001, 'manual tick should persist load average');
    harness_assert(abs((float)($state['lastRamFreePercent'] ?? 0.0) - 42.0) < 0.0001, 'manual tick should persist free RAM');
}

function harness_test_tick_inactive_persists_telemetry(): void
{
    harness_reset_runtime();
    writeDistressAutotuneState([
        'enabled' => true,
        'desiredConcurrency' => 2048,
    ]);

    $result = distressAutotuneTick(2.25, 33.0);
    harness_assert(($result['ok'] ?? false) === true, 'inactive tick should succeed');
    harness_assert(($result['reason'] ?? '') === 'distress_inactive', 'inactive tick should report distress_inactive');

    $state = readDistressAutotuneState();
    harness_assert(abs((float)($state['lastLoadAverage'] ?? 0.0) - 2.25) < 0.0001, 'inactive tick should persist load average');
    harness_assert(abs((float)($state['lastRamFreePercent'] ?? 0.0) - 33.0) < 0.0001, 'inactive tick should persist free RAM');
}

function harness_test_restart_failure_path(): void
{
    harness_reset_runtime();
    $GLOBALS['distressHarness']['serviceActive'] = true;
    $GLOBALS['distressHarness']['servicePid'] = 777;
    $GLOBALS['distressHarness']['serviceStartShouldFail'] = true;

    $state = readDistressAutotuneState();
    $result = restartDistressForAutotune($state);
    harness_assert(($result['ok'] ?? true) === false, 'restart helper should fail when start fails');
    harness_assert(($result['error'] ?? '') === 'service_start_failed', 'restart helper should report service_start_failed');
}

function harness_test_coarse_target_enters_refine_on_drop(): void
{
    harness_reset_runtime();
    $state = [
        'searchPhase' => DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
        'refineLowConcurrency' => null,
        'refineHighConcurrency' => null,
    ];

    $target = determineDistressSearchTarget(
        $state,
        4096,
        8192,
        100.0,
        2048,
        85.0,
        85.0,
        true
    );

    harness_assert($state['searchPhase'] === DISTRESS_AUTOTUNE_SEARCH_PHASE_REFINE, 'coarse search should enter refine phase after confirmed drop');
    harness_assert((int)$state['refineLowConcurrency'] === 2048, 'refine lower bound should be set to previous best concurrency');
    harness_assert((int)$state['refineHighConcurrency'] === 4096, 'refine upper bound should be set to current concurrency');
    harness_assert($target === 3072, 'refine midpoint should be selected as next target');
}

function harness_test_coarse_target_holds_without_drop_window(): void
{
    harness_reset_runtime();
    $state = [
        'searchPhase' => DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
    ];

    $target = determineDistressSearchTarget(
        $state,
        4096,
        8192,
        100.0,
        2048,
        96.0,
        null,
        false
    );

    harness_assert($state['searchPhase'] === DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE, 'coarse search should stay coarse while drop window is not ready');
    harness_assert($target === 4096, 'coarse search should hold current concurrency above best until drop window is ready');
}

function harness_test_coarse_target_uses_exploration_growth(): void
{
    harness_reset_runtime();
    $state = [
        'searchPhase' => DISTRESS_AUTOTUNE_SEARCH_PHASE_COARSE,
    ];

    $target = determineDistressSearchTarget(
        $state,
        2048,
        4096,
        100.0,
        2048,
        100.0,
        100.0,
        true
    );

    harness_assert($target === 4096, 'coarse search should use exploration target when still probing upward');
}

function harness_test_refine_target_narrows_high_bound_on_bad_probe(): void
{
    harness_reset_runtime();
    $state = [
        'searchPhase' => DISTRESS_AUTOTUNE_SEARCH_PHASE_REFINE,
        'refineLowConcurrency' => 2048,
        'refineHighConcurrency' => 4096,
    ];

    $target = determineDistressSearchTarget(
        $state,
        3072,
        4096,
        100.0,
        2048,
        80.0,
        null,
        false
    );

    harness_assert((int)$state['refineHighConcurrency'] === 3072, 'refine search should lower the upper bound on a bad probe');
    harness_assert($target === 2560, 'refine search should pick the midpoint of the narrowed interval');
}

function harness_test_refine_target_converges_to_hold(): void
{
    harness_reset_runtime();
    $state = [
        'searchPhase' => DISTRESS_AUTOTUNE_SEARCH_PHASE_REFINE,
        'refineLowConcurrency' => 2048,
        'refineHighConcurrency' => 2112,
    ];

    $target = determineDistressSearchTarget(
        $state,
        2112,
        4096,
        100.0,
        2048,
        80.0,
        null,
        false
    );

    harness_assert($state['searchPhase'] === DISTRESS_AUTOTUNE_SEARCH_PHASE_HOLD, 'refine search should switch to hold when there is no meaningful midpoint left');
    harness_assert($target === 2048, 'refine search should fall back to the best known concurrency when converged');
}

function harness_test_hold_target_returns_best_concurrency(): void
{
    harness_reset_runtime();
    $state = [
        'searchPhase' => DISTRESS_AUTOTUNE_SEARCH_PHASE_HOLD,
    ];

    $target = determineDistressSearchTarget(
        $state,
        3072,
        4096,
        100.0,
        2048,
        98.0,
        null,
        false
    );

    harness_assert($target === 2048, 'hold search should return the best known concurrency');
}

function harness_test_best_probe_state_prefers_confirmed_best(): void
{
    harness_reset_runtime();
    $state = [
        'bestBpsMbps' => 100.0,
        'bestBpsConcurrency' => 2048,
        'confirmedBestBpsMbps' => null,
        'confirmedBestBpsConcurrency' => null,
        'probeWindowBps' => [100.0, 100.0, 100.0, 100.0],
    ];

    $decision = updateDistressBestProbeState($state, 2048, 100.0);

    harness_assert(abs((float)$decision['decisionBestBpsMbps'] - 100.0) < 0.0001, 'decision best BPS should use confirmed best when enough windows are present');
    harness_assert((int)$decision['decisionBestBpsConcurrency'] === 2048, 'decision best concurrency should match the confirmed best concurrency');
    harness_assert((int)($state['confirmedBestBpsConcurrency'] ?? 0) === 2048, 'confirmed best concurrency should be stored in state');
}

function harness_test_safety_target_hits_min_on_zero_ram(): void
{
    harness_reset_runtime();
    $target = calculateDistressSafetyTargetConcurrency(2048, 0.5, 0.0);
    harness_assert($target === DISTRESS_AUTOTUNE_MIN_CONCURRENCY, 'zero free RAM should clamp safety target to the minimum concurrency');
}

function harness_test_safety_target_holds_in_ram_recovery_zone(): void
{
    harness_reset_runtime();
    $target = calculateDistressSafetyTargetConcurrency(2048, 5.0, 12.0);
    harness_assert($target === 2048, 'RAM recovery zone should hold concurrency even if load is high');
}

function harness_test_safety_target_reduces_on_high_load(): void
{
    harness_reset_runtime();
    $target = calculateDistressSafetyTargetConcurrency(2048, 4.0, 40.0);
    harness_assert($target < 2048, 'high load above the dead zone should reduce concurrency');
}

function harness_test_exploration_target_doubles_on_low_load(): void
{
    harness_reset_runtime();
    $target = calculateDistressExplorationTargetConcurrency(2048, 0.3, 40.0);
    harness_assert($target === 4096, 'very low load should allow aggressive exploration growth');
}

function harness_test_exploration_target_small_increase_near_medium_threshold(): void
{
    harness_reset_runtime();
    $targetLoad = getDistressAutotuneTargetLoad();
    $load = getDistressAutotuneMediumIncreaseThreshold($targetLoad);
    $target = calculateDistressExplorationTargetConcurrency(2048, $load, 40.0);
    harness_assert($target > 2048 && $target < 4096, 'load near the medium threshold should increase concurrency conservatively');
}

function harness_test_dead_zone_detection_accepts_small_bps_delta(): void
{
    harness_reset_runtime();
    harness_assert(isWithinDistressBpsDeadZone(98.0, 100.0) === true, 'small BPS deviation should stay within the dead zone');
}

function harness_test_dead_zone_detection_rejects_large_bps_delta(): void
{
    harness_reset_runtime();
    harness_assert(isWithinDistressBpsDeadZone(85.0, 100.0) === false, 'large BPS deviation should fall outside the dead zone');
}

function harness_test_bps_is_normalized_to_speed_within_tolerance_below_speed(): void
{
    harness_reset_runtime();
    $normalized = normalizeDistressBpsAgainstSpeed(92.0, 100.0);
    harness_assert(abs((float)$normalized - 100.0) < 0.0001, 'BPS slightly below speed should be treated as normal within tolerance');
}

function harness_test_bps_is_normalized_to_speed_within_tolerance_above_speed(): void
{
    harness_reset_runtime();
    $normalized = normalizeDistressBpsAgainstSpeed(108.0, 100.0);
    harness_assert(abs((float)$normalized - 100.0) < 0.0001, 'BPS slightly above speed should also be treated as normal within tolerance');
}

function harness_test_bps_is_capped_at_upper_speed_tolerance(): void
{
    harness_reset_runtime();
    $normalized = normalizeDistressBpsAgainstSpeed(125.0, 100.0);
    harness_assert(abs((float)$normalized - 110.0) < 0.0001, 'BPS above the acceptable speed ceiling should be capped at speed plus tolerance');
}

function harness_test_probe_score_uses_recent_window_median(): void
{
    harness_reset_runtime();
    $state = [
        'probeWindowBps' => [50.0, 100.0, 60.0, 70.0],
    ];
    $score = calculateDistressProbeScore($state, 3);
    harness_assert(abs((float)$score - 70.0) < 0.0001, 'probe score should use the median of the most recent required windows');
}

function harness_test_settle_counter_decrements_and_blocks_bps_evaluation(): void
{
    harness_reset_runtime();
    $state = [
        'bpsSettleCyclesRemaining' => 2,
    ];
    $skip = shouldSkipDistressBpsEvaluationForSettle($state);
    harness_assert($skip === true, 'positive settle counter should skip BPS evaluation');
    harness_assert((int)$state['bpsSettleCyclesRemaining'] === 1, 'settle counter should decrement after skipping');
}

function harness_test_settle_counter_allows_bps_evaluation_when_zero(): void
{
    harness_reset_runtime();
    $state = [
        'bpsSettleCyclesRemaining' => 0,
    ];
    $skip = shouldSkipDistressBpsEvaluationForSettle($state);
    harness_assert($skip === false, 'zero settle counter should allow BPS evaluation');
}

function harness_test_manual_second_start_does_not_refresh_upload_cap(): void
{
    harness_reset_runtime();
    writeDistressAutotuneState([
        'enabled' => true,
        'desiredConcurrency' => 2048,
        'uploadCapMbps' => 111.0,
        'uploadCapMeasuredAt' => time(),
    ]);

    harness_assert(prepareDistressUploadCapBeforeStart(false) === true, 'manual start with existing upload cap should succeed');
    harness_assert((int)$GLOBALS['distressHarness']['uploadCapMeasureCount'] === 0, 'manual second start should not re-measure upload cap');
}

function harness_test_scheduler_start_forces_upload_cap_refresh(): void
{
    harness_reset_runtime();
    writeDistressAutotuneState([
        'enabled' => true,
        'desiredConcurrency' => 2048,
        'uploadCapMbps' => 111.0,
        'uploadCapMeasuredAt' => time(),
    ]);

    harness_assert(prepareDistressUploadCapBeforeStart(true) === true, 'scheduler start should succeed');
    harness_assert((int)$GLOBALS['distressHarness']['uploadCapMeasureCount'] === 1, 'scheduler start should force upload cap re-measurement');
}

function harness_test_target_set_change_refreshes_upload_cap(): void
{
    harness_reset_runtime();
    $state = [
        'lastTargetCount' => 100,
        'lastBpsCycleId' => 'cycle-a',
        'uploadCapMbps' => 111.0,
        'uploadCapMeasuredAt' => time(),
    ];

    $cycle = syncDistressBpsCycleState($state, [
        'cycleId' => 'cycle-b',
        'latestTargetCount' => 150,
    ]);

    harness_assert(($cycle['currentCycleId'] ?? '') === 'cycle-b', 'cycle state should expose the new cycle id');
    harness_assert((int)$GLOBALS['distressHarness']['uploadCapMeasureCount'] === 1, 'target set change should re-measure upload cap');
    harness_assert(abs((float)($state['uploadCapMbps'] ?? 0.0) - 123.45) < 0.0001, 'state should be updated with the refreshed upload cap');
}

function harness_test_service_start_autostart_forces_upload_cap_refresh_once_per_boot(): void
{
    harness_reset_runtime();
    $GLOBALS['distressHarness']['autostartEnabled'] = true;
    writeDistressAutotuneState([
        'enabled' => true,
        'desiredConcurrency' => 2048,
        'uploadCapMbps' => 111.0,
        'uploadCapMeasuredAt' => time(),
        'lastAutostartBootId' => 'boot-old',
    ]);

    harness_assert(prepareDistressUploadCapForServiceStart() === true, 'autostart service prepare should succeed');
    harness_assert((int)$GLOBALS['distressHarness']['uploadCapMeasureCount'] === 1, 'autostart should force upload cap refresh on a new boot');

    $state = readDistressAutotuneState();
    harness_assert(($state['lastAutostartBootId'] ?? '') === 'boot-a', 'autostart boot id should be persisted after refresh');

    harness_assert(prepareDistressUploadCapForServiceStart() === true, 'repeated service prepare in the same boot should succeed');
    harness_assert((int)$GLOBALS['distressHarness']['uploadCapMeasureCount'] === 1, 'autostart should not refresh upload cap more than once per boot');
}

function harness_test_service_start_skip_marker_prevents_duplicate_refresh_after_manual_start(): void
{
    harness_reset_runtime();
    $GLOBALS['distressHarness']['autostartEnabled'] = true;
    writeDistressAutotuneState([
        'enabled' => true,
        'desiredConcurrency' => 2048,
        'uploadCapMbps' => 111.0,
        'uploadCapMeasuredAt' => time(),
        'lastAutostartBootId' => 'boot-old',
    ]);

    harness_assert(markDistressUploadCapServicePrestartSkip() === true, 'manual start path should be able to mark prestart skip');
    harness_assert(prepareDistressUploadCapForServiceStart() === true, 'service prepare should honor the prestart skip marker');
    harness_assert((int)$GLOBALS['distressHarness']['uploadCapMeasureCount'] === 0, 'skip marker should prevent duplicate upload cap refresh');
    harness_assert(!is_file(DISTRESS_AUTOTUNE_SERVICE_PRESTART_SKIP_FILE), 'skip marker should be consumed after service prepare');
}

$tests = [
    'state_write_roundtrip' => 'harness_test_state_write_roundtrip',
    'manual_mode_without_timer' => 'harness_test_manual_mode_without_timer',
    'auto_mode_requires_timer' => 'harness_test_auto_mode_requires_timer',
    'tick_manual_persists_telemetry' => 'harness_test_tick_manual_persists_telemetry',
    'tick_inactive_persists_telemetry' => 'harness_test_tick_inactive_persists_telemetry',
    'restart_failure_path' => 'harness_test_restart_failure_path',
    'coarse_target_enters_refine_on_drop' => 'harness_test_coarse_target_enters_refine_on_drop',
    'coarse_target_holds_without_drop_window' => 'harness_test_coarse_target_holds_without_drop_window',
    'coarse_target_uses_exploration_growth' => 'harness_test_coarse_target_uses_exploration_growth',
    'refine_target_narrows_high_bound_on_bad_probe' => 'harness_test_refine_target_narrows_high_bound_on_bad_probe',
    'refine_target_converges_to_hold' => 'harness_test_refine_target_converges_to_hold',
    'hold_target_returns_best_concurrency' => 'harness_test_hold_target_returns_best_concurrency',
    'best_probe_state_prefers_confirmed_best' => 'harness_test_best_probe_state_prefers_confirmed_best',
    'safety_target_hits_min_on_zero_ram' => 'harness_test_safety_target_hits_min_on_zero_ram',
    'safety_target_holds_in_ram_recovery_zone' => 'harness_test_safety_target_holds_in_ram_recovery_zone',
    'safety_target_reduces_on_high_load' => 'harness_test_safety_target_reduces_on_high_load',
    'exploration_target_doubles_on_low_load' => 'harness_test_exploration_target_doubles_on_low_load',
    'exploration_target_small_increase_near_medium_threshold' => 'harness_test_exploration_target_small_increase_near_medium_threshold',
    'dead_zone_detection_accepts_small_bps_delta' => 'harness_test_dead_zone_detection_accepts_small_bps_delta',
    'dead_zone_detection_rejects_large_bps_delta' => 'harness_test_dead_zone_detection_rejects_large_bps_delta',
    'bps_is_normalized_to_speed_within_tolerance_below_speed' => 'harness_test_bps_is_normalized_to_speed_within_tolerance_below_speed',
    'bps_is_normalized_to_speed_within_tolerance_above_speed' => 'harness_test_bps_is_normalized_to_speed_within_tolerance_above_speed',
    'bps_is_capped_at_upper_speed_tolerance' => 'harness_test_bps_is_capped_at_upper_speed_tolerance',
    'probe_score_uses_recent_window_median' => 'harness_test_probe_score_uses_recent_window_median',
    'settle_counter_decrements_and_blocks_bps_evaluation' => 'harness_test_settle_counter_decrements_and_blocks_bps_evaluation',
    'settle_counter_allows_bps_evaluation_when_zero' => 'harness_test_settle_counter_allows_bps_evaluation_when_zero',
    'manual_second_start_does_not_refresh_upload_cap' => 'harness_test_manual_second_start_does_not_refresh_upload_cap',
    'scheduler_start_forces_upload_cap_refresh' => 'harness_test_scheduler_start_forces_upload_cap_refresh',
    'target_set_change_refreshes_upload_cap' => 'harness_test_target_set_change_refreshes_upload_cap',
    'service_start_autostart_forces_upload_cap_refresh_once_per_boot' => 'harness_test_service_start_autostart_forces_upload_cap_refresh_once_per_boot',
    'service_start_skip_marker_prevents_duplicate_refresh_after_manual_start' => 'harness_test_service_start_skip_marker_prevents_duplicate_refresh_after_manual_start',
];

$passed = 0;
$failed = 0;
$failures = [];

foreach ($tests as $name => $fn) {
    try {
        $fn();
        $passed++;
        echo "[PASS] $name\n";
    } catch (Throwable $e) {
        $failed++;
        $failures[] = $name . ': ' . $e->getMessage();
        echo "[FAIL] $name: " . $e->getMessage() . "\n";
    }
}

echo "\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ($failures !== []) {
    echo "Failures:\n";
    foreach ($failures as $failure) {
        echo "- $failure\n";
    }
}

exit($failed === 0 ? 0 : 1);
