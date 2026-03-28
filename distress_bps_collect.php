<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/root_helper_client.php';

const DISTRESS_BPS_STATE_FILE = __DIR__ . '/var/state/distress-bps.json';
const DISTRESS_BPS_DEBUG_LOG_FILE = __DIR__ . '/var/log/distress-bps-collector-debug.log';
const DISTRESS_BPS_LOG_FILE = '/var/log/adss.log';
const DISTRESS_BPS_SAMPLE_LIMIT = 24;
const DISTRESS_BPS_LOG_FALLBACK_LINES = 4000;
const DISTRESS_BPS_LOG_READ_BYTES = 4194304;
const DISTRESS_BPS_STALE_AFTER_SECONDS = 900;
const DISTRESS_BPS_MIN_SAMPLES = 3;
const DISTRESS_BPS_WARMUP_AFTER_START_SECONDS = 60;
const DISTRESS_BPS_RUN_CORE_MAX_SECONDS = 240;
const DISTRESS_BPS_RUN_TAIL_IGNORE_SECONDS = 60;

function writeDistressBpsDebugLog(string $event, array $context = []): void
{
    $dir = dirname(DISTRESS_BPS_DEBUG_LOG_FILE);
    if (!is_dir($dir) && !(@mkdir($dir, 0775, true) || is_dir($dir))) {
        return;
    }

    $parts = ['event=' . $event];
    foreach ($context as $key => $value) {
        if (is_bool($value)) {
            $normalized = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $normalized = 'null';
        } elseif (is_scalar($value)) {
            $normalized = (string)$value;
        } else {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES);
            $normalized = is_string($json) ? $json : 'unserializable';
        }
        $parts[] = $key . '=' . $normalized;
    }

    @file_put_contents(
        DISTRESS_BPS_DEBUG_LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] ' . implode(' ', $parts) . "\n",
        FILE_APPEND
    );
}

function isLikelyDistressLogLine(string $line): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+INFO\s+-\s+/', $line) === 1;
}

function ensureDistressBpsStateDirectory(): bool
{
    $dir = dirname(DISTRESS_BPS_STATE_FILE);
    return is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir);
}

function parseDistressBpsUnitMultiplier(string $unit): ?float
{
    $normalized = strtolower(trim($unit));
    $map = [
        'kb' => 0.001,
        'mb' => 1.0,
        'gb' => 1000.0,
        'tb' => 1000000.0,
    ];

    return $map[$normalized] ?? null;
}

function parseDistressBpsMbpsFromLogLine(string $line): ?float
{
    if (!isLikelyDistressLogLine($line)) {
        return null;
    }

    if (preg_match('/\bbps=([0-9]+(?:\.[0-9]+)?)([KMGT]?b)\b/i', $line, $matches) !== 1) {
        return null;
    }

    $value = (float)$matches[1];
    $multiplier = parseDistressBpsUnitMultiplier((string)$matches[2]);
    if ($value < 0.0 || $multiplier === null) {
        return null;
    }

    return $value * $multiplier;
}

function parseDistressLoadedTargetCountFromLogLine(string $line): ?int
{
    if (!isLikelyDistressLogLine($line)) {
        return null;
    }

    if (preg_match('/\bloaded\s+(\d+)\s+targets\b/i', $line, $matches) !== 1) {
        return null;
    }

    return max(0, (int)$matches[1]);
}

function parseDistressStartedConcurrencyFromLogLine(string $line): ?int
{
    if (!isLikelyDistressLogLine($line)) {
        return null;
    }

    if (preg_match('/\bstarted with concurrency:\s*(\d+)\b/i', $line, $matches) !== 1) {
        return null;
    }

    return max(0, (int)$matches[1]);
}

function parseDistressLogTimestamp(string $line): ?int
{
    if (!isLikelyDistressLogLine($line)) {
        return null;
    }

    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\b/', $line, $matches) !== 1) {
        return null;
    }

    $timestamp = strtotime((string)$matches[1]);
    return $timestamp === false ? null : (int)$timestamp;
}

