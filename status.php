<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="/image/ukraine.png" rel="icon">
    <title>ITUA</title>
    <!-- Import Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet"/>
    <style>
        /* Reset some default browser styles */
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
            max-width: 960px;
            width: 100%;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            color: #2c3e50;
        }

        .menu {
            display: flex;
            justify-content: center;
            list-style: none;
            padding: 0;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }

        .menu li {
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

        .form-container {
            max-width: 500px;
            margin: 0 auto;
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        .form-container h2 {
            text-align: center;
            margin-bottom: 20px;
            color: #2c3e50;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #2c3e50;
            outline: none;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .submit-btn {
            display: block;
            width: 100%;
            padding: 12px;
            background: #2c3e50;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 1.1rem;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .submit-btn:hover {
            background: #1a252f;
        }

        @media (max-width: 600px) {
            .menu {
                flex-direction: column;
            }
        }
        .checkbox_container {
            margin-top: 50px;
        }
        /* Make checkbox bigger */
        #autoRefreshCheckbox {
            width: 15px;
            height: 15px;
            transform: scale(1.8); /* Makes it 80% bigger */
            cursor: pointer;
            margin-right: 10px;
        }
        label {
            font-size: 20px; /* Larger label text */
            cursor: pointer;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="content">
        <h1>Tools status:</h1>
        <div class="checkbox_container">
            <label>
                <input type="checkbox" id="autoRefreshCheckbox">
                Enable Auto Refresh (Every 5 Seconds)
            </label>
        </div>
        <br/><br/>
        <?php
        $config = require 'config/config.php';
        foreach ($config['daemonNames'] as $daemon) {
            // Get the status of the daemon
            $status = shell_exec("systemctl is-active $daemon");
            if (!is_null($status) && trim($status) === 'active') {
                echo "$daemon is running.";
            } else {
                echo "$daemon is not running.";
            }
            echo "<br/>";
            // Fetch recent logs
            echo "Fetching logs from journalctl:<br/>";
            echo nl2br(shell_exec("sudo journalctl -u $daemon --no-pager -n 5"));
        }
        echo "<br/>Common logs:<br/>";
        echo nl2br(shell_exec("tail -n20 /var/log/adss.log"));
        ?>
        <div class="menu">
            <a href="/">Back</a>
        </div>

        <script>
            let refreshInterval = null; // Holds the interval ID

            // Function to start auto-refresh
            function startAutoRefresh() {
                if (!refreshInterval) {
                    refreshInterval = setInterval(() => {
                        location.reload();
                    }, 5000);
                }
            }

            // Function to stop auto-refresh
            function stopAutoRefresh() {
                clearInterval(refreshInterval);
                refreshInterval = null;
            }

            // Get the checkbox element
            const autoRefreshCheckbox = document.getElementById("autoRefreshCheckbox");

            // Check if auto-refresh was enabled before page reload
            if (localStorage.getItem("autoRefresh") === "true") {
                autoRefreshCheckbox.checked = true;
                startAutoRefresh(); // Start refreshing immediately
            }

            // Listen for checkbox changes
            autoRefreshCheckbox.addEventListener("change", function () {
                if (this.checked) {
                    localStorage.setItem("autoRefresh", "true"); // Save state
                    startAutoRefresh();
                } else {
                    localStorage.setItem("autoRefresh", "false");
                    stopAutoRefresh();
                }
            });
        </script>
</body>
</html>
