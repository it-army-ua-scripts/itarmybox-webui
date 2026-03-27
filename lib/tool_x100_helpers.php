<?php

require_once __DIR__ . '/root_helper_client.php';

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
        'ignoreBundledFreeVpn' => '0',
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
