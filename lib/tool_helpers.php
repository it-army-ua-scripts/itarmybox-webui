<?php
require_once __DIR__ . '/root_helper_client.php';

function getServiceLogs(string $serviceName): string
{
    $config = require __DIR__ . '/../config/config.php';
    $response = root_helper_request([
        'action' => 'service_logs',
        'modules' => $config['daemonNames'],
        'module' => $serviceName,
        'lines' => 5,
    ]);
    $logs = (string)($response['logs'] ?? '');
    if (trim($logs) === '') {
        $logs = "No journal entries available for this service.";
    }
    return preg_replace('/^[ \t]+/m', '', $logs) ?? $logs;
}

function getConfigStringFromServiceFile(string $serviceName): string
{
    $config = require __DIR__ . '/../config/config.php';
    $response = root_helper_request([
        'action' => 'service_execstart_get',
        'modules' => $config['daemonNames'],
        'module' => $serviceName,
    ]);
    if (($response['ok'] ?? false) !== true) {
        return '';
    }
    return (string)($response['execStart'] ?? '');
}

function getCurrentAdjustableParams(string $configString, array $adjustableParams, string $daemonName): array
{
    $configAsArray = str_getcsv($configString, ' ');
    $currentAdjustableParams = [];
    $aliases = [
        'ifaces' => ['bind'],
        'bind' => ['ifaces'],
        'disable-udp-flood' => ['direct-udp-failover'],
        'direct-udp-failover' => ['disable-udp-flood']
    ];
    $flagOnlyByDaemon = [
        'distress' => ['enable-icmp-flood', 'enable-packet-flood', 'disable-udp-flood']
    ];
    $flagOnly = array_flip($flagOnlyByDaemon[$daemonName] ?? []);
    $options = [];
    for ($i = 1; $i < count($configAsArray); $i++) {
        $token = $configAsArray[$i];
        if (!str_starts_with($token, '--')) {
            continue;
        }
        $key = substr($token, 2);
        $next = $configAsArray[$i + 1] ?? null;
        if ($next !== null && !str_starts_with($next, '--')) {
            $options[$key] = $next;
            $i++;
        } else {
            $options[$key] = true;
        }
    }

    foreach ($adjustableParams as $adjustableParam) {
        $candidateKeys = array_merge([$adjustableParam], $aliases[$adjustableParam] ?? []);
        foreach ($candidateKeys as $candidateKey) {
            if (array_key_exists($candidateKey, $options)) {
                $value = $options[$candidateKey];
                if (isset($flagOnly[$adjustableParam])) {
                    $currentAdjustableParams[$adjustableParam] = ($value === true) ? '1' : (string)$value;
                } else {
                    $currentAdjustableParams[$adjustableParam] = ($value === true) ? '' : (string)$value;
                }
                break;
            }
        }
    }
    return $currentAdjustableParams;
}

function updateServiceConfigParams(string $configString, array $updatedParams, string $daemonName): array
{
    $configAsArray = str_getcsv($configString, ' ');
    $aliases = [
        'ifaces' => ['bind'],
        'bind' => ['ifaces'],
        'disable-udp-flood' => ['direct-udp-failover'],
        'direct-udp-failover' => ['disable-udp-flood']
    ];
    $flagOnlyByDaemon = [
        'distress' => ['enable-icmp-flood', 'enable-packet-flood', 'disable-udp-flood']
    ];
    $flagOnly = array_flip($flagOnlyByDaemon[$daemonName] ?? []);

    $baseTokens = [];
    $options = [];
    foreach ($configAsArray as $idx => $token) {
        if ($idx === 0) {
            $baseTokens[] = $token;
            continue;
        }
        if (!str_starts_with($token, '--')) {
            continue;
        }
        $key = substr($token, 2);
        $next = $configAsArray[$idx + 1] ?? null;
        if ($next !== null && !str_starts_with($next, '--')) {
            $options[$key] = $next;
        } else {
            $options[$key] = true;
        }
    }

    foreach ($updatedParams as $updatedParamKey => $updatedParam) {
        $updatedParam = trim((string)$updatedParam);
        $allKeys = array_merge([$updatedParamKey], $aliases[$updatedParamKey] ?? []);
        foreach ($allKeys as $optionKey) {
            unset($options[$optionKey]);
        }

        $isFlagOnly = isset($flagOnly[$updatedParamKey]);
        if ($updatedParam === '' || $updatedParam === '0') {
            continue;
        }
        $options[$updatedParamKey] = $isFlagOnly ? true : $updatedParam;
    }

    // Keep a stable source tag for module traffic accounting.
    if (in_array($daemonName, ['mhddos', 'distress'], true)) {
        $options['source'] = 'itarmybox';
    }

    $out = $baseTokens;
    foreach ($options as $key => $value) {
        $out[] = '--' . $key;
        if ($value !== true) {
            $out[] = $value;
        }
    }
    return $out;
}

function updateServiceFile(string $serviceName, array $updatedConfigParams): bool
{
    $config = require __DIR__ . '/../config/config.php';
    $response = root_helper_request([
        'action' => 'service_execstart_set',
        'modules' => $config['daemonNames'],
        'module' => $serviceName,
        'execStart' => implode(' ', $updatedConfigParams),
    ]);
    return ($response['ok'] ?? false) === true;
}

