<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/tool_helpers.php';
$config = require 'config/config.php';

function detectGlobalUserId(): string
{
    $mhddos = getCurrentAdjustableParams(getConfigStringFromServiceFile('mhddos'), ['user-id'], 'mhddos');
    $distress = getCurrentAdjustableParams(getConfigStringFromServiceFile('distress'), ['user-id'], 'distress');
    $x100 = getX100ConfigValues();

    $candidates = [
        trim((string)($mhddos['user-id'] ?? '')),
        trim((string)($distress['user-id'] ?? '')),
        trim((string)($x100['itArmyUserId'] ?? '')),
    ];
    foreach ($candidates as $candidate) {
        if ($candidate !== '') {
            return $candidate;
        }
    }
    return '';
}

function saveGlobalUserId(string $userId, array $config): bool
{
    if (preg_match('/^\d+$/', $userId) !== 1) {
        return false;
    }

    $updatedModules = [];
    $updatedAny = false;

    $mhddosConfig = getConfigStringFromServiceFile('mhddos');
    if ($mhddosConfig !== '') {
        $mhddosOk = updateServiceFile(
            'mhddos',
            updateServiceConfigParams($mhddosConfig, ['user-id' => $userId], 'mhddos')
        );
        if ($mhddosOk) {
            $updatedAny = true;
            $updatedModules['mhddos'] = true;
        }
    }

    $distressConfig = getConfigStringFromServiceFile('distress');
    if ($distressConfig !== '') {
        $distressOk = updateServiceFile(
            'distress',
            updateServiceConfigParams($distressConfig, ['user-id' => $userId], 'distress')
        );
        if ($distressOk) {
            $updatedAny = true;
            $updatedModules['distress'] = true;
        }
    }

    $x100Ok = setX100ConfigValues(['itArmyUserId' => $userId]);
    if ($x100Ok) {
        $updatedAny = true;
        $updatedModules['x100'] = true;
    }

    if (!$updatedAny) {
        return false;
    }

    $status = root_helper_request([
        'action' => 'status_snapshot',
        'modules' => $config['daemonNames'],
        'lines' => 1,
    ]);
    $activeModule = (($status['ok'] ?? false) === true) ? ($status['activeModule'] ?? null) : null;
    if (
        is_string($activeModule) &&
        in_array($activeModule, $config['daemonNames'], true) &&
        isset($updatedModules[$activeModule])
    ) {
        root_helper_request([
            'action' => 'service_restart',
            'modules' => $config['daemonNames'],
            'module' => $activeModule,
        ]);
    }

    return true;
}

$userId = detectGlobalUserId();
$message = '';
$messageClass = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userIdSubmitted = trim((string)($_POST['global_user_id'] ?? ''));
    if ($userIdSubmitted === '' || preg_match('/^\d+$/', $userIdSubmitted) !== 1) {
        $message = t('error') . ': ' . t('invalid_user_id');
        $messageClass = 'status inactive';
    } else {
        $ok = saveGlobalUserId($userIdSubmitted, $config);
        $message = $ok ? t('service_updated') : (t('error') . ': ' . t('settings_not_saved'));
        $messageClass = $ok ? 'status active' : 'status inactive';
        if ($ok) {
            $userId = $userIdSubmitted;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/image/ukraine.png" rel="icon">
    <title>ITUA</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet" />
    <link href="/styles.css" rel="stylesheet" />
</head>
<body class="padded">
<div class="container">
    <div class="content centered">
        <h1><?= htmlspecialchars(t('user_id'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($message !== ''): ?>
            <div class="<?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="service" style="max-width: 760px; text-align: left;">
            <p>
                <?= htmlspecialchars(t('user_id_help_part1'), ENT_QUOTES, 'UTF-8') ?>
                <a href="https://t.me/itarmy_stats_bot" target="_blank" rel="noopener noreferrer">@itarmy_stats_bot</a>.
                <?= htmlspecialchars(t('user_id_help_part2'), ENT_QUOTES, 'UTF-8') ?>
                <a href="https://itarmy.com.ua/leaderboard/" target="_blank" rel="noopener noreferrer">itarmy.com.ua/leaderboard</a>.
            </p>
        </div>
        <div class="form-container">
            <form method="post" action="">
                <div class="form-group">
                    <label for="global_user_id"><?= htmlspecialchars(t('user_id_integer'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" id="global_user_id" name="global_user_id" value="<?= htmlspecialchars($userId, ENT_QUOTES, 'UTF-8') ?>" placeholder="digits only" required>
                </div>
                <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        </div>
        <div class="menu centered">
            <a href="<?= htmlspecialchars(url_with_lang('/settings.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
    </div>
</div>
<?= render_app_footer() ?>
</body>
</html>