function readTailBytesFromFile(string $path, int $bytes): string
{
    if ($bytes <= 0 || !is_readable($path)) {
        return '';
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return '';
    }

    if (@fseek($handle, 0, SEEK_END) !== 0) {
        fclose($handle);
        return '';
    }

    $fileSize = @ftell($handle);
    if (!is_int($fileSize) || $fileSize < 0) {
        fclose($handle);
        return '';
    }

    $offset = max(0, $fileSize - $bytes);
    if (@fseek($handle, $offset, SEEK_SET) !== 0) {
        fclose($handle);
        return '';
    }

    $content = stream_get_contents($handle);
    fclose($handle);

    if (!is_string($content)) {
        return '';
    }

    if ($offset > 0) {
        $newlinePos = strpos($content, "\n");
        if ($newlinePos !== false) {
            $content = substr($content, $newlinePos + 1);
        }
    }

    return $content;
}

function readDistressLogs(int $fallbackLines): string
{
    $fileLogs = readTailBytesFromFile(DISTRESS_BPS_LOG_FILE, DISTRESS_BPS_LOG_READ_BYTES);
    if (trim($fileLogs) !== '') {
        writeDistressBpsDebugLog('collector_log_source', [
            'source' => 'file',
            'path' => DISTRESS_BPS_LOG_FILE,
            'readBytes' => DISTRESS_BPS_LOG_READ_BYTES,
        ]);
        return $fileLogs;
    }

    $config = require __DIR__ . '/config/config.php';
    $response = root_helper_request([
        'action' => 'service_logs',
        'modules' => $config['daemonNames'],
        'module' => 'distress',
        'lines' => $fallbackLines,
    ]);

    if (($response['ok'] ?? false) !== true) {
        writeDistressBpsDebugLog('collector_log_source', [
            'source' => 'fallback_failed',
            'fallbackLines' => $fallbackLines,
        ]);
        return '';
    }

    writeDistressBpsDebugLog('collector_log_source', [
        'source' => 'root_helper_fallback',
        'fallbackLines' => $fallbackLines,
    ]);
    return (string)($response['logs'] ?? '');
}

function createDistressRun(
    int $startedAt,
    int $startedConcurrency,
    ?int $targetCount,
    ?int $targetCountAt
): array {
    return [
        'startedAt' => $startedAt,
        'endedAt' => null,
        'startedConcurrency' => $startedConcurrency,
        'targetCount' => $targetCount,
        'targetCountAt' => $targetCountAt,
        'cycleId' => $targetCount !== null ? 'targets:' . $targetCount : null,
        'samples' => [],
    ];
}

function finalizeDistressRun(array &$runs, ?array $run, ?int $endedAt): void
{
    if ($run === null) {
        return;
    }

    if ($endedAt !== null && $endedAt > (int)$run['startedAt']) {
        $run['endedAt'] = $endedAt;
    }

    $runs[] = $run;
}

