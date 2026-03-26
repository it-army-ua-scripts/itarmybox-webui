<?php

declare(strict_types=1);

const WIFI_TXPOWER_STATE_FILE = '/opt/itarmy/wifi-txpower.json';
const WIFI_AP_INTERFACE = 'wlan0';
const WIFI_TXPOWER_MIN_CENTIDBM = 50;
const WIFI_TXPOWER_MAX_CENTIDBM = 3100;

function findExecutable(array $paths): ?string
{
    foreach ($paths as $path) {
        if (is_string($path) && $path !== '' && is_executable($path)) {
            return $path;
        }
    }
    return null;
}

function findIwBinary(): ?string
{
    return findExecutable(['/usr/sbin/iw', '/usr/bin/iw', '/sbin/iw', '/bin/iw']);
}

function readDesiredTxPower(): ?array
{
    $raw = @file_get_contents(WIFI_TXPOWER_STATE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    $centiDbm = $data['centiDbm'] ?? null;
    if (!is_int($centiDbm) || $centiDbm < WIFI_TXPOWER_MIN_CENTIDBM || $centiDbm > WIFI_TXPOWER_MAX_CENTIDBM) {
        return null;
    }

    return [
        'iface' => WIFI_AP_INTERFACE,
        'centiDbm' => $centiDbm,
    ];
}

$state = readDesiredTxPower();
if ($state === null) {
    exit(0);
}

$iw = findIwBinary();
if ($iw === null) {
    fwrite(STDERR, "iw not found\n");
    exit(1);
}

$iface = $state['iface'];
$centiDbm = (int)$state['centiDbm'];
$command = escapeshellarg($iw) . ' dev ' . escapeshellarg($iface) . ' set txpower fixed ' . escapeshellarg((string)$centiDbm);
exec($command . ' 2>/dev/null', $output, $code);
exit($code);
