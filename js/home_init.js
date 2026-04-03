(function () {
    const app = window.ItArmyHome || {};

    app.init = function init() {
        const params = new URLSearchParams(window.location.search);
        const initialLang = params.get("lang") || app.shared.getCookie("lang") || app.getStoredLang() || "uk";
        app.setStoredLang(initialLang);
        app.syncLangUrl(initialLang);
        app.applyLang(initialLang);
        app.fetchVersionInfo();
        app.refreshMainStatusIndicator();
        app.refreshTrafficLimit();
        app.refreshSystemMonitor();
        app.notifyTeamNotice();
        app.notifyIfUserIdMissing();

        window.setInterval(app.refreshMainStatusIndicator, 5000);
        window.setInterval(app.refreshSystemMonitor, 4000);
        window.setInterval(app.refreshTrafficLimit, 15000);

        app.els.langEnBtn.addEventListener("click", () => app.setLang("en"));
        app.els.langUkBtn.addEventListener("click", () => app.setLang("uk"));
        if (typeof app.initPowerControls === "function") {
            app.initPowerControls();
        }
        app.els.teamNoticeModalCloseEl.addEventListener("click", app.closeTeamNoticeModalFromAction);
        app.els.teamNoticeModalCloseEl.addEventListener("pointerup", app.closeTeamNoticeModalFromAction);
        app.els.teamNoticeModalCloseEl.addEventListener("touchend", app.closeTeamNoticeModalFromAction, { passive: false });
        app.els.teamNoticeModalEl.addEventListener("click", (event) => {
            if (event.target === app.els.teamNoticeModalEl) {
                app.hideTeamNoticeModal();
            }
        });
        app.els.userIdModalCloseEl.addEventListener("click", app.closeUserIdModalFromAction);
        app.els.userIdModalCloseEl.addEventListener("pointerup", app.closeUserIdModalFromAction);
        app.els.userIdModalCloseEl.addEventListener("touchend", app.closeUserIdModalFromAction, { passive: false });
        app.els.copyBotNameEl.addEventListener("click", app.copyBotName);
        app.els.userIdModalEl.addEventListener("click", (event) => {
            if (event.target === app.els.userIdModalEl) {
                app.hideUserIdModal();
            }
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && !app.els.teamNoticeModalEl.hidden) {
                app.hideTeamNoticeModal();
                return;
            }
            if (event.key === "Escape" && !app.els.userIdModalEl.hidden) {
                app.hideUserIdModal();
            }
        });
    };

    app.init();
})();
