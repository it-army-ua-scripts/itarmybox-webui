<?php

require_once __DIR__ . '/root_helper_client.php';
require_once __DIR__ . '/tool_helpers.php';

function render_module_action_form(string $path, string $daemonName, string $label): string
{
    $actionUrl = url_with_lang($path . '?daemon=' . rawurlencode($daemonName) . '&source=tool_action');
    return '<div class="menu"><form method="post" action="' . htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="daemon" value="' . htmlspecialchars($daemonName, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>'
        . '</form></div>';
}

function render_module_action_link(string $path, string $daemonName, string $label, string $source = 'tool_action'): string
{
    $href = url_with_lang($path . '?daemon=' . rawurlencode($daemonName) . '&source=' . rawurlencode($source));
    return '<div class="menu"><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</a></div>';
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
        'distress_upload_cap_measure_success',
        'distress_upload_cap_measure_failed',
        'distress_upload_cap_required_for_auto',
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
    if ($daemonName === 'distress' && (($post['distress-action'] ?? '') === 'measure-upload-cap')) {
        $measureResponse = measureDistressUploadCap();
        return [
            'flash' => (($measureResponse['ok'] ?? false) === true) ? 'distress_upload_cap_measure_success' : 'distress_upload_cap_measure_failed',
            'flashClass' => (($measureResponse['ok'] ?? false) === true) ? 'active' : 'inactive',
            'flashSecondary' => (($measureResponse['ok'] ?? false) === true)
                ? ''
                : ((string)($measureResponse['error'] ?? '') === 'distress_upload_cap_measure_failed'
                    ? ''
                    : (string)($measureResponse['error'] ?? '')),
        ];
    }

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
            $paramsToSave['distress-concurrency-mode'] = $post['distress-concurrency-mode'] ?? 'manual';
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
            $updatedConfigParams = updateServiceConfigParams($currentConfigString, $paramsToSave, $daemonName);
            if ($daemonName === 'distress') {
                $saveOk = saveDistressSettings(
                    implode(' ', $updatedConfigParams),
                    (($distressValidation['autotuneEnabled'] ?? false) === true),
                    (int)($distressValidation['concurrencyValue'] ?? 0)
                );
                if (!$saveOk && $saveError === '') {
                    $saveError = 'settings_not_saved';
                }
            } else {
                $saveOk = updateServiceFile($daemonName, $updatedConfigParams);
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
