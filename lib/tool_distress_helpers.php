<?php

require_once __DIR__ . '/root_helper_client.php';

const DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY = 2048;
const DISTRESS_MANUAL_DEFAULT_CONCURRENCY = 4096;
const DISTRESS_MAX_CONCURRENCY = 30720;

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

    $concurrencyModeRaw = strtolower(trim((string)($params['distress-concurrency-mode'] ?? 'auto')));
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
    if ($concurrencyRaw === '') {
        if ($concurrencyModeRaw === 'auto') {
            $autotuneSettings = getDistressAutotuneSettings();
            $normalized['concurrency'] = (string)(
                (($autotuneSettings['ok'] ?? false) === true && ($autotuneSettings['enabled'] ?? false) === true)
                    ? (int)($autotuneSettings['currentConcurrency'] ?? DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY)
                    : DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY
            );
        } else {
            $normalized['concurrency'] = (string)DISTRESS_MANUAL_DEFAULT_CONCURRENCY;
        }
    } else {
        if ($concurrencyRaw !== trim($concurrencyRaw) || preg_match('/^\d+$/', $concurrencyRaw) !== 1) {
            return ['ok' => false, 'error' => 'invalid_concurrency'];
        }
        $concurrency = (int)$concurrencyRaw;
        if ($concurrency < 64 || $concurrency > DISTRESS_MAX_CONCURRENCY) {
            return ['ok' => false, 'error' => 'invalid_concurrency'];
        }
        $normalized['concurrency'] = (string)$concurrency;
    }

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
        'concurrencyValue' => (int)$normalized['concurrency'],
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
            'enabled' => true,
            'desiredConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
            'configConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
            'liveAppliedConcurrency' => null,
            'currentConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
            'defaultConcurrency' => DISTRESS_AUTOTUNE_INITIAL_CONCURRENCY,
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
        'lastCpuPsiSomeAvg10' => isset($response['lastCpuPsiSomeAvg10']) && is_numeric($response['lastCpuPsiSomeAvg10'])
            ? (float)$response['lastCpuPsiSomeAvg10']
            : null,
        'lastMemoryPsiSomeAvg10' => isset($response['lastMemoryPsiSomeAvg10']) && is_numeric($response['lastMemoryPsiSomeAvg10'])
            ? (float)$response['lastMemoryPsiSomeAvg10']
            : null,
        'lastMemoryPsiFullAvg10' => isset($response['lastMemoryPsiFullAvg10']) && is_numeric($response['lastMemoryPsiFullAvg10'])
            ? (float)$response['lastMemoryPsiFullAvg10']
            : null,
        'lastIoPsiSomeAvg10' => isset($response['lastIoPsiSomeAvg10']) && is_numeric($response['lastIoPsiSomeAvg10'])
            ? (float)$response['lastIoPsiSomeAvg10']
            : null,
        'lastIoPsiFullAvg10' => isset($response['lastIoPsiFullAvg10']) && is_numeric($response['lastIoPsiFullAvg10'])
            ? (float)$response['lastIoPsiFullAvg10']
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
