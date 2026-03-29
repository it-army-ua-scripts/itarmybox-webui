<?php

declare(strict_types=1);

function root_helper_request(array $payload): array
{
    return $GLOBALS['distressSettingsHarness']['rootHelperResponse'] ?? ['ok' => false];
}

require_once __DIR__ . '/../lib/tool_distress_helpers.php';

function distress_settings_harness_reset(): void
{
    $GLOBALS['distressSettingsHarness'] = [
        'rootHelperResponse' => [
            'ok' => true,
            'enabled' => false,
            'currentConcurrency' => 4096,
            'configConcurrency' => 4096,
        ],
    ];
}

function distress_settings_harness_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function distress_settings_test_missing_mode_defaults_to_manual(): void
{
    distress_settings_harness_reset();
    $result = normalizeAndValidateDistressPostParams([
        'concurrency' => '6144',
    ]);

    distress_settings_harness_assert(($result['ok'] ?? false) === true, 'validation should succeed');
    distress_settings_harness_assert(($result['autotuneEnabled'] ?? true) === false, 'missing mode should default to manual');
    distress_settings_harness_assert((int)($result['concurrencyValue'] ?? 0) === 6144, 'manual concurrency should be preserved');
}

function distress_settings_test_empty_concurrency_uses_manual_config_default(): void
{
    distress_settings_harness_reset();
    $result = normalizeAndValidateDistressPostParams([
        'distress-concurrency-mode' => 'manual',
        'concurrency' => '',
    ]);

    distress_settings_harness_assert(($result['ok'] ?? false) === true, 'validation should succeed when concurrency is empty');
    distress_settings_harness_assert(($result['autotuneEnabled'] ?? true) === false, 'manual mode should remain manual');
    distress_settings_harness_assert((int)($result['concurrencyValue'] ?? 0) === 4096, 'manual mode should fall back to saved config concurrency');
}

$tests = [
    'missing_mode_defaults_to_manual' => 'distress_settings_test_missing_mode_defaults_to_manual',
    'empty_concurrency_uses_manual_config_default' => 'distress_settings_test_empty_concurrency_uses_manual_config_default',
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
