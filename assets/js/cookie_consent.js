(function () {
  "use strict";

  var COOKIE_NAME = "SERVITECH_COOKIE_CONSENT";
  var STORAGE_KEY = "servitech.cookieConsent";
  var VERSION = "2";
  var MAX_AGE = 60 * 60 * 24 * 180;
  var DEFAULT_PREFERENCES = {
    version: VERSION,
    necessary: true,
    updatedAt: ""
  };

  var listeners = [];
  var lastFocusedElement = null;
  var root = null;
  var banner = null;
  var modal = null;
  var dialog = null;
  var memoryPreferences = null;

  function clonePreferences(preferences) {
    return {
      version: VERSION,
      necessary: true,
      updatedAt: preferences && preferences.updatedAt ? String(preferences.updatedAt) : ""
    };
  }

  function cookiePath() {
    if (root && root.dataset.storagePath) {
      return root.dataset.storagePath;
    }
    return "/";
  }

  function readCookie(name) {
    var encodedName = name.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, "\\$&");
    var match = document.cookie.match(new RegExp("(?:^|; )" + encodedName + "=([^;]*)"));
    return match ? decodeURIComponent(match[1]) : "";
  }

  function writeCookie(value) {
    var expires = new Date(Date.now() + (MAX_AGE * 1000)).toUTCString();
    document.cookie = COOKIE_NAME + "=" + encodeURIComponent(JSON.stringify(value))
      + "; Max-Age=" + MAX_AGE
      + "; Expires=" + expires
      + "; Path=" + cookiePath()
      + "; SameSite=Lax"
      + (window.location.protocol === "https:" ? "; Secure" : "");

    return Boolean(readCookie(COOKIE_NAME));
  }

  function readLocalPreferences() {
    try {
      return window.localStorage.getItem(STORAGE_KEY) || "";
    } catch (error) {
      return "";
    }
  }

  function writeLocalPreferences(value) {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
      return window.localStorage.getItem(STORAGE_KEY) !== null;
    } catch (error) {
      return false;
    }
  }

  function parsePreferences(raw) {
    if (!raw) {
      return null;
    }

    try {
      var parsed = JSON.parse(raw);
      if (!parsed || parsed.necessary !== true) {
        return null;
      }
      return clonePreferences(parsed);
    } catch (error) {
      return null;
    }
  }

  function preferenceTime(preferences) {
    var value = preferences && preferences.updatedAt ? Date.parse(preferences.updatedAt) : 0;
    return Number.isFinite(value) ? value : 0;
  }

  function storedPreferences() {
    if (memoryPreferences) {
      return clonePreferences(memoryPreferences);
    }

    var cookiePreferences = parsePreferences(readCookie(COOKIE_NAME));
    var localPreferences = parsePreferences(readLocalPreferences());

    if (!cookiePreferences) {
      return localPreferences;
    }
    if (!localPreferences) {
      return cookiePreferences;
    }

    return preferenceTime(localPreferences) > preferenceTime(cookiePreferences)
      ? localPreferences
      : cookiePreferences;
  }

  function synchronizePreferenceStorage(preferences) {
    if (!preferences) {
      return;
    }
    writeCookie(preferences);
    writeLocalPreferences(preferences);
  }

  function currentPreferences() {
    return storedPreferences() || clonePreferences(DEFAULT_PREFERENCES);
  }

  function hasChoice() {
    return Boolean(storedPreferences());
  }

  function allows(category) {
    return category === "necessary";
  }

  function notify(preferences) {
    listeners.slice().forEach(function (listener) {
      try {
        listener(clonePreferences(preferences));
      } catch (error) {
        window.setTimeout(function () {
          throw error;
        }, 0);
      }
    });
  }

  function savePreferences() {
    var next = clonePreferences(DEFAULT_PREFERENCES);
    next.updatedAt = new Date().toISOString();
    memoryPreferences = next;
    synchronizePreferenceStorage(next);

    hideBanner();
    closeModal(false);
    notify(next);
  }

  function acceptAll() {
    savePreferences();
  }

  function rejectNonEssential() {
    savePreferences();
  }

  function onChange(listener) {
    if (typeof listener !== "function") {
      return function () {};
    }
    listeners.push(listener);
    return function () {
      listeners = listeners.filter(function (item) {
        return item !== listener;
      });
    };
  }

  function whenAllowed(category, onAllowed, onBlocked) {
    if (allows(category) && typeof onAllowed === "function") {
      onAllowed(currentPreferences());
    } else if (!allows(category) && typeof onBlocked === "function") {
      onBlocked(currentPreferences());
    }
    return function () {
      return undefined;
    };
  }

  window.servitechPrivacyControls = {
    version: VERSION,
    getPreferences: currentPreferences,
    hasChoice: hasChoice,
    allows: allows,
    onChange: onChange,
    whenAllowed: whenAllowed,
    openPreferences: openModal,
    savePreferences: savePreferences,
    acceptAll: acceptAll,
    rejectNonEssential: rejectNonEssential
  };
  window.servitechCookieConsent = window.servitechPrivacyControls;

  function hideBanner() {
    if (banner) {
      banner.hidden = true;
    }
  }

  function showBanner() {
    if (!root || !banner) {
      return;
    }
    root.hidden = false;
    banner.hidden = false;
  }

  function openModal() {
    if (!root || !modal || !dialog) {
      return;
    }

    lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    root.hidden = false;
    hideBanner();
    modal.hidden = false;
    document.documentElement.classList.add("site-privacy-controls-open");
    document.body.classList.add("site-privacy-controls-open");
    window.setTimeout(function () {
      dialog.focus();
    }, 0);
  }

  function closeModal(saveIfUnanswered) {
    if (!modal) {
      return;
    }

    if (saveIfUnanswered !== false && !hasChoice()) {
      savePreferences();
      return;
    }

    modal.hidden = true;
    document.documentElement.classList.remove("site-privacy-controls-open");
    document.body.classList.remove("site-privacy-controls-open");

    if (window.location.hash === "#privacy-settings") {
      if (window.history && typeof window.history.replaceState === "function") {
        window.history.replaceState(null, "", window.location.pathname + window.location.search);
      } else {
        window.location.hash = "privacy-settings-closed";
      }
    }

    if (lastFocusedElement && document.contains(lastFocusedElement)) {
      lastFocusedElement.focus();
    }
    lastFocusedElement = null;
  }

  function handleAction(action) {
    if (action === "continue-required" || action === "save") {
      savePreferences();
      return;
    }
    if (action === "manage") {
      openModal();
      return;
    }
    if (action === "close") {
      closeModal(true);
    }
  }

  function focusableElements() {
    if (!dialog) {
      return [];
    }
    return Array.prototype.slice.call(dialog.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    ));
  }

  function trapFocus(event) {
    if (!modal || modal.hidden || event.key !== "Tab") {
      return;
    }

    var focusable = focusableElements();
    var first = focusable[0];
    var last = focusable[focusable.length - 1];

    if (!first || !last) {
      event.preventDefault();
      dialog.focus();
      return;
    }

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function initUi() {
    root = document.querySelector("[data-site-privacy-root]");
    if (!root || root.dataset.privacyControlsReady === "true") {
      return;
    }

    root.dataset.privacyControlsReady = "true";
    banner = root.querySelector("[data-privacy-notice]");
    modal = root.querySelector("[data-privacy-modal]");
    dialog = root.querySelector(".site-privacy-controls__dialog");

    document.addEventListener("click", function (event) {
      var trigger = event.target && event.target.closest
        ? event.target.closest("[data-privacy-action], [data-privacy-settings-open]")
        : null;
      if (!trigger) {
        return;
      }

      if (trigger.hasAttribute("data-privacy-settings-open")) {
        event.preventDefault();
        openModal();
        return;
      }

      var action = trigger.getAttribute("data-privacy-action");
      if (action) {
        event.preventDefault();
        handleAction(action);
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && modal && !modal.hidden) {
        closeModal(true);
        return;
      }
      trapFocus(event);
    });

    if (window.addEventListener) {
      window.addEventListener("hashchange", function () {
        if (window.location.hash === "#privacy-settings") {
          openModal();
        }
      });
    }

    if (!hasChoice()) {
      showBanner();
    } else {
      memoryPreferences = storedPreferences();
      synchronizePreferenceStorage(memoryPreferences);
      hideBanner();
    }

    if (window.location.hash === "#privacy-settings") {
      openModal();
    }
  }

  if (document.querySelector("[data-site-privacy-root]")) {
    initUi();
  } else if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initUi);
  } else {
    initUi();
  }
})();
