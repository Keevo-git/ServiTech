(function () {
  "use strict";

  var STORAGE_KEY = "servitech.customer.toasts.v1";
  var DEFAULT_DURATION = 7000;
  var activeToasts = {};
  var toastCounter = 0;

  function normalizeTone(tone) {
    return ["success", "info", "warning", "error"].indexOf(tone) !== -1
      ? tone
      : "info";
  }

  function readStoredToasts() {
    try {
      var stored = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || "[]");
      return Array.isArray(stored) ? stored : [];
    } catch (error) {
      return [];
    }
  }

  function writeStoredToasts(toasts) {
    try {
      if (toasts.length) {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(toasts));
      } else {
        sessionStorage.removeItem(STORAGE_KEY);
      }
    } catch (error) {
      // Toasts still work for this page when storage is unavailable.
    }
  }

  function storeToast(toast) {
    var stored = readStoredToasts().filter(function (item) {
      return item.id !== toast.id && item.expiresAt > Date.now();
    });
    stored.push(toast);
    writeStoredToasts(stored);
  }

  function removeStoredToast(id) {
    var stored = readStoredToasts().filter(function (item) {
      return item.id !== id && item.expiresAt > Date.now();
    });
    writeStoredToasts(stored);
  }

  function ensureContainer() {
    var container = document.getElementById("customerToastContainer");
    if (container) return container;

    container = document.createElement("div");
    container.id = "customerToastContainer";
    container.className = "customer-toast-container";
    container.setAttribute("aria-live", "polite");
    container.setAttribute("aria-atomic", "false");
    document.body.appendChild(container);
    return container;
  }

  function dismissToast(id) {
    var record = activeToasts[id];
    removeStoredToast(id);
    if (!record) return;

    window.clearTimeout(record.timeoutId);
    record.element.classList.add("is-leaving");
    record.element.classList.remove("is-visible");
    window.setTimeout(function () {
      if (record.element.parentNode) {
        record.element.parentNode.removeChild(record.element);
      }
      delete activeToasts[id];
    }, 240);
  }

  function findMatchingToast(message, tone) {
    var ids = Object.keys(activeToasts);
    for (var index = 0; index < ids.length; index += 1) {
      var record = activeToasts[ids[index]];
      if (record.message === message && record.tone === tone) return record;
    }
    return null;
  }

  function renderToast(toast) {
    var remaining = toast.expiresAt - Date.now();
    if (!toast.message || remaining <= 0) {
      removeStoredToast(toast.id);
      return null;
    }

    var matchingToast = findMatchingToast(toast.message, toast.tone);
    if (matchingToast) return matchingToast.id;

    var element = document.createElement("div");
    element.className = "customer-toast customer-toast--" + toast.tone;
    element.setAttribute("role", toast.tone === "error" || toast.tone === "warning" ? "alert" : "status");

    var icon = document.createElement("span");
    icon.className = "customer-toast__icon";
    icon.setAttribute("aria-hidden", "true");

    var message = document.createElement("p");
    message.className = "customer-toast__message";
    message.textContent = toast.message;

    var closeButton = document.createElement("button");
    closeButton.type = "button";
    closeButton.className = "customer-toast__close";
    closeButton.setAttribute("aria-label", "Dismiss notification");
    closeButton.textContent = "x";
    closeButton.addEventListener("click", function () {
      dismissToast(toast.id);
    });

    element.appendChild(icon);
    element.appendChild(message);
    element.appendChild(closeButton);
    ensureContainer().appendChild(element);

    activeToasts[toast.id] = {
      element: element,
      message: toast.message,
      tone: toast.tone,
      timeoutId: window.setTimeout(function () {
        dismissToast(toast.id);
      }, remaining),
    };

    window.requestAnimationFrame(function () {
      element.classList.add("is-visible");
    });

    return toast.id;
  }

  function showToast(message, options) {
    var config = typeof options === "string" ? { tone: options } : options || {};
    var toast = {
      id: config.id || "customer-toast-" + Date.now() + "-" + (toastCounter += 1),
      message: String(message || "").trim(),
      tone: normalizeTone(config.tone),
      expiresAt: config.expiresAt || Date.now() + (config.duration || DEFAULT_DURATION),
    };

    if (!toast.message) return null;
    if (config.persist) storeToast(toast);
    return renderToast(toast);
  }

  function showToastForNavigation(message, options) {
    var config = Object.assign({}, options || {}, { persist: true });
    return showToast(message, config);
  }

  function restoreStoredToasts() {
    var stored = readStoredToasts().filter(function (toast) {
      return toast.expiresAt > Date.now();
    });
    writeStoredToasts(stored);
    stored.forEach(renderToast);
  }

  window.servitechToast = showToast;
  window.servitechToastForNavigation = showToastForNavigation;
  window.servitechDismissToast = dismissToast;

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", restoreStoredToasts);
  } else {
    restoreStoredToasts();
  }
})();
