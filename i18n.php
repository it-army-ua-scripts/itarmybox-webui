<?php

function app_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }

    $allowed = ['en', 'uk'];
    $requested = $_GET['lang'] ?? ($_COOKIE['lang'] ?? 'en');
    if (!in_array($requested, $allowed, true)) {
        $requested = 'en';
    }

    if (!isset($_COOKIE['lang']) || $_COOKIE['lang'] !== $requested) {
        setcookie('lang', $requested, time() + 31536000, '/');
    }

    $lang = $requested;
    return $lang;
}

function t(string $key, array $vars = []): string
{
    static $translations = [
        'en' => [
            'main_menu' => 'Main Menu',
            'status' => 'Status',
            'tools' => 'Tools',
            'update' => 'Update',
            'back' => 'Back',
            'ddos_tools' => 'DDOS tools',
            'update_log' => 'Update log',
            'tools_status' => 'Tools status',
            'active_module' => 'Active module',
            'checking' => 'Checking...',
            'common_logs' => 'Common logs',
            'common_logs_for' => 'Common logs ({{module}})',
            'common_logs_no_active' => 'Common logs (no active module)',
            'module_running' => '{{module}} is running.',
            'module_not_running' => '{{module}} is not running.',
            'no_module_running' => 'No module is running.',
            'service_updated' => 'Service updated!',
            'settings' => 'Settings',
            'settings_for' => '{{module}} settings',
            'status_label' => 'Status:',
            'start' => 'Start',
            'stop' => 'Stop',
            'error' => 'Error',
            'return_to_main_menu' => 'Return to main menu',
            'fetching_logs' => 'Fetching logs from journalctl:',
            'save' => 'Save',
            'yes' => 'Yes',
            'no' => 'No',
            'user_id_integer' => 'User ID (Integer):',
            'number_of_copies' => 'Number of copies:',
            'percentage_personal_ip' => 'Percentage of personal IP:',
            'threads' => 'Threads:',
            'language' => 'Language (ua | en | es | de | pl | it):',
            'proxies_path_or_url' => 'Proxies (file path or URL):',
            'network_interfaces' => 'Network interfaces (space-separated):',
            'disable_udp_flood' => 'Disable UDP flood (1 | 0):',
            'enable_icmp_flood' => 'Enable ICMP flood (1 | 0):',
            'enable_packet_flood' => 'Enable packet flood (1 | 0):',
            'udp_packet_size' => 'UDP packet size (576-1420):',
            'packets_per_connection' => 'Packets per connection (1-100):',
            'proxies_file_path' => 'Proxies (file path):',
            'network_interface' => 'Network interfaces (comma-separated):',
            'number_tor_connections' => 'Number of Tor connections:',
            'number_task_creators' => 'Number of task creators:'
        ],
        'uk' => [
            'main_menu' => 'Р“РѕР»РѕРІРЅРµ РјРµРЅСЋ',
            'status' => 'РЎС‚Р°С‚СѓСЃ',
            'tools' => 'Р†РЅСЃС‚СЂСѓРјРµРЅС‚Рё',
            'update' => 'РћРЅРѕРІР»РµРЅРЅСЏ',
            'back' => 'РќР°Р·Р°Рґ',
            'ddos_tools' => 'DDoS С–РЅСЃС‚СЂСѓРјРµРЅС‚Рё',
            'update_log' => 'Р–СѓСЂРЅР°Р» РѕРЅРѕРІР»РµРЅРЅСЏ',
            'tools_status' => 'РЎС‚Р°С‚СѓСЃ С–РЅСЃС‚СЂСѓРјРµРЅС‚С–РІ',
            'active_module' => 'РђРєС‚РёРІРЅРёР№ РјРѕРґСѓР»СЊ',
            'checking' => 'РџРµСЂРµРІС–СЂРєР°...',
            'common_logs' => 'Р—Р°РіР°Р»СЊРЅРёР№ Р»РѕРі',
            'common_logs_for' => 'Р—Р°РіР°Р»СЊРЅРёР№ Р»РѕРі ({{module}})',
            'common_logs_no_active' => 'Р—Р°РіР°Р»СЊРЅРёР№ Р»РѕРі (РЅРµРјР°С” Р°РєС‚РёРІРЅРѕРіРѕ РјРѕРґСѓР»СЏ)',
            'module_running' => 'РњРѕРґСѓР»СЊ {{module}} Р·Р°РїСѓС‰РµРЅРёР№.',
            'module_not_running' => 'РњРѕРґСѓР»СЊ {{module}} РЅРµ Р·Р°РїСѓС‰РµРЅРёР№.',
            'no_module_running' => 'РќРµРјР°С” Р·Р°РїСѓС‰РµРЅРѕРіРѕ РјРѕРґСѓР»СЏ.',
            'service_updated' => 'РЎРµСЂРІС–СЃ РѕРЅРѕРІР»РµРЅРѕ!',
            'settings' => 'РќР°Р»Р°С€С‚СѓРІР°РЅРЅСЏ',
            'settings_for' => 'РќР°Р»Р°С€С‚СѓРІР°РЅРЅСЏ {{module}}',
            'status_label' => 'РЎС‚Р°С‚СѓСЃ:',
            'start' => 'Р—Р°РїСѓСЃС‚РёС‚Рё',
            'stop' => 'Р—СѓРїРёРЅРёС‚Рё',
            'error' => 'РџРѕРјРёР»РєР°',
            'return_to_main_menu' => 'РџРѕРІРµСЂРЅСѓС‚РёСЃСЏ РІ РіРѕР»РѕРІРЅРµ РјРµРЅСЋ',
            'fetching_logs' => 'РћС‚СЂРёРјР°РЅРЅСЏ Р»РѕРіС–РІ Р· journalctl:',
            'save' => 'Р—Р±РµСЂРµРіС‚Рё',
            'yes' => 'Так',
            'no' => 'Ні',
            'user_id_integer' => 'ID РєРѕСЂРёСЃС‚СѓРІР°С‡Р° (С†С–Р»Рµ С‡РёСЃР»Рѕ):',
            'number_of_copies' => 'РљС–Р»СЊРєС–СЃС‚СЊ РєРѕРїС–Р№:',
            'percentage_personal_ip' => 'Р’С–РґСЃРѕС‚РѕРє РїРµСЂСЃРѕРЅР°Р»СЊРЅРѕС— IP:',
            'threads' => 'РџРѕС‚РѕРєРё:',
            'number_tor_connections' => 'РљС–Р»СЊРєС–СЃС‚СЊ Tor-Р·\'С”РґРЅР°РЅСЊ:',
            'number_task_creators' => 'РљС–Р»СЊРєС–СЃС‚СЊ task creator-С–РІ:'
        ]
    ];

    $lang = app_lang();
    $text = $translations[$lang][$key] ?? ($translations['en'][$key] ?? $key);
    foreach ($vars as $varKey => $varValue) {
        $text = str_replace('{{' . $varKey . '}}', (string)$varValue, $text);
    }

    return $text;
}

function url_with_lang(string $url): string
{
    $separator = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $separator . 'lang=' . rawurlencode(app_lang());
}
