<?php

if (!function_exists('root_helper_request')) {
    require_once __DIR__ . '/root_helper_client.php';
}

const DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY = 2048;
const DISTRESS_MANUAL_DEFAULT_CONCURRENCY = 4096;
const DISTRESS_MAX_CONCURRENCY = 30720;

function getDistressSavedConfig(): array
{
    $config = require __DIR__ . '/../config/config.php';
    $response = root_helper_request([
        'action' => 'distress_config_get',
        'modules' => $config['daemonNames'],
    ]);

    if (($response['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'mode' => 'manual',
            'manualConcurrency' => DISTRESS_MANUAL_DEFAULT_CONCURRENCY,
            'params' => [],
        ];
    }

    return [
        'ok' => true,
        'mode' => isset($response['mode']) && is_string($response['mode']) ? $response['mode'] : 'manual',
        'manualConcurrency' => isset($response['manualConcurrency']) && is_numeric($response['manualConcurrency'])
            ? (int)$response['manualConcurrency']
            : DISTRESS_MANUAL_DEFAULT_CONCURRENCY,
        'params' => isset($response['params']) && is_array($response['params']) ? $response['params'] : [],
    ];
}

function normalizeDistressPostParams(array $params): array
{
    $useMyIp = (int)($params['use-my-ip'] ?? 0);
    if ($useMyIp <= 0) {
        $params['enable-icmp-flood'] = '';
        $params['enable-packet-flood'] = '';
        $params['disable-udp-flood'] = '';
        $params['udp-packet-size'] = '';
        $params['direct-udp-mixed-flood-packets-per-conn'] = '';
        return $params;
    }

    $disableUdpFlood = (string)($params['disable-udp-flood'] ?? '0');
    if ($disableUdpFlood === '1') {
        $params['udp-packet-size'] = '';
        $params['direct-udp-mixed-flood-packets-per-conn'] = '';
    }
    return $params;
}

function normalizeAndValidateDistressPostParams(array $params): array
{
    $normalized = $params;
    $savedConfig = getDistressSavedConfig();
    $savedManualConcurrency = ($savedConfig['ok'] ?? false) === true
        ? (int)($savedConfig['manualConcurrency'] ?? DISTRESS_MANUAL_DEFAULT_CONCURRENCY)
        : DISTRESS_MANUAL_DEFAULT_CONCURRENCY;

    $concurrencyModeRaw = strtolower(trim((string)($params['distress-concurrency-mode'] ?? 'manual')));
    if (!in_array($concurrencyModeRaw, ['auto', 'manual'], true)) {
        return ['ok' => false, 'error' => 'invalid_concurrency_mode'];
    }

    $useMyIpRaw = (string)($params['use-my-ip'] ?? '');
    if ($useMyIpRaw === '') {
        $normalized['use-my-ip'] = '';
    } else {
        if ($useMyIpRaw !== trim($useMyIpRaw) || preg_match('/^\d+$/', $useMyIpRaw) !== 1) {
            return ['ok' => false, 'error' => 'invalid_use_my_ip_digits'];
        }
        $useMyIp = (int)$useMyIpRaw;
        if ($useMyIp < 0 || $useMyIp > 100) {
            return ['ok' => false, 'error' => 'invalid_use_my_ip_range'];
        }
        $normalized['use-my-ip'] = (string)$useMyIp;
    }

    $useTorRaw = (string)($params['use-tor'] ?? '');
    if ($useTorRaw === '') {
        $normalized['use-tor'] = '';
    } else {
        if ($useTorRaw !== trim($useTorRaw) || preg_match('/^\d+$/', $useTorRaw) !== 1) {
            return ['ok' => false, 'error' => 'invalid_use_tor_digits'];
        }
        $useTor = (int)$useTorRaw;
        if ($useTor < 0 || $useTor > 100) {
            return ['ok' => false, 'error' => 'invalid_use_tor_range'];
        }
        $normalized['use-tor'] = (string)$useTor;
    }

    $concurrencyRaw = (string)($params['concurrency'] ?? '');
    $parsedConcurrency = null;
    if ($concurrencyRaw !== '') {
        if ($concurrencyRaw !== trim($concurrencyRaw) || preg_match('/^\d+$/', $concurrencyRaw) !== 1) {
            return ['ok' => false, 'error' => 'invalid_concurrency'];
        }
        $parsedConcurrency = (int)$concurrencyRaw;
        if ($parsedConcurrency < 64 || $parsedConcurrency > DISTRESS_MAX_CONCURRENCY) {
            return ['ok' => false, 'error' => 'invalid_concurrency'];
        }
    }
    $manualConcurrency = $parsedConcurrency ?? $savedManualConcurrency;
    $autotuneSettings = getDistressAutotuneSettings();
    $effectiveConcurrency = $manualConcurrency;
    if ($concurrencyModeRaw === 'auto') {
        $effectiveConcurrency = (($autotuneSettings['ok'] ?? false) === true && ($autotuneSettings['enabled'] ?? false) === true)
            ? (int)($autotuneSettings['currentConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY)
            : $manualConcurrency;
    }
    $normalized['concurrency'] = (string)$manualConcurrency;

    foreach ([
        'enable-icmp-flood',
        'enable-packet-flood',
        'disable-udp-flood',
        'udp-packet-size',
        'direct-udp-mixed-flood-packets-per-conn',
        'proxies-path',
        'interface',
    ] as $key) {
        if (!array_key_exists($key, $normalized)) {
            $normalized[$key] = '';
        }
    }

    $normalized = normalizeDistressPostParams($normalized);
    return [
        'ok' => true,
        'params' => $normalized,
        'autotuneEnabled' => ($concurrencyModeRaw === 'auto'),
        'manualConcurrencyValue' => $manualConcurrency,
        'effectiveConcurrencyValue' => $effectiveConcurrency,
    ];
}

function getDistressAutotuneSettings(): array
{
    $config = require __DIR__ . '/../config/config.php';
    $response = root_helper_request([
        'action' => 'distress_autotune_get',
        'modules' => $config['daemonNames'],
    ]);

    if (($response['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'enabled' => false,
            'desiredConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
            'configConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
            'liveAppliedConcurrency' => null,
            'currentConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
            'defaultConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
            'uploadCapMbps' => null,
            'uploadCapMeasuredAt' => null,
            'uploadCapStatus' => 'idle',
            'uploadCapProgressPercent' => 0,
            'uploadCapProgressAttempt' => null,
            'uploadCapProgressTotal' => 3,
            'uploadCapProgressPhase' => 'idle',
            'uploadCapMeasureCooldownRemaining' => 0,
            'uploadCapBlockedByActiveModules' => [],
            'uploadCapLastError' => null,
            'uploadCapLastMethod' => null,
        ];
    }

    return [
        'ok' => true,
        'enabled' => ($response['enabled'] ?? false) === true,
        'serviceActive' => ($response['serviceActive'] ?? false) === true,
        'desiredConcurrency' => (int)($response['desiredConcurrency'] ?? $response['currentConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY),
        'configConcurrency' => (int)($response['configConcurrency'] ?? $response['currentConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY),
        'liveAppliedConcurrency' => isset($response['liveAppliedConcurrency']) && is_numeric($response['liveAppliedConcurrency'])
            ? (int)$response['liveAppliedConcurrency']
            : null,
        'currentConcurrency' => (int)($response['currentConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY),
        'defaultConcurrency' => (int)($response['defaultConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY),
        'cooldownSeconds' => (int)($response['cooldownSeconds'] ?? 300),
        'cooldownRemaining' => (int)($response['cooldownRemaining'] ?? 0),
        'statusKey' => (string)($response['statusKey'] ?? 'distress_autotune_status_active'),
        'targetLoad' => (float)($response['targetLoad'] ?? 1.0),
        'cpuCount' => (int)($response['cpuCount'] ?? 1),
        'cpuEffectiveCapacity' => isset($response['cpuEffectiveCapacity']) && is_numeric($response['cpuEffectiveCapacity'])
            ? (float)$response['cpuEffectiveCapacity']
            : 1.0,
        'systemCpuReserve' => isset($response['systemCpuReserve']) && is_numeric($response['systemCpuReserve'])
            ? (float)$response['systemCpuReserve']
            : 1.0,
        'minFreeRamPercent' => (float)($response['minFreeRamPercent'] ?? 10.0),
        'lastLoadAverage' => isset($response['lastLoadAverage']) && is_numeric($response['lastLoadAverage'])
            ? (float)$response['lastLoadAverage']
            : null,
        'lastRamFreePercent' => isset($response['lastRamFreePercent']) && is_numeric($response['lastRamFreePercent'])
            ? (float)$response['lastRamFreePercent']
            : null,
        'lastBpsMbps' => isset($response['lastBpsMbps']) && is_numeric($response['lastBpsMbps'])
            ? (float)$response['lastBpsMbps']
            : null,
        'bestBpsMbps' => isset($response['bestBpsMbps']) && is_numeric($response['bestBpsMbps'])
            ? (float)$response['bestBpsMbps']
            : null,
        'bestBpsConcurrency' => isset($response['bestBpsConcurrency']) && is_numeric($response['bestBpsConcurrency'])
            ? (int)$response['bestBpsConcurrency']
            : null,
        'uploadCapMbps' => isset($response['uploadCapMbps']) && is_numeric($response['uploadCapMbps'])
            ? (float)$response['uploadCapMbps']
            : null,
        'uploadCapMeasuredAt' => isset($response['uploadCapMeasuredAt']) && is_numeric($response['uploadCapMeasuredAt'])
            ? (int)$response['uploadCapMeasuredAt']
            : null,
        'uploadCapStatus' => isset($response['uploadCapStatus']) && is_string($response['uploadCapStatus'])
            ? $response['uploadCapStatus']
            : 'idle',
        'uploadCapProgressPercent' => isset($response['uploadCapProgressPercent']) && is_numeric($response['uploadCapProgressPercent'])
            ? max(0, min(100, (int)$response['uploadCapProgressPercent']))
            : 0,
        'uploadCapProgressAttempt' => isset($response['uploadCapProgressAttempt']) && is_numeric($response['uploadCapProgressAttempt']) && (int)$response['uploadCapProgressAttempt'] > 0
            ? (int)$response['uploadCapProgressAttempt']
            : null,
        'uploadCapProgressTotal' => isset($response['uploadCapProgressTotal']) && is_numeric($response['uploadCapProgressTotal']) && (int)$response['uploadCapProgressTotal'] > 0
            ? (int)$response['uploadCapProgressTotal']
            : 3,
        'uploadCapProgressPhase' => isset($response['uploadCapProgressPhase']) && is_string($response['uploadCapProgressPhase'])
            ? $response['uploadCapProgressPhase']
            : 'idle',
        'uploadCapMeasureCooldownRemaining' => isset($response['uploadCapMeasureCooldownRemaining']) && is_numeric($response['uploadCapMeasureCooldownRemaining'])
            ? max(0, (int)$response['uploadCapMeasureCooldownRemaining'])
            : 0,
        'uploadCapBlockedByActiveModules' => isset($response['uploadCapBlockedByActiveModules']) && is_array($response['uploadCapBlockedByActiveModules'])
            ? array_values(array_filter($response['uploadCapBlockedByActiveModules'], 'is_string'))
            : [],
        'uploadCapLastError' => isset($response['uploadCapLastError']) && is_string($response['uploadCapLastError'])
            ? $response['uploadCapLastError']
            : null,
        'uploadCapLastMethod' => isset($response['uploadCapLastMethod']) && is_string($response['uploadCapLastMethod'])
            ? $response['uploadCapLastMethod']
            : null,
        'lastTargetCount' => isset($response['lastTargetCount']) && is_numeric($response['lastTargetCount'])
            ? max(0, (int)$response['lastTargetCount'])
            : null,
    ];
}

function saveDistressAutotuneSettings(bool $enabled, int $concurrency): bool
{
    $config = require __DIR__ . '/../config/config.php';
    $response = root_helper_request([
        'action' => 'distress_autotune_set',
        'modules' => $config['daemonNames'],
        'enabled' => $enabled,
        'concurrency' => $concurrency,
    ]);

    return ($response['ok'] ?? false) === true;
}

function saveDistressSettings(array $params, bool $enabled, int $effectiveConcurrency, int $manualConcurrency): array
{
    $config = require __DIR__ . '/../config/config.php';
    return root_helper_request([
        'action' => 'distress_settings_set',
        'modules' => $config['daemonNames'],
        'params' => $params,
        'enabled' => $enabled,
        'concurrency' => $effectiveConcurrency,
        'manualConcurrency' => $manualConcurrency,
    ]);
}

function measureDistressUploadCap(): array
{
    $config = require __DIR__ . '/../config/config.php';
    return root_helper_request([
        'action' => 'distress_upload_cap_measure',
        'modules' => $config['daemonNames'],
    ]);
}
