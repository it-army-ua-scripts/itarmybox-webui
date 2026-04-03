(function () {
    const app = window.ItArmyHome || {};

    app.isAnyHomeModalOpen = function isAnyHomeModalOpen() {
        return Boolean(
            (app.els.teamNoticeModalEl && app.els.teamNoticeModalEl.hidden === false) ||
            (app.els.userIdModalEl && app.els.userIdModalEl.hidden === false)
        );
    };

    app.getTeamNoticeSnoozeUntil = function getTeamNoticeSnoozeUntil() {
        const raw = app.shared.getStorage(app.keys.teamNoticeModalSnoozeKey);
        const value = raw ? Number(raw) : 0;
        return Number.isFinite(value) ? value : 0;
    };

    app.setTeamNoticeSnoozeUntil = function setTeamNoticeSnoozeUntil(timestampMs) {
        if (!Number.isFinite(timestampMs)) {
            return;
        }
        app.shared.setStorage(app.keys.teamNoticeModalSnoozeKey, String(Math.floor(timestampMs)));
    };

    app.clearTeamNoticeSnooze = function clearTeamNoticeSnooze() {
        app.shared.removeStorage(app.keys.teamNoticeModalSnoozeKey);
    };

    app.showTeamNoticeModal = function showTeamNoticeModal() {
        app.els.teamNoticeModalSkipEl.checked = false;
        app.els.teamNoticeModalEl.hidden = false;
        document.body.classList.add("modal-open");
        app.els.teamNoticeModalTextEl.scrollTop = 0;
        app.els.teamNoticeModalCloseEl.focus();
    };

    app.hideTeamNoticeModal = function hideTeamNoticeModal() {
        if (app.els.teamNoticeModalSkipEl.checked) {
            app.setTeamNoticeSnoozeUntil(Date.now() + 24 * 60 * 60 * 1000);
        } else {
            app.clearTeamNoticeSnooze();
        }
        app.els.teamNoticeModalEl.hidden = true;
        if (!app.isAnyHomeModalOpen()) {
            document.body.classList.remove("modal-open");
        }
        if (app.state.userIdModalDeferred && typeof app.notifyIfUserIdMissing === "function") {
            app.state.userIdModalDeferred = false;
            app.notifyIfUserIdMissing();
        }
    };

    app.notifyTeamNotice = function notifyTeamNotice() {
        const snoozeUntil = app.getTeamNoticeSnoozeUntil();
        if (snoozeUntil > Date.now() || app.isAnyHomeModalOpen()) {
            return;
        }
        app.showTeamNoticeModal();
    };

    app.closeTeamNoticeModalFromAction = function closeTeamNoticeModalFromAction(event) {
        event.preventDefault();
        event.stopPropagation();
        app.hideTeamNoticeModal();
    };
})();
