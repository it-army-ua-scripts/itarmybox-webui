(function () {
    const app = window.ItArmyHome || {};

    app.getUserIdModalHiddenPreference = function getUserIdModalHiddenPreference() {
        return app.shared.getStorage(app.keys.userIdModalPreferenceKey) === "1";
    };

    app.setUserIdModalHiddenPreference = function setUserIdModalHiddenPreference(hidden) {
        if (hidden) {
            app.shared.setStorage(app.keys.userIdModalPreferenceKey, "1");
            return;
        }
        app.shared.removeStorage(app.keys.userIdModalPreferenceKey);
    };

    app.getUserIdSnoozeUntil = function getUserIdSnoozeUntil() {
        const raw = app.shared.getStorage(app.keys.userIdModalSnoozeKey);
        const value = raw ? Number(raw) : 0;
        return Number.isFinite(value) ? value : 0;
    };

    app.setUserIdSnoozeUntil = function setUserIdSnoozeUntil(timestampMs) {
        if (!Number.isFinite(timestampMs)) {
            return;
        }
        app.shared.setStorage(app.keys.userIdModalSnoozeKey, String(Math.floor(timestampMs)));
    };

    app.clearUserIdSnooze = function clearUserIdSnooze() {
        app.shared.removeStorage(app.keys.userIdModalSnoozeKey);
    };

    app.copyBotName = async function copyBotName() {
        const botName = app.els.copyBotNameEl.textContent.trim();
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
            app.els.copyBotHintEl.textContent = app.getText().copiedBotHint;
            app.els.copyBotNameEl.classList.add("copied");
            if (app.state.copyBotHintTimer) {
                window.clearTimeout(app.state.copyBotHintTimer);
            }
            app.state.copyBotHintTimer = window.setTimeout(() => {
                app.els.copyBotHintEl.textContent = app.getText().copyBotHint;
                app.els.copyBotNameEl.classList.remove("copied");
            }, 1800);
        } catch (e) {
            app.els.copyBotHintEl.textContent = botName;
        }
    };

    app.showUserIdModal = function showUserIdModal() {
        app.els.userIdModalSkipEl.checked = app.getUserIdModalHiddenPreference();
        app.els.userIdModalEl.hidden = false;
        document.body.classList.add("modal-open");
        app.els.userIdModalCloseEl.focus();
    };

    app.hideUserIdModal = function hideUserIdModal() {
        app.setUserIdModalHiddenPreference(false);
        if (app.els.userIdModalSkipEl.checked) {
            app.setUserIdSnoozeUntil(Date.now() + 24 * 60 * 60 * 1000);
        } else {
            app.clearUserIdSnooze();
        }
        app.els.userIdModalEl.hidden = true;
        document.body.classList.remove("modal-open");
    };

    app.notifyIfUserIdMissing = async function notifyIfUserIdMissing() {
        try {
            const data = await app.shared.fetchJson(app.config.userIdStatusUrl || "/user_id.php?ajax=status", { cache: "no-store" });
            const snoozeUntil = app.getUserIdSnoozeUntil();
            const shouldSnooze = snoozeUntil > Date.now();
            if (data && data.ok === true && data.userIdConfigured === false && !app.getUserIdModalHiddenPreference() && !shouldSnooze) {
                if (typeof app.isAnyHomeModalOpen === "function" && app.isAnyHomeModalOpen()) {
                    app.state.userIdModalDeferred = true;
                } else {
                    app.showUserIdModal();
                }
            } else if (data && data.ok === true && data.userIdConfigured === true) {
                app.setUserIdModalHiddenPreference(false);
                app.clearUserIdSnooze();
                app.state.userIdModalDeferred = false;
            }
        } catch (e) {
        }
    };

    app.closeUserIdModalFromAction = function closeUserIdModalFromAction(event) {
        event.preventDefault();
        event.stopPropagation();
        app.hideUserIdModal();
    };
})();
