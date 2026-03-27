(function () {
    const app = window.ItArmyHome || {};

    app.unlockPowerDragState = function unlockPowerDragState() {
        app.state.isDraggingPower = false;
        if (app.els.powerLeverEl) {
            app.els.powerLeverEl.classList.remove("is-dragging");
        }
    };

    app.getDesiredTrafficPercent = function getDesiredTrafficPercent() {
        const raw = app.shared.getStorage(app.keys.trafficDesiredKey);
        const value = raw ? Number(raw) : 0;
        return Number.isFinite(value) ? value : 0;
    };

    app.setDesiredTrafficPercent = function setDesiredTrafficPercent(value) {
        if (!Number.isFinite(value)) {
            return;
        }
        app.shared.setStorage(app.keys.trafficDesiredKey, String(Math.round(value)));
    };

    app.clearDesiredTrafficPercent = function clearDesiredTrafficPercent() {
        app.shared.removeStorage(app.keys.trafficDesiredKey);
    };

    app.showPowerScheduleLockedMessage = function showPowerScheduleLockedMessage() {
        const text = app.getText();
        const moduleLabel = app.state.powerScheduleModule
            ? String(app.state.powerScheduleModule).toUpperCase()
            : "";
        app.els.powerStatusEl.textContent = moduleLabel
            ? text.powerControlledByScheduleHint.replace("{{module}}", moduleLabel)
            : text.powerControlledByScheduleHintGeneric;
    };

    app.handleLockedPowerInteraction = function handleLockedPowerInteraction() {
        if (app.state.powerSchedulePercent !== null) {
            app.renderPowerState(app.state.powerSchedulePercent);
        }
        app.showPowerScheduleLockedMessage();
    };

    app.setPowerScheduleLockState = function setPowerScheduleLockState(locked, scheduleModule, schedulePercent) {
        const state = app.state;
        const els = app.els;
        state.powerScheduleLocked = locked === true;
        state.powerScheduleModule = state.powerScheduleLocked && typeof scheduleModule === "string" ? scheduleModule : "";
        state.powerSchedulePercent = state.powerScheduleLocked && Number.isFinite(Number(schedulePercent))
            ? Math.max(25, Math.min(100, Number(schedulePercent)))
            : null;
        els.powerSliderEl.setAttribute("aria-disabled", state.powerScheduleLocked ? "true" : "false");
        els.powerSliderEl.disabled = state.powerScheduleLocked;
        els.powerSliderEl.classList.toggle("is-locked", state.powerScheduleLocked);
        if (state.powerScheduleLocked) {
            const text = app.getText();
            const moduleLabel = state.powerScheduleModule
                ? String(state.powerScheduleModule).toUpperCase()
                : "";
            els.powerStatusEl.textContent = moduleLabel
                ? text.powerControlledBySchedule.replace("{{module}}", moduleLabel)
                : text.powerControlledByScheduleGeneric;
            app.clearDesiredTrafficPercent();
            return;
        }
        els.powerStatusEl.textContent = app.getText().powerApplied;
    };

    app.commitPowerChange = function commitPowerChange() {
        if (app.state.powerScheduleLocked) {
            app.handleLockedPowerInteraction();
            return;
        }
        const value = String(app.els.powerSliderEl.value);
        const now = Date.now();
        if (app.state.powerCommitValue === value && (now - app.state.powerCommitStamp) < 400) {
            return;
        }
        app.state.powerCommitValue = value;
        app.state.powerCommitStamp = now;
        app.unlockPowerDragState();
        app.schedulePowerApply();
    };

    app.initPowerControls = function initPowerControls() {
        app.els.powerSliderEl.addEventListener("pointerdown", () => {
            if (app.state.powerScheduleLocked) {
                app.handleLockedPowerInteraction();
                return;
            }
            app.state.isDraggingPower = true;
            if (app.els.powerLeverEl) {
                app.els.powerLeverEl.classList.add("is-dragging");
            }
        });
        app.els.powerSliderEl.addEventListener("touchstart", () => {
            if (app.state.powerScheduleLocked) {
                app.handleLockedPowerInteraction();
                return;
            }
            app.state.isDraggingPower = true;
            if (app.els.powerLeverEl) {
                app.els.powerLeverEl.classList.add("is-dragging");
            }
        }, { passive: true });
        app.els.powerSliderEl.addEventListener("input", () => {
            if (app.state.powerScheduleLocked) {
                app.handleLockedPowerInteraction();
                return;
            }
            app.renderPowerState(app.els.powerSliderEl.value);
            app.els.powerStatusEl.textContent = "";
        });
        app.els.powerSliderEl.addEventListener("change", app.commitPowerChange);
        app.els.powerSliderEl.addEventListener("mouseup", app.commitPowerChange);
        app.els.powerSliderEl.addEventListener("touchend", app.commitPowerChange, { passive: true });
        app.els.powerSliderEl.addEventListener("pointerup", app.commitPowerChange);
        app.els.powerSliderEl.addEventListener("pointercancel", app.unlockPowerDragState);
        app.els.powerSliderEl.addEventListener("touchcancel", app.unlockPowerDragState);
    };

    app.schedulePowerApply = function schedulePowerApply() {
        if (app.state.powerApplyTimer) {
            window.clearTimeout(app.state.powerApplyTimer);
        }
        app.state.powerApplyScheduled = true;
        app.state.powerApplyTimer = window.setTimeout(() => app.applyTrafficLimit(app.els.powerSliderEl.value), 140);
    };

    app.shouldIgnorePowerResponse = function shouldIgnorePowerResponse(seq) {
        return seq < app.state.powerRequestSeq;
    };

    app.refreshTrafficLimit = async function refreshTrafficLimit() {
        try {
            const data = await app.shared.fetchJson(app.config.trafficLimitUrl || "/traffic_limit.php", { cache: "no-store" });
            if (!data || data.ok !== true || app.state.powerPendingPercent !== null || app.state.isDraggingPower || app.state.powerApplyScheduled) {
                if (data && data.scheduleLocked === true) {
                    app.renderPowerState(data.schedulePercent || data.percent);
                    app.setPowerScheduleLockState(true, data.scheduleModule, data.schedulePercent || data.percent);
                }
                return;
            }
            app.renderPowerState(data.percent);
            app.setPowerScheduleLockState(data.scheduleLocked === true, data.scheduleModule, data.schedulePercent || data.percent);
            const desired = app.getDesiredTrafficPercent();
            if (data.scheduleLocked === true) {
                return;
            }
            if (desired >= 25 && desired <= 100 && Math.abs(desired - data.percent) >= 2) {
                app.els.powerStatusEl.textContent = app.getText().powerApplying;
            } else {
                app.els.powerStatusEl.textContent = app.getText().powerApplied;
            }
            const now = Date.now();
            if (!app.state.isDraggingPower && desired >= 25 && desired <= 100 && Math.abs(desired - data.percent) >= 2) {
                if (now - app.state.lastAutoApplyAt > 30000) {
                    app.state.lastAutoApplyAt = now;
                    app.applyTrafficLimit(desired);
                }
            }
        } catch (e) {
        }
    };

    app.applyTrafficLimit = async function applyTrafficLimit(percent) {
        if (app.state.powerScheduleLocked) {
            app.state.powerApplyScheduled = false;
            return;
        }
        const normalized = Math.max(25, Math.min(100, Number(percent) || 100));
        const seq = app.state.powerRequestSeq + 1;
        app.state.powerRequestSeq = seq;
        app.state.powerApplyScheduled = false;
        if (app.state.powerAbortController) {
            try {
                app.state.powerAbortController.abort();
            } catch (e) {
            }
        }
        app.state.powerAbortController = typeof AbortController === "function" ? new AbortController() : null;
        app.state.powerPendingPercent = normalized;
        app.renderPowerState(normalized);
        app.setDesiredTrafficPercent(normalized);
        app.els.powerStatusEl.textContent = app.getText().powerApplying;
        try {
            const requestOptions = {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ percent: normalized })
            };
            if (app.state.powerAbortController) {
                requestOptions.signal = app.state.powerAbortController.signal;
            }
            const data = await app.shared.fetchJson(app.config.trafficLimitUrl || "/traffic_limit.php", requestOptions);
            if (app.shouldIgnorePowerResponse(seq)) {
                return;
            }
            if (!data || data.ok !== true) {
                if (data && data.scheduleLocked === true) {
                    app.renderPowerState(data.currentPercent || data.schedulePercent || normalized);
                    app.setPowerScheduleLockState(true, data.scheduleModule, data.currentPercent || data.schedulePercent || normalized);
                    app.showPowerScheduleLockedMessage();
                    if (seq >= app.state.powerAppliedSeq) {
                        app.state.powerPendingPercent = null;
                        app.state.powerAppliedSeq = seq;
                    }
                    app.refreshTrafficLimit();
                    return;
                }
                app.setPowerScheduleLockState(false);
                app.els.powerStatusEl.textContent = app.getText().powerApplyFailed;
                if (seq >= app.state.powerAppliedSeq) {
                    app.state.powerPendingPercent = null;
                    app.state.powerAppliedSeq = seq;
                }
                app.refreshTrafficLimit();
                return;
            }
            app.renderPowerState(data.percent);
            app.setPowerScheduleLockState(data.scheduleLocked === true, data.scheduleModule, data.schedulePercent || data.percent);
            app.setDesiredTrafficPercent(data.percent);
            app.els.powerStatusEl.textContent = app.getText().powerApplied;
            app.state.powerPendingPercent = null;
            app.state.powerAppliedSeq = seq;
        } catch (e) {
            if (e && e.name === "AbortError") {
                return;
            }
            if (app.shouldIgnorePowerResponse(seq)) {
                return;
            }
            app.els.powerStatusEl.textContent = app.getText().powerApplyFailed;
            app.state.powerPendingPercent = null;
            app.state.powerAppliedSeq = seq;
            app.refreshTrafficLimit();
        } finally {
            if (seq >= app.state.powerAppliedSeq) {
                app.state.powerAbortController = null;
            }
        }
    };
})();
