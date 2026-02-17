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
            'autostart' => 'Autostart',
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
            'footer_slogan' => 'Together to freedom',
            'autostart_settings' => 'Autostart Settings',
            'current_autostart' => 'Current autostart',
            'autostart_for' => 'Autostart enabled for {{module}}',
            'autostart_none' => 'Autostart is disabled',
            'select_autostart_module' => 'Select module for autostart:',
            'autostart_disable' => 'Disable autostart',
            'autostart_updated' => 'Autostart settings updated.',
            'autostart_update_failed' => 'Failed to update autostart settings.',
            'schedule_settings' => 'Schedule (cron)',
            'schedule_enabled' => 'Enable schedule:',
            'schedule_module' => 'Scheduled module:',
            'schedule_day_mode' => 'Day selection:',
            'schedule_all_days' => 'All days',
            'schedule_specific_days' => 'Only selected days',
            'schedule_select_days' => 'Select days:',
            'schedule_start_time' => 'Start time:',
            'schedule_stop_time' => 'Stop time:',
            'schedule_disabled' => 'Schedule is disabled.',
            'schedule_current' => 'Scheduled: {{module}}, {{day}}, {{start}} - {{stop}}',
            'schedule_line' => '#{{idx}} {{module}} | {{days}} | {{start}} - {{stop}}',
            'schedule_interval' => 'Interval {{num}}',
            'add_interval' => 'Add interval',
            'remove_interval' => 'Remove',
            'schedule_limit_hint' => 'Up to 2 schedules per day.',
            'schedule_updated' => 'Schedule updated.',
            'schedule_update_failed' => 'Failed to update schedule.',
            'all_days' => 'All days',
            'day_sunday' => 'Sunday',
            'day_monday' => 'Monday',
            'day_tuesday' => 'Tuesday',
            'day_wednesday' => 'Wednesday',
            'day_thursday' => 'Thursday',
            'day_friday' => 'Friday',
            'day_saturday' => 'Saturday',
            'fetching_logs' => 'Fetching logs from journalctl:',
            'service_info' => 'Service info',
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
            'disable_udp_flood' => 'Disable UDP flood:',
            'enable_icmp_flood' => 'Enable ICMP flood:',
            'enable_packet_flood' => 'Enable packet flood:',
            'udp_packet_size' => 'UDP packet size (576-1420):',
            'packets_per_connection' => 'Packets per connection (1-100):',
            'proxies_file_path' => 'Proxies (file path):',
            'network_interface' => 'Network interfaces (comma-separated):',
            'initial_distress_scale' => 'Initial Distress Scale (10-40960):',
            'ignore_bundled_free_vpn' => 'Ignore Bundled Free VPN:',
            'number_tor_connections' => 'Number of Tor connections:',
            'number_task_creators' => 'Number of task creators:'
        ],
        'uk' => [
            'main_menu' => 'Головне меню',
            'status' => 'Статус',
            'tools' => 'Інструменти',
            'update' => 'Оновлення',
            'autostart' => 'Автостарт',
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
            'footer_slogan' => 'Разом до свободи',
            'autostart_settings' => 'Налаштування автостарту',
            'current_autostart' => 'Поточний автостарт',
            'autostart_for' => 'Автостарт увімкнено для {{module}}',
            'autostart_none' => 'Автостарт вимкнений',
            'select_autostart_module' => 'Оберіть модуль для автостарту:',
            'autostart_disable' => 'Вимкнути автостарт',
            'autostart_updated' => 'Налаштування автостарту оновлено.',
            'autostart_update_failed' => 'Не вдалося оновити налаштування автостарту.',
            'schedule_settings' => 'Розклад (cron)',
            'schedule_enabled' => 'Увімкнути розклад:',
            'schedule_module' => 'Модуль у розкладі:',
            'schedule_day_mode' => 'Вибір днів:',
            'schedule_all_days' => 'Усі дні',
            'schedule_specific_days' => 'Лише вибрані дні',
            'schedule_select_days' => 'Оберіть дні:',
            'schedule_start_time' => 'Час запуску:',
            'schedule_stop_time' => 'Час зупинки:',
            'schedule_disabled' => 'Розклад вимкнено.',
            'schedule_current' => 'Розклад: {{module}}, {{day}}, {{start}} - {{stop}}',
            'schedule_line' => '#{{idx}} {{module}} | {{days}} | {{start}} - {{stop}}',
            'schedule_interval' => 'Інтервал {{num}}',
            'add_interval' => 'Додати інтервал',
            'remove_interval' => 'Видалити',
            'schedule_limit_hint' => 'До 2 планувань на добу.',
            'schedule_updated' => 'Розклад оновлено.',
            'schedule_update_failed' => 'Не вдалося оновити розклад.',
            'all_days' => 'Усі дні',
            'day_sunday' => 'Неділя',
            'day_monday' => 'Понеділок',
            'day_tuesday' => 'Вівторок',
            'day_wednesday' => 'Середа',
            'day_thursday' => 'Четвер',
            'day_friday' => 'Пʼятниця',
            'day_saturday' => 'Субота',
            'fetching_logs' => 'Отримання логів з journalctl:',
            'service_info' => 'Інформація про службу',
            'save' => 'Зберегти',
            'yes' => 'Так',
            'no' => 'Ні',
            'user_id_integer' => 'ID користувача (ціле число):',
            'number_of_copies' => 'Кількість копій:',
            'percentage_personal_ip' => 'Відсоток персональної IP:',
            'threads' => 'Потоки:',
            'language' => 'Мова (ua | en | es | de | pl | it):',
            'proxies_path_or_url' => 'Проксі (шлях до файлу або URL):',
            'network_interfaces' => 'Мережеві інтерфейси (через пробіл):',
            'disable_udp_flood' => 'Вимкнути UDP flood:',
            'enable_icmp_flood' => 'Увімкнути ICMP flood:',
            'enable_packet_flood' => 'Увімкнути packet flood:',
            'udp_packet_size' => 'Розмір UDP пакета (576-1420):',
            'packets_per_connection' => 'Кількість пакетів на зʼєднання (1-100):',
            'proxies_file_path' => 'Проксі (шлях до файлу):',
            'network_interface' => 'Мережеві інтерфейси (через кому):',
            'initial_distress_scale' => 'Початковий Distress Scale (10-40960):',
            'ignore_bundled_free_vpn' => 'Ігнорувати вбудований безкоштовний VPN:',
            'number_tor_connections' => 'Кількість Tor-зʼєднань:',
            'number_task_creators' => 'Кількість створювачів завдань:'
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
