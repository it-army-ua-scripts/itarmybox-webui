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

    $mhddosConfig = getConfigStringFromServiceFile('mhddos');
    $distressConfig = getConfigStringFromServiceFile('distress');
    if ($mhddosConfig === '' || $distressConfig === '') {
        return false;
    }

    $mhddosOk = updateServiceFile(
        'mhddos',
        updateServiceConfigParams($mhddosConfig, ['user-id' => $userId], 'mhddos')
    );
    $distressOk = updateServiceFile(
        'distress',
        updateServiceConfigParams($distressConfig, ['user-id' => $userId], 'distress')
    );
    $x100Ok = setX100ConfigValues(['itArmyUserId' => $userId]);

    if (!$mhddosOk || !$distressOk || !$x100Ok) {
        return false;
    }

    $status = root_helper_request([
        'action' => 'status_snapshot',
        'modules' => $config['daemonNames'],
        'lines' => 1,
    ]);
    $activeModule = (($status['ok'] ?? false) === true) ? ($status['activeModule'] ?? null) : null;
    if (is_string($activeModule) && in_array($activeModule, $config['daemonNames'], true)) {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'global_user_id_save') {
    $userIdSubmitted = trim((string)($_POST['global_user_id'] ?? ''));
    if ($userIdSubmitted === '' || preg_match('/^\d+$/', $userIdSubmitted) !== 1) {
        $message = t('error') . ': invalid User ID';
        $messageClass = 'status inactive';
    } else {
        $ok = saveGlobalUserId($userIdSubmitted, $config);
        $message = $ok ? t('service_updated') : (t('error') . ': settings were not saved');
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
        <h1><?= htmlspecialchars(t('settings'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($message !== ''): ?>
            <div class="<?= htmlspecialchars($messageClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="form-container">
            <form method="post" action="">
                <input type="hidden" name="action" value="global_user_id_save">
                <div class="form-group">
                    <label for="global_user_id"><?= htmlspecialchars(t('user_id_integer'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" id="global_user_id" name="global_user_id" value="<?= htmlspecialchars($userId, ENT_QUOTES, 'UTF-8') ?>" placeholder="digits only" required>
                </div>
                <button class="submit-btn" type="submit"><?= htmlspecialchars(t('save'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        </div>
        <ul class="menu centered">
            <li><a href="<?= htmlspecialchars(url_with_lang('/autostart.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('autostart'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/update.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('update'), ENT_QUOTES, 'UTF-8') ?></a></li>
            <li><a href="<?= htmlspecialchars(url_with_lang('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('back'), ENT_QUOTES, 'UTF-8') ?></a></li>
        </ul>
    </div>
</div>
<?= render_app_footer() ?>
</body>
</html>
