<?php

declare(strict_types=1);

$modules = (require __DIR__ . '/config/config.php')['daemonNames'] ?? null;
if (!is_array($modules) || $modules === []) {
    fwrite(STDERR, "invalid modules\n");
    exit(1);
}

function find_php_cli_for_prepare(): ?string
{
    $candidates = [];
    if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }
    array_push($candidates, '/usr/bin/php', '/usr/local/bin/php', '/bin/php');

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

$phpCli = find_php_cli_for_prepare();
if ($phpCli === null) {
    fwrite(STDERR, "php cli unavailable\n");
    exit(1);
}

$payload = [
    'action' => 'distress_service_prepare',
    'modules' => $modules,
];

$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($payloadJson) || $payloadJson === '') {
    fwrite(STDERR, "payload encode failed\n");
    exit(1);
}

$command = sprintf(
    '%s %s %s',
    escapeshellarg($phpCli),
    escapeshellarg(__DIR__ . '/root_helper.php'),
    escapeshellarg($payloadJson)
);

$output = [];
$exitCode = 1;
exec($command . ' 2>/dev/null', $output, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "prepare command failed\n");
    exit(1);
}

$responseRaw = trim(implode("\n", $output));
$response = json_decode($responseRaw, true);
if (!is_array($response)) {
    fwrite(STDERR, "invalid prepare response\n");
    exit(1);
}

exit((($response['ok'] ?? false) === true) ? 0 : 1);
