<?php

declare(strict_types=1);

function parseBoolString(string $value): ?bool
{
    $normalized = strtolower(trim($value));
    return match ($normalized) {
        'yes', 'true', '1' => true,
        'no', 'false', '0' => false,
        default => null,
    };
}

function findTimedatectl(): ?string
{
    return findExecutable(['/usr/bin/timedatectl', '/bin/timedatectl']);
}

function findSystemctl(): ?string
{
    return findExecutable(['/usr/bin/systemctl', '/bin/systemctl']);
}

function getTimeSyncStatus(): array
{
    $timedatectl = findTimedatectl();
    $systemctl = findSystemctl();
    if ($timedatectl === null) {
        return ['ok' => false, 'error' => 'timedatectl_not_found'];
    }

    $output = runCommand(escapeshellarg($timedatectl) . ' show --property=Timezone --property=NTP --property=NTPSynchronized --property=NTPService --value', $code);
    if ($code !== 0) {
        return ['ok' => false, 'error' => 'timedatectl_show_failed'];
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($output));
    $timezone = trim((string)($lines[0] ?? ''));
    $ntpEnabled = parseBoolString((string)($lines[1] ?? ''));
    $ntpSynced = parseBoolString((string)($lines[2] ?? ''));
    $ntpService = trim((string)($lines[3] ?? ''));
    if ($ntpService === '' && $systemctl !== null) {
        $services = ['systemd-timesyncd', 'chronyd', 'ntp', 'ntpd'];
        foreach ($services as $service) {
            $serviceSafe = escapeshellarg($service . '.service');
            $state = trim(runCommand(escapeshellarg($systemctl) . " is-active $serviceSafe", $svcCode));
            if ($svcCode === 0 && $state !== '') {
                $ntpService = $service;
                break;
            }
        }
    }

    return [
        'ok' => true,
        'timezone' => $timezone,
        'ntpEnabled' => $ntpEnabled,
        'ntpSynchronized' => $ntpSynced,
        'ntpService' => $ntpService !== '' ? $ntpService : null,
        'timezoneOk' => $timezone === 'Europe/Kyiv',
        'ntpOk' => $ntpEnabled === true,
    ];
}

function ensureTimeSync(): array
{
    return ensureTimeSyncForTimezone('Europe/Kyiv');
}

function ensureTimeSyncForTimezone(string $timezone): array
{
    if (preg_match('/^[A-Za-z0-9._+-]+(?:\/[A-Za-z0-9._+\-]+)+$/', $timezone) !== 1) {
        return ['ok' => false, 'error' => 'invalid_timezone'];
    }

    $timedatectl = findTimedatectl();
    $systemctl = findSystemctl();
    if ($timedatectl === null) {
        return ['ok' => false, 'error' => 'timedatectl_not_found'];
    }

    $timesyncd = findExecutable(['/lib/systemd/systemd-timesyncd', '/usr/lib/systemd/systemd-timesyncd']);
    if ($timesyncd !== null && $systemctl !== null) {
        runCommand(escapeshellarg($systemctl) . ' enable systemd-timesyncd.service', $enableCode);
        runCommand(escapeshellarg($systemctl) . ' start systemd-timesyncd.service', $startCode);
    }

    runCommand(escapeshellarg($timedatectl) . ' set-timezone ' . escapeshellarg($timezone), $timezoneCode);
    if ($timezoneCode !== 0) {
        return ['ok' => false, 'error' => 'set_timezone_failed'];
    }

    runCommand(escapeshellarg($timedatectl) . ' set-ntp true', $ntpCode);
    if ($ntpCode !== 0) {
        return ['ok' => false, 'error' => 'set_ntp_failed'];
    }

    if ($timesyncd !== null && $systemctl !== null) {
        runCommand(escapeshellarg($systemctl) . ' restart systemd-timesyncd.service', $restartCode);
    }

    $status = getTimeSyncStatus();
    if (($status['ok'] ?? false) !== true) {
        return $status;
    }

    if (($status['timezone'] ?? '') !== $timezone || ($status['ntpOk'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'time_sync_verification_failed'] + $status;
    }

    return $status;
}

function waitForTimeSyncReady(int $maxWaitSeconds = 180): void
{
    $deadline = time() + max(0, $maxWaitSeconds);
    do {
        $status = getTimeSyncStatus();
        if (($status['ok'] ?? false) === true && ($status['ntpSynchronized'] ?? false) === true) {
            return;
        }
        sleep(5);
    } while (time() < $deadline);
}
