<?php

declare(strict_types=1);

function trafficLimitPercentToMbit(int $percent): int
{
    $percent = max(25, min(100, $percent));
    if ($percent <= 80) {
        return (int)round(20 + (($percent - 25) * (300 - 20) / (80 - 25)));
    }
    return (int)round(300 + (($percent - 80) * (750 - 300) / (100 - 80)));
}

function normalizeTrafficPercentValue($value): ?int
{
    if (is_int($value)) {
        $percent = $value;
    } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
        $percent = (int)trim($value);
    } else {
        return null;
    }

    if ($percent < 25 || $percent > 100) {
        return null;
    }

    return $percent;
}

function trafficLimitStateDefault(): array
{
    return [
        'ok' => true,
        'iface' => 'eth0',
        'percent' => trafficLimitMbitToPercent(50),
        'mbit' => 50,
    ];
}

function trafficLimitMbitToPercent(int $mbit): int
{
    $mbit = max(20, min(750, $mbit));
    if ($mbit <= 300) {
        return (int)round(25 + (($mbit - 20) * (80 - 25) / (300 - 20)));
    }
    return (int)round(80 + (($mbit - 300) * (100 - 80) / (750 - 300)));
}

function readTrafficLimitFromTc(): ?array
{
    $tc = findTcBinary();
    if ($tc === null) {
        return null;
    }
    $iface = 'eth0';
    $output = runCommand(escapeshellarg($tc) . ' qdisc show dev ' . escapeshellarg($iface), $code);
    if ($code !== 0 || trim($output) === '') {
        return null;
    }

    if (preg_match('/\brate\s+(\d+)([kmg])bit\b/i', $output, $matches) !== 1) {
        return null;
    }

    $value = (int)$matches[1];
    $unit = strtolower($matches[2]);
    $mbit = match ($unit) {
        'g' => $value * 1000,
        'm' => $value,
        'k' => max(1, (int)round($value / 1000)),
        default => $value,
    };

    return [
        'ok' => true,
        'iface' => $iface,
        'percent' => trafficLimitMbitToPercent($mbit),
        'mbit' => max(20, min(750, $mbit)),
        'source' => 'tc',
    ];
}

function readTrafficLimitStateFile(): ?array
{
    $default = trafficLimitStateDefault();
    $raw = @file_get_contents(TRAFFIC_LIMIT_STATE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $percent = normalizeTrafficPercentValue($data['percent'] ?? null);
    $iface = (string)($data['iface'] ?? $default['iface']);
    if ($iface !== 'eth0' || $percent === null) {
        return null;
    }
    return [
        'ok' => true,
        'iface' => 'eth0',
        'percent' => $percent,
        'mbit' => trafficLimitPercentToMbit($percent),
        'source' => 'state',
    ];
}

function getTrafficLimitState(): array
{
    $tcState = readTrafficLimitFromTc();
    if ($tcState !== null) {
        return $tcState;
    }

    $stateFile = readTrafficLimitStateFile();
    if ($stateFile !== null) {
        $restored = setTrafficLimit((int)$stateFile['percent']);
        if (($restored['ok'] ?? false) === true) {
            return $restored + ['source' => 'state'];
        }
        return $restored + [
            'desiredPercent' => (int)$stateFile['percent'],
            'desiredMbit' => trafficLimitPercentToMbit((int)$stateFile['percent']),
            'source' => 'state',
        ];
    }

    $default = trafficLimitStateDefault();
    $initialized = setTrafficLimit((int)$default['percent']);
    if (($initialized['ok'] ?? false) === true) {
        return $initialized + ['source' => 'default'];
    }

    return $initialized + [
        'desiredPercent' => (int)$default['percent'],
        'desiredMbit' => (int)$default['mbit'],
        'source' => 'default',
    ];
}

function setTrafficLimit(int $percent): array
{
    if ($percent < 25 || $percent > 100) {
        return ['ok' => false, 'error' => 'invalid_traffic_limit_percent'];
    }
    $tc = findTcBinary();
    if ($tc === null) {
        return ['ok' => false, 'error' => 'tc_not_found'];
    }
    $iface = 'eth0';
    $mbit = trafficLimitPercentToMbit($percent);
    $rate = $mbit . 'mbit';
    $burst = ($mbit >= 500) ? '1536kb' : (($mbit >= 200) ? '1024kb' : '384kb');
    runCommand(escapeshellarg($tc) . ' qdisc replace dev ' . escapeshellarg($iface) . ' root tbf rate ' . escapeshellarg($rate) . ' burst ' . escapeshellarg($burst) . ' latency 70ms', $code);
    if ($code !== 0) {
        return ['ok' => false, 'error' => 'traffic_limit_apply_failed'];
    }
    @file_put_contents(
        TRAFFIC_LIMIT_STATE_FILE,
        json_encode([
            'iface' => $iface,
            'percent' => $percent,
            'updated_at' => time(),
        ], JSON_UNESCAPED_SLASHES)
    );
    return [
        'ok' => true,
        'iface' => $iface,
        'percent' => $percent,
        'mbit' => $mbit,
        'source' => 'set',
    ];
}

function getTrafficLimitRollbackSnapshot(): ?array
{
    $state = readTrafficLimitFromTc();
    if ($state === null) {
        $state = readTrafficLimitStateFile();
    }

    $source = (string)($state['source'] ?? '');
    $percent = normalizeTrafficPercentValue($state['percent'] ?? null);
    if (($source !== 'tc' && $source !== 'state') || $percent === null) {
        return null;
    }

    return [
        'percent' => $percent,
        'source' => $source,
    ];
}
