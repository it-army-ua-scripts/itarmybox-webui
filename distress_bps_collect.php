<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/root_helper_client.php';

const DISTRESS_BPS_STATE_FILE = __DIR__ . '/var/state/distress-bps.json';
const DISTRESS_BPS_SAMPLE_LIMIT = 6;
const DISTRESS_BPS_LOG_LINES = 240;
const DISTRESS_BPS_STALE_AFTER_SECONDS = 900;
const DISTRESS_BPS_MIN_SAMPLES = 3;

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

function readDistressLogs(int $lines): string
{
    $config = require __DIR__ . '/config/config.php';
    $response = root_helper_request([
        'action' => 'service_logs',
        'modules' => $config['daemonNames'],
        'module' => 'distress',
        'lines' => $lines,
    ]);

    if (($response['ok'] ?? false) !== true) {
        return '';
    }

    return (string)($response['logs'] ?? '');
}

function buildDistressBpsStatePayload(string $logs): array
{
    $samples = [];
    $latestTargetCount = null;
    $latestTargetCountAt = null;
    $cycleStartedAt = null;
    $cycleId = null;

    $lines = preg_split('/\r\n|\r|\n/', trim($logs));
    if (!is_array($lines)) {
        $lines = [];
    }

    foreach ($lines as $line) {
        $timestamp = parseDistressLogTimestamp((string)$line);

        $targetCount = parseDistressLoadedTargetCountFromLogLine((string)$line);
        if ($targetCount !== null) {
            $latestTargetCount = $targetCount;
            $latestTargetCountAt = $timestamp;
            $cycleStartedAt = $timestamp;
            $cycleId = $timestamp !== null
                ? ('targets:' . $targetCount . '@' . $timestamp)
                : ('targets:' . $targetCount);
            $samples = [];
        }

        $bpsMbps = parseDistressBpsMbpsFromLogLine((string)$line);
        if ($bpsMbps === null || $timestamp === null) {
            continue;
        }

        if ($cycleStartedAt !== null && $timestamp < $cycleStartedAt) {
            continue;
        }

        $samples[] = [
            'capturedAt' => $timestamp,
            'bpsMbps' => $bpsMbps,
        ];
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

    return [
        'updatedAt' => time(),
        'staleAfterSeconds' => DISTRESS_BPS_STALE_AFTER_SECONDS,
        'minSamples' => DISTRESS_BPS_MIN_SAMPLES,
        'sampleLimit' => DISTRESS_BPS_SAMPLE_LIMIT,
        'sampleCount' => $sampleCount,
        'movingAverageMbps' => $movingAverageMbps,
        'latestBpsMbps' => $latestBpsMbps,
        'latestSampleAt' => $latestSampleAt,
        'latestTargetCount' => $latestTargetCount,
        'latestTargetCountAt' => $latestTargetCountAt,
        'cycleId' => $cycleId,
        'cycleStartedAt' => $cycleStartedAt,
        'hasFreshSamples' => $sampleCount > 0 && $latestSampleAt !== null,
        'samples' => $samples,
    ];
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

$logs = readDistressLogs(DISTRESS_BPS_LOG_LINES);
$payload = buildDistressBpsStatePayload($logs);

exit(writeDistressBpsState($payload) ? 0 : 1);
