(function () {
  "use strict";

  const BASE_Z_INDEX = 2147480000;
  const LAYER_STEP = 10;
  const FOCUSABLE_SELECTOR = [
    "button:not([disabled])",
    "input:not([disabled])",
    "select:not([disabled])",
    "textarea:not([disabled])",
    "a[href]",
    '[tabindex]:not([tabindex="-1"])',
  ].join(", ");
  const stack = [];

  function focusElement(element) {
    if (!element || typeof element.focus !== "function") return;
    window.setTimeout(() => element.focus(), 0);
  }

  function focusableElements(dialog) {
    return Array.from(dialog?.querySelectorAll(FOCUSABLE_SELECTOR) || []);
  }

  function firstFocusable(dialog) {
    return focusableElements(dialog)[0] || null;
  }

  function setCovered(entry, covered) {
    if (!entry?.dialog) return;
    entry.dialog.inert = covered;
    entry.dialog.setAttribute("aria-hidden", covered ? "true" : "false");
  }

  function open({ overlay, dialog = overlay, focus = null, onEscape = null }) {
    if (!overlay || !dialog) return;

    const existingIndex = stack.findIndex((entry) => entry.overlay === overlay);
    if (existingIndex !== -1) {
      stack.splice(existingIndex, 1);
    }

    const previous = stack[stack.length - 1] || null;
    setCovered(previous, true);

    const zIndex = BASE_Z_INDEX + (stack.length * LAYER_STEP);
    const entry = {
      overlay,
      dialog,
      focus,
      onEscape,
      previousFocus: document.activeElement,
      previousOverlayZ: overlay.style.zIndex,
      previousDialogZ: dialog.style.zIndex,
    };
    overlay.style.zIndex = String(zIndex);
    dialog.style.zIndex = String(zIndex + 1);
    stack.push(entry);
    setCovered(entry, false);
    focusElement(focus || firstFocusable(dialog));
  }

  function close(overlay) {
    const index = stack.findIndex((entry) => entry.overlay === overlay);
    if (index === -1) return;

    const [entry] = stack.splice(index, 1);
    entry.overlay.style.zIndex = entry.previousOverlayZ;
    entry.dialog.style.zIndex = entry.previousDialogZ;
    entry.dialog.inert = false;

    const top = stack[stack.length - 1] || null;
    setCovered(top, false);
    focusElement(top ? (top.focus || firstFocusable(top.dialog)) : entry.previousFocus);
  }

  function top() {
    return stack[stack.length - 1] || null;
  }

  document.addEventListener("keydown", (event) => {
    const entry = top();
    if (!entry) return;

    if (event.key === "Escape" && typeof entry.onEscape === "function") {
      event.preventDefault();
      event.stopImmediatePropagation();
      entry.onEscape();
      return;
    }

    if (event.key !== "Tab") return;
    const focusables = focusableElements(entry.dialog);
    if (!focusables.length) return;

    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      focusElement(last);
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      focusElement(first);
    }
  }, true);

  document.addEventListener("focusin", (event) => {
    const entry = top();
    if (!entry || entry.dialog.contains(event.target)) return;
    focusElement(entry.focus || firstFocusable(entry.dialog));
  }, true);

  window.servitechAdminModalStack = { open, close, top };
})();
