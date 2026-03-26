(function () {
    const shared = window.ItArmyBox || {};
    const translations = shared.readJsonScript("home-translations", {});
    const config = shared.readJsonScript("home-config", {});

    const langEnBtn = document.getElementById("lang-en");
    const langUkBtn = document.getElementById("lang-uk");
    const titleEl = document.getElementById("title");
    const statusEl = document.getElementById("link-status");
    const toolsEl = document.getElementById("link-tools");
    const settingsEl = document.getElementById("link-settings");
    const headerBarEl = document.getElementById("app-header-bar");
    const todayTxLabelEl = document.getElementById("today-tx-label");
    const todayTxValueEl = document.getElementById("today-tx-value");
    const powerPercentEl = document.getElementById("power-percent");
    const powerRateEl = document.getElementById("power-rate");
    const powerNoteEl = document.getElementById("power-note");
    const powerHelpEl = document.getElementById("power-help");
    const powerLeverEl = document.querySelector(".power-lever");
    const powerSliderEl = document.getElementById("power-slider");
    const powerStatusEl = document.getElementById("power-status");
    const monitorTxLabelEl = document.getElementById("monitor-tx-label");
    const monitorTxValueEl = document.getElementById("monitor-tx-value");
    const monitorRamValueEl = document.getElementById("monitor-ram-value");
    const monitorCpuValueEl = document.getElementById("monitor-cpu-value");
    const monitorTempLabelEl = document.getElementById("monitor-temp-label");
    const monitorTempValueEl = document.getElementById("monitor-temp-value");
    const monitorIpLabelEl = document.getElementById("monitor-ip-label");
    const monitorIpValueEl = document.getElementById("monitor-ip-value");
    const monitorMemoryTempCardEl = document.getElementById("monitor-memory-temp-card");
    const monitorMemoryTempLabelEl = document.getElementById("monitor-memory-temp-label");
    const monitorMemoryTempValueEl = document.getElementById("monitor-memory-temp-value");
    const userIdModalEl = document.getElementById("user-id-modal");
    const userIdModalBadgeEl = document.getElementById("user-id-modal-badge");
    const userIdModalTitleEl = document.getElementById("user-id-modal-title");
    const userIdModalTextEl = document.getElementById("user-id-modal-text");
    const userIdModalCloseEl = document.getElementById("user-id-modal-close");
    const userIdModalLinkEl = document.getElementById("user-id-modal-link");
    const copyBotNameEl = document.getElementById("copy-bot-name");
    const copyBotHintEl = document.getElementById("copy-bot-hint");
    const userIdModalSkipEl = document.getElementById("user-id-modal-skip");
    const userIdModalSkipLabelEl = document.getElementById("user-id-modal-skip-label");

    const userIdModalPreferenceKey = "itarmybox-hide-userid-modal";
    const userIdModalSnoozeKey = "itarmybox-userid-snooze-until";
    const appLangStorageKey = "itarmybox-lang";
    let activeLang = "uk";
    let versionInfo = { branch: "...", current: "...", github: "..." };
    let copyBotHintTimer = null;
    let powerApplyTimer = null;
    let powerPendingPercent = null;
    let headerHasData = false;
    let vnstatStatusChecked = false;
    let vnstatInstallAttempted = false;
    let lastAutoApplyAt = 0;
    let isDraggingPower = false;
    let powerScheduleLocked = false;
    let powerScheduleModule = "";
    let powerSchedulePercent = null;
    const trafficDesiredKey = "itarmybox-traffic-desired";

    function getText() {
        return translations[activeLang] || translations.uk || translations.en || {};
    }

    function getStoredLang() {
        const stored = shared.getStorage(appLangStorageKey);
        return stored === "uk" || stored === "en" ? stored : null;
    }

    function setStoredLang(lang) {
        shared.setStorage(appLangStorageKey, lang);
    }

    function getUserIdModalHiddenPreference() {
        return shared.getStorage(userIdModalPreferenceKey) === "1";
    }

    function setUserIdModalHiddenPreference(hidden) {
        if (hidden) {
            shared.setStorage(userIdModalPreferenceKey, "1");
            return;
        }
        shared.removeStorage(userIdModalPreferenceKey);
    }

    function getUserIdSnoozeUntil() {
        const raw = shared.getStorage(userIdModalSnoozeKey);
        const value = raw ? Number(raw) : 0;
        return Number.isFinite(value) ? value : 0;
    }

    function setUserIdSnoozeUntil(timestampMs) {
        if (!Number.isFinite(timestampMs)) {
            return;
        }
        shared.setStorage(userIdModalSnoozeKey, String(Math.floor(timestampMs)));
    }

    function clearUserIdSnooze() {
        shared.removeStorage(userIdModalSnoozeKey);
    }

    function updateLinks(lang) {
        statusEl.href = "/status.php?lang=" + encodeURIComponent(lang);
        toolsEl.href = "/tools_list.php?lang=" + encodeURIComponent(lang);
        settingsEl.href = "/settings.php?lang=" + encodeURIComponent(lang);
        const footerUpdateActionEl = document.getElementById("footer-update-action");
        if (footerUpdateActionEl) {
            footerUpdateActionEl.href = "/settings.php?lang=" + encodeURIComponent(lang);
        }
        userIdModalLinkEl.href = "/user_id.php?lang=" + encodeURIComponent(lang);
    }

    function percentToMbit(percent) {
        const clamped = Math.max(25, Math.min(100, Number(percent) || 100));
        if (clamped <= 80) {
            return Math.round(20 + ((clamped - 25) * (300 - 20) / (80 - 25)));
        }
        return Math.round(300 + ((clamped - 80) * (750 - 300) / (100 - 80)));
    }

    function renderPowerState(percent) {
        const normalized = Math.max(25, Math.min(100, Number(percent) || 100));
        powerSliderEl.value = String(normalized);
        powerPercentEl.textContent = String(normalized) + "%";
        powerRateEl.textContent = String(percentToMbit(normalized)) + " Мбіт/с";
        powerSliderEl.style.setProperty("--power-fill", ((normalized - 25) / 75 * 100).toFixed(2) + "%");
    }

    function getDesiredTrafficPercent() {
        const raw = shared.getStorage(trafficDesiredKey);
        const value = raw ? Number(raw) : 0;
        return Number.isFinite(value) ? value : 0;
    }

    function setDesiredTrafficPercent(value) {
        if (!Number.isFinite(value)) {
            return;
        }
        shared.setStorage(trafficDesiredKey, String(Math.round(value)));
    }

    function clearDesiredTrafficPercent() {
        shared.removeStorage(trafficDesiredKey);
    }

    function showPowerScheduleLockedMessage() {
        const text = getText();
        const moduleLabel = powerScheduleModule
            ? String(powerScheduleModule).toUpperCase()
            : "";
        powerStatusEl.textContent = moduleLabel
            ? text.powerControlledByScheduleHint.replace("{{module}}", moduleLabel)
            : text.powerControlledByScheduleHintGeneric;
    }

    function setPowerScheduleLockState(locked, scheduleModule, schedulePercent = null) {
        powerScheduleLocked = locked === true;
        powerScheduleModule = powerScheduleLocked && typeof scheduleModule === "string" ? scheduleModule : "";
        powerSchedulePercent = powerScheduleLocked && Number.isFinite(Number(schedulePercent))
            ? Math.max(25, Math.min(100, Number(schedulePercent)))
            : null;
        powerSliderEl.setAttribute("aria-disabled", powerScheduleLocked ? "true" : "false");
        powerSliderEl.classList.toggle("is-locked", powerScheduleLocked);
        if (powerScheduleLocked) {
            const text = getText();
            const moduleLabel = powerScheduleModule
                ? String(powerScheduleModule).toUpperCase()
                : "";
            powerStatusEl.textContent = moduleLabel
                ? text.powerControlledBySchedule.replace("{{module}}", moduleLabel)
                : text.powerControlledByScheduleGeneric;
            clearDesiredTrafficPercent();
            return;
        }
        powerStatusEl.textContent = getText().powerApplied;
    }

    function schedulePowerApply() {
        if (powerApplyTimer) {
            window.clearTimeout(powerApplyTimer);
        }
        powerApplyTimer = window.setTimeout(() => applyTrafficLimit(powerSliderEl.value), 140);
    }

    function applyLang(lang) {
        activeLang = lang === "uk" || lang === "en" ? lang : "en";
        document.documentElement.lang = activeLang;
        const text = getText();
        titleEl.textContent = text.title;
        statusEl.textContent = text.status;
        toolsEl.textContent = text.tools;
        settingsEl.textContent = text.settings;
        if (headerHasData) {
            todayTxLabelEl.textContent = text.todayTxLabel;
        }
        powerNoteEl.textContent = text.powerNote;
        powerHelpEl.textContent = text.powerHelp;
        monitorTempLabelEl.textContent = text.monitorTemperature;
        monitorIpLabelEl.textContent = text.monitorIp;
        monitorMemoryTempLabelEl.textContent = text.monitorMemoryTemperature;
        if (powerScheduleLocked) {
            setPowerScheduleLockState(true, powerScheduleModule);
        }

        const footerSloganEl = document.getElementById("footer-slogan");
        if (footerSloganEl) {
            footerSloganEl.textContent = text.footerSlogan;
        }

        const footerVersionCurrentLabelEl = document.getElementById("footer-version-current-label");
        const footerVersionGithubLabelEl = document.getElementById("footer-version-github-label");
        const footerBranchLabelEl = document.getElementById("footer-branch-label");
        const footerVersionCurrentEl = document.getElementById("footer-version-current");
        const footerVersionGithubEl = document.getElementById("footer-version-github");
        const footerVersionBranchEl = document.getElementById("footer-version-branch");
        const footerUpdateStateEl = document.getElementById("footer-update-state");
        const footerUpdateActionEl = document.getElementById("footer-update-action");
        if (footerVersionCurrentLabelEl) footerVersionCurrentLabelEl.textContent = text.versionLabel;
        if (footerVersionGithubLabelEl) footerVersionGithubLabelEl.textContent = text.githubLabel;
        if (footerBranchLabelEl) footerBranchLabelEl.textContent = text.branchLabel;
        if (footerVersionCurrentEl) footerVersionCurrentEl.textContent = versionInfo.current;
        if (footerVersionGithubEl) footerVersionGithubEl.textContent = versionInfo.github;
        if (footerVersionBranchEl) footerVersionBranchEl.textContent = versionInfo.branch;

        const haveBoth = versionInfo.current !== "unknown" &&
            versionInfo.github !== "unknown" &&
            versionInfo.current !== "..." &&
            versionInfo.github !== "...";
        const updateNeeded = haveBoth && versionInfo.current !== versionInfo.github;
        if (footerUpdateStateEl) {
            footerUpdateStateEl.textContent = updateNeeded ? text.updateAvailable : text.upToDate;
            shared.setBooleanClass(footerUpdateStateEl, "update-needed", updateNeeded);
            shared.setBooleanClass(footerUpdateStateEl, "up-to-date", !updateNeeded);
        }
        if (footerUpdateActionEl) {
            footerUpdateActionEl.textContent = text.updateNow;
            shared.setBooleanClass(footerUpdateActionEl, "visible", updateNeeded);
        }

        userIdModalTitleEl.textContent = text.userIdMissingTitle;
        userIdModalBadgeEl.textContent = text.userIdMissingBadge;
        userIdModalTextEl.textContent = text.userIdMissingNotice;
        userIdModalCloseEl.textContent = text.closeModal;
        userIdModalLinkEl.textContent = text.openUserIdSettings;
        copyBotHintEl.textContent = text.copyBotHint;
        userIdModalSkipLabelEl.textContent = text.skipUserIdModal;
        shared.setBooleanClass(langEnBtn, "active", activeLang === "en");
        shared.setBooleanClass(langUkBtn, "active", activeLang === "uk");
        updateLinks(activeLang);
    }

    function syncLangUrl(lang) {
        try {
            const url = new URL(window.location.href);
            url.searchParams.set("lang", lang);
            window.history.replaceState({}, "", url.toString());
        } catch (e) {
        }
    }

    async function ensureVnstatAvailable() {
        if (vnstatInstallAttempted || config.vnstatAutoInstall !== true) {
            return;
        }

        try {
            const status = await shared.fetchJson(config.vnstatInstallUrl || "/vnstat.php", { cache: "no-store" });
            vnstatStatusChecked = true;
            if (status && status.ok === true && status.ready === true) {
                vnstatInstallAttempted = true;
                return;
            }
        } catch (e) {
            vnstatStatusChecked = true;
        }

        vnstatInstallAttempted = true;
        try {
            await shared.fetchJson(config.vnstatInstallUrl || "/vnstat.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ install: true })
            });
            window.setTimeout(refreshSystemMonitor, 4000);
        } catch (e) {
        }
    }

    async function refreshTrafficLimit() {
        try {
            const data = await shared.fetchJson(config.trafficLimitUrl || "/traffic_limit.php", { cache: "no-store" });
            if (!data || data.ok !== true || powerPendingPercent !== null || isDraggingPower) {
                if (data && data.scheduleLocked === true) {
                    renderPowerState(data.schedulePercent || data.percent);
                    setPowerScheduleLockState(true, data.scheduleModule, data.schedulePercent || data.percent);
                }
                return;
            }
            renderPowerState(data.percent);
            setPowerScheduleLockState(data.scheduleLocked === true, data.scheduleModule, data.schedulePercent || data.percent);
            const desired = getDesiredTrafficPercent();
            if (data.scheduleLocked === true) {
                return;
            }
            if (desired >= 25 && desired <= 100 && Math.abs(desired - data.percent) >= 2) {
                powerStatusEl.textContent = getText().powerApplying;
            } else {
                powerStatusEl.textContent = getText().powerApplied;
            }
            const now = Date.now();
            if (!isDraggingPower && desired >= 25 && desired <= 100 && Math.abs(desired - data.percent) >= 2) {
                if (now - lastAutoApplyAt > 30000) {
                    lastAutoApplyAt = now;
                    applyTrafficLimit(desired);
                }
            }
        } catch (e) {
        }
    }

    async function applyTrafficLimit(percent) {
        if (powerScheduleLocked) {
            return;
        }
        const normalized = Math.max(25, Math.min(100, Number(percent) || 100));
        powerPendingPercent = normalized;
        renderPowerState(normalized);
        setDesiredTrafficPercent(normalized);
        powerStatusEl.textContent = getText().powerApplying;
        try {
            const data = await shared.fetchJson(config.trafficLimitUrl || "/traffic_limit.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ percent: normalized })
            });
            if (!data || data.ok !== true) {
                if (data && data.scheduleLocked === true) {
                    renderPowerState(data.currentPercent || data.schedulePercent || normalized);
                    setPowerScheduleLockState(true, data.scheduleModule, data.currentPercent || data.schedulePercent || normalized);
                    showPowerScheduleLockedMessage();
                    powerPendingPercent = null;
                    refreshTrafficLimit();
                    return;
                } else {
                    setPowerScheduleLockState(false);
                }
                powerStatusEl.textContent = getText().powerApplyFailed;
                powerPendingPercent = null;
                refreshTrafficLimit();
                return;
            }
            renderPowerState(data.percent);
            setPowerScheduleLockState(data.scheduleLocked === true, data.scheduleModule, data.schedulePercent || data.percent);
            setDesiredTrafficPercent(data.percent);
            powerStatusEl.textContent = getText().powerApplied;
            powerPendingPercent = null;
        } catch (e) {
            powerStatusEl.textContent = getText().powerApplyFailed;
            powerPendingPercent = null;
            refreshTrafficLimit();
        }
    }

    function formatPercent(value) {
        return typeof value === "number" && Number.isFinite(value)
            ? value.toFixed(1) + "%"
            : getText().monitorUnavailable;
    }

    function formatTemperature(value) {
        return typeof value === "number" && Number.isFinite(value)
            ? value.toFixed(1) + "°C"
            : getText().monitorUnavailable;
    }

    function formatVnstatAmount(value) {
        if (typeof value !== "string") {
            return getText().monitorUnavailable;
        }
        const trimmed = value.trim();
        const match = trimmed.match(/^([0-9]+(?:[.,][0-9]+)?)\s*([KMGT]?i?B)$/i);
        if (!match) {
            return trimmed || getText().monitorUnavailable;
        }

        const numeric = Number(match[1].replace(",", "."));
        if (!Number.isFinite(numeric)) {
            return trimmed || getText().monitorUnavailable;
        }

        const unit = match[2];
        const normalizedUnit = unit.charAt(0).toUpperCase() + unit.slice(1);
        const digits = numeric >= 100 ? 0 : (numeric >= 10 ? 1 : 2);
        return numeric.toFixed(digits) + " " + normalizedUnit;
    }

    function setHeaderStat(rawValue) {
        const text = getText();
        const hasData = typeof rawValue === "string" && rawValue.trim() !== "";
        headerHasData = hasData;
        if (!hasData) {
            todayTxLabelEl.textContent = "";
            todayTxValueEl.textContent = "";
            shared.setBooleanClass(headerBarEl, "is-empty", true);
            return;
        }
        todayTxLabelEl.textContent = text.todayTxLabel;
        todayTxValueEl.textContent = formatVnstatAmount(rawValue);
        shared.setBooleanClass(headerBarEl, "is-empty", false);
    }

    async function refreshSystemMonitor() {
        try {
            const data = await shared.fetchJson(config.systemMonitorUrl || "/system_monitor.php", { cache: "no-store" });
            if (!data || data.ok !== true) {
                return;
            }
            const iface = typeof data.iface === "string" && data.iface ? data.iface : "eth0";
            monitorTxLabelEl.textContent = "TX " + iface;
            monitorTxValueEl.textContent = typeof data.txRate === "string" && data.txRate ? data.txRate : getText().monitorUnavailable;
            setHeaderStat(data.todayTx);
            monitorRamValueEl.textContent = formatPercent(data.ramPercent);
            monitorCpuValueEl.textContent = formatPercent(data.cpuPercent);
            monitorTempValueEl.textContent = formatTemperature(data.temperatureC);
            monitorIpValueEl.textContent = typeof data.ipv4 === "string" && data.ipv4 ? data.ipv4 : getText().monitorUnavailable;

            if (typeof data.memoryTemperatureC === "number" && Number.isFinite(data.memoryTemperatureC)) {
                monitorMemoryTempCardEl.hidden = false;
                monitorMemoryTempValueEl.textContent = formatTemperature(data.memoryTemperatureC);
            } else {
                monitorMemoryTempCardEl.hidden = true;
            }

            if (!vnstatStatusChecked && config.vnstatAutoInstall === true) {
                await ensureVnstatAvailable();
            }
        } catch (e) {
            const fallback = getText().monitorUnavailable;
            setHeaderStat("");
            monitorTxValueEl.textContent = fallback;
            monitorRamValueEl.textContent = fallback;
            monitorCpuValueEl.textContent = fallback;
            monitorTempValueEl.textContent = fallback;
            monitorIpValueEl.textContent = fallback;
            monitorMemoryTempCardEl.hidden = true;
        }
    }

    async function copyBotName() {
        const botName = copyBotNameEl.textContent.trim();
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(botName);
            } else {
                const textArea = document.createElement("textarea");
                textArea.value = botName;
                textArea.setAttribute("readonly", "");
                textArea.style.position = "absolute";
                textArea.style.left = "-9999px";
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand("copy");
                document.body.removeChild(textArea);
            }
            copyBotHintEl.textContent = getText().copiedBotHint;
            copyBotNameEl.classList.add("copied");
            if (copyBotHintTimer) {
                window.clearTimeout(copyBotHintTimer);
            }
            copyBotHintTimer = window.setTimeout(() => {
                copyBotHintEl.textContent = getText().copyBotHint;
                copyBotNameEl.classList.remove("copied");
            }, 1800);
        } catch (e) {
            copyBotHintEl.textContent = botName;
        }
    }

    function showUserIdModal() {
        userIdModalSkipEl.checked = getUserIdModalHiddenPreference();
        userIdModalEl.hidden = false;
        document.body.classList.add("modal-open");
        userIdModalCloseEl.focus();
    }

    function hideUserIdModal() {
        setUserIdModalHiddenPreference(false);
        if (userIdModalSkipEl.checked) {
            setUserIdSnoozeUntil(Date.now() + 24 * 60 * 60 * 1000);
        } else {
            clearUserIdSnooze();
        }
        userIdModalEl.hidden = true;
        document.body.classList.remove("modal-open");
    }

    async function fetchVersionInfo() {
        try {
            const data = await shared.fetchJson(config.versionInfoUrl || "/version_info.php", { cache: "no-store" });
            if (!data || data.ok !== true) {
                return;
            }
            versionInfo = {
                branch: data.branch || "...",
                current: data.current || "unknown",
                github: data.github || "unknown"
            };
            applyLang(activeLang);
        } catch (e) {
        }
    }

    async function refreshMainStatusIndicator() {
        try {
            const url = (config.statusAjaxBaseUrl || "/status.php?ajax=1") + "&lang=" + encodeURIComponent(activeLang);
            const data = await shared.fetchJson(url, { cache: "no-store" });
            const isActive = !!(data && data.ok === true && data.activeModule);
            shared.setBooleanClass(statusEl, "main-status-active", isActive);
            shared.setBooleanClass(statusEl, "main-status-inactive", !isActive);
        } catch (e) {
            statusEl.classList.remove("main-status-active");
            statusEl.classList.add("main-status-inactive");
        }
    }

    async function notifyIfUserIdMissing() {
        try {
            const data = await shared.fetchJson(config.userIdStatusUrl || "/user_id.php?ajax=status", { cache: "no-store" });
            const snoozeUntil = getUserIdSnoozeUntil();
            const shouldSnooze = snoozeUntil > Date.now();
            if (data && data.ok === true && data.userIdConfigured === false && !getUserIdModalHiddenPreference() && !shouldSnooze) {
                showUserIdModal();
            } else if (data && data.ok === true && data.userIdConfigured === true) {
                setUserIdModalHiddenPreference(false);
                clearUserIdSnooze();
            }
        } catch (e) {
        }
    }

    function setLang(lang) {
        document.cookie = "lang=" + encodeURIComponent(lang) + "; path=/; max-age=31536000";
        setStoredLang(lang);
        syncLangUrl(lang);
        applyLang(lang);
    }

    function closeUserIdModalFromAction(event) {
        event.preventDefault();
        event.stopPropagation();
        hideUserIdModal();
    }

    function init() {
        const params = new URLSearchParams(window.location.search);
        const initialLang = params.get("lang") || shared.getCookie("lang") || getStoredLang() || "uk";
        setStoredLang(initialLang);
        syncLangUrl(initialLang);
        applyLang(initialLang);
        fetchVersionInfo();
        refreshMainStatusIndicator();
        refreshTrafficLimit();
        refreshSystemMonitor();
        notifyIfUserIdMissing();

        window.setInterval(refreshMainStatusIndicator, 5000);
        window.setInterval(refreshSystemMonitor, 4000);
        window.setInterval(refreshTrafficLimit, 15000);

        langEnBtn.addEventListener("click", () => setLang("en"));
        langUkBtn.addEventListener("click", () => setLang("uk"));
        powerSliderEl.addEventListener("pointerdown", () => {
            if (powerScheduleLocked) {
                if (powerSchedulePercent !== null) {
                    renderPowerState(powerSchedulePercent);
                }
                showPowerScheduleLockedMessage();
                return;
            }
            isDraggingPower = true;
            if (powerLeverEl) {
                powerLeverEl.classList.add("is-dragging");
            }
        });
        powerSliderEl.addEventListener("touchstart", () => {
            if (powerScheduleLocked) {
                if (powerSchedulePercent !== null) {
                    renderPowerState(powerSchedulePercent);
                }
                showPowerScheduleLockedMessage();
                return;
            }
            isDraggingPower = true;
            if (powerLeverEl) {
                powerLeverEl.classList.add("is-dragging");
            }
        }, { passive: true });
        powerSliderEl.addEventListener("input", () => {
            if (powerScheduleLocked) {
                if (powerSchedulePercent !== null) {
                    renderPowerState(powerSchedulePercent);
                }
                showPowerScheduleLockedMessage();
                return;
            }
            renderPowerState(powerSliderEl.value);
            powerStatusEl.textContent = "";
        });
        powerSliderEl.addEventListener("change", () => {
            if (powerScheduleLocked) {
                if (powerSchedulePercent !== null) {
                    renderPowerState(powerSchedulePercent);
                }
                showPowerScheduleLockedMessage();
                return;
            }
            isDraggingPower = false;
            if (powerLeverEl) {
                powerLeverEl.classList.remove("is-dragging");
            }
            schedulePowerApply();
        });
        powerSliderEl.addEventListener("mouseup", () => {
            if (powerScheduleLocked) {
                if (powerSchedulePercent !== null) {
                    renderPowerState(powerSchedulePercent);
                }
                showPowerScheduleLockedMessage();
                return;
            }
            isDraggingPower = false;
            if (powerLeverEl) {
                powerLeverEl.classList.remove("is-dragging");
            }
            schedulePowerApply();
        });
        powerSliderEl.addEventListener("touchend", () => {
            if (powerScheduleLocked) {
                if (powerSchedulePercent !== null) {
                    renderPowerState(powerSchedulePercent);
                }
                showPowerScheduleLockedMessage();
                return;
            }
            isDraggingPower = false;
            if (powerLeverEl) {
                powerLeverEl.classList.remove("is-dragging");
            }
            schedulePowerApply();
        }, { passive: true });
        powerSliderEl.addEventListener("pointerup", () => {
            if (powerScheduleLocked) {
                if (powerSchedulePercent !== null) {
                    renderPowerState(powerSchedulePercent);
                }
                showPowerScheduleLockedMessage();
                return;
            }
            isDraggingPower = false;
            if (powerLeverEl) {
                powerLeverEl.classList.remove("is-dragging");
            }
            schedulePowerApply();
        });
        powerSliderEl.addEventListener("pointercancel", () => {
            isDraggingPower = false;
            if (powerLeverEl) {
                powerLeverEl.classList.remove("is-dragging");
            }
        });
        powerSliderEl.addEventListener("touchcancel", () => {
            isDraggingPower = false;
            if (powerLeverEl) {
                powerLeverEl.classList.remove("is-dragging");
            }
        });
        userIdModalCloseEl.addEventListener("click", closeUserIdModalFromAction);
        userIdModalCloseEl.addEventListener("pointerup", closeUserIdModalFromAction);
        userIdModalCloseEl.addEventListener("touchend", closeUserIdModalFromAction, { passive: false });
        copyBotNameEl.addEventListener("click", copyBotName);
        userIdModalEl.addEventListener("click", (event) => {
            if (event.target === userIdModalEl) {
                hideUserIdModal();
            }
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && !userIdModalEl.hidden) {
                hideUserIdModal();
            }
        });
    }

    init();
})();
