<?php

require_once __DIR__ . '/root_helper_client.php';
require_once __DIR__ . '/tool_helpers.php';

function render_module_action_form(string $path, string $daemonName, string $label): string
{
    return '<div class="menu"><form method="post" action="' . htmlspecialchars(url_with_lang($path), ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="daemon" value="' . htmlspecialchars($daemonName, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>'
        . '</form></div>';
}

function build_tool_url(string $daemonName, array $params = []): string
{
    $query = array_merge(['daemon' => $daemonName], $params);
    return url_with_lang('/tool.php?' . http_build_query($query));
}

function tool_allowed_flash_keys(): array
{
    return [
        'settings_saved',
        'settings_saved_and_restarted',
        'settings_saved_restart_failed',
        'settings_not_saved',
        'invalid_distress_settings',
        'invalid_mhddos_settings',
        'invalid_use_my_ip_digits',
        'invalid_use_my_ip_range',
        'invalid_use_tor_digits',
        'invalid_use_tor_range',
        'invalid_concurrency',
        'invalid_concurrency_mode',
        'invalid_copies',
        'invalid_threads',
    ];
}

function tool_service_info(array $daemonNames, string $daemonName): array
{
    return root_helper_request([
        'action' => 'service_info',
        'modules' => $daemonNames,
        'module' => $daemonName,
    ]);
}

function tool_handle_post(array $config, string $daemonName, array $post, bool $wasActiveBeforeSave): array
{
    $saveOk = false;
    $saveError = '';
    $restartError = '';
    $distressValidation = null;

    if ($daemonName === 'x100') {
        $saveOk = setX100ConfigValues($post);
    } else {
        $allowedParamKeys = array_flip($config['adjustableParams'][$daemonName] ?? []);
        $paramsToSave = array_intersect_key($post, $allowedParamKeys);
        if ($daemonName === 'distress') {
            $paramsToSave['distress-concurrency-mode'] = $post['distress-concurrency-mode'] ?? 'auto';
            $distressValidation = normalizeAndValidateDistressPostParams($paramsToSave);
            if (($distressValidation['ok'] ?? false) !== true) {
                $saveError = (string)($distressValidation['error'] ?? 'invalid_distress_settings');
            } else {
                $paramsToSave = (array)$distressValidation['params'];
            }
        }
        if ($daemonName === 'mhddos') {
            $mhddosValidation = normalizeAndValidateMhddosPostParams($paramsToSave);
            if (($mhddosValidation['ok'] ?? false) !== true) {
                $saveError = (string)($mhddosValidation['error'] ?? 'invalid_mhddos_settings');
            } else {
                $paramsToSave = (array)$mhddosValidation['params'];
            }
        }
        $currentConfigString = getConfigStringFromServiceFile($daemonName);
        if ($saveError === '' && $currentConfigString !== '') {
            $saveOk = updateServiceFile(
                $daemonName,
                updateServiceConfigParams($currentConfigString, $paramsToSave, $daemonName)
            );
            if ($saveOk && $daemonName === 'distress') {
                $saveOk = saveDistressAutotuneSettings(
                    (($distressValidation['autotuneEnabled'] ?? false) === true),
                    (int)($distressValidation['concurrencyValue'] ?? 0)
                );
                if (!$saveOk && $saveError === '') {
                    $saveError = 'settings_not_saved';
                }
            }
        }
    }

    if ($saveOk && $wasActiveBeforeSave) {
        $restartResponse = root_helper_request([
            'action' => 'service_restart',
            'modules' => $config['daemonNames'],
            'module' => $daemonName,
        ]);
        if (($restartResponse['ok'] ?? false) !== true) {
            $restartError = 'settings_saved_restart_failed';
        }
    }

    if ($saveOk) {
        $flashKey = ($wasActiveBeforeSave && $restartError === '')
            ? 'settings_saved_and_restarted'
            : 'settings_saved';
        $redirectParams = [
            'flash' => $flashKey,
            'flashClass' => 'active',
        ];
        if ($restartError !== '') {
            $redirectParams['flashSecondary'] = $restartError;
        }
    } else {
        $redirectParams = [
            'flash' => ($saveError !== '') ? $saveError : 'settings_not_saved',
            'flashClass' => 'inactive',
        ];
    }

    return $redirectParams;
}

function tool_current_adjustable_params(array $config, string $daemonName): array
{
    if ($daemonName === 'x100') {
        return getX100ConfigValues();
    }

    return getCurrentAdjustableParams(
        getConfigStringFromServiceFile($daemonName),
        $config['adjustableParams'][$daemonName],
        $daemonName
    );
}
