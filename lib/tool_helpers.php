<?php

function getServiceLogs(string $serviceName): string
{
    $serviceSafe = escapeshellarg($serviceName);
    $logs = (string)shell_exec("journalctl -u $serviceSafe --no-pager -n 5 2>/dev/null");
    if (trim($logs) === '') {
        $logs = (string)shell_exec("journalctl -u $serviceSafe --no-pager -n 5 2>/dev/null");
    }
    if (trim($logs) === '') {
        $logs = "No journal entries available for this service.";
    }
    return preg_replace('/^[ \t]+/m', '', $logs) ?? $logs;
}

function getConfigStringFromServiceFile(string $serviceName): string
{
    $pattern = '/ExecStart=/';
    $handle = fopen('/opt/itarmy/services/' . $serviceName . '.service', "r");
    $result = '';
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if (preg_match($pattern, $line)) {
                $result = trim($line);
            }
        }
        fclose($handle);
    }
    return $result;
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

    $out = $baseTokens;
    foreach ($options as $key => $value) {
        $out[] = '--' . $key;
        if ($value !== true) {
            $out[] = $value;
        }
    }
    return $out;
}

function updateServiceFile(string $serviceName, array $updatedConfigParams): void
{
    $pattern = "/ExecStart=.*/";
    $serviceFile = '/opt/itarmy/services/' . $serviceName . '.service';
    $content = file_get_contents($serviceFile);
    $content = preg_replace($pattern, implode(" ", $updatedConfigParams), $content, 1);
    file_put_contents($serviceFile, $content);
}

function setX100ConfigValues(array $updatedConfig): void
{
    $envFile = '/opt/itarmy/x100-for-docker/put-your-ovpn-files-here/x100-config.txt';
    $allowed = ['itArmyUserId', 'initialDistressScale', 'ignoreBundledFreeVpn'];
    $content = file_get_contents($envFile);
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
    file_put_contents($envFile, $content);
}

function getX100ConfigValues(): array
{
    $envFile = '/opt/itarmy/x100-for-docker/put-your-ovpn-files-here/x100-config.txt';
    $result = [
        'itArmyUserId' => '',
        'initialDistressScale' => '',
        'ignoreBundledFreeVpn' => '0'
    ];
    if (!is_readable($envFile)) {
        return $result;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES);
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
