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

    function createActionForm(path, module, label) {
        const form = document.createElement("form");
        form.method = "post";
        form.action = path + "?lang=" + encodeURIComponent(config.lang || "uk");

        const daemonInput = document.createElement("input");
        daemonInput.type = "hidden";
        daemonInput.name = "daemon";
        daemonInput.value = module;
        form.appendChild(daemonInput);

        const submitButton = document.createElement("button");
        submitButton.type = "submit";
        submitButton.textContent = label;
        form.appendChild(submitButton);

        return form;
    }

    function renderActiveModuleActions(activeModuleName, selectedModuleName) {
        activeModuleActionsEl.innerHTML = "";
        if (activeModuleName) {
            activeModuleActionsEl.appendChild(createActionForm("/stop.php", activeModuleName, config.text.stop));
            return;
        }

        if (selectedModuleName) {
            activeModuleActionsEl.appendChild(createActionForm("/start.php", selectedModuleName, config.text.start));
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