function scoreDistressRun(array $run): ?array
{
    $startedAt = isset($run['startedAt']) && is_numeric($run['startedAt']) ? (int)$run['startedAt'] : null;
    $endedAt = isset($run['endedAt']) && is_numeric($run['endedAt']) ? (int)$run['endedAt'] : null;
    $samples = isset($run['samples']) && is_array($run['samples']) ? $run['samples'] : [];
    if ($startedAt === null || $endedAt === null || $endedAt <= $startedAt || $samples === []) {
        return null;
    }

    $durationSeconds = $endedAt - $startedAt;
    $scoreWindowStartAt = $startedAt + DISTRESS_BPS_WARMUP_AFTER_START_SECONDS;
    $scoreWindowEndOffset = min(
        DISTRESS_BPS_RUN_CORE_MAX_SECONDS,
        $durationSeconds - DISTRESS_BPS_RUN_TAIL_IGNORE_SECONDS
    );
    if ($scoreWindowEndOffset <= DISTRESS_BPS_WARMUP_AFTER_START_SECONDS) {
        return null;
    }

    $scoreWindowEndAt = $startedAt + $scoreWindowEndOffset;
    $scoredSamples = array_values(array_filter(
        $samples,
        static fn(array $sample): bool => isset($sample['capturedAt'], $sample['bpsMbps'])
            && is_numeric($sample['capturedAt'])
            && is_numeric($sample['bpsMbps'])
            && (int)$sample['capturedAt'] >= $scoreWindowStartAt
            && (int)$sample['capturedAt'] < $scoreWindowEndAt
    ));

    if (count($scoredSamples) < DISTRESS_BPS_MIN_SAMPLES) {
        return null;
    }

    $sum = 0.0;
    foreach ($scoredSamples as $sample) {
        $sum += (float)$sample['bpsMbps'];
    }

    $latestSample = $scoredSamples[count($scoredSamples) - 1];
    return [
        'movingAverageMbps' => $sum / count($scoredSamples),
        'latestBpsMbps' => (float)$latestSample['bpsMbps'],
        'latestSampleAt' => (int)$latestSample['capturedAt'],
        'sampleCount' => count($scoredSamples),
        'samples' => $scoredSamples,
        'scoreMethod' => 'completed_run_core_average',
        'scoreWindowStartedAt' => $scoreWindowStartAt,
        'scoreWindowEndedAt' => $scoreWindowEndAt,
        'scoredRunStartedAt' => $startedAt,
        'scoredRunEndedAt' => $endedAt,
        'scoredRunDurationSeconds' => $durationSeconds,
        'startedConcurrency' => isset($run['startedConcurrency']) && is_numeric($run['startedConcurrency'])
            ? (int)$run['startedConcurrency']
            : null,
        'targetCount' => isset($run['targetCount']) && is_numeric($run['targetCount'])
            ? (int)$run['targetCount']
            : null,
        'targetCountAt' => isset($run['targetCountAt']) && is_numeric($run['targetCountAt'])
            ? (int)$run['targetCountAt']
            : null,
        'cycleId' => isset($run['cycleId']) && is_string($run['cycleId']) ? $run['cycleId'] : null,
    ];
}

