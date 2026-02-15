<?php
$config = require 'config/config.php';
$daemonNames = $config['daemonNames'];

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');

    $activeModule = null;
    foreach ($daemonNames as $daemon) {
        $daemonSafe = escapeshellarg($daemon);
        $status = trim((string)shell_exec("systemctl is-active -- $daemonSafe"));
        if ($status === 'active' && $activeModule === null) {
            $activeModule = $daemon;
        }
    }

    $commonLogs = '';
    if ($activeModule !== null) {
        $activeModuleSafe = escapeshellarg($activeModule);
        $commonLogs = (string)shell_exec("journalctl -u $activeModuleSafe --no-pager -n 80 2>/dev/null");
        if (trim($commonLogs) === '') {
            $commonLogs = (string)shell_exec("sudo -n journalctl -u $activeModuleSafe --no-pager -n 80 2>/dev/null");
        }
    }

    if (trim($commonLogs) === '') {
        $commonLogs = (string)shell_exec("tail -n80 /var/log/adss.log 2>/dev/null");
    }

    echo json_encode(
        [
            'ok' => true,
            'activeModule' => $activeModule,
            'commonLogs' => $commonLogs
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/image/ukraine.png" rel="icon">
    <title>ITUA</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet"/>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Roboto', sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fa, #c3cfe2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
            padding: 20px;
        }

        .container {
            background: #fff;
            max-width: 1080px;
            width: 100%;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        h1 {
            text-align: center;
            margin-bottom: 20px;
            color: #2c3e50;
        }

        .service {
            margin-bottom: 20px;
            padding: 14px;
            border: 1px solid #dfe6e9;
            border-radius: 8px;
            background: #fafafa;
        }

        .service-title {
            font-size: 1.15rem;
            font-weight: 500;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .status {
            margin-bottom: 10px;
            font-weight: 500;
        }

        .status.active {
            color: #1e824c;
        }

        .status.inactive {
            color: #c0392b;
        }

        .log-box {
            background: #111;
            color: #f1f1f1;
            border-radius: 6px;
            padding: 10px;
            height: 200px;
            overflow-y: auto;
            white-space: pre-wrap;
            font-family: Consolas, "Courier New", monospace;
            font-size: 13px;
            line-height: 1.4;
        }

        .menu {
            display: flex;
            justify-content: center;
            list-style: none;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .menu a {
            display: inline-block;
            padding: 12px 20px;
            font-size: 1.1rem;
            font-weight: 500;
            text-decoration: none;
            color: #2c3e50;
            background: #ecf0f1;
            border-radius: 8px;
            transition: background 0.3s ease, transform 0.3s ease, box-shadow 0.3s ease;
        }

        .menu a:hover {
            background: #bdc3c7;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Tools status</h1>

    <div class="service">
        <div class="service-title" id="active-module-name">Active module</div>
        <div class="status inactive" id="active-module-status">Checking...</div>
    </div>

    <div class="service">
        <div class="service-title" id="common-log-title">Common logs</div>
        <div class="log-box" id="common-log"></div>
    </div>

    <div class="menu">
        <a href="/">Back</a>
    </div>
</div>

<script>
    const serviceState = {};
    const commonLogEl = document.getElementById("common-log");
    const commonLogTitleEl = document.getElementById("common-log-title");
    const activeModuleNameEl = document.getElementById("active-module-name");
    const activeModuleStatusEl = document.getElementById("active-module-status");

    function appendOrReplace(el, newText, key) {
        const oldText = serviceState[key] || "";
        if (newText.startsWith(oldText)) {
            el.textContent += newText.slice(oldText.length);
        } else {
            el.textContent = newText;
        }
        serviceState[key] = newText;
        el.scrollTop = el.scrollHeight;
    }

    async function updateStatus() {
        try {
            const response = await fetch("/status.php?ajax=1", { cache: "no-store" });
            const data = await response.json();
            if (!data || !data.ok) {
                return;
            }

            if (data.activeModule) {
                activeModuleNameEl.textContent = data.activeModule;
                activeModuleStatusEl.textContent = data.activeModule + " is running.";
                activeModuleStatusEl.classList.add("active");
                activeModuleStatusEl.classList.remove("inactive");
                commonLogTitleEl.textContent = "Common logs (" + data.activeModule + ")";
            } else {
                activeModuleNameEl.textContent = "No active module";
                activeModuleStatusEl.textContent = "No module is running.";
                activeModuleStatusEl.classList.add("inactive");
                activeModuleStatusEl.classList.remove("active");
                commonLogTitleEl.textContent = "Common logs (no active module)";
            }
            appendOrReplace(commonLogEl, data.commonLogs || "", "common");
        } catch (e) {
        }
    }

    updateStatus();
    setInterval(updateStatus, 2000);
</script>
</body>
</html>
