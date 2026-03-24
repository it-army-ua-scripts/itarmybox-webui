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
    const appLangStorageKey = "itarmybox-lang";
    let activeLang = "uk";
    let versionInfo = { current: "...", github: "..." };
    let copyBotHintTimer = null;
    let powerApplyTimer = null;
    let powerPendingPercent = null;
    let headerHasData = false;
    let vnstatInstallAttempted = false;

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

    function schedulePowerApply() {
        if (powerApplyTimer) {
            window.clearTimeout(powerApplyTimer);
        }
        powerApplyTimer = window.setTimeout(() => applyTrafficLimit(powerSliderEl.value), 80);
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

        const footerSloganEl = document.getElementById("footer-slogan");
        if (footerSloganEl) {
            footerSloganEl.textContent = text.footerSlogan;
        }

        const footerVersionCurrentLabelEl = document.getElementById("footer-version-current-label");
        const footerVersionGithubLabelEl = document.getElementById("footer-version-github-label");
        const footerVersionCurrentEl = document.getElementById("footer-version-current");
        const footerVersionGithubEl = document.getElementById("footer-version-github");
        const footerUpdateStateEl = document.getElementById("footer-update-state");
        const footerUpdateActionEl = document.getElementById("footer-update-action");
        if (footerVersionCurrentLabelEl) footerVersionCurrentLabelEl.textContent = text.versionLabel;
        if (footerVersionGithubLabelEl) footerVersionGithubLabelEl.textContent = text.githubLabel;
        if (footerVersionCurrentEl) footerVersionCurrentEl.textContent = versionInfo.current;
        if (footerVersionGithubEl) footerVersionGithubEl.textContent = versionInfo.github;

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

    async function refreshTrafficLimit() {
        try {
            const data = await shared.fetchJson(config.trafficLimitUrl || "/traffic_limit.php", { cache: "no-store" });
            if (!data || data.ok !== true || powerPendingPercent !== null) {
                return;
            }
            renderPowerState(data.percent);
        } catch (e) {
        }
    }

    async function applyTrafficLimit(percent) {
        const normalized = Math.max(25, Math.min(100, Number(percent) || 100));
        powerPendingPercent = normalized;
        renderPowerState(normalized);
        powerStatusEl.textContent = getText().powerApplying;
        try {
            const data = await shared.fetchJson(config.trafficLimitUrl || "/traffic_limit.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ percent: normalized })
            });
            if (!data || data.ok !== true) {
                powerStatusEl.textContent = getText().powerApplyFailed;
                powerPendingPercent = null;
                refreshTrafficLimit();
                return;
            }
            renderPowerState(data.percent);
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
        const binaryFactors = {
            KiB: 1024,
            MiB: 1024 ** 2,
            GiB: 1024 ** 3,
            TiB: 1024 ** 4,
        };
        const decimalUnits = {
            KiB: "KB",
            MiB: "MB",
            GiB: "GB",
            TiB: "TB",
            KB: "KB",
            MB: "MB",
            GB: "GB",
            TB: "TB",
        };

        if (!Object.prototype.hasOwnProperty.call(binaryFactors, unit)) {
            return numeric.toFixed(numeric >= 100 ? 0 : (numeric >= 10 ? 1 : 2)) + " " + (decimalUnits[unit] || unit);
        }

        const bytes = numeric * binaryFactors[unit];
        const decimalUnit = decimalUnits[unit] || unit.replace("i", "");
        const divisor = {
            KB: 1000,
            MB: 1000 ** 2,
            GB: 1000 ** 3,
            TB: 1000 ** 4,
        }[decimalUnit];
        const converted = bytes / divisor;
        const digits = converted >= 100 ? 0 : (converted >= 10 ? 1 : 2);
        return converted.toFixed(digits) + " " + decimalUnit;
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

            if (!vnstatInstallAttempted && config.vnstatAutoInstall === true) {
                const hasTodayTx = typeof data.todayTx === "string" && data.todayTx.trim() !== "";
                if (!hasTodayTx) {
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
        setUserIdModalHiddenPreference(userIdModalSkipEl.checked);
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
            if (data && data.ok === true && data.userIdConfigured === false && !getUserIdModalHiddenPreference()) {
                showUserIdModal();
            } else if (data && data.ok === true && data.userIdConfigured === true) {
                setUserIdModalHiddenPreference(false);
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
        powerSliderEl.addEventListener("input", () => {
            renderPowerState(powerSliderEl.value);
            powerStatusEl.textContent = "";
        });
        powerSliderEl.addEventListener("change", schedulePowerApply);
        powerSliderEl.addEventListener("mouseup", schedulePowerApply);
        powerSliderEl.addEventListener("touchend", schedulePowerApply, { passive: true });
        powerSliderEl.addEventListener("pointerup", schedulePowerApply);
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