function buildDistressBpsStatePayload(string $logs): array
{
    $latestTargetCount = null;
    $latestTargetCountAt = null;
    $currentRun = null;
    $runs = [];

    $lines = preg_split('/\r\n|\r|\n/', trim($logs));
    if (!is_array($lines)) {
        $lines = [];
    }

    foreach ($lines as $line) {
        $timestamp = parseDistressLogTimestamp((string)$line);

        $startedConcurrencyCandidate = parseDistressStartedConcurrencyFromLogLine((string)$line);
        if ($startedConcurrencyCandidate !== null && $timestamp !== null) {
            finalizeDistressRun($runs, $currentRun, $timestamp);
            $currentRun = createDistressRun(
                $timestamp,
                $startedConcurrencyCandidate,
                $latestTargetCount,
                $latestTargetCountAt
            );
        }

        $targetCount = parseDistressLoadedTargetCountFromLogLine((string)$line);
        if ($targetCount !== null) {
            $latestTargetCount = $targetCount;
            $latestTargetCountAt = $timestamp;
        }

        $bpsMbps = parseDistressBpsMbpsFromLogLine((string)$line);
        if ($bpsMbps === null || $timestamp === null) {
            continue;
        }

        if ($currentRun === null) {
            continue;
        }
        $runStartedAt = (int)$currentRun['startedAt'];
        if ($timestamp < ($runStartedAt + DISTRESS_BPS_WARMUP_AFTER_START_SECONDS)) {
            continue;
        }

        $currentRun['samples'][] = [
            'capturedAt' => $timestamp,
            'bpsMbps' => $bpsMbps,
        ];
    }

    if ($currentRun !== null) {
        finalizeDistressRun($runs, $currentRun, null);
    }

    $completedRuns = array_values(array_filter(
        $runs,
        static fn(array $run): bool => isset($run['endedAt']) && is_numeric($run['endedAt'])
    ));

    $latestScoredRun = null;
    for ($idx = count($completedRuns) - 1; $idx >= 0; $idx--) {
        $scored = scoreDistressRun($completedRuns[$idx]);
        if ($scored !== null) {
            $latestScoredRun = $scored;
            break;
        }
    }

    $sampleCount = $latestScoredRun['sampleCount'] ?? 0;
    $movingAverageMbps = $latestScoredRun['movingAverageMbps'] ?? null;
    $latestBpsMbps = $latestScoredRun['latestBpsMbps'] ?? null;
    $latestSampleAt = $latestScoredRun['latestSampleAt'] ?? null;
    $samples = $latestScoredRun['samples'] ?? [];
    if (count($samples) > DISTRESS_BPS_SAMPLE_LIMIT) {
        $samples = array_slice($samples, -DISTRESS_BPS_SAMPLE_LIMIT);
        $sampleCount = count($samples);
    }

    $payload = [
        'updatedAt' => time(),
        'staleAfterSeconds' => DISTRESS_BPS_STALE_AFTER_SECONDS,
        'minSamples' => DISTRESS_BPS_MIN_SAMPLES,
        'sampleLimit' => DISTRESS_BPS_SAMPLE_LIMIT,
        'scoreMethod' => $latestScoredRun['scoreMethod'] ?? 'completed_run_core_average',
        'runCoreMaxSeconds' => DISTRESS_BPS_RUN_CORE_MAX_SECONDS,
        'runTailIgnoreSeconds' => DISTRESS_BPS_RUN_TAIL_IGNORE_SECONDS,
        'sampleCount' => $sampleCount,
        'movingAverageMbps' => $movingAverageMbps,
        'latestBpsMbps' => $latestBpsMbps,
        'latestSampleAt' => $latestSampleAt,
        'latestTargetCount' => $latestTargetCount,
        'latestTargetCountAt' => $latestTargetCountAt,
        'cycleId' => $latestScoredRun['cycleId'] ?? ($latestTargetCount !== null ? 'targets:' . $latestTargetCount : null),
        'cycleStartedAt' => $latestScoredRun['scoredRunStartedAt'] ?? null,
        'runStartedAt' => $latestScoredRun['scoredRunStartedAt'] ?? null,
        'runEndedAt' => $latestScoredRun['scoredRunEndedAt'] ?? null,
        'runWarmupUntil' => isset($latestScoredRun['scoredRunStartedAt']) ? ((int)$latestScoredRun['scoredRunStartedAt'] + DISTRESS_BPS_WARMUP_AFTER_START_SECONDS) : null,
        'startedConcurrency' => $latestScoredRun['startedConcurrency'] ?? null,
        'scoreWindowStartedAt' => $latestScoredRun['scoreWindowStartedAt'] ?? null,
        'scoreWindowEndedAt' => $latestScoredRun['scoreWindowEndedAt'] ?? null,
        'scoredRunDurationSeconds' => $latestScoredRun['scoredRunDurationSeconds'] ?? null,
        'completedRunCount' => count($completedRuns),
        'hasFreshSamples' => $sampleCount >= DISTRESS_BPS_MIN_SAMPLES && $latestSampleAt !== null,
        'samples' => $samples,
    ];

    writeDistressBpsDebugLog('collector_payload', [
        'logLines' => count($lines),
        'completedRunCount' => count($completedRuns),
        'sampleCount' => $sampleCount,
        'movingAverageMbps' => $movingAverageMbps,
        'latestBpsMbps' => $latestBpsMbps,
        'latestSampleAt' => $latestSampleAt,
        'latestTargetCount' => $latestTargetCount,
        'cycleId' => $payload['cycleId'],
        'runStartedAt' => $payload['runStartedAt'],
        'runEndedAt' => $payload['runEndedAt'],
        'scoreWindowStartedAt' => $payload['scoreWindowStartedAt'],
        'scoreWindowEndedAt' => $payload['scoreWindowEndedAt'],
        'startedConcurrency' => $payload['startedConcurrency'],
        'scoreMethod' => $payload['scoreMethod'],
        'hasFreshSamples' => $payload['hasFreshSamples'],
    ]);

    return $payload;
}

function writeDistressBpsState(array $payload): bool
{
    if (!ensureDistressBpsStateDirectory()) {
        return false;
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    $tmpFile = DISTRESS_BPS_STATE_FILE . '.tmp';
    if (@file_put_contents($tmpFile, $json) === false) {
        return false;
    }

    return @rename($tmpFile, DISTRESS_BPS_STATE_FILE);
}

$logs = readDistressLogs(DISTRESS_BPS_LOG_FALLBACK_LINES);
$payload = buildDistressBpsStatePayload($logs);

$ok = writeDistressBpsState($payload);
writeDistressBpsDebugLog('collector_write', [
    'ok' => $ok,
    'stateFile' => DISTRESS_BPS_STATE_FILE,
]);

exit($ok ? 0 : 1);
