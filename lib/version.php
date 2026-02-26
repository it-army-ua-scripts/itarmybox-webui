<?php

const WEBUI_GITHUB_REPO = 'https://github.com/it-army-ua-scripts/itarmybox-webui';
const WEBUI_GITHUB_BRANCH = 'main';

function webui_repo_root(): string
{
    return dirname(__DIR__);
}

function webui_local_version(): string
{
    $path = webui_repo_root() . '/VERSION';
    if (!is_file($path)) {
        return 'unknown';
    }
    $value = trim((string)@file_get_contents($path));
    return $value !== '' ? $value : 'unknown';
}

function webui_version_cache_path(): string
{
    return '/tmp/itarmybox-webui-github-version.json';
}

function webui_read_cached_github_version(): ?array
{
    $path = webui_version_cache_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $version = trim((string)($data['version'] ?? ''));
    $fetchedAt = (int)($data['fetched_at'] ?? 0);
    if ($version === '' || $fetchedAt <= 0) {
        return null;
    }
    return ['version' => $version, 'fetched_at' => $fetchedAt];
}

function webui_cache_github_version(string $version): void
{
    $payload = json_encode(
        ['version' => $version, 'fetched_at' => time()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (is_string($payload)) {
        @file_put_contents(webui_version_cache_path(), $payload);
    }
}

function webui_fetch_github_version(int $maxAgeSeconds = 300): string
{
    $cached = webui_read_cached_github_version();
    if ($cached !== null && (time() - (int)$cached['fetched_at']) < $maxAgeSeconds) {
        return (string)$cached['version'];
    }

    $rawUrl = 'https://raw.githubusercontent.com/it-army-ua-scripts/itarmybox-webui/'
        . rawurlencode(WEBUI_GITHUB_BRANCH)
        . '/VERSION';
    $remoteVersion = '';

    if (is_executable('/usr/bin/curl')) {
        $cmd = '/usr/bin/curl -fsSL --max-time 5 ' . escapeshellarg($rawUrl) . ' 2>/dev/null';
        $remoteVersion = trim((string)@shell_exec($cmd));
    } elseif (is_executable('/usr/bin/wget')) {
        $cmd = '/usr/bin/wget -q -T 5 -O - ' . escapeshellarg($rawUrl) . ' 2>/dev/null';
        $remoteVersion = trim((string)@shell_exec($cmd));
    }

    if ($remoteVersion !== '') {
        webui_cache_github_version($remoteVersion);
        return $remoteVersion;
    }

    if ($cached !== null) {
        return (string)$cached['version'];
    }

    return 'unknown';
}

function webui_versions(): array
{
    return [
        'current' => webui_local_version(),
        'github' => webui_fetch_github_version(),
    ];
}
