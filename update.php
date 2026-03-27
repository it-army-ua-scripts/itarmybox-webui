<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/version.php';
require_once 'lib/root_helper_client.php';

$selectedBranch = webui_selected_branch();
$updateLog = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestedBranch = trim((string)($_POST['branch'] ?? ''));
    if (!in_array($requestedBranch, WEBUI_ALLOWED_BRANCHES, true)) {
        $updateLog = t('update_branch_invalid');
    } else {
        $response = root_helper_request([
            'action' => 'system_update_run',
            'modules' => (require 'config/config.php')['daemonNames'],
            'branch' => $requestedBranch,
        ]);
        $saveBranchError = false;
        if (($response['ok'] ?? false) === true) {
            if (webui_set_selected_branch($requestedBranch)) {
                $selectedBranch = $requestedBranch;
            } else {
                $saveBranchError = true;
            }
        }
        $updateLog = trim((string)($response['output'] ?? ''));
        if ($updateLog === '') {
            $updateLog = (($response['ok'] ?? false) === true)
                ? 'Update completed.'
                : (string)($response['error'] ?? 'update_failed');
        }
        if ($saveBranchError) {
            $updateLog = t('update_branch_save_failed') . "\n\n" . $updateLog;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(app_lang(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/image/ukraine.png" rel="icon">
    <title>ITUA</title>
    <!-- Import Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet" />
    <link href="/styles.css" rel="stylesheet" />
</head>
<body class="padded">

<div class="container">
    <div class="content">
        <h1><?= htmlspecialchars(t('update'), ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="service" style="max-width: 760px; margin: 0 auto 20px; text-align: left;">
            <div class="service-title"><?= htmlspecialchars(t('update_branch'), ENT_QUOTES, 'UTF-8') ?></div>
            <p><strong><?= htmlspecialchars(t('current_update_branch'), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($selectedBranch, ENT_QUOTES, 'UTF-8') ?></p>
            <p><?= htmlspecialchars(t('dev_branch_note'), ENT_QUOTES, 'UTF-8') ?></p>
            <form method="post" action="" class="branch-switch-actions">
                <button class="submit-btn<?= $selectedBranch === 'main' ? ' active-branch-btn' : '' ?>" type="submit" name="branch" value="main"><?= htmlspecialchars(t('update_branch_main'), ENT_QUOTES, 'UTF-8') ?></button>
                <button class="submit-btn<?= $selectedBranch === 'dev' ? ' active-branch-btn' : '' ?>" type="submit" name="branch" value="dev"><?= htmlspecialchars(t('update_branch_dev'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        </div>
        <h1><?= htmlspecialchars(t('update_log'), ENT_QUOTES, 'UTF-8') ?>:</h1>
        <div class="service" style="max-width: 760px; margin: 0 auto;">
            <div class="log-box tool-log-box"><?= nl2br(htmlspecialchars($updateLog !== '' ? $updateLog : t('update_branch_select_hint'), ENT_QUOTES, 'UTF-8')) ?></div>
        </div>
        <div class="menu">
            <?= render_back_link('/') ?>
        </div>
    </div>
</div>
<?= render_app_footer() ?>
</body>
</html>
