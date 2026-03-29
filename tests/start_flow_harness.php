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

require_once __DIR__ . '/../lib/start_helpers.php';

function start_harness_reset(): void
{
    $GLOBALS['startHarness'] = [
        'configString' => 'ExecStart=/usr/bin/distress --concurrency 2048',
        'updateServiceFileOk' => true,
        'rootHelperResponse' => ['ok' => true],
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

function start_harness_test_manual_launch_ignores_service_file_update_failures(): void
{
    start_harness_reset();
    $GLOBALS['startHarness']['updateServiceFileOk'] = false;
    $result = start_module_request('distress', ['daemonNames' => ['mhddos', 'distress', 'x100']]);
    start_harness_assert(($result['ok'] ?? false) === true, 'manual launch should not depend on service file update');
    start_harness_assert(($result['messageKey'] ?? '') === 'start_requested', 'manual launch should still report start_requested when root helper accepts activation');
}

function start_harness_test_manual_launch_returns_success_when_execstart_read_is_unavailable(): void
{
    start_harness_reset();
    $GLOBALS['startHarness']['configString'] = '';
    $result = start_module_request('mhddos', ['daemonNames' => ['mhddos', 'distress', 'x100']]);
    start_harness_assert(($result['ok'] ?? false) === true, 'manual launch should not fail when ExecStart cannot be read');
    start_harness_assert(($result['messageKey'] ?? '') === 'start_requested', 'manual launch should report start_requested when root helper accepts activation');
}

function start_harness_test_distress_always_uses_background_worker(): void
{
    start_harness_reset();
    start_harness_assert(start_uses_background_worker('distress') === true, 'distress should always use the background worker flow');
    start_harness_assert(start_uses_background_worker('mhddos') === false, 'mhddos should keep the direct start flow');
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
    'manual_launch_ignores_service_file_update_failures' => 'start_harness_test_manual_launch_ignores_service_file_update_failures',
    'manual_launch_succeeds_when_execstart_read_is_unavailable' => 'start_harness_test_manual_launch_returns_success_when_execstart_read_is_unavailable',
    'distress_always_uses_background_worker' => 'start_harness_test_distress_always_uses_background_worker',
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
