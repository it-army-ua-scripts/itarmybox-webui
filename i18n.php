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
            'main_menu' => 'Головне меню',
            'status' => 'Статус',
            'tools' => 'Інструменти',
            'update' => 'Оновлення',
            'back' => 'Назад',
            'ddos_tools' => 'DDoS інструменти',
            'update_log' => 'Журнал оновлення',
            'tools_status' => 'Статус інструментів',
            'active_module' => 'Активний модуль',
            'checking' => 'Перевірка...',
            'common_logs' => 'Загальний лог',
            'common_logs_for' => 'Загальний лог ({{module}})',
            'common_logs_no_active' => 'Загальний лог (немає активного модуля)',
            'module_running' => 'Модуль {{module}} запущений.',
            'module_not_running' => 'Модуль {{module}} не запущений.',
            'no_module_running' => 'Немає запущеного модуля.',
            'service_updated' => 'Сервіс оновлено!',
            'settings' => 'Налаштування',
            'settings_for' => 'Налаштування {{module}}',
            'status_label' => 'Статус:',
            'start' => 'Запустити',
            'stop' => 'Зупинити',
            'error' => 'Помилка',
            'return_to_main_menu' => 'Повернутися в головне меню',
            'fetching_logs' => 'Отримання логів з journalctl:',
            'save' => 'Зберегти',
            'user_id_integer' => 'ID користувача (ціле число):',
            'number_of_copies' => 'Кількість копій:',
            'percentage_personal_ip' => 'Відсоток персональної IP:',
            'threads' => 'Потоки:',
            'number_tor_connections' => 'Кількість Tor-з\'єднань:',
            'number_task_creators' => 'Кількість task creator-ів:'
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