function setX100ConfigValues(array $updatedConfig): bool
{
    $allowed = ['itArmyUserId', 'initialDistressScale', 'ignoreBundledFreeVpn'];
    $config = require __DIR__ . '/../config/config.php';
    $readResponse = root_helper_request([
        'action' => 'x100_config_get',
        'modules' => $config['daemonNames'],
    ]);
    if (($readResponse['ok'] ?? false) !== true) {
        return false;
    }
    $content = (string)($readResponse['content'] ?? '');
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $updatedConfig)) {
            continue;
        }
        $value = trim((string)$updatedConfig[$key]);
        if ($key === 'ignoreBundledFreeVpn' && $value === '') {
            $value = '0';
        }
        $pattern = '/^' . preg_quote($key, '/') . '=.*/m';
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $key . '=' . $value, $content, 1);
        } else {
            $content .= PHP_EOL . $key . '=' . $value;
        }
    }
    $writeResponse = root_helper_request([
        'action' => 'x100_config_set',
        'modules' => $config['daemonNames'],
        'content' => $content,
    ]);
    return ($writeResponse['ok'] ?? false) === true;
}

function getX100ConfigValues(): array
{
    $result = [
        'itArmyUserId' => '',
        'initialDistressScale' => '',
        'ignoreBundledFreeVpn' => '0'
    ];
    $config = require __DIR__ . '/../config/config.php';
    $response = root_helper_request([
        'action' => 'x100_config_get',
        'modules' => $config['daemonNames'],
    ]);
    if (($response['ok'] ?? false) !== true) {
        return $result;
    }
    $content = (string)($response['content'] ?? '');
    $lines = preg_split('/\r\n|\r|\n/', $content);
    if (!is_array($lines)) {
        return $result;
    }
    foreach ($lines as $line) {
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        if (array_key_exists($key, $result)) {
            $result[$key] = trim($value);
        }
    }
    return $result;
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

    $useMyIpRaw = (string)($params['use-my-ip'] ?? '');
    if ($useMyIpRaw === '') {
        $normalized['use-my-ip'] = '0';
    } else {
        if ($useMyIpRaw !== trim($useMyIpRaw) || preg_match('/^\d+$/', $useMyIpRaw) !== 1) {
            return ['ok' => false, 'error' => 'Invalid use-my-ip: only digits from 0 to 100, no spaces.'];
        }
        $useMyIp = (int)$useMyIpRaw;
        if ($useMyIp < 0 || $useMyIp > 100) {
            return ['ok' => false, 'error' => 'Invalid use-my-ip: value must be between 0 and 100.'];
        }
        $normalized['use-my-ip'] = (string)$useMyIp;
    }

    $useTorRaw = (string)($params['use-tor'] ?? '');
    if ($useTorRaw === '') {
        $normalized['use-tor'] = '0';
    } else {
        if ($useTorRaw !== trim($useTorRaw) || preg_match('/^\d+$/', $useTorRaw) !== 1) {
            return ['ok' => false, 'error' => 'Invalid use-tor: only digits from 0 to 100, no spaces.'];
        }
        $useTor = (int)$useTorRaw;
        if ($useTor < 0 || $useTor > 100) {
            return ['ok' => false, 'error' => 'Invalid use-tor: value must be between 0 and 100.'];
        }
        $normalized['use-tor'] = (string)$useTor;
    }

    $concurrencyRaw = (string)($params['concurrency'] ?? '');
    if ($concurrencyRaw === '') {
        $normalized['concurrency'] = '4096';
    } else {
        if ($concurrencyRaw !== trim($concurrencyRaw) || preg_match('/^\d+$/', $concurrencyRaw) !== 1) {
            return ['ok' => false, 'error' => 'Invalid concurrency: only digits, no spaces.'];
        }
        $normalized['concurrency'] = $concurrencyRaw;
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
    return ['ok' => true, 'params' => $normalized];
}

function normalizeAndValidateMhddosPostParams(array $params): array
{
    $normalized = $params;

    $copiesRaw = (string)($params['copies'] ?? '');
    if ($copiesRaw === '') {
        $normalized['copies'] = 'auto';
    } else {
        if ($copiesRaw !== trim($copiesRaw) || preg_match('/^(?:auto|\d+)$/i', $copiesRaw) !== 1) {
            return ['ok' => false, 'error' => 'Invalid copies: use auto or digits, no spaces.'];
        }
        $normalized['copies'] = strtolower($copiesRaw);
    }

    $useMyIpRaw = (string)($params['use-my-ip'] ?? '');
    if ($useMyIpRaw === '') {
        $normalized['use-my-ip'] = '0';
    } else {
        if ($useMyIpRaw !== trim($useMyIpRaw) || preg_match('/^\d+$/', $useMyIpRaw) !== 1) {
            return ['ok' => false, 'error' => 'Invalid use-my-ip: only digits from 0 to 100, no spaces.'];
        }
        $useMyIp = (int)$useMyIpRaw;
        if ($useMyIp < 0 || $useMyIp > 100) {
            return ['ok' => false, 'error' => 'Invalid use-my-ip: value must be between 0 and 100.'];
        }
        $normalized['use-my-ip'] = (string)$useMyIp;
    }

    $threadsRaw = (string)($params['threads'] ?? '');
    if ($threadsRaw === '') {
        $normalized['threads'] = '8000';
    } else {
        if ($threadsRaw !== trim($threadsRaw) || preg_match('/^\d+$/', $threadsRaw) !== 1) {
            return ['ok' => false, 'error' => 'Invalid threads: only digits, no spaces.'];
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
