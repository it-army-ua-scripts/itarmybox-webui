(function () {
    const storageKey = "itarmybox-theme";
    const root = document.documentElement;
    const themeApi = window.ItArmyTheme || {};

    function readConfig() {
        const el = document.getElementById("theme-config");
        if (!el) {
            return {
                toggleLabel: "Toggle theme",
                darkLabel: "Dark theme",
                lightLabel: "Light theme"
            };
        }
        try {
            const parsed = JSON.parse(el.textContent || "");
            return {
                toggleLabel: String(parsed.toggleLabel || "Toggle theme"),
                darkLabel: String(parsed.darkLabel || "Dark theme"),
                lightLabel: String(parsed.lightLabel || "Light theme")
            };
        } catch (e) {
            return {
                toggleLabel: "Toggle theme",
                darkLabel: "Dark theme",
                lightLabel: "Light theme"
            };
        }
    }

    function getStoredTheme() {
        try {
            const value = window.localStorage.getItem(storageKey);
            return value === "dark" || value === "light" ? value : null;
        } catch (e) {
            return null;
        }
    }

    function setStoredTheme(theme) {
        try {
            window.localStorage.setItem(storageKey, theme);
        } catch (e) {
        }
    }

    function resolveTheme() {
        const stored = getStoredTheme();
        if (stored !== null) {
            return stored;
        }
        return window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches
            ? "dark"
            : "light";
    }

    function applyTheme(theme) {
        root.setAttribute("data-theme", theme);
        document.body.setAttribute("data-theme", theme);
        const toggle = document.getElementById("theme-toggle");
        if (toggle) {
            const config = readConfig();
            const isDark = theme === "dark";
            toggle.setAttribute("aria-pressed", isDark ? "true" : "false");
            toggle.setAttribute("title", isDark ? config.lightLabel : config.darkLabel);
            toggle.setAttribute("aria-label", config.toggleLabel);
            toggle.classList.toggle("is-dark", isDark);
        }
    }

    themeApi.applyTheme = applyTheme;
    themeApi.resolveTheme = resolveTheme;
    themeApi.refresh = function refreshThemeUi() {
        applyTheme(resolveTheme());
    };

    function ensureToggle() {
        if (document.getElementById("theme-toggle")) {
            return;
        }

        const bar = document.createElement("div");
        bar.className = "app-theme-bar";

        const button = document.createElement("button");
        button.type = "button";
        button.id = "theme-toggle";
        button.className = "theme-toggle";
        button.innerHTML = '<span class="theme-toggle-thumb"></span>';

        bar.appendChild(button);
        document.body.insertBefore(bar, document.body.firstChild);
    }

    function bindToggle() {
        const toggle = document.getElementById("theme-toggle");
        if (!toggle || toggle.dataset.bound === "1") {
            return;
        }
        toggle.dataset.bound = "1";
        toggle.addEventListener("click", function () {
            const nextTheme = resolveTheme() === "dark" ? "light" : "dark";
            setStoredTheme(nextTheme);
            applyTheme(nextTheme);
        });
    }

    function initTheme() {
        ensureToggle();
        bindToggle();
        applyTheme(resolveTheme());
    }

    window.ItArmyTheme = themeApi;

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initTheme, { once: true });
    } else {
        initTheme();
    }
})();
