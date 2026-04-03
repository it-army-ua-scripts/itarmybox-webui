<?php

declare(strict_types=1);

function tokenizeExecStartString(string $execStartLine): array
{
    $trimmed = trim($execStartLine);
    if ($trimmed === '') {
        return [];
    }

    preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\s]+/', $trimmed, $matches);
    $tokens = [];
    foreach ($matches[0] ?? [] as $token) {
        if (!is_string($token) || $token === '') {
            continue;
        }

        $first = $token[0];
        $last = substr($token, -1);
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $inner = substr($token, 1, -1);
            if ($first === '"') {
                $token = preg_replace('/\\\\(["\\\\])/', '$1', $inner) ?? $inner;
            } else {
                $token = str_replace(["\\'", "\\\\"], ["'", "\\"], $inner);
            }
        }

        $tokens[] = $token;
    }

    return $tokens;
}

function renderExecStartValue(string $value): string
{
    if ($value === '') {
        return '""';
    }

    if (preg_match('/^[^\s"\'\\\\]+$/', $value) === 1) {
        return $value;
    }

    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    return '"' . $escaped . '"';
}

function parseExecStartComponents(string $execStartLine): ?array
{
    $tokens = tokenizeExecStartString($execStartLine);
    if ($tokens === []) {
        return null;
    }

    $baseTokens = [];
    $options = [];
    $count = count($tokens);
    $idx = 0;
    while ($idx < $count && !str_starts_with((string)$tokens[$idx], '--')) {
        $baseTokens[] = (string)$tokens[$idx];
        $idx++;
    }

    while ($idx < $count) {
        $token = (string)$tokens[$idx];
        if (!str_starts_with($token, '--')) {
            $idx++;
            continue;
        }

        $key = substr($token, 2);
        $next = $tokens[$idx + 1] ?? null;
        if (is_string($next) && !str_starts_with($next, '--')) {
            $options[$key] = $next;
            $idx += 2;
            continue;
        }

        $options[$key] = true;
        $idx++;
    }

    return [
        'baseTokens' => $baseTokens,
        'options' => $options,
    ];
}

function buildExecStartFromComponents(array $baseTokens, array $options): ?string
{
    if ($baseTokens === []) {
        return null;
    }

    $tokens = [];
    foreach ($baseTokens as $token) {
        if (!is_string($token) || $token === '') {
            continue;
        }
        $tokens[] = renderExecStartValue($token);
    }

    if ($tokens === []) {
        return null;
    }

    foreach ($options as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }

        $tokens[] = '--' . $key;
        if ($value !== true) {
            $tokens[] = renderExecStartValue((string)$value);
        }
    }

    return implode(' ', $tokens);
}

function updateExecStartOptionsString(
    string $execStartLine,
    array $updatedParams,
    array $aliases = [],
    array $flagOnlyKeys = [],
    array $forcedOptions = []
): ?string {
    $components = parseExecStartComponents($execStartLine);
    if (!is_array($components)) {
        return null;
    }

    $baseTokens = $components['baseTokens'] ?? [];
    $options = is_array($components['options'] ?? null) ? $components['options'] : [];
    $flagOnly = array_flip($flagOnlyKeys);

    foreach ($updatedParams as $updatedParamKey => $updatedParam) {
        if (!is_string($updatedParamKey) || $updatedParamKey === '') {
            continue;
        }

        $updatedValue = trim((string)$updatedParam);
        $allKeys = array_merge([$updatedParamKey], $aliases[$updatedParamKey] ?? []);
        foreach ($allKeys as $optionKey) {
            unset($options[$optionKey]);
        }

        $isFlagOnly = isset($flagOnly[$updatedParamKey]);
        if ($updatedValue === '' || $updatedValue === '0') {
            continue;
        }

        $options[$updatedParamKey] = $isFlagOnly ? true : $updatedValue;
    }

    foreach ($forcedOptions as $forcedKey => $forcedValue) {
        foreach (array_merge([(string)$forcedKey], $aliases[(string)$forcedKey] ?? []) as $optionKey) {
            unset($options[$optionKey]);
        }
        $options[(string)$forcedKey] = $forcedValue;
    }

    return buildExecStartFromComponents($baseTokens, $options);
}
