<?php

declare(strict_types=1);

function root_helper_request(array $payload): array
{
    $GLOBALS['distressSettingsHarness']['requests'][] = $payload;

    return match ((string)($payload['action'] ?? '')) {
        'distress_autotune_get' => $GLOBALS['distressSettingsHarness']['autotuneResponse'] ?? ['ok' => false],
        'distress_config_get' => $GLOBALS['distressSettingsHarness']['configResponse'] ?? ['ok' => false],
        'distress_settings_set' => $GLOBALS['distressSettingsHarness']['saveResponse'] ?? ['ok' => false],
        'service_restart' => ['ok' => true],
        default => ['ok' => false],
    };
}

require_once __DIR__ . '/../lib/tool_page_helpers.php';

function distress_settings_harness_reset(): void
{
    $GLOBALS['distressSettingsHarness'] = [
        'requests' => [],
        'autotuneResponse' => [
            'ok' => true,
            'enabled' => false,
            'currentConcurrency' => 4096,
            'configConcurrency' => 4096,
        ],
        'configResponse' => [
            'ok' => true,
            'mode' => 'manual',
            'manualConcurrency' => 4096,
            'params' => [
                'use-my-ip' => '',
                'use-tor' => '',
                'enable-icmp-flood' => '',
                'enable-packet-flood' => '',
                'disable-udp-flood' => '',
                'udp-packet-size' => '',
                'direct-udp-mixed-flood-packets-per-conn' => '',
                'proxies-path' => '',
            ],
        ],
        'saveResponse' => ['ok' => true],
    ];
}

function distress_settings_harness_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function distress_settings_last_request(string $action): ?array
{
    $requests = $GLOBALS['distressSettingsHarness']['requests'] ?? [];
    for ($idx = count($requests) - 1; $idx >= 0; $idx--) {
        if ((string)($requests[$idx]['action'] ?? '') === $action) {
            return $requests[$idx];
        }
    }

    return null;
}

function distress_settings_test_missing_mode_defaults_to_manual(): void
{
    distress_settings_harness_reset();
    $result = normalizeAndValidateDistressPostParams([
        'concurrency' => '6144',
    ]);

    distress_settings_harness_assert(($result['ok'] ?? false) === true, 'validation should succeed');
    distress_settings_harness_assert(($result['autotuneEnabled'] ?? true) === false, 'missing mode should default to manual');
    distress_settings_harness_assert((int)($result['manualConcurrencyValue'] ?? 0) === 6144, 'manual concurrency should be preserved');
    distress_settings_harness_assert((int)($result['effectiveConcurrencyValue'] ?? 0) === 6144, 'effective concurrency should match manual mode');
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
    distress_settings_harness_assert((int)($result['manualConcurrencyValue'] ?? 0) === 4096, 'manual mode should fall back to saved manual concurrency');
}

function distress_settings_test_auto_mode_uses_runtime_concurrency_for_effective_save(): void
{
    distress_settings_harness_reset();
    $GLOBALS['distressSettingsHarness']['autotuneResponse'] = [
        'ok' => true,
        'enabled' => true,
        'currentConcurrency' => 8192,
        'configConcurrency' => 8192,
    ];

    $result = normalizeAndValidateDistressPostParams([
        'distress-concurrency-mode' => 'auto',
        'concurrency' => '2048',
    ]);

    distress_settings_harness_assert(($result['ok'] ?? false) === true, 'auto validation should succeed');
    distress_settings_harness_assert((int)($result['manualConcurrencyValue'] ?? 0) === 2048, 'manual fallback concurrency may still be preserved');
    distress_settings_harness_assert((int)($result['effectiveConcurrencyValue'] ?? 0) === 8192, 'auto mode should save current runtime concurrency, not stale posted concurrency');
}

function distress_settings_test_tool_handle_post_sends_structured_manual_save_payload(): void
{
    distress_settings_harness_reset();
    $config = require __DIR__ . '/../config/config.php';

    $redirect = tool_handle_post($config, 'distress', [
        'distress-concurrency-mode' => 'manual',
        'concurrency' => '6144',
        'use-tor' => '3',
        'proxies-path' => '/tmp/custom proxies.txt',
    ], false);

    distress_settings_harness_assert(($redirect['flash'] ?? '') === 'settings_saved', 'manual save should report success');
    $payload = distress_settings_last_request('distress_settings_set');
    distress_settings_harness_assert(is_array($payload), 'manual save should submit distress_settings_set');
    distress_settings_harness_assert(!array_key_exists('execStart', $payload), 'manual save should not submit raw ExecStart anymore');
    distress_settings_harness_assert((int)($payload['manualConcurrency'] ?? 0) === 6144, 'manual save should persist manualConcurrency separately');
    distress_settings_harness_assert((int)($payload['concurrency'] ?? 0) === 6144, 'manual save should use manual concurrency as effective concurrency');
    distress_settings_harness_assert((string)($payload['params']['proxies-path'] ?? '') === '/tmp/custom proxies.txt', 'manual save should preserve spaced proxies path in structured params');
}

function distress_settings_test_tool_handle_post_auto_save_ignores_stale_concurrency(): void
{
    distress_settings_harness_reset();
    $GLOBALS['distressSettingsHarness']['autotuneResponse'] = [
        'ok' => true,
        'enabled' => true,
        'currentConcurrency' => 8192,
        'configConcurrency' => 8192,
    ];
    $config = require __DIR__ . '/../config/config.php';

    $redirect = tool_handle_post($config, 'distress', [
        'distress-concurrency-mode' => 'auto',
        'concurrency' => '2048',
        'use-tor' => '5',
    ], false);

    distress_settings_harness_assert(($redirect['flash'] ?? '') === 'settings_saved', 'auto save should report success');
    $payload = distress_settings_last_request('distress_settings_set');
    distress_settings_harness_assert(is_array($payload), 'auto save should submit distress_settings_set');
    distress_settings_harness_assert((int)($payload['concurrency'] ?? 0) === 8192, 'auto save should use live autotune concurrency as effective value');
    distress_settings_harness_assert((string)($payload['params']['use-tor'] ?? '') === '5', 'auto save should still persist other params');
}

$tests = [
    'missing_mode_defaults_to_manual' => 'distress_settings_test_missing_mode_defaults_to_manual',
    'empty_concurrency_uses_manual_config_default' => 'distress_settings_test_empty_concurrency_uses_manual_config_default',
    'auto_mode_uses_runtime_concurrency_for_effective_save' => 'distress_settings_test_auto_mode_uses_runtime_concurrency_for_effective_save',
    'tool_handle_post_sends_structured_manual_save_payload' => 'distress_settings_test_tool_handle_post_sends_structured_manual_save_payload',
    'tool_handle_post_auto_save_ignores_stale_concurrency' => 'distress_settings_test_tool_handle_post_auto_save_ignores_stale_concurrency',
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
