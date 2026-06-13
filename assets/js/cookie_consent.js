(function () {
  "use strict";

  var COOKIE_NAME = "SERVITECH_COOKIE_CONSENT";
  var STORAGE_KEY = "servitech.cookieConsent";
  var VERSION = "1";
  var MAX_AGE = 60 * 60 * 24 * 180;
  var DEFAULT_PREFERENCES = {
    version: VERSION,
    necessary: true,
    functional: false,
    updatedAt: ""
  };

  var listeners = [];
  var allowedCallbacks = [];
  var lastFocusedElement = null;
  var root = null;
  var banner = null;
  var modal = null;
  var dialog = null;
  var functionalToggle = null;
  var memoryPreferences = null;

  function clonePreferences(preferences) {
    return {
      version: VERSION,
      necessary: true,
      functional: Boolean(preferences && preferences.functional),
      updatedAt: preferences && preferences.updatedAt ? String(preferences.updatedAt) : ""
    };
  }

  function cookiePath() {
    if (root && root.dataset.cookiePath) {
      return root.dataset.cookiePath;
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
      if (!parsed || parsed.necessary !== true || typeof parsed.functional !== "boolean") {
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
    if (category === "necessary") {
      return true;
    }
    var preferences = storedPreferences();
    return Boolean(preferences && preferences[category] === true);
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

    allowedCallbacks.slice().forEach(function (entry) {
      runAllowedCallback(entry);
    });
  }

  function savePreferences(partial) {
    var next = clonePreferences(partial);
    next.updatedAt = new Date().toISOString();
    memoryPreferences = next;
    synchronizePreferenceStorage(next);

    hideBanner();
    closeModal();
    notify(next);
  }

  function acceptAll() {
    savePreferences({ functional: true });
  }

  function rejectNonEssential() {
    savePreferences({ functional: false });
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

  function runAllowedCallback(entry) {
    var allowed = allows(entry.category);
    if (allowed && !entry.didAllow) {
      entry.didAllow = true;
      entry.onAllowed(currentPreferences());
      return;
    }

    if (!allowed && typeof entry.onBlocked === "function") {
      entry.didAllow = false;
      entry.onBlocked(currentPreferences());
    }
  }

  function whenAllowed(category, onAllowed, onBlocked) {
    if (typeof onAllowed !== "function") {
      return function () {};
    }

    var entry = {
      category: category,
      onAllowed: onAllowed,
      onBlocked: onBlocked,
      didAllow: false
    };
    allowedCallbacks.push(entry);
    runAllowedCallback(entry);

    return function () {
      allowedCallbacks = allowedCallbacks.filter(function (item) {
        return item !== entry;
      });
    };
  }

  window.servitechCookieConsent = {
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

  function syncModalFields() {
    if (functionalToggle) {
      functionalToggle.checked = allows("functional");
    }
  }

  function openModal() {
    if (!root || !modal || !dialog) {
      return;
    }

    lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    root.hidden = false;
    hideBanner();
    syncModalFields();
    modal.hidden = false;
    document.documentElement.classList.add("cookie-consent-open");
    document.body.classList.add("cookie-consent-open");
    window.setTimeout(function () {
      dialog.focus();
    }, 0);
  }

  function closeModal() {
    if (!modal) {
      return;
    }

    modal.hidden = true;
    document.documentElement.classList.remove("cookie-consent-open");
    document.body.classList.remove("cookie-consent-open");

    if (!hasChoice()) {
      showBanner();
    }

    if (lastFocusedElement && document.contains(lastFocusedElement)) {
      lastFocusedElement.focus();
    }
    lastFocusedElement = null;
  }

  function handleAction(action) {
    if (action === "accept-all") {
      acceptAll();
      return;
    }
    if (action === "reject") {
      rejectNonEssential();
      return;
    }
    if (action === "manage") {
      openModal();
      return;
    }
    if (action === "save") {
      savePreferences({ functional: Boolean(functionalToggle && functionalToggle.checked) });
      return;
    }
    if (action === "close") {
      closeModal();
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
    root = document.querySelector("[data-cookie-consent-root]");
    if (!root || root.dataset.cookieConsentReady === "true") {
      return;
    }

    root.dataset.cookieConsentReady = "true";
    banner = root.querySelector("[data-cookie-banner]");
    modal = root.querySelector("[data-cookie-modal]");
    dialog = root.querySelector(".cookie-consent__dialog");
    functionalToggle = root.querySelector("[data-cookie-functional-toggle]");

    document.addEventListener("click", function (event) {
      var trigger = event.target && event.target.closest
        ? event.target.closest("[data-cookie-action], [data-cookie-preferences-open]")
        : null;
      if (!trigger) {
        return;
      }

      if (trigger.hasAttribute("data-cookie-preferences-open")) {
        event.preventDefault();
        openModal();
        return;
      }

      var action = trigger.getAttribute("data-cookie-action");
      if (action) {
        event.preventDefault();
        handleAction(action);
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && modal && !modal.hidden) {
        closeModal();
        return;
      }
      trapFocus(event);
    });

    if (window.addEventListener) {
      window.addEventListener("hashchange", function () {
        if (window.location.hash === "#cookie-preferences") {
          openModal();
        }
      });
    }

    if (!hasChoice()) {
      showBanner();
    } else {
      memoryPreferences = storedPreferences();
      synchronizePreferenceStorage(memoryPreferences);
      root.hidden = false;
      hideBanner();
    }

    if (window.location.hash === "#cookie-preferences") {
      openModal();
    }
  }

  if (document.querySelector("[data-cookie-consent-root]")) {
    initUi();
  } else if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initUi);
  } else {
    initUi();
  }
})();
