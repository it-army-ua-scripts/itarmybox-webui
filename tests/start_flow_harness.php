<?php

declare(strict_types=1);

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

function start_harness_test_failure_is_reported_from_root_helper(): void
{
    start_harness_reset();
    $GLOBALS['startHarness']['rootHelperResponse'] = ['ok' => false, 'error' => 'service_switch_failed'];
    $result = start_module_request('distress', ['daemonNames' => ['mhddos', 'distress', 'x100']]);
    start_harness_assert(($result['ok'] ?? true) === false, 'failed root helper response should fail start request');
    start_harness_assert(($result['messageKey'] ?? '') === 'start_failed', 'failed root helper response should report start_failed');
    start_harness_assert(($result['error'] ?? '') === 'service_switch_failed', 'root helper error should be preserved');
}

function start_harness_test_auto_requires_manual_speed_measure_message_is_preserved(): void
{
    start_harness_reset();
    $GLOBALS['startHarness']['rootHelperResponse'] = ['ok' => false, 'error' => 'distress_upload_cap_required_for_auto'];
    $result = start_module_request('distress', ['daemonNames' => ['mhddos', 'distress', 'x100']]);
    start_harness_assert(($result['ok'] ?? true) === false, 'auto start without manual speed measurement should fail');
    start_harness_assert(($result['messageKey'] ?? '') === 'distress_upload_cap_required_for_auto', 'specific auto-speed requirement message should be preserved');
    start_harness_assert(($result['error'] ?? '') === 'distress_upload_cap_required_for_auto', 'specific root helper error should be preserved');
}

$tests = [
    'manual_launch_returns_success_when_root_helper_accepts' => 'start_harness_test_manual_launch_returns_success_when_root_helper_accepts',
    'manual_launch_ignores_service_file_update_failures' => 'start_harness_test_manual_launch_ignores_service_file_update_failures',
    'manual_launch_succeeds_when_execstart_read_is_unavailable' => 'start_harness_test_manual_launch_returns_success_when_execstart_read_is_unavailable',
    'failure_is_reported_from_root_helper' => 'start_harness_test_failure_is_reported_from_root_helper',
    'auto_requires_manual_speed_measure_message_is_preserved' => 'start_harness_test_auto_requires_manual_speed_measure_message_is_preserved',
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
