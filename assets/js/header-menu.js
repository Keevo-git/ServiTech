(function () {
  if (window.ServiTechHeaderMenuInitialized) return;
  window.ServiTechHeaderMenuInitialized = true;

  var MOBILE_BREAKPOINT = 900;
  var logoutState = {
    activeModal: null,
    previousFocus: null,
    scrollX: 0,
    scrollY: 0
  };

  function isCompactViewport() {
    return window.matchMedia("(max-width: " + MOBILE_BREAKPOINT + "px)").matches;
  }

  function setMenuExpanded(container, expanded) {
    var toggle = container.querySelector(".nav-toggle");
    var menu = container.querySelector("[data-collapsible-menu]");

    container.classList.toggle("is-menu-open", expanded);
    if (toggle) {
      toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
    }
    if (menu) {
      if (isCompactViewport()) {
        menu.setAttribute("aria-hidden", expanded ? "false" : "true");
      } else {
        menu.removeAttribute("aria-hidden");
      }
    }
  }

  function closeMenu(container) {
    setMenuExpanded(container, false);
  }

  function closeOpenMenus() {
    document.querySelectorAll(".has-nav-menu.is-menu-open").forEach(closeMenu);
  }

  function initMenu(container, index) {
    var toggle = container.querySelector(".nav-toggle");
    var menu = container.querySelector("[data-collapsible-menu]");

    if (!toggle || !menu) return;

    if (!menu.id) {
      menu.id = "responsive-menu-" + (index + 1);
    }
    toggle.setAttribute("aria-controls", menu.id);

    toggle.addEventListener("click", function (event) {
      if (event.__servitechHeaderMenuHandled) return;
      event.__servitechHeaderMenuHandled = true;
      setMenuExpanded(container, !container.classList.contains("is-menu-open"));
    });

    menu.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        if (isCompactViewport()) closeMenu(container);
      });
    });

    document.addEventListener("click", function (event) {
      if (!isCompactViewport()) return;
      if (!container.classList.contains("is-menu-open")) return;
      if (container.contains(event.target)) return;
      closeMenu(container);
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && container.classList.contains("is-menu-open")) {
        closeMenu(container);
      }
    });

    window.addEventListener("resize", function () {
      if (!isCompactViewport()) closeMenu(container);
    });

    closeMenu(container);
  }

  function initAdminMenuFallback() {
    if (window.ServiTechAdminHeaderMenuFallbackInitialized) return;
    window.ServiTechAdminHeaderMenuFallbackInitialized = true;

    document.addEventListener("click", function (event) {
      if (event.__servitechHeaderMenuHandled || !event.target || !event.target.closest) return;

      var toggle = event.target.closest(".admin-shared-header .nav-toggle");
      if (!toggle) return;

      var container = toggle.closest(".admin-shared-header.has-nav-menu");
      if (!container || !container.querySelector("[data-collapsible-menu]")) return;

      event.preventDefault();
      setMenuExpanded(container, !container.classList.contains("is-menu-open"));
    });
  }

  function initHeaderMenus() {
    document.querySelectorAll(".has-nav-menu").forEach(initMenu);
    initAdminMenuFallback();
  }

  function normalizeText(value) {
    return String(value || "").replace(/\s+/g, " ").trim().toLowerCase();
  }

  function absoluteUrl(value) {
    if (!value) return "";
    try {
      return new URL(value, window.location.href).href;
    } catch (error) {
      return String(value || "");
    }
  }

  function urlPath(value) {
    if (!value) return "";
    try {
      return new URL(value, window.location.href).pathname.toLowerCase();
    } catch (error) {
      return String(value || "").split("?")[0].split("#")[0].toLowerCase();
    }
  }

  function isLogoutUrl(value) {
    var path = urlPath(value);
    return (
      path.indexOf("/auth/logout.php") !== -1 ||
      path.indexOf("/pages/admin/logout.php") !== -1 ||
      /(^|\/)logout\.php$/.test(path)
    );
  }

  function findLogoutTrigger(target) {
    if (!target || !target.closest) return null;
    if (target.closest(".logout-confirm-overlay")) return null;
    return target.closest("a, button, input[type='button'], input[type='submit'], [role='button'], [data-logout-confirm]");
  }

  function readCandidateUrl(trigger) {
    if (!trigger) return "";

    var candidates = [
      trigger.getAttribute("data-logout-url"),
      trigger.getAttribute("href"),
      trigger.getAttribute("formaction"),
      trigger.getAttribute("data-href")
    ];

    if (trigger.form) {
      candidates.push(trigger.form.getAttribute("action"));
    }

    for (var index = 0; index < candidates.length; index += 1) {
      var candidate = candidates[index];
      if (candidate && candidate !== "#") {
        return absoluteUrl(candidate);
      }
    }

    return "";
  }

  function getLogoutTheme(trigger, url) {
    var explicitTheme = trigger ? trigger.getAttribute("data-logout-theme") : "";
    if (explicitTheme === "admin" || explicitTheme === "customer") {
      return explicitTheme;
    }

    if (
      (trigger && trigger.classList.contains("admin-logout-link")) ||
      isLogoutUrl(url) && urlPath(url).indexOf("/pages/admin/logout.php") !== -1 ||
      document.body.classList.contains("admin-dashboard") ||
      document.body.classList.contains("admin-page") ||
      (document.querySelector(".navbar .logo h1") &&
        document.querySelector(".navbar .logo h1").textContent.toLowerCase().indexOf("admin") !== -1)
    ) {
      return "admin";
    }

    return "customer";
  }

  function resolveLogoutRequest(trigger) {
    if (!trigger) return null;

    var url = readCandidateUrl(trigger);
    var text = normalizeText(trigger.textContent || trigger.value);
    var hasLogoutIntent = (
      trigger.hasAttribute("data-logout-confirm") ||
      trigger.classList.contains("admin-logout-link") ||
      isLogoutUrl(url) ||
      (text === "logout" || text === "log out")
    );

    if (!hasLogoutIntent) return null;

    var theme = getLogoutTheme(trigger, url);
    if (!url || url === window.location.href + "#") {
      url = theme === "admin" ? "/pages/admin/logout.php" : "/auth/logout.php";
    }

    return {
      trigger: trigger,
      url: absoluteUrl(url),
      theme: theme
    };
  }

  function ensureLogoutModalStyles() {
    if (document.getElementById("servitech-logout-confirm-styles")) return;

    var style = document.createElement("style");
    style.id = "servitech-logout-confirm-styles";
    style.textContent = [
      "html.logout-confirm-open,body.logout-confirm-open{scrollbar-gutter:stable!important;}",
      "body.logout-confirm-open{overscroll-behavior:contain!important;}",
      ".logout-confirm-overlay{position:fixed!important;inset:0!important;z-index:2147483000!important;display:flex!important;align-items:center!important;justify-content:center!important;width:100vw!important;height:100dvh!important;padding:clamp(16px,4vw,32px)!important;background:rgba(45,21,15,.58)!important;box-sizing:border-box!important;overflow:hidden!important;}",
      ".logout-confirm-overlay--admin{background:rgba(8,21,39,.64)!important;}",
      ".logout-confirm-modal{box-sizing:border-box!important;width:min(100%,420px)!important;max-height:calc(100dvh - 32px)!important;overflow-y:auto!important;overscroll-behavior:contain!important;padding:clamp(22px,4vw,30px)!important;border:1px solid rgba(74,5,5,.14)!important;border-radius:18px!important;background:#fff!important;color:#32211a!important;box-shadow:0 24px 70px rgba(28,15,10,.34)!important;text-align:left!important;font-family:inherit!important;}",
      ".logout-confirm-overlay--admin .logout-confirm-modal{border-color:rgba(26,63,115,.18)!important;color:#112338!important;box-shadow:0 26px 74px rgba(10,27,49,.38)!important;}",
      ".logout-confirm-modal__header{margin-bottom:10px!important;}",
      ".logout-confirm-modal h2{margin:0!important;color:#4A0505!important;font-size:clamp(22px,5vw,26px)!important;line-height:1.2!important;letter-spacing:0!important;font-weight:800!important;}",
      ".logout-confirm-overlay--admin .logout-confirm-modal h2{color:#112b4f!important;}",
      ".logout-confirm-modal p{margin:0!important;color:#76513d!important;font-size:15px!important;line-height:1.55!important;}",
      ".logout-confirm-overlay--admin .logout-confirm-modal p{color:#5d6f86!important;}",
      ".logout-confirm-modal__actions{display:flex!important;justify-content:flex-end!important;gap:12px!important;margin-top:24px!important;}",
      ".logout-confirm-modal__button{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:auto!important;min-width:112px!important;min-height:46px!important;margin:0!important;padding:11px 18px!important;border-radius:14px!important;font-size:15px!important;font-weight:800!important;line-height:1.2!important;cursor:pointer!important;text-decoration:none!important;transition:background-color .2s ease,border-color .2s ease,color .2s ease,transform .18s ease,box-shadow .2s ease!important;}",
      ".logout-confirm-modal__button:hover{transform:translateY(-1px)!important;}",
      ".logout-confirm-modal__button:active{transform:translateY(0)!important;}",
      ".logout-confirm-modal__button:focus-visible{outline:2px solid #ff8b2c!important;outline-offset:2px!important;}",
      ".logout-confirm-overlay--admin .logout-confirm-modal__button:focus-visible{outline-color:rgba(36,168,188,.9)!important;}",
      ".logout-confirm-modal__button--cancel{border:1px solid #e7cdbd!important;background:#fff7ef!important;color:#5c2d1b!important;box-shadow:none!important;}",
      ".logout-confirm-modal__button--cancel:hover{border-color:#dfbda9!important;background:#f4e6dc!important;}",
      ".logout-confirm-overlay--admin .logout-confirm-modal__button--cancel{border-color:#cbd8e8!important;background:#f5f9ff!important;color:#17365f!important;}",
      ".logout-confirm-overlay--admin .logout-confirm-modal__button--cancel:hover{border-color:#adc4df!important;background:#eaf3ff!important;}",
      ".logout-confirm-modal__button--confirm{border:1px solid rgba(188,35,24,.72)!important;background:#b42318!important;color:#fff!important;box-shadow:0 9px 18px rgba(180,35,24,.22)!important;}",
      ".logout-confirm-modal__button--confirm:hover{border-color:rgba(214,38,28,.92)!important;background:#d72638!important;}",
      ".logout-confirm-overlay--admin .logout-confirm-modal__button--confirm{border-color:rgba(255,190,198,.72)!important;background:#b91c1c!important;color:#fff!important;box-shadow:0 9px 18px rgba(185,28,28,.22)!important;}",
      ".logout-confirm-overlay--admin .logout-confirm-modal__button--confirm:hover{border-color:rgba(255,210,216,.9)!important;background:#dc2626!important;}",
      "@media (max-width:520px){.logout-confirm-overlay{padding:14px!important;}.logout-confirm-modal{width:100%!important;padding:22px 18px!important;border-radius:16px!important;}.logout-confirm-modal__actions{display:grid!important;grid-template-columns:1fr 1fr!important;gap:10px!important;}.logout-confirm-modal__button{width:100%!important;min-width:0!important;padding-right:12px!important;padding-left:12px!important;}}",
      "@media (max-width:360px){.logout-confirm-modal__actions{grid-template-columns:1fr!important;}}"
    ].join("");

    (document.head || document.documentElement).appendChild(style);
  }

  function modalElement() {
    return logoutState.activeModal ? logoutState.activeModal.querySelector(".logout-confirm-modal") : null;
  }

  function lockBackgroundScroll() {
    logoutState.scrollX = window.scrollX || document.documentElement.scrollLeft || 0;
    logoutState.scrollY = window.scrollY || document.documentElement.scrollTop || 0;

    document.addEventListener("wheel", preventBackgroundScroll, { passive: false, capture: true });
    document.addEventListener("touchmove", preventBackgroundScroll, { passive: false, capture: true });
    window.addEventListener("scroll", keepScrollPosition, { passive: true });
    document.documentElement.classList.add("logout-confirm-open");
    document.body.classList.add("logout-confirm-open");
  }

  function unlockBackgroundScroll() {
    document.removeEventListener("wheel", preventBackgroundScroll, true);
    document.removeEventListener("touchmove", preventBackgroundScroll, true);
    window.removeEventListener("scroll", keepScrollPosition);
    document.documentElement.classList.remove("logout-confirm-open");
    document.body.classList.remove("logout-confirm-open");
  }

  function keepScrollPosition() {
    if (!logoutState.activeModal) return;
    if (window.scrollX === logoutState.scrollX && window.scrollY === logoutState.scrollY) return;
    window.scrollTo(logoutState.scrollX, logoutState.scrollY);
  }

  function preventBackgroundScroll(event) {
    var modal = modalElement();
    if (!modal || !modal.contains(event.target)) {
      event.preventDefault();
      keepScrollPosition();
    }
  }

  function closeLogoutModal() {
    if (!logoutState.activeModal) return;

    document.removeEventListener("keydown", handleLogoutModalKeydown);
    unlockBackgroundScroll();
    logoutState.activeModal.remove();
    logoutState.activeModal = null;

    if (logoutState.previousFocus && typeof logoutState.previousFocus.focus === "function") {
      logoutState.previousFocus.focus({ preventScroll: true });
    }
    logoutState.previousFocus = null;
  }

  function isScrollableKey(event) {
    return ["ArrowDown", "ArrowUp", "PageDown", "PageUp", "Home", "End", " "].indexOf(event.key) !== -1;
  }

  function isInteractiveControl(element) {
    return element && element.closest && element.closest("button, a, input, select, textarea, [role='button']");
  }

  function handleLogoutModalKeydown(event) {
    if (!logoutState.activeModal) return;

    if (event.key === "Escape") {
      event.preventDefault();
      closeLogoutModal();
      return;
    }

    var modal = modalElement();
    if (isScrollableKey(event) && (!modal || !modal.contains(event.target) || !isInteractiveControl(event.target))) {
      event.preventDefault();
      keepScrollPosition();
      return;
    }

    if (event.key !== "Tab") return;

    var focusable = logoutState.activeModal.querySelectorAll(
      'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
    );
    if (!focusable.length) return;

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

  function openLogoutModal(requestOrTrigger) {
    var request = requestOrTrigger && requestOrTrigger.url
      ? requestOrTrigger
      : resolveLogoutRequest(requestOrTrigger);
    if (!request) return false;

    closeLogoutModal();
    closeOpenMenus();
    ensureLogoutModalStyles();

    logoutState.previousFocus = request.trigger || document.activeElement;

    var overlay = document.createElement("div");
    overlay.className = "logout-confirm-overlay logout-confirm-overlay--" + request.theme;
    overlay.setAttribute("data-servitech-logout-modal", "");
    overlay.innerHTML =
      '<section class="logout-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="logout-confirm-title" aria-describedby="logout-confirm-message" tabindex="-1">' +
        '<div class="logout-confirm-modal__header">' +
          '<h2 id="logout-confirm-title">Confirm Logout</h2>' +
        "</div>" +
        '<p id="logout-confirm-message">Are you sure you want to log out?</p>' +
        '<div class="logout-confirm-modal__actions">' +
          '<button type="button" class="logout-confirm-modal__button logout-confirm-modal__button--cancel" data-logout-cancel>Cancel</button>' +
          '<button type="button" class="logout-confirm-modal__button logout-confirm-modal__button--confirm" data-logout-confirm-action>Logout</button>' +
        "</div>" +
      "</section>";

    overlay.addEventListener("click", function (event) {
      if (event.target === overlay) {
        closeLogoutModal();
      }
    });

    overlay.querySelector("[data-logout-cancel]").addEventListener("click", closeLogoutModal);
    overlay.querySelector("[data-logout-confirm-action]").addEventListener("click", function () {
      window.location.assign(request.url);
    });

    document.body.appendChild(overlay);
    logoutState.activeModal = overlay;
    lockBackgroundScroll();
    document.addEventListener("keydown", handleLogoutModalKeydown);

    var cancelButton = overlay.querySelector("[data-logout-cancel]");
    if (cancelButton) {
      cancelButton.focus({ preventScroll: true });
    }

    return true;
  }

  function handleDelegatedLogoutClick(event) {
    var trigger = findLogoutTrigger(event.target);
    var request = resolveLogoutRequest(trigger);
    if (!request) return;

    event.preventDefault();
    event.stopPropagation();
    if (typeof event.stopImmediatePropagation === "function") {
      event.stopImmediatePropagation();
    }
    openLogoutModal(request);
  }

  function initLogoutConfirmation() {
    if (window.ServiTechLogoutConfirmDelegated) return;
    window.ServiTechLogoutConfirmDelegated = true;
    document.addEventListener("click", handleDelegatedLogoutClick, true);
  }

  window.ServiTechLogoutConfirm = {
    close: closeLogoutModal,
    init: initLogoutConfirmation,
    isLogoutTrigger: function (element) {
      return !!resolveLogoutRequest(findLogoutTrigger(element));
    },
    open: openLogoutModal
  };

  initLogoutConfirmation();

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initHeaderMenus);
  } else {
    initHeaderMenus();
  }
})();
