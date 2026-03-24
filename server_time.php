<?php
header('Content-Type: application/json; charset=UTF-8');

$lang = $_GET['lang'] ?? 'en';
$lang = ($lang === 'uk') ? 'uk' : 'en';

function getSystemTimezone(): ?string
{
    foreach (['/usr/bin/timedatectl', '/bin/timedatectl'] as $path) {
        if (is_executable($path)) {
            $tz = trim((string)@shell_exec($path . ' show -p Timezone --value 2>/dev/null'));
            if ($tz !== '') {
                return $tz;
            }
        }
    }
    return null;
}

$tz = getSystemTimezone();
$timezone = null;
if (is_string($tz) && $tz !== '') {
    try {
        $timezone = new DateTimeZone($tz);
    } catch (Exception $e) {
        $timezone = null;
    }
}

$now = new DateTimeImmutable('now', $timezone ?: null);
$weekdayIdx = (int)$now->format('w');
$day = $now->format('d');
$monthIdx = (int)$now->format('n');
$time = $now->format('H:i');

$weekdaysEn = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$monthsEn = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

$weekdaysUk = ['Неділя', 'Понеділок', 'Вівторок', 'Середа', 'Четвер', "Пʼятниця", 'Субота'];
$monthsUk = [1 => 'січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];

if ($lang === 'uk') {
    $text = $weekdaysUk[$weekdayIdx] . ', ' . $day . ' ' . $monthsUk[$monthIdx] . ' ' . $time;
} else {
    $text = $weekdaysEn[$weekdayIdx] . ', ' . $day . ' ' . $monthsEn[$monthIdx] . ' ' . $time;
}

echo json_encode([
    'ok' => true,
    'text' => $text,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
