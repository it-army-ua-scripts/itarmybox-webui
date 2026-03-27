<?php

function normalizeAndValidateMhddosPostParams(array $params): array
{
    $normalized = $params;

    $copiesRaw = (string)($params['copies'] ?? '');
    if ($copiesRaw === '') {
        $normalized['copies'] = 'auto';
    } else {
        if ($copiesRaw !== trim($copiesRaw) || preg_match('/^(?:auto|\d+)$/i', $copiesRaw) !== 1) {
            return ['ok' => false, 'error' => 'invalid_copies'];
        }
        $normalized['copies'] = strtolower($copiesRaw);
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

    $threadsRaw = (string)($params['threads'] ?? '');
    if ($threadsRaw === '') {
        $normalized['threads'] = '6500';
    } else {
        if ($threadsRaw !== trim($threadsRaw) || preg_match('/^\d+$/', $threadsRaw) !== 1) {
            return ['ok' => false, 'error' => 'invalid_threads'];
        }
        $normalized['threads'] = $threadsRaw;
    }

    foreach (['lang', 'proxies', 'ifaces'] as $optionalKey) {
        if (!array_key_exists($optionalKey, $normalized)) {
            $normalized[$optionalKey] = '';
        }
    }

    return ['ok' => true, 'params' => $normalized];
}
