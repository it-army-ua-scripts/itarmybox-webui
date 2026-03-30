<?php

require_once __DIR__ . '/root_helper_client.php';
require_once __DIR__ . '/execstart_helpers.php';

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
    $currentAdjustableParams = [];
    $aliases = [
        'ifaces' => ['bind'],
        'bind' => ['ifaces'],
        'disable-udp-flood' => ['direct-udp-failover'],
        'direct-udp-failover' => ['disable-udp-flood'],
    ];
    $flagOnlyByDaemon = [
        'distress' => ['enable-icmp-flood', 'enable-packet-flood', 'disable-udp-flood'],
    ];
    $flagOnly = array_flip($flagOnlyByDaemon[$daemonName] ?? []);
    $components = parseExecStartComponents($configString);
    $options = is_array($components['options'] ?? null) ? $components['options'] : [];

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
    $aliases = [
        'ifaces' => ['bind'],
        'bind' => ['ifaces'],
        'disable-udp-flood' => ['direct-udp-failover'],
        'direct-udp-failover' => ['disable-udp-flood'],
    ];
    $flagOnlyByDaemon = [
        'distress' => ['enable-icmp-flood', 'enable-packet-flood', 'disable-udp-flood'],
    ];
    $filteredParams = $updatedParams;
    if ($daemonName === 'distress') {
        unset($filteredParams['distress-concurrency-mode']);
    }

    $updatedExecStart = updateExecStartOptionsString(
        $configString,
        $filteredParams,
        $aliases,
        $flagOnlyByDaemon[$daemonName] ?? [],
        in_array($daemonName, ['mhddos', 'distress'], true) ? ['source' => 'itarmybox'] : []
    );

    return tokenizeExecStartString($updatedExecStart ?? $configString);
}

function updateServiceFile(string $serviceName, array $updatedConfigParams): bool
{
    return updateServiceExecStartString($serviceName, implode(' ', $updatedConfigParams));
}

function updateServiceExecStartString(string $serviceName, string $execStart): bool
{
    $config = require __DIR__ . '/../config/config.php';
    $response = root_helper_request([
        'action' => 'service_execstart_set',
        'modules' => $config['daemonNames'],
        'module' => $serviceName,
        'execStart' => $execStart,
    ]);
    return ($response['ok'] ?? false) === true;
}
