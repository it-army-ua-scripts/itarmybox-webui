<?php

declare(strict_types=1);

define('DISTRESS_BPS_COLLECT_TEST_MODE', true);

require_once __DIR__ . '/../distress_bps_collect.php';

function collector_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function collector_test_active_run_is_scored_without_restart(): void
{
    $logs = implode("\n", [
        '2026-03-30 10:00:00 INFO - loaded 120 targets',
        '2026-03-30 10:00:05 INFO - started with concurrency: 2048',
        '2026-03-30 10:01:10 INFO - bps=90Mb',
        '2026-03-30 10:01:40 INFO - bps=100Mb',
        '2026-03-30 10:02:10 INFO - bps=110Mb',
    ]);

    $payload = buildDistressBpsStatePayload($logs);

    collector_assert(($payload['scoreMethod'] ?? '') === 'active_run_average', 'active run should be scored before the next restart exists');
    collector_assert((int)($payload['startedConcurrency'] ?? 0) === 2048, 'active run should preserve started concurrency');
    collector_assert((int)($payload['runStartedAt'] ?? 0) > 0, 'active run should expose run start time');
    collector_assert(($payload['runEndedAt'] ?? null) === null, 'active run should stay open-ended');
    collector_assert(abs((float)($payload['movingAverageMbps'] ?? 0.0) - 100.0) < 0.0001, 'active run average should use current-run BPS samples');
    collector_assert((int)($payload['sampleCount'] ?? 0) === 3, 'active run should report the active sample count');
    collector_assert(($payload['hasFreshSamples'] ?? false) === true, 'active run should be considered fresh');
}

$tests = [
    'active_run_is_scored_without_restart' => 'collector_test_active_run_is_scored_without_restart',
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
