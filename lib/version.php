<?php

const WEBUI_GITHUB_REPO = 'https://github.com/it-army-ua-scripts/itarmybox-webui';
const WEBUI_DEFAULT_BRANCH = 'main';
const WEBUI_ALLOWED_BRANCHES = ['main', 'dev'];

function webui_repo_root(): string
{
    return dirname(__DIR__);
}

function webui_branch_state_path(): string
{
    return '/tmp/itarmybox-webui-update-branch.txt';
}

function webui_selected_branch(): string
{
    $raw = @file_get_contents(webui_branch_state_path());
    $branch = is_string($raw) ? trim($raw) : '';
    return in_array($branch, WEBUI_ALLOWED_BRANCHES, true) ? $branch : WEBUI_DEFAULT_BRANCH;
}

function webui_set_selected_branch(string $branch): bool
{
    $branch = trim($branch);
    if (!in_array($branch, WEBUI_ALLOWED_BRANCHES, true)) {
        return false;
    }
    return @file_put_contents(webui_branch_state_path(), $branch) !== false;
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
    return '/tmp/itarmybox-webui-github-version-' . webui_selected_branch() . '.json';
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
    $branch = webui_selected_branch();
    $cached = webui_read_cached_github_version();
    if ($cached !== null && (time() - (int)$cached['fetched_at']) < $maxAgeSeconds) {
        return (string)$cached['version'];
    }

    $rawUrl = 'https://raw.githubusercontent.com/it-army-ua-scripts/itarmybox-webui/'
        . rawurlencode($branch)
        . '/VERSION';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "User-Agent: itarmybox-webui\r\n",
        ],
    ]);
    $remoteVersion = trim((string)@file_get_contents($rawUrl, false, $context));

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
        'branch' => webui_selected_branch(),
        'current' => webui_local_version(),
        'github' => webui_fetch_github_version(),
    ];
}
