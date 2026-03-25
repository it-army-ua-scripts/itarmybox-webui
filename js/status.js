(function () {
    const shared = window.ItArmyBox || {};
    const config = shared.readJsonScript("status-config", {});
    const serviceState = {};

    const commonLogEl = document.getElementById("common-log");
    const activeModuleActionsEl = document.getElementById("active-module-actions");
    const activeModuleNameEl = document.getElementById("active-module-name");
    const activeModuleStatusEl = document.getElementById("active-module-status");
    const autostartStatusEl = document.getElementById("autostart-status");
    const controlStatusEl = document.getElementById("control-status");

    function appendOrReplace(el, newText, key) {
        const oldText = serviceState[key] || "";
        const shouldStickToBottom =
            oldText === "" ||
            Math.abs(el.scrollHeight - el.clientHeight - el.scrollTop) < 24;

        if (newText.startsWith(oldText)) {
            el.textContent += newText.slice(oldText.length);
        } else {
            el.textContent = newText;
        }

        serviceState[key] = newText;
        if (shouldStickToBottom) {
            el.scrollTop = el.scrollHeight;
        }
    }

    function actionUrl(path, module) {
        return path + "?daemon=" + encodeURIComponent(module) + "&lang=" + encodeURIComponent(config.lang || "uk");
    }

    function renderActiveModuleActions(activeModuleName, selectedModuleName) {
        activeModuleActionsEl.innerHTML = "";
        if (activeModuleName) {
            const stopLink = document.createElement("a");
            stopLink.href = actionUrl("/stop.php", activeModuleName);
            stopLink.textContent = config.text.stop;
            activeModuleActionsEl.appendChild(stopLink);
            return;
        }

        if (selectedModuleName) {
            const startLink = document.createElement("a");
            startLink.href = actionUrl("/start.php", selectedModuleName);
            startLink.textContent = config.text.start;
            activeModuleActionsEl.appendChild(startLink);
        }
    }

    function setStatusBadge(el, value, isActive) {
        el.textContent = value;
        shared.setBooleanClass(el, "active", isActive);
        shared.setBooleanClass(el, "inactive", !isActive);
    }

    function renderUnavailableState() {
        activeModuleNameEl.textContent = config.text.activeModule;
        setStatusBadge(activeModuleStatusEl, config.text.statusUnavailable, false);
        setStatusBadge(autostartStatusEl, config.text.statusUnavailable, false);
        setStatusBadge(controlStatusEl, config.text.statusUnavailable, false);
        activeModuleActionsEl.innerHTML = "";
    }

    async function updateStatus() {
        try {
            const data = await shared.fetchJson(config.ajaxUrl, { cache: "no-store" });
            if (!data || !data.ok) {
                renderUnavailableState();
                return;
            }

            const selectedModuleName = typeof data.selectedModule === "string" ? data.selectedModule : "";
            if (selectedModuleName) {
                setStatusBadge(
                    autostartStatusEl,
                    config.text.autostartFor.replace("{{module}}", selectedModuleName),
                    true
                );
            } else {
                setStatusBadge(autostartStatusEl, config.text.autostartNone, false);
            }

            if (data.scheduleLocked === true) {
                const moduleName = typeof data.scheduleModule === "string" ? data.scheduleModule.toUpperCase() : "";
                const power = Number.isFinite(Number(data.schedulePercent)) ? String(Math.round(Number(data.schedulePercent))) + "%" : "";
                const text = moduleName && power
                    ? config.text.controlSchedule.replace("{{module}}", moduleName).replace("{{power}}", power)
                    : config.text.controlScheduleGeneric;
                setStatusBadge(controlStatusEl, text, true);
            } else {
                setStatusBadge(controlStatusEl, config.text.controlManual, false);
            }

            if (data.activeModule) {
                activeModuleNameEl.textContent = config.text.activeModule;
                setStatusBadge(activeModuleStatusEl, data.activeModule, true);
                renderActiveModuleActions(String(data.activeModule), "");
            } else {
                activeModuleNameEl.textContent = config.text.activeModule;
                setStatusBadge(activeModuleStatusEl, config.text.noModuleRunning, false);
                renderActiveModuleActions("", selectedModuleName);
            }

            appendOrReplace(commonLogEl, data.commonLogs || "", "common");
        } catch (e) {
            renderUnavailableState();
        }
    }

    updateStatus();
    window.setInterval(updateStatus, 2000);
})();
