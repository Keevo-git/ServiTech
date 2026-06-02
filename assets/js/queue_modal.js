(function () {
  "use strict";

  var BASE_Z_INDEX = 10000;
  var STACK_STEP = 20;

  function isVisible(element) {
    return !!element && window.getComputedStyle(element).display !== "none";
  }

  function visibleOverlays(exclude) {
    return Array.from(document.querySelectorAll(".modal-overlay")).filter(function (overlay) {
      return overlay !== exclude && isVisible(overlay);
    });
  }

  function numericZIndex(element) {
    var value = parseInt(window.getComputedStyle(element).zIndex, 10);
    return Number.isFinite(value) ? value : 0;
  }

  function topVisibleOverlay() {
    return visibleOverlays(null).reduce(function (topLayer, layer) {
      if (!topLayer || numericZIndex(layer) >= numericZIndex(topLayer)) {
        return layer;
      }
      return topLayer;
    }, null);
  }

  function syncScrollLock() {
    var hasVisibleModal = visibleOverlays(null).length > 0;
    document.body.classList.toggle("modal-open", hasVisibleModal);
    document.documentElement.classList.toggle("modal-open", hasVisibleModal);
  }

  function suspendLayer(layer) {
    var count = Number(layer.dataset.servitechSuspendCount || 0);
    if (count === 0) {
      layer._servitechSuspendedState = {
        pointerEvents: layer.style.pointerEvents,
        hadAriaHidden: layer.hasAttribute("aria-hidden"),
        ariaHidden: layer.getAttribute("aria-hidden"),
        inert: layer.hasAttribute("inert")
      };
      layer.style.pointerEvents = "none";
      layer.setAttribute("aria-hidden", "true");
      layer.setAttribute("inert", "");
    }
    layer.dataset.servitechSuspendCount = String(count + 1);
  }

  function restoreLayer(layer) {
    var count = Math.max(0, Number(layer.dataset.servitechSuspendCount || 0) - 1);
    if (count > 0) {
      layer.dataset.servitechSuspendCount = String(count);
      return;
    }

    delete layer.dataset.servitechSuspendCount;
    var state = layer._servitechSuspendedState;
    delete layer._servitechSuspendedState;
    if (!state) return;

    layer.style.pointerEvents = state.pointerEvents;
    if (state.hadAriaHidden) {
      layer.setAttribute("aria-hidden", state.ariaHidden || "false");
    } else {
      layer.removeAttribute("aria-hidden");
    }
    if (!state.inert) {
      layer.removeAttribute("inert");
    }
  }

  function focusElement(element) {
    if (!element || typeof element.focus !== "function") return;
    window.requestAnimationFrame(function () {
      element.focus();
    });
  }

  function showModalLayer(overlay, options) {
    if (!overlay) return;
    var config = options || {};
    if (isVisible(overlay)) {
      focusElement(config.focusTarget || overlay.querySelector("[autofocus], button, [href], input, select, textarea, [tabindex]:not([tabindex='-1'])"));
      return;
    }
    var lowerLayers = visibleOverlays(overlay);
    var highestZIndex = lowerLayers.reduce(function (highest, layer) {
      return Math.max(highest, numericZIndex(layer));
    }, BASE_Z_INDEX);

    overlay._servitechPreviousFocus = document.activeElement;
    overlay._servitechSuspendedLayers = config.suspendUnderlying === false ? [] : lowerLayers;
    overlay._servitechSuspendedLayers.forEach(suspendLayer);
    overlay.style.zIndex = String(highestZIndex + STACK_STEP);
    overlay.style.pointerEvents = "auto";
    overlay.style.display = "flex";
    overlay.setAttribute("aria-hidden", "false");
    syncScrollLock();
    focusElement(config.focusTarget || overlay.querySelector("[autofocus], button, [href], input, select, textarea, [tabindex]:not([tabindex='-1'])"));
  }

  function hideModalLayer(overlay) {
    if (!overlay) return;
    overlay.style.display = "none";
    overlay.setAttribute("aria-hidden", "true");
    (overlay._servitechSuspendedLayers || []).forEach(restoreLayer);
    overlay._servitechSuspendedLayers = [];
    overlay.style.zIndex = "";
    syncScrollLock();

    var previousFocus = overlay._servitechPreviousFocus;
    overlay._servitechPreviousFocus = null;
    if (previousFocus && document.contains(previousFocus) && !previousFocus.closest("[inert]")) {
      focusElement(previousFocus);
    }
  }

  function setOptionalText(element, value) {
    if (!element) return;
    var text = String(value || "").trim();
    element.textContent = text;
    element.hidden = text === "";
  }

  function openQueueSuccessModal(queueCode, options) {
    var overlay = document.getElementById("queueModal");
    var dialog = overlay && overlay.querySelector(".queue-success-modal");
    if (!overlay || !dialog) return;

    var config = options || {};
    var queueNumber = document.getElementById("modalQueueNo");
    var title = document.getElementById("queueModalTitle");
    var message = document.getElementById("queueModalMessage");
    var service = document.getElementById("queueModalService");
    var note = document.getElementById("queueModalNote");
    var primaryAction = document.getElementById("viewQueueBtn");

    if (queueNumber) queueNumber.textContent = String(queueCode || "").trim() || "Pending";
    if (title) title.textContent = config.title || "Queue Joined Successfully";
    if (message) message.textContent = config.message || "Your service request has been added to the queue.";
    setOptionalText(service, config.service ? "Service: " + config.service : "");
    if (note) note.textContent = config.note || "You can check your queue status while you wait for the next update.";

    showModalLayer(overlay, { focusTarget: primaryAction || dialog });
  }

  function closeQueueSuccessModal() {
    hideModalLayer(document.getElementById("queueModal"));
  }

  function trapQueueModalFocus(event) {
    var overlay = document.getElementById("queueModal");
    if (!overlay || !isVisible(overlay) || topVisibleOverlay() !== overlay) return;

    if (event.key === "Escape") {
      event.preventDefault();
      closeQueueSuccessModal();
      return;
    }
    if (event.key !== "Tab") return;

    var focusable = Array.from(overlay.querySelectorAll("button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex='-1'])"));
    if (!focusable.length) {
      event.preventDefault();
      overlay.querySelector(".queue-success-modal").focus();
      return;
    }

    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  window.servitechShowModalLayer = showModalLayer;
  window.servitechHideModalLayer = hideModalLayer;
  window.openQueueSuccessModal = openQueueSuccessModal;
  window.closeQueueSuccessModal = closeQueueSuccessModal;

  document.addEventListener("keydown", trapQueueModalFocus);
  document.addEventListener("DOMContentLoaded", function () {
    var overlay = document.getElementById("queueModal");
    var closeButton = document.getElementById("queueModalCloseBtn");
    if (closeButton) closeButton.addEventListener("click", closeQueueSuccessModal);
    if (overlay) {
      overlay.addEventListener("click", function (event) {
        if (event.target === overlay) closeQueueSuccessModal();
      });
    }
  });
})();
