<?php
require_once 'lib/root_helper_client.php';
header('Content-Type: application/json; charset=UTF-8');

$lang = $_GET['lang'] ?? 'en';
$lang = ($lang === 'uk') ? 'uk' : 'en';

function getSystemTimezone(): ?string
{
    $modules = (require 'config/config.php')['daemonNames'];
    $response = root_helper_request([
        'action' => 'time_sync_status',
        'modules' => $modules,
    ]);
    if (($response['ok'] ?? false) !== true) {
        return null;
    }
    $tz = trim((string)($response['timezone'] ?? ''));
    if ($tz !== '') {
        return $tz;
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

$weekdaysUk = [
    "\u{041d}\u{0435}\u{0434}\u{0456}\u{043b}\u{044f}",
    "\u{041f}\u{043e}\u{043d}\u{0435}\u{0434}\u{0456}\u{043b}\u{043e}\u{043a}",
    "\u{0412}\u{0456}\u{0432}\u{0442}\u{043e}\u{0440}\u{043e}\u{043a}",
    "\u{0421}\u{0435}\u{0440}\u{0435}\u{0434}\u{0430}",
    "\u{0427}\u{0435}\u{0442}\u{0432}\u{0435}\u{0440}",
    "\u{041f}\u{02bc}\u{044f}\u{0442}\u{043d}\u{0438}\u{0446}\u{044f}",
    "\u{0421}\u{0443}\u{0431}\u{043e}\u{0442}\u{0430}",
];
$monthsUk = [
    1 => "\u{0441}\u{0456}\u{0447}\u{043d}\u{044f}",
    "\u{043b}\u{044e}\u{0442}\u{043e}\u{0433}\u{043e}",
    "\u{0431}\u{0435}\u{0440}\u{0435}\u{0437}\u{043d}\u{044f}",
    "\u{043a}\u{0432}\u{0456}\u{0442}\u{043d}\u{044f}",
    "\u{0442}\u{0440}\u{0430}\u{0432}\u{043d}\u{044f}",
    "\u{0447}\u{0435}\u{0440}\u{0432}\u{043d}\u{044f}",
    "\u{043b}\u{0438}\u{043f}\u{043d}\u{044f}",
    "\u{0441}\u{0435}\u{0440}\u{043f}\u{043d}\u{044f}",
    "\u{0432}\u{0435}\u{0440}\u{0435}\u{0441}\u{043d}\u{044f}",
    "\u{0436}\u{043e}\u{0432}\u{0442}\u{043d}\u{044f}",
    "\u{043b}\u{0438}\u{0441}\u{0442}\u{043e}\u{043f}\u{0430}\u{0434}\u{0430}",
    "\u{0433}\u{0440}\u{0443}\u{0434}\u{043d}\u{044f}",
];

if ($lang === 'uk') {
    $text = $weekdaysUk[$weekdayIdx] . ', ' . $day . ' ' . $monthsUk[$monthIdx] . ' ' . $time;
} else {
    $text = $weekdaysEn[$weekdayIdx] . ', ' . $day . ' ' . $monthsEn[$monthIdx] . ' ' . $time;
}

echo json_encode([
    'ok' => true,
    'text' => $text,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
