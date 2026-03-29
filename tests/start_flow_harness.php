<?php

declare(strict_types=1);

$workspaceRoot = dirname(__DIR__);
$runtimeRoot = $workspaceRoot . '/var/test-start-flow';
$stateDir = $runtimeRoot . '/state';
@mkdir($stateDir, 0777, true);

$GLOBALS['startHarness'] = [
    'configString' => 'ExecStart=/usr/bin/distress --concurrency 2048',
    'updateServiceFileOk' => true,
    'rootHelperResponse' => ['ok' => true],
    'autotuneSettings' => ['ok' => true, 'enabled' => true],
];

function root_helper_request(array $payload): array
{
    return $GLOBALS['startHarness']['rootHelperResponse'] ?? ['ok' => false];
}

function getConfigStringFromServiceFile(string $serviceName): string
{
    return $GLOBALS['startHarness']['configString'] ?? '';
}

function updateServiceConfigParams(string $configString, array $updatedParams, string $daemonName): array
{
    return [$configString];
}

function updateServiceFile(string $serviceName, array $updatedConfigParams): bool
{
    return ($GLOBALS['startHarness']['updateServiceFileOk'] ?? false) === true;
}

function getDistressAutotuneSettings(): array
{
    return $GLOBALS['startHarness']['autotuneSettings'] ?? ['ok' => false, 'enabled' => false];
}

require_once __DIR__ . '/../lib/start_helpers.php';

function start_harness_reset(): void
{
    $GLOBALS['startHarness'] = [
        'configString' => 'ExecStart=/usr/bin/distress --concurrency 2048',
        'updateServiceFileOk' => true,
        'rootHelperResponse' => ['ok' => true],
        'autotuneSettings' => ['ok' => true, 'enabled' => true],
    ];

    @unlink(DISTRESS_START_TASK_FILE);
    @unlink(DISTRESS_START_TASK_FILE . '.tmp');
}

function start_harness_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function start_harness_test_manual_launch_returns_success_when_root_helper_accepts(): void
{
    start_harness_reset();
    $result = start_module_request('distress', ['daemonNames' => ['mhddos', 'distress', 'x100']]);
    start_harness_assert(($result['ok'] ?? false) === true, 'manual launch should succeed when root helper accepts activation');
    start_harness_assert(($result['messageKey'] ?? '') === 'start_requested', 'manual launch should report start_requested');
}

function start_harness_test_manual_launch_returns_failure_when_service_file_update_fails(): void
{
    start_harness_reset();
    $GLOBALS['startHarness']['updateServiceFileOk'] = false;
    $result = start_module_request('distress', ['daemonNames' => ['mhddos', 'distress', 'x100']]);
    start_harness_assert(($result['ok'] ?? true) === false, 'manual launch should fail when service file update fails');
    start_harness_assert(($result['error'] ?? '') === 'service_execstart_update_failed', 'manual launch should expose service_execstart_update_failed');
}

function start_harness_test_auto_launch_detection_tracks_saved_autotune_mode(): void
{
    start_harness_reset();
    start_harness_assert(is_distress_auto_start('distress') === true, 'distress auto start should be detected when autotune is enabled');
    $GLOBALS['startHarness']['autotuneSettings'] = ['ok' => true, 'enabled' => false];
    start_harness_assert(is_distress_auto_start('distress') === false, 'distress auto start should be false when manual mode is saved');
}

function start_harness_test_start_task_state_roundtrip(): void
{
    start_harness_reset();
    start_harness_assert(reset_start_task_state('distress') === true, 'resetting task state should succeed');
    $state = read_start_task_state();
    start_harness_assert(($state['status'] ?? '') === 'pending', 'task state should enter pending after reset');
    start_harness_assert(complete_start_task_state('distress', true, null) === true, 'completing task state should succeed');
    $state = read_start_task_state();
    start_harness_assert(($state['status'] ?? '') === 'success', 'task state should become success after completion');
    start_harness_assert(($state['messageKey'] ?? '') === 'start_requested', 'task state should store start_requested on success');
}

$tests = [
    'manual_launch_returns_success_when_root_helper_accepts' => 'start_harness_test_manual_launch_returns_success_when_root_helper_accepts',
    'manual_launch_returns_failure_when_service_file_update_fails' => 'start_harness_test_manual_launch_returns_failure_when_service_file_update_fails',
    'auto_launch_detection_tracks_saved_autotune_mode' => 'start_harness_test_auto_launch_detection_tracks_saved_autotune_mode',
    'start_task_state_roundtrip' => 'start_harness_test_start_task_state_roundtrip',
];

$passed = 0;
$failed = 0;

foreach ($tests as $name => $fn) {
    try {
        $fn();
        $passed++;
        echo "[PASS] $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "[FAIL] $name: " . $e->getMessage() . "\n";
    }
}

echo "\nPassed: $passed\n";
echo "Failed: $failed\n";

exit($failed === 0 ? 0 : 1);
