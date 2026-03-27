(function () {
    const shared = window.ItArmyBox || {};
    const app = window.ItArmyHome || {};

    app.shared = shared;
    app.translations = shared.readJsonScript("home-translations", {});
    app.config = shared.readJsonScript("home-config", {});
    app.els = {
        langEnBtn: document.getElementById("lang-en"),
        langUkBtn: document.getElementById("lang-uk"),
        titleEl: document.getElementById("title"),
        statusEl: document.getElementById("link-status"),
        toolsEl: document.getElementById("link-tools"),
        settingsEl: document.getElementById("link-settings"),
        headerBarEl: document.getElementById("app-header-bar"),
        todayTxLabelEl: document.getElementById("today-tx-label"),
        todayTxValueEl: document.getElementById("today-tx-value"),
        powerPercentEl: document.getElementById("power-percent"),
        powerRateEl: document.getElementById("power-rate"),
        powerNoteEl: document.getElementById("power-note"),
        powerHelpEl: document.getElementById("power-help"),
        powerLeverEl: document.querySelector(".power-lever"),
        powerSliderEl: document.getElementById("power-slider"),
        powerStatusEl: document.getElementById("power-status"),
        monitorTxLabelEl: document.getElementById("monitor-tx-label"),
        monitorTxValueEl: document.getElementById("monitor-tx-value"),
        monitorRamValueEl: document.getElementById("monitor-ram-value"),
        monitorCpuValueEl: document.getElementById("monitor-cpu-value"),
        monitorTempLabelEl: document.getElementById("monitor-temp-label"),
        monitorTempValueEl: document.getElementById("monitor-temp-value"),
        monitorIpLabelEl: document.getElementById("monitor-ip-label"),
        monitorIpValueEl: document.getElementById("monitor-ip-value"),
        monitorMemoryTempCardEl: document.getElementById("monitor-memory-temp-card"),
        monitorMemoryTempLabelEl: document.getElementById("monitor-memory-temp-label"),
        monitorMemoryTempValueEl: document.getElementById("monitor-memory-temp-value"),
        userIdModalEl: document.getElementById("user-id-modal"),
        userIdModalBadgeEl: document.getElementById("user-id-modal-badge"),
        userIdModalTitleEl: document.getElementById("user-id-modal-title"),
        userIdModalTextEl: document.getElementById("user-id-modal-text"),
        userIdModalCloseEl: document.getElementById("user-id-modal-close"),
        userIdModalLinkEl: document.getElementById("user-id-modal-link"),
        copyBotNameEl: document.getElementById("copy-bot-name"),
        copyBotHintEl: document.getElementById("copy-bot-hint"),
        userIdModalSkipEl: document.getElementById("user-id-modal-skip"),
        userIdModalSkipLabelEl: document.getElementById("user-id-modal-skip-label")
    };
    app.keys = {
        userIdModalPreferenceKey: "itarmybox-hide-userid-modal",
        userIdModalSnoozeKey: "itarmybox-userid-snooze-until",
        appLangStorageKey: "itarmybox-lang",
        trafficDesiredKey: "itarmybox-traffic-desired"
    };
    app.state = {
        activeLang: "uk",
        versionInfo: { branch: "...", current: "...", github: "..." },
        copyBotHintTimer: null,
        powerApplyTimer: null,
        powerPendingPercent: null,
        headerHasData: false,
        vnstatStatusChecked: false,
        vnstatInstallAttempted: false,
        lastAutoApplyAt: 0,
        isDraggingPower: false,
        powerApplyScheduled: false,
        powerScheduleLocked: false,
        powerScheduleModule: "",
        powerSchedulePercent: null,
        powerRequestSeq: 0,
        powerAppliedSeq: 0,
        powerCommitStamp: 0,
        powerCommitValue: null,
        powerAbortController: null
    };

    app.getText = function getText() {
        const translations = app.translations;
        const activeLang = app.state.activeLang;
        return translations[activeLang] || translations.uk || translations.en || {};
    };

    app.getStoredLang = function getStoredLang() {
        const stored = app.shared.getStorage(app.keys.appLangStorageKey);
        return stored === "uk" || stored === "en" ? stored : null;
    };

    app.setStoredLang = function setStoredLang(lang) {
        app.shared.setStorage(app.keys.appLangStorageKey, lang);
    };

    app.updateLinks = function updateLinks(lang) {
        const els = app.els;
        els.statusEl.href = "/status.php?lang=" + encodeURIComponent(lang);
        els.toolsEl.href = "/tools_list.php?lang=" + encodeURIComponent(lang);
        els.settingsEl.href = "/settings.php?lang=" + encodeURIComponent(lang);
        const footerUpdateActionEl = document.getElementById("footer-update-action");
        if (footerUpdateActionEl) {
            footerUpdateActionEl.href = "/settings.php?lang=" + encodeURIComponent(lang);
        }
        els.userIdModalLinkEl.href = "/user_id.php?lang=" + encodeURIComponent(lang);
    };

    app.percentToMbit = function percentToMbit(percent) {
        const clamped = Math.max(25, Math.min(100, Number(percent) || 100));
        if (clamped <= 80) {
            return Math.round(20 + ((clamped - 25) * (300 - 20) / (80 - 25)));
        }
        return Math.round(300 + ((clamped - 80) * (750 - 300) / (100 - 80)));
    };

    app.renderPowerState = function renderPowerState(percent) {
        const els = app.els;
        const normalized = Math.max(25, Math.min(100, Number(percent) || 100));
        els.powerSliderEl.value = String(normalized);
        els.powerPercentEl.textContent = String(normalized) + "%";
        els.powerRateEl.textContent = String(app.percentToMbit(normalized)) + " \u041c\u0431\u0456\u0442/\u0441";
        els.powerSliderEl.style.setProperty("--power-fill", ((normalized - 25) / 75 * 100).toFixed(2) + "%");
    };

    app.setHeaderStat = function setHeaderStat(rawValue) {
        const text = app.getText();
        const els = app.els;
        const hasData = typeof rawValue === "string" && rawValue.trim() !== "";
        app.state.headerHasData = hasData;
        if (!hasData) {
            els.todayTxLabelEl.textContent = "";
            els.todayTxValueEl.textContent = "";
            app.shared.setBooleanClass(els.headerBarEl, "is-empty", true);
            return;
        }
        els.todayTxLabelEl.textContent = text.todayTxLabel;
        els.todayTxValueEl.textContent = app.formatVnstatAmount(rawValue);
        app.shared.setBooleanClass(els.headerBarEl, "is-empty", false);
    };

    app.applyLang = function applyLang(lang) {
        const els = app.els;
        const state = app.state;
        state.activeLang = lang === "uk" || lang === "en" ? lang : "en";
        document.documentElement.lang = state.activeLang;
        const text = app.getText();
        els.titleEl.textContent = text.title;
        els.statusEl.textContent = text.status;
        els.toolsEl.textContent = text.tools;
        els.settingsEl.textContent = text.settings;
        if (state.headerHasData) {
            els.todayTxLabelEl.textContent = text.todayTxLabel;
        }
        els.powerNoteEl.textContent = text.powerNote;
        els.powerHelpEl.textContent = text.powerHelp;
        els.monitorTempLabelEl.textContent = text.monitorTemperature;
        els.monitorIpLabelEl.textContent = text.monitorIp;
        els.monitorMemoryTempLabelEl.textContent = text.monitorMemoryTemperature;
        if (state.powerScheduleLocked && typeof app.setPowerScheduleLockState === "function") {
            app.setPowerScheduleLockState(true, state.powerScheduleModule);
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
        if (footerVersionCurrentEl) footerVersionCurrentEl.textContent = state.versionInfo.current;
        if (footerVersionGithubEl) footerVersionGithubEl.textContent = state.versionInfo.github;
        if (footerVersionBranchEl) footerVersionBranchEl.textContent = state.versionInfo.branch;

        const haveBoth = state.versionInfo.current !== "unknown" &&
            state.versionInfo.github !== "unknown" &&
            state.versionInfo.current !== "..." &&
            state.versionInfo.github !== "...";
        const updateNeeded = haveBoth && state.versionInfo.current !== state.versionInfo.github;
        if (footerUpdateStateEl) {
            footerUpdateStateEl.textContent = updateNeeded ? text.updateAvailable : text.upToDate;
            app.shared.setBooleanClass(footerUpdateStateEl, "update-needed", updateNeeded);
            app.shared.setBooleanClass(footerUpdateStateEl, "up-to-date", !updateNeeded);
        }
        if (footerUpdateActionEl) {
            footerUpdateActionEl.textContent = text.updateNow;
            app.shared.setBooleanClass(footerUpdateActionEl, "visible", updateNeeded);
        }

        els.userIdModalTitleEl.textContent = text.userIdMissingTitle;
        els.userIdModalBadgeEl.textContent = text.userIdMissingBadge;
        els.userIdModalTextEl.textContent = text.userIdMissingNotice;
        els.userIdModalCloseEl.textContent = text.closeModal;
        els.userIdModalLinkEl.textContent = text.openUserIdSettings;
        els.copyBotHintEl.textContent = text.copyBotHint;
        els.userIdModalSkipLabelEl.textContent = text.skipUserIdModal;
        app.shared.setBooleanClass(els.langEnBtn, "active", state.activeLang === "en");
        app.shared.setBooleanClass(els.langUkBtn, "active", state.activeLang === "uk");
        app.updateLinks(state.activeLang);
        if (window.ItArmyTheme && typeof window.ItArmyTheme.refresh === "function") {
            window.ItArmyTheme.refresh();
        }
    };

    app.syncLangUrl = function syncLangUrl(lang) {
        try {
            const url = new URL(window.location.href);
            url.searchParams.set("lang", lang);
            window.history.replaceState({}, "", url.toString());
        } catch (e) {
        }
    };

    app.setLang = function setLang(lang) {
        document.cookie = "lang=" + encodeURIComponent(lang) + "; path=/; max-age=31536000";
        app.setStoredLang(lang);
        app.syncLangUrl(lang);
        app.applyLang(lang);
    };

    window.ItArmyHome = app;
})();
