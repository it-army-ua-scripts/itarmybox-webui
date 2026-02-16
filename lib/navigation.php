<?php

function build_safe_redirect_target(array $allowedPaths, string $defaultPath): string
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        $parts = parse_url($referer);
        $path = $parts['path'] ?? '';
        if (in_array($path, $allowedPaths, true)) {
            $query = $parts['query'] ?? '';
            return $query !== '' ? ($path . '?' . $query) : $path;
        }
    }
    return $defaultPath;
}

function redirect_back_or_default(array $allowedPaths, string $defaultPath): void
{
    header('Location: ' . build_safe_redirect_target($allowedPaths, $defaultPath));
    exit();
}
