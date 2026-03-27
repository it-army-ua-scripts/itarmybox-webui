<?php

declare(strict_types=1);

function appendRebootLog(string $message): void
{
    $line = '[' . date('c') . '] ' . $message . "\n";
    @file_put_contents('/tmp/itarmybox-reboot.log', $line, FILE_APPEND);
}

function systemReboot(): array
{
    $systemctl = findExecutable(['/usr/bin/systemctl', '/bin/systemctl']);
    $shutdown = findExecutable(['/usr/sbin/shutdown', '/sbin/shutdown', '/usr/bin/shutdown', '/bin/shutdown']);
    $reboot = findExecutable(['/usr/sbin/reboot', '/sbin/reboot', '/usr/bin/reboot', '/bin/reboot']);

    $uid = function_exists('posix_geteuid') ? (string)posix_geteuid() : 'unknown';
    appendRebootLog('request: uid=' . $uid);

    $candidates = [];
    if ($systemctl !== null) {
        $candidates[] = ['name' => 'systemctl', 'cmd' => escapeshellarg($systemctl) . ' reboot'];
    }
    if ($shutdown !== null) {
        $candidates[] = ['name' => 'shutdown', 'cmd' => escapeshellarg($shutdown) . ' -r now'];
    }
    if ($reboot !== null) {
        $candidates[] = ['name' => 'reboot', 'cmd' => escapeshellarg($reboot)];
    }

    if ($candidates === []) {
        appendRebootLog('error: reboot_command_not_found');
        return ['ok' => false, 'error' => 'reboot_command_not_found'];
    }

    foreach ($candidates as $candidate) {
        $name = $candidate['name'];
        $cmd = $candidate['cmd'];
        $output = runCommand($cmd, $code);
        appendRebootLog('try ' . $name . ' exit=' . $code . ' output=' . str_replace("\n", ' ', $output));
        if ($code === 0) {
            return ['ok' => true, 'method' => $name];
        }
    }

    return ['ok' => false, 'error' => 'reboot_failed'];
}

function runSystemUpdate(?string $branch = null): array
{
    if (!is_file(UPDATE_SCRIPT_PATH) || !is_readable(UPDATE_SCRIPT_PATH)) {
        return ['ok' => false, 'error' => 'update_script_not_found'];
    }

    $envPrefix = '';
    if ($branch !== null) {
        $normalizedBranch = trim($branch);
        if ($normalizedBranch !== 'main' && $normalizedBranch !== 'dev') {
            return ['ok' => false, 'error' => 'invalid_branch'];
        }
        $envPrefix = 'ITARMYBOX_UPDATE_BRANCH=' . escapeshellarg($normalizedBranch) . ' ';
    }

    $output = runCommandVerbose(
        $envPrefix . 'ITARMYBOX_SKIP_ROOT_HELPER_REFRESH=1 /usr/bin/env bash ' . escapeshellarg(UPDATE_SCRIPT_PATH),
        $code
    );
    return [
        'ok' => $code === 0,
        'error' => $code === 0 ? null : 'update_failed',
        'output' => $output,
    ];
}
