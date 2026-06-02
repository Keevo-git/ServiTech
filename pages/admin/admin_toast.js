(function () {
  "use strict";

  const storageKey = "servitech.admin.pendingToast";
  const validTypes = new Set(["success", "error", "warning", "info"]);
  const icons = {
    success: "&#10003;",
    error: "!",
    warning: "!",
    info: "i",
  };

  function normalizeType(type) {
    return validTypes.has(type) ? type : "info";
  }

  function ensureStack() {
    let stack = document.getElementById("adminToastStack");
    if (stack || !document.body) return stack;

    stack = document.createElement("div");
    stack.className = "admin-toast-stack";
    stack.id = "adminToastStack";
    stack.setAttribute("aria-live", "polite");
    stack.setAttribute("aria-label", "Admin notifications");
    document.body.appendChild(stack);
    return stack;
  }

  function dismiss(toast) {
    if (!toast || toast.classList.contains("is-leaving")) return;
    toast.classList.add("is-leaving");
    window.setTimeout(() => toast.remove(), 240);
  }

  function show(message, type = "info", options = {}) {
    const cleanMessage = String(message || "").trim();
    if (!cleanMessage) return null;

    const stack = ensureStack();
    if (!stack) {
      document.addEventListener("DOMContentLoaded", () => show(cleanMessage, type, options), { once: true });
      return null;
    }

    const toastType = normalizeType(type);
    const toast = document.createElement("div");
    toast.className = `admin-toast admin-toast--${toastType}`;
    toast.setAttribute("role", toastType === "error" || toastType === "warning" ? "alert" : "status");
    toast.innerHTML = `
      <span class="admin-toast__icon" aria-hidden="true">${icons[toastType]}</span>
      <span class="admin-toast__message"></span>
      <button class="admin-toast__close" type="button" aria-label="Dismiss notification">&times;</button>
    `;
    toast.querySelector(".admin-toast__message").textContent = cleanMessage;
    toast.querySelector(".admin-toast__close").addEventListener("click", () => dismiss(toast));
    stack.appendChild(toast);

    const duration = Number(options.duration) || (toastType === "error" ? 8000 : 6000);
    if (duration > 0) {
      window.setTimeout(() => dismiss(toast), duration);
    }
    return toast;
  }

  function persist(message, type = "success", options = {}) {
    try {
      window.sessionStorage.setItem(storageKey, JSON.stringify({
        message: String(message || ""),
        type: normalizeType(type),
        options,
      }));
    } catch (error) {
      show(message, type, options);
    }
  }

  function consumePending() {
    let pending = null;
    try {
      pending = window.sessionStorage.getItem(storageKey);
      window.sessionStorage.removeItem(storageKey);
    } catch (error) {
      return;
    }
    if (!pending) return;

    try {
      const toast = JSON.parse(pending);
      show(toast.message, toast.type, toast.options);
    } catch (error) {
      window.sessionStorage.removeItem(storageKey);
    }
  }

  window.servitechAdminToast = {
    show,
    persist,
    success: (message, options) => show(message, "success", options),
    error: (message, options) => show(message, "error", options),
    warning: (message, options) => show(message, "warning", options),
    info: (message, options) => show(message, "info", options),
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", consumePending, { once: true });
  } else {
    consumePending();
  }
})();
