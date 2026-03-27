(function () {
    const app = window.ItArmyHome || {};

    app.formatPercent = function formatPercent(value) {
        return typeof value === "number" && Number.isFinite(value)
            ? value.toFixed(1) + "%"
            : app.getText().monitorUnavailable;
    };

    app.formatTemperature = function formatTemperature(value) {
        return typeof value === "number" && Number.isFinite(value)
            ? value.toFixed(1) + "°C"
            : app.getText().monitorUnavailable;
    };

    app.formatVnstatAmount = function formatVnstatAmount(value) {
        if (typeof value !== "string") {
            return app.getText().monitorUnavailable;
        }
        const trimmed = value.trim();
        const match = trimmed.match(/^([0-9]+(?:[.,][0-9]+)?)\s*([KMGT]?i?B)$/i);
        if (!match) {
            return trimmed || app.getText().monitorUnavailable;
        }

        const numeric = Number(match[1].replace(",", "."));
        if (!Number.isFinite(numeric)) {
            return trimmed || app.getText().monitorUnavailable;
        }

        const unit = match[2];
        const normalizedUnit = unit.charAt(0).toUpperCase() + unit.slice(1);
        const digits = numeric >= 100 ? 0 : (numeric >= 10 ? 1 : 2);
        return numeric.toFixed(digits) + " " + normalizedUnit;
    };

    app.ensureVnstatAvailable = async function ensureVnstatAvailable() {
        if (app.state.vnstatInstallAttempted || app.config.vnstatAutoInstall !== true) {
            return;
        }

        try {
            const status = await app.shared.fetchJson(app.config.vnstatInstallUrl || "/vnstat.php", { cache: "no-store" });
            app.state.vnstatStatusChecked = true;
            if (status && status.ok === true && status.ready === true) {
                app.state.vnstatInstallAttempted = true;
                return;
            }
        } catch (e) {
            app.state.vnstatStatusChecked = true;
        }

        app.state.vnstatInstallAttempted = true;
        try {
            await app.shared.fetchJson(app.config.vnstatInstallUrl || "/vnstat.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ install: true })
            });
            window.setTimeout(app.refreshSystemMonitor, 4000);
        } catch (e) {
        }
    };

    app.refreshSystemMonitor = async function refreshSystemMonitor() {
        const els = app.els;
        try {
            const data = await app.shared.fetchJson(app.config.systemMonitorUrl || "/system_monitor.php", { cache: "no-store" });
            if (!data || data.ok !== true) {
                return;
            }
            const iface = typeof data.iface === "string" && data.iface ? data.iface : "eth0";
            els.monitorTxLabelEl.textContent = "TX " + iface;
            els.monitorTxValueEl.textContent = typeof data.txRate === "string" && data.txRate ? data.txRate : app.getText().monitorUnavailable;
            app.setHeaderStat(data.todayTx);
            els.monitorRamValueEl.textContent = app.formatPercent(data.ramPercent);
            els.monitorCpuValueEl.textContent = app.formatPercent(data.cpuPercent);
            els.monitorTempValueEl.textContent = app.formatTemperature(data.temperatureC);
            els.monitorIpValueEl.textContent = typeof data.ipv4 === "string" && data.ipv4 ? data.ipv4 : app.getText().monitorUnavailable;

            if (typeof data.memoryTemperatureC === "number" && Number.isFinite(data.memoryTemperatureC)) {
                els.monitorMemoryTempCardEl.hidden = false;
                els.monitorMemoryTempValueEl.textContent = app.formatTemperature(data.memoryTemperatureC);
            } else {
                els.monitorMemoryTempCardEl.hidden = true;
            }

            if (!app.state.vnstatStatusChecked && app.config.vnstatAutoInstall === true) {
                await app.ensureVnstatAvailable();
            }
        } catch (e) {
            const fallback = app.getText().monitorUnavailable;
            app.setHeaderStat("");
            els.monitorTxValueEl.textContent = fallback;
            els.monitorRamValueEl.textContent = fallback;
            els.monitorCpuValueEl.textContent = fallback;
            els.monitorTempValueEl.textContent = fallback;
            els.monitorIpValueEl.textContent = fallback;
            els.monitorMemoryTempCardEl.hidden = true;
        }
    };

    app.fetchVersionInfo = async function fetchVersionInfo() {
        try {
            const data = await app.shared.fetchJson(app.config.versionInfoUrl || "/version_info.php", { cache: "no-store" });
            if (!data || data.ok !== true) {
                return;
            }
            app.state.versionInfo = {
                branch: data.branch || "...",
                current: data.current || "unknown",
                github: data.github || "unknown"
            };
            app.applyLang(app.state.activeLang);
        } catch (e) {
        }
    };

    app.refreshMainStatusIndicator = async function refreshMainStatusIndicator() {
        try {
            const url = (app.config.statusAjaxBaseUrl || "/status.php?ajax=1") + "&lang=" + encodeURIComponent(app.state.activeLang);
            const data = await app.shared.fetchJson(url, { cache: "no-store" });
            const isActive = !!(data && data.ok === true && data.activeModule);
            app.shared.setBooleanClass(app.els.statusEl, "main-status-active", isActive);
            app.shared.setBooleanClass(app.els.statusEl, "main-status-inactive", !isActive);
        } catch (e) {
            app.els.statusEl.classList.remove("main-status-active");
            app.els.statusEl.classList.add("main-status-inactive");
        }
    };
})();
