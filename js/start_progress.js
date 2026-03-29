(function () {
    const shared = window.ItArmyBox || {};
    const config = shared.readJsonScript("start-progress-config", {});
    if (!config || config.daemon !== "distress") {
        return;
    }

    const stateEl = document.getElementById("start-progress-state");
    const speedStatusEl = document.getElementById("start-progress-speed-status");
    const speedValueEl = document.getElementById("start-progress-speed-value");
    const speedMethodEl = document.getElementById("start-progress-speed-method");
    const speedErrorEl = document.getElementById("start-progress-speed-error");

    let pollTimer = null;
    let completed = false;

    function setText(el, text, hidden) {
        if (!el) {
            return;
        }
        el.hidden = Boolean(hidden);
        if (!hidden) {
            el.textContent = text;
        }
    }

    function mapSpeedStatus(status) {
        switch (status) {
            case "running":
                return config.text.speedRunning;
            case "success":
                return config.text.speedSuccess;
            case "failed":
                return config.text.speedFailed;
            case "skipped":
                return config.text.speedSkipped;
            default:
                return config.text.speedIdle;
        }
    }

    function formatMethod(method) {
        switch (method) {
            case "php_curl":
                return "PHP cURL";
            case "curl_binary":
                return "curl";
            case "mixed":
                return "PHP cURL + curl";
            default:
                return method || "";
        }
    }

    function updateSpeedState(snapshot) {
        const speed = snapshot && snapshot.distressAutotune ? snapshot.distressAutotune : null;
        if (!speed) {
            setText(speedStatusEl, config.text.speedLabel + " " + config.text.speedIdle, false);
            setText(speedValueEl, "", true);
            setText(speedMethodEl, "", true);
            setText(speedErrorEl, "", true);
            return;
        }

        setText(speedStatusEl, config.text.speedLabel + " " + mapSpeedStatus(speed.uploadCapStatus || "idle"), false);

        if (typeof speed.uploadCapMbps === "number" && Number.isFinite(speed.uploadCapMbps)) {
            const measuredAt = typeof speed.uploadCapMeasuredAt === "number" && Number.isFinite(speed.uploadCapMeasuredAt)
                ? new Date(speed.uploadCapMeasuredAt * 1000).toLocaleString()
                : null;
            const valueText = config.text.speedValue.replace("{{value}}", speed.uploadCapMbps.toFixed(2))
                + (measuredAt ? " | " + config.text.speedMeasuredAt.replace("{{value}}", measuredAt) : "");
            setText(speedValueEl, valueText, false);
        } else {
            setText(speedValueEl, "", true);
        }

        const methodValue = formatMethod(speed.uploadCapLastMethod || "");
        setText(speedMethodEl, methodValue ? config.text.speedMethod.replace("{{value}}", methodValue) : "", !methodValue);

        const errorValue = typeof speed.uploadCapLastError === "string" ? speed.uploadCapLastError.trim() : "";
        setText(speedErrorEl, errorValue ? config.text.speedError.replace("{{value}}", errorValue) : "", !errorValue);
    }

    async function pollStatus() {
        if (completed) {
            return;
        }
        try {
            const snapshot = await shared.fetchJson(config.pollAjaxUrl, { cache: "no-store" });
            updateSpeedState(snapshot);
        } catch (e) {
        }
    }

    async function startDistress() {
        if (stateEl) {
            stateEl.textContent = config.text.startWaiting;
            shared.setBooleanClass(stateEl, "inactive", true);
            shared.setBooleanClass(stateEl, "active", false);
        }

        pollTimer = window.setInterval(pollStatus, 1000);
        await pollStatus();

        try {
            const result = await shared.fetchJson(config.startAjaxUrl, {
                method: "POST",
                cache: "no-store",
            });
            completed = true;
            if (pollTimer !== null) {
                window.clearInterval(pollTimer);
            }

            if (stateEl) {
                const ok = result && result.ok === true;
                stateEl.textContent = ok ? config.text.startRequested : config.text.startFailed;
                shared.setBooleanClass(stateEl, "active", ok);
                shared.setBooleanClass(stateEl, "inactive", !ok);
            }

            window.setTimeout(function () {
                window.location.href = (result && result.redirectUrl) ? result.redirectUrl : config.statusRedirectBase;
            }, 700);
        } catch (e) {
            completed = true;
            if (pollTimer !== null) {
                window.clearInterval(pollTimer);
            }
            if (stateEl) {
                stateEl.textContent = config.text.startFailed;
                shared.setBooleanClass(stateEl, "active", false);
                shared.setBooleanClass(stateEl, "inactive", true);
            }
            window.setTimeout(function () {
                window.location.href = config.statusFailedUrl || config.statusRedirectBase;
            }, 700);
        }
    }

    startDistress();
})();
