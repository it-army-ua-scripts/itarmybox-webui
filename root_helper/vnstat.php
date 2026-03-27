<?php

declare(strict_types=1);

function findAptGet(): ?string
{
    return findExecutable(['/usr/bin/apt-get', '/bin/apt-get', '/usr/bin/apt', '/bin/apt']);
}

function findVnstatBinary(): ?string
{
    return findExecutable(['/usr/bin/vnstat', '/bin/vnstat']);
}

function detectPrimaryNetworkInterface(): string
{
    $ip = findExecutable(['/usr/sbin/ip', '/usr/bin/ip', '/sbin/ip', '/bin/ip']);
    if ($ip !== null) {
        $output = runCommand(escapeshellarg($ip) . ' route show default', $code);
        if ($code === 0 && preg_match('/\bdev\s+([a-zA-Z0-9._:-]+)/', $output, $matches) === 1) {
            $iface = trim((string)($matches[1] ?? ''));
            if ($iface !== '' && $iface !== 'lo') {
                return $iface;
            }
        }
    }

    $netPaths = glob('/sys/class/net/*');
    if (is_array($netPaths)) {
        foreach ($netPaths as $path) {
            $iface = basename($path);
            if ($iface !== '' && $iface !== 'lo') {
                return $iface;
            }
        }
    }

    return VNSTAT_INTERFACE;
}

function isVnstatInstalled(): bool
{
    return findVnstatBinary() !== null;
}

function isVnstatInterfaceReady(string $iface = VNSTAT_INTERFACE): bool
{
    $vnstat = findVnstatBinary();
    if ($vnstat === null) {
        return false;
    }

    $output = runCommand(
        escapeshellarg($vnstat) . ' --oneline -i ' . escapeshellarg($iface),
        $code
    );
    return $code === 0 && trim($output) !== '';
}

function getVnstatStatus(): array
{
    $iface = detectPrimaryNetworkInterface();
    $installed = isVnstatInstalled();
    $serviceEnabled = false;
    $serviceActive = false;
    $databaseReady = false;

    if ($installed) {
        $systemctl = findSystemctl();
        if ($systemctl !== null) {
            runCommand(escapeshellarg($systemctl) . ' is-enabled vnstat.service', $enabledCode);
            runCommand(escapeshellarg($systemctl) . ' is-active vnstat.service', $activeCode);
            $serviceEnabled = $enabledCode === 0;
            $serviceActive = $activeCode === 0;
        }
        $databaseReady = isVnstatInterfaceReady($iface);
    }

    return [
        'ok' => true,
        'installed' => $installed,
        'iface' => $iface,
        'serviceEnabled' => $serviceEnabled,
        'serviceActive' => $serviceActive,
        'databaseReady' => $databaseReady,
        'ready' => $installed && $databaseReady,
    ];
}

function ensureVnstatInterfaceDatabase(string $iface = VNSTAT_INTERFACE): bool
{
    if (isVnstatInterfaceReady($iface)) {
        return true;
    }

    $vnstat = findVnstatBinary();
    if ($vnstat === null) {
        return false;
    }

    $ifaceArg = escapeshellarg($iface);
    $commands = [
        escapeshellarg($vnstat) . ' --add -i ' . $ifaceArg,
        escapeshellarg($vnstat) . ' --create -i ' . $ifaceArg,
    ];
    foreach ($commands as $command) {
        runCommand($command, $commandCode);
        if (isVnstatInterfaceReady($iface)) {
            return true;
        }
    }

    return isVnstatInterfaceReady($iface);
}

function installVnstat(): array
{
    $iface = detectPrimaryNetworkInterface();
    $alreadyInstalled = isVnstatInstalled();
    if (!$alreadyInstalled) {
        $apt = findAptGet();
        if ($apt === null) {
            return ['ok' => false, 'error' => 'apt_not_found'];
        }

        $cmd = 'DEBIAN_FRONTEND=noninteractive ' . escapeshellarg($apt) . ' install -y vnstat';
        $output = runCommand($cmd, $code);
        if ($code !== 0) {
            return ['ok' => false, 'error' => 'vnstat_install_failed', 'output' => $output];
        }
    }

    if (!isVnstatInstalled()) {
        return ['ok' => false, 'error' => 'vnstat_not_found_after_install'];
    }

    $systemctl = findSystemctl();
    if ($systemctl !== null) {
        runCommand(escapeshellarg($systemctl) . ' enable vnstat.service', $enableCode);
        runCommand(escapeshellarg($systemctl) . ' restart vnstat.service', $restartCode);
    }

    if (!ensureVnstatInterfaceDatabase($iface)) {
        return getVnstatStatus() + ['ok' => false, 'error' => 'vnstat_interface_init_failed'];
    }

    $status = getVnstatStatus();
    return $status + ['already' => $alreadyInstalled];
}
