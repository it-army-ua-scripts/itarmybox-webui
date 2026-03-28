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
const DISTRESS_BPS_AVERAGING_WINDOW_SECONDS = 240;

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

function buildDistressBpsStatePayload(string $logs): array
{
    $samples = [];
    $latestTargetCount = null;
    $latestTargetCountAt = null;
    $cycleStartedAt = null;
    $cycleId = null;
    $runStartedAt = null;
    $runWarmupUntil = null;
    $startedConcurrency = null;

    $lines = preg_split('/\r\n|\r|\n/', trim($logs));
    if (!is_array($lines)) {
        $lines = [];
    }

    foreach ($lines as $line) {
        $timestamp = parseDistressLogTimestamp((string)$line);

        $startedConcurrencyCandidate = parseDistressStartedConcurrencyFromLogLine((string)$line);
        if ($startedConcurrencyCandidate !== null && $timestamp !== null) {
            $runStartedAt = $timestamp;
            $runWarmupUntil = $timestamp + DISTRESS_BPS_WARMUP_AFTER_START_SECONDS;
            $startedConcurrency = $startedConcurrencyCandidate;
            $samples = [];
        }

        $targetCount = parseDistressLoadedTargetCountFromLogLine((string)$line);
        if ($targetCount !== null) {
            if ($latestTargetCount === null || $targetCount !== $latestTargetCount) {
                $cycleStartedAt = $timestamp;
                $cycleId = 'targets:' . $targetCount;
                $samples = [];
            } elseif ($cycleId === null) {
                $cycleId = 'targets:' . $targetCount;
            }

            $latestTargetCount = $targetCount;
            $latestTargetCountAt = $timestamp;
        }

        $bpsMbps = parseDistressBpsMbpsFromLogLine((string)$line);
        if ($bpsMbps === null || $timestamp === null) {
            continue;
        }

        if ($cycleStartedAt !== null && $timestamp < $cycleStartedAt) {
            continue;
        }
        if ($runWarmupUntil !== null && $timestamp < $runWarmupUntil) {
            continue;
        }

        $samples[] = [
            'capturedAt' => $timestamp,
            'bpsMbps' => $bpsMbps,
        ];
    }

    if ($samples !== []) {
        $latestSampleAt = (int)$samples[count($samples) - 1]['capturedAt'];
        $windowStartedAt = $latestSampleAt - DISTRESS_BPS_AVERAGING_WINDOW_SECONDS;
        $samples = array_values(array_filter(
            $samples,
            static fn(array $sample): bool => (int)$sample['capturedAt'] >= $windowStartedAt
        ));
    }

    if (count($samples) > DISTRESS_BPS_SAMPLE_LIMIT) {
        $samples = array_slice($samples, -DISTRESS_BPS_SAMPLE_LIMIT);
    }

    $sampleCount = count($samples);
    $movingAverageMbps = null;
    $latestBpsMbps = null;
    $latestSampleAt = null;
    if ($sampleCount > 0) {
        $sum = 0.0;
        foreach ($samples as $sample) {
            $sum += (float)$sample['bpsMbps'];
        }
        $movingAverageMbps = $sum / $sampleCount;
        $latestSample = $samples[$sampleCount - 1];
        $latestBpsMbps = (float)$latestSample['bpsMbps'];
        $latestSampleAt = (int)$latestSample['capturedAt'];
    }

    $payload = [
        'updatedAt' => time(),
        'staleAfterSeconds' => DISTRESS_BPS_STALE_AFTER_SECONDS,
        'minSamples' => DISTRESS_BPS_MIN_SAMPLES,
        'sampleLimit' => DISTRESS_BPS_SAMPLE_LIMIT,
        'averagingWindowSeconds' => DISTRESS_BPS_AVERAGING_WINDOW_SECONDS,
        'sampleCount' => $sampleCount,
        'movingAverageMbps' => $movingAverageMbps,
        'latestBpsMbps' => $latestBpsMbps,
        'latestSampleAt' => $latestSampleAt,
        'latestTargetCount' => $latestTargetCount,
        'latestTargetCountAt' => $latestTargetCountAt,
        'cycleId' => $cycleId,
        'cycleStartedAt' => $cycleStartedAt,
        'runStartedAt' => $runStartedAt,
        'runWarmupUntil' => $runWarmupUntil,
        'startedConcurrency' => $startedConcurrency,
        'hasFreshSamples' => $sampleCount > 0 && $latestSampleAt !== null,
        'samples' => $samples,
    ];

    writeDistressBpsDebugLog('collector_payload', [
        'logLines' => count($lines),
        'sampleCount' => $sampleCount,
        'averagingWindowSeconds' => DISTRESS_BPS_AVERAGING_WINDOW_SECONDS,
        'movingAverageMbps' => $movingAverageMbps,
        'latestBpsMbps' => $latestBpsMbps,
        'latestSampleAt' => $latestSampleAt,
        'latestTargetCount' => $latestTargetCount,
        'cycleId' => $cycleId,
        'cycleStartedAt' => $cycleStartedAt,
        'runStartedAt' => $runStartedAt,
        'runWarmupUntil' => $runWarmupUntil,
        'startedConcurrency' => $startedConcurrency,
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
