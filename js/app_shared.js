window.ItArmyBox = window.ItArmyBox || {};

window.ItArmyBox.readJsonScript = function readJsonScript(id, fallback) {
    const el = document.getElementById(id);
    if (!el) {
        return fallback;
    }
    try {
        return JSON.parse(el.textContent || "");
    } catch (e) {
        return fallback;
    }
};

window.ItArmyBox.getCookie = function getCookie(name) {
    const parts = document.cookie.split("; ").find((row) => row.startsWith(name + "="));
    return parts ? decodeURIComponent(parts.split("=")[1]) : null;
};

window.ItArmyBox.getStorage = function getStorage(key) {
    try {
        return window.localStorage.getItem(key);
    } catch (e) {
        return null;
    }
};

window.ItArmyBox.setStorage = function setStorage(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch (e) {
    }
};

window.ItArmyBox.removeStorage = function removeStorage(key) {
    try {
        window.localStorage.removeItem(key);
    } catch (e) {
    }
};

window.ItArmyBox.fetchJson = async function fetchJson(url, options) {
    const response = await fetch(url, options || { cache: "no-store" });
    return response.json();
};

window.ItArmyBox.setBooleanClass = function setBooleanClass(el, className, enabled) {
    if (!el) {
        return;
    }
    el.classList.toggle(className, Boolean(enabled));
};
