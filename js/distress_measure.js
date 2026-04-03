(function () {
    const configEl = document.getElementById("distress-measure-config");
    const formEl = document.getElementById("distress-settings-form");
    const measureButtonEl = document.getElementById("distress-measure-button");
    const modalEl = document.getElementById("distress-measure-modal");
    const closeEl = document.getElementById("distress-measure-close");
    const statusEl = document.getElementById("distress-measure-status");
    const detailEl = document.getElementById("distress-measure-detail");
    const progressBarEl = document.getElementById("distress-measure-progress-bar");
    const progressPercentEl = document.getElementById("distress-measure-progress-percent");
    const resultEl = document.getElementById("distress-measure-result");
    const modeEl = document.getElementById("distress-concurrency-mode");
    const startLinkEl = document.getElementById("distress-start-link");
    const startHintEl = document.getElementById("distress-start-gate-hint");
    const autotunePanelEl = document.getElementById("distress-autotune-panel");

    if (!configEl || !formEl || !measureButtonEl || !modalEl || !statusEl || !detailEl || !progressBarEl || !progressPercentEl || !resultEl) {
        return;
    }

    let requestInFlight = false;
    let progressValue = 0;
    let progressTimer = null;
    let progressPollAbortController = null;

    const config = JSON.parse(configEl.textContent || "{}");
    const text = config.text || {};
    let hasMeasurement = config.hasMeasurement === true;
    let cooldownRemaining = typeof config.cooldownRemaining === "number" ? config.cooldownRemaining : 0;
    let blockedByActiveModules = Array.isArray(config.blockedByActiveModules) ? config.blockedByActiveModules : [];
    let cooldownTimer = null;

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

    function mapStatus(status) {
        switch (status) {
            case "running":
                return text.statusRunning;
            case "success":
                return text.statusSuccess;
            case "failed":
                return text.statusFailed;
            case "skipped":
                return text.statusSkipped;
            default:
                return text.statusIdle;
        }
    }

    function setProgress(value) {
        progressValue = Math.max(0, Math.min(100, value));
        progressBarEl.style.width = progressValue + "%";
        progressPercentEl.textContent = Math.round(progressValue) + "%";
    }

    function setResultMessage(message, ok) {
        resultEl.hidden = false;
        resultEl.textContent = message || "";
        resultEl.className = "form-message distress-measure-result " + (ok ? "status active" : "status inactive");
    }

    function updateMeasureCooldownHint() {
        const hintEl = document.getElementById("distress-measure-cooldown-hint");
        const blockedHintEl = document.getElementById("distress-measure-blocked-hint");
        if (!measureButtonEl || !hintEl || !blockedHintEl) {
            return;
        }

        const cooldownBlocked = cooldownRemaining > 0;
        const activeModulesBlocked = blockedByActiveModules.length > 0;
        measureButtonEl.disabled = cooldownBlocked || activeModulesBlocked || requestInFlight;

        hintEl.hidden = !cooldownBlocked;
        if (cooldownBlocked) {
            hintEl.textContent = (text.cooldownText || "").replace("{{seconds}}", String(cooldownRemaining));
        } else {
            hintEl.textContent = "";
        }

        blockedHintEl.hidden = !activeModulesBlocked;
        blockedHintEl.textContent = activeModulesBlocked ? (text.blockedText || "") : "";
    }

    function stopCooldownTimer() {
        if (cooldownTimer !== null) {
            window.clearInterval(cooldownTimer);
            cooldownTimer = null;
        }
    }

    function startCooldownTimer() {
        stopCooldownTimer();
        updateMeasureCooldownHint();
        if (cooldownRemaining <= 0) {
            return;
        }
        cooldownTimer = window.setInterval(function () {
            if (cooldownRemaining > 0) {
                cooldownRemaining -= 1;
                updateMeasureCooldownHint();
            }
            if (cooldownRemaining <= 0) {
                stopCooldownTimer();
            }
        }, 1000);
    }

    function showModal() {
        modalEl.hidden = false;
        document.body.classList.add("modal-open");
    }

    function hideModal() {
        if (requestInFlight) {
            detailEl.textContent = text.progressCloseBlocked || "";
            return;
        }
        modalEl.hidden = true;
        document.body.classList.remove("modal-open");
    }

    function setLine(id, textValue, hidden) {
        const el = document.getElementById(id);
        if (!el) {
            return;
        }
        el.hidden = Boolean(hidden);
        if (!hidden) {
            el.textContent = textValue;
        }
    }

    function updatePageState(payload) {
        const statusText = (text.statusLabel || "") + " " + mapStatus(payload.uploadCapStatus || "idle");
        setLine("distress-upload-cap-status-line", statusText, false);

        if (typeof payload.uploadCapMbps === "number" && Number.isFinite(payload.uploadCapMbps)) {
            setLine("distress-upload-cap-value-line", (text.valueText || "").replace("{{value}}", payload.uploadCapMbps.toFixed(2)), false);
        } else {
            setLine("distress-upload-cap-value-line", "", true);
        }

        if (typeof payload.uploadCapMeasuredAt === "number" && Number.isFinite(payload.uploadCapMeasuredAt) && payload.uploadCapMeasuredAt > 0) {
            const measuredAt = new Date(payload.uploadCapMeasuredAt * 1000).toLocaleString();
            setLine("distress-upload-cap-measured-at-line", (text.measuredAtText || "").replace("{{value}}", measuredAt), false);
            setLine("distress-upload-cap-required-line", "", true);
            hasMeasurement = true;
        } else {
            setLine("distress-upload-cap-measured-at-line", "", true);
        }

        const methodValue = formatMethod(payload.uploadCapLastMethod || "");
        if (methodValue) {
            setLine("distress-upload-cap-method-line", (text.methodText || "").replace("{{value}}", methodValue), false);
        } else {
            setLine("distress-upload-cap-method-line", "", true);
        }

        const errorValue = typeof payload.uploadCapLastError === "string" ? payload.uploadCapLastError.trim() : "";
        if (errorValue) {
            setLine("distress-upload-cap-error-line", (text.errorText || "").replace("{{value}}", errorValue), false);
        } else {
            setLine("distress-upload-cap-error-line", "", true);
        }

        if (typeof payload.uploadCapMeasureCooldownRemaining === "number" && Number.isFinite(payload.uploadCapMeasureCooldownRemaining)) {
            cooldownRemaining = Math.max(0, Math.round(payload.uploadCapMeasureCooldownRemaining));
            startCooldownTimer();
        }
        if (Array.isArray(payload.uploadCapBlockedByActiveModules)) {
            blockedByActiveModules = payload.uploadCapBlockedByActiveModules;
        }

        updateStartGate();
        updateMeasureCooldownHint();
    }

    function applyMeasurementProgress(payload) {
        const percent = typeof payload.uploadCapProgressPercent === "number"
            ? payload.uploadCapProgressPercent
            : progressValue;
        setProgress(percent);

        const phase = payload.uploadCapProgressPhase || "idle";
        const attempt = typeof payload.uploadCapProgressAttempt === "number" ? payload.uploadCapProgressAttempt : null;
        const total = typeof payload.uploadCapProgressTotal === "number" ? payload.uploadCapProgressTotal : null;

        if (phase === "preparing") {
            statusEl.textContent = text.progressPreparing || "";
            detailEl.textContent = text.progressDetail || "";
            return;
        }

        if (phase === "attempt") {
            statusEl.textContent = text.progressRunning || "";
            if (attempt !== null && total !== null && total > 0) {
                detailEl.textContent = (text.progressAttempt || "")
                    .replace("{{current}}", String(attempt))
                    .replace("{{total}}", String(total));
            } else {
                detailEl.textContent = text.progressRunning || "";
            }
            return;
        }

        if (phase === "finalizing") {
            statusEl.textContent = text.progressAlmostDone || "";
            detailEl.textContent = text.progressAlmostDone || "";
            return;
        }

        if ((payload.uploadCapStatus || "idle") === "running") {
            statusEl.textContent = text.progressRunning || "";
            detailEl.textContent = text.progressDetail || "";
        }
    }

    function stopProgressPolling() {
        if (progressTimer !== null) {
            window.clearInterval(progressTimer);
            progressTimer = null;
        }
        if (progressPollAbortController) {
            progressPollAbortController.abort();
            progressPollAbortController = null;
        }
    }

    async function pollMeasurementProgress() {
        if (!config.measureStatusUrl) {
            return;
        }

        try {
            progressPollAbortController = new AbortController();
            const response = await fetch(config.measureStatusUrl, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    "X-Requested-With": "fetch"
                },
                signal: progressPollAbortController.signal
            });
            const payload = await response.json();
            if (payload && payload.ok === true) {
                applyMeasurementProgress(payload);
            }
        } catch (error) {
            if (error && error.name === "AbortError") {
                return;
            }
        } finally {
            progressPollAbortController = null;
        }
    }

    function startProgressPolling() {
        stopProgressPolling();
        pollMeasurementProgress();
        progressTimer = window.setInterval(function () {
            if (requestInFlight) {
                pollMeasurementProgress();
            }
        }, 700);
    }

    function updateStartGate() {
        if (!modeEl) {
            return;
        }

        const mode = modeEl.value === "auto" ? "auto" : "manual";
        if (autotunePanelEl) {
            autotunePanelEl.hidden = mode !== "auto";
        }

        if (!startLinkEl) {
            return;
        }

        const blocked = mode === "auto" && !hasMeasurement;
        const ready = mode === "auto" && hasMeasurement;
        const href = startLinkEl.dataset.startHref || startLinkEl.getAttribute("href") || "#";

        startLinkEl.classList.toggle("is-blocked", blocked);
        startLinkEl.classList.toggle("is-ready", ready);
        startLinkEl.classList.toggle("is-manual", !blocked && !ready);
        startLinkEl.setAttribute("aria-disabled", blocked ? "true" : "false");
        startLinkEl.setAttribute("href", blocked ? "#" : href);

        if (startHintEl) {
            startHintEl.hidden = !blocked && !ready;
            startHintEl.classList.toggle("is-blocked", blocked);
            startHintEl.classList.toggle("is-ready", ready);
            startHintEl.textContent = blocked
                ? (text.startAutoBlocked || "")
                : (ready ? (text.startAutoReady || "") : "");
        }
    }

    async function runMeasurement() {
        requestInFlight = true;
        updateMeasureCooldownHint();
        resultEl.hidden = true;
        resultEl.textContent = "";
        progressBarEl.classList.remove("is-success", "is-failed");
        setProgress(6);
        statusEl.textContent = text.progressPreparing || "";
        detailEl.textContent = text.progressDetail || "";
        showModal();
        startProgressPolling();

        const formData = new FormData();
        formData.set("distress-action", "measure-upload-cap");

        try {
            const response = await fetch(config.ajaxUrl || window.location.href, {
                method: "POST",
                body: formData,
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    "X-Requested-With": "fetch"
                }
            });
            const payload = await response.json();
            stopProgressPolling();
            requestInFlight = false;
            updateMeasureCooldownHint();

            const ok = payload && payload.ok === true;
            progressBarEl.classList.toggle("is-success", ok);
            progressBarEl.classList.toggle("is-failed", !ok);
            setProgress(100);
            statusEl.textContent = ok ? (text.statusSuccess || "") : (text.statusFailed || "");
            detailEl.textContent = ok ? (payload.flashText || "") : (payload.secondaryText || payload.flashText || text.progressError || "");
            setResultMessage(payload.flashText || (ok ? "" : text.progressError || ""), ok);
            updatePageState(payload || {});
            if (ok) {
                window.setTimeout(hideModal, 1200);
            }
        } catch (error) {
            stopProgressPolling();
            requestInFlight = false;
            updateMeasureCooldownHint();
            progressBarEl.classList.remove("is-success");
            progressBarEl.classList.add("is-failed");
            setProgress(100);
            statusEl.textContent = text.statusFailed || "";
            detailEl.textContent = text.progressError || "";
            setResultMessage(text.progressError || "", false);
        }
    }

    measureButtonEl.addEventListener("click", function (event) {
        event.preventDefault();
        if (!requestInFlight && cooldownRemaining <= 0 && blockedByActiveModules.length === 0) {
            runMeasurement();
        }
    });

    if (modeEl) {
        modeEl.addEventListener("change", updateStartGate);
    }

    if (startLinkEl) {
        startLinkEl.addEventListener("click", function (event) {
            if (startLinkEl.getAttribute("aria-disabled") === "true") {
                event.preventDefault();
            }
        });
    }

    closeEl.addEventListener("click", hideModal);
    modalEl.addEventListener("click", function (event) {
        if (event.target === modalEl) {
            hideModal();
        }
    });

    updateStartGate();
    startCooldownTimer();
})();
