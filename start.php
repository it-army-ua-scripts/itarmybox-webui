<?php
require_once 'i18n.php';
require_once 'lib/footer.php';
require_once 'lib/start_helpers.php';
$config = require 'config/config.php';

function render_distress_start_progress_page(string $daemon): void
{
    $lang = app_lang();
    $ui = $lang === 'uk'
        ? [
            'title' => 'Запуск Distress',
            'headline' => 'Підготовка запуску Distress',
            'message' => 'Зараз виконується speed-тест для автотюну. Це може зайняти до кількох хвилин.',
            'hint' => 'Не закривайте сторінку. Після завершення запуску відкриється сторінка статусу.',
            'steps' => [
                'Перевірка налаштувань Distress',
                'Вимірювання speed для autotune',
                'Запуск модуля',
            ],
            'working' => 'Виконання...',
            'success' => 'Запуск завершено. Відкриваємо статус...',
            'failed' => 'Не вдалося запустити Distress.',
            'retry' => 'Спробувати ще раз',
        ]
        : [
            'title' => 'Starting Distress',
            'headline' => 'Preparing Distress start',
            'message' => 'A speed test for autotune is running now. This can take a couple of minutes.',
            'hint' => 'Do not close this page. You will be redirected to Status after the start finishes.',
            'steps' => [
                'Checking Distress settings',
                'Measuring speed for autotune',
                'Starting module',
            ],
            'working' => 'Working...',
            'success' => 'Start completed. Redirecting to status...',
            'failed' => 'Failed to start Distress.',
            'retry' => 'Try again',
        ];

    $ajaxUrl = htmlspecialchars(url_with_lang('/start.php?ajax=1'), ENT_QUOTES, 'UTF-8');
    $statusUrl = htmlspecialchars(url_with_lang('/status.php'), ENT_QUOTES, 'UTF-8');
    $daemonEscaped = htmlspecialchars($daemon, ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/image/ukraine.png" rel="icon">
    <title><?= htmlspecialchars($ui['title'], ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet"/>
    <link href="/styles.css" rel="stylesheet" />
    <style>
        .start-progress-card { max-width: 560px; margin: 0 auto; }
        .start-progress-steps { margin: 18px 0; padding: 0 0 0 20px; color: #c9d4e5; }
        .start-progress-steps li { margin: 10px 0; }
        .start-progress-spinner {
            width: 42px; height: 42px; border-radius: 50%;
            border: 4px solid rgba(255,255,255,0.15);
            border-top-color: #7cc7ff;
            animation: start-spin 1s linear infinite;
            margin: 18px auto;
        }
        .start-progress-actions { display: flex; justify-content: center; gap: 12px; margin-top: 20px; }
        .start-progress-actions[hidden] { display: none; }
        @keyframes start-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body class="padded status-page">
<div class="container status-container start-progress-card">
    <h1><?= htmlspecialchars($ui['headline'], ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="form-message status active" id="start-progress-message"><?= htmlspecialchars($ui['message'], ENT_QUOTES, 'UTF-8') ?></div>
    <div class="start-progress-spinner" id="start-progress-spinner" aria-hidden="true"></div>
    <div class="status active" id="start-progress-state"><?= htmlspecialchars($ui['working'], ENT_QUOTES, 'UTF-8') ?></div>
    <ol class="start-progress-steps">
        <?php foreach ($ui['steps'] as $step): ?>
            <li><?= htmlspecialchars($step, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ol>
    <div class="service-title"><?= htmlspecialchars($ui['hint'], ENT_QUOTES, 'UTF-8') ?></div>
    <div class="start-progress-actions" id="start-progress-actions" hidden>
        <form method="post" action="<?= htmlspecialchars(url_with_lang('/start.php'), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="daemon" value="<?= $daemonEscaped ?>">
            <button type="submit"><?= htmlspecialchars($ui['retry'], ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <?= render_back_link('/status.php') ?>
    </div>
</div>
<script>
(function () {
    const ajaxUrl = <?= json_encode(url_with_lang('/start.php?ajax=1'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const statusUrl = <?= json_encode(url_with_lang('/status.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const daemon = <?= json_encode($daemon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const ui = <?= json_encode($ui, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const messageEl = document.getElementById('start-progress-message');
    const stateEl = document.getElementById('start-progress-state');
    const spinnerEl = document.getElementById('start-progress-spinner');
    const actionsEl = document.getElementById('start-progress-actions');
    const body = new URLSearchParams();
    body.set('daemon', daemon);

    function fail(redirectUrl) {
        messageEl.textContent = ui.failed;
        messageEl.className = 'form-message status inactive';
        stateEl.textContent = ui.failed;
        stateEl.className = 'status inactive';
        if (spinnerEl) {
            spinnerEl.hidden = true;
        }
        if (actionsEl) {
            actionsEl.hidden = false;
        }
        window.setTimeout(() => {
            if (redirectUrl) {
                window.location.href = redirectUrl;
            }
        }, 2500);
    }

    async function poll() {
        try {
            const response = await fetch(ajaxUrl + '&status=1&daemon=' + encodeURIComponent(daemon), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await response.json().catch(() => null);
            if (!response.ok || !data) {
                throw new Error('request_failed');
            }

            if (data.status === 'success') {
                messageEl.textContent = ui.success;
                stateEl.textContent = ui.success;
                window.location.href = data.redirect || statusUrl;
                return;
            }

            if (data.status === 'failed') {
                fail(data.redirect || (statusUrl + '?msg=start_failed&ok=0'));
                return;
            }

            window.setTimeout(poll, 2000);
        } catch (error) {
            fail(statusUrl + '?msg=start_failed&ok=0');
        }
    }

    fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
        credentials: 'same-origin'
    })
        .then(async (response) => {
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || !data.ok) {
                throw new Error((data && data.redirect) ? data.redirect : (statusUrl + '?msg=start_failed&ok=0'));
            }
            window.setTimeout(poll, 800);
        })
        .catch((error) => {
            const redirectUrl = error && error.message ? error.message : (statusUrl + '?msg=start_failed&ok=0');
            fail(redirectUrl);
        });
})();
</script>
<?= render_app_footer() ?>
</body>
</html>
<?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $daemon = (string)($_POST['daemon'] ?? '');
    if (in_array($daemon, $config['daemonNames'], true)) {
        if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
            header('Content-Type: application/json; charset=UTF-8');
            if (!reset_start_task_state($daemon) || !spawn_distress_start_worker()) {
                echo json_encode([
                    'ok' => false,
                    'redirect' => url_with_lang('/status.php?msg=start_failed&ok=0'),
                    'status' => 'failed',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            echo json_encode([
                'ok' => true,
                'status' => 'pending',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if (is_distress_auto_start($daemon)) {
            render_distress_start_progress_page($daemon);
            exit;
        }

        $result = start_module_request($daemon, $config);
        $messageKey = (string)($result['messageKey'] ?? 'start_failed');
        $messageOk = (($result['ok'] ?? false) === true);
        $target = '/status.php?msg=' . rawurlencode($messageKey) . '&ok=' . ($messageOk ? '1' : '0');
        header('Location: ' . url_with_lang($target));
        exit;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['status']) && $_GET['status'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    $daemon = (string)($_GET['daemon'] ?? '');
    $state = read_start_task_state();
    $status = (string)($state['status'] ?? 'idle');
    if (($state['daemon'] ?? null) !== $daemon) {
        $status = 'idle';
    }
    $messageKey = (string)($state['messageKey'] ?? ($status === 'success' ? 'start_requested' : 'start_failed'));
    $messageOk = $status === 'success';
    $redirect = url_with_lang('/status.php?msg=' . rawurlencode($messageKey) . '&ok=' . ($messageOk ? '1' : '0'));
    echo json_encode([
        'ok' => true,
        'status' => $status,
        'redirect' => $redirect,
        'error' => $state['error'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Location: ' . url_with_lang('/status.php'));
exit;
