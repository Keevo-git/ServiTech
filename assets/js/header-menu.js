(function () {
  if (window.ServiTechHeaderMenuInitialized) return;
  window.ServiTechHeaderMenuInitialized = true;

  var MOBILE_BREAKPOINT = 900;
  var activeLogoutModal = null;
  var previousFocus = null;
  var scrollLockState = null;

  function isCompactViewport() {
    return window.matchMedia("(max-width: " + MOBILE_BREAKPOINT + "px)").matches;
  }

  function initMenu(container, index) {
    var toggle = container.querySelector(".nav-toggle");
    var menu = container.querySelector("[data-collapsible-menu]");

    if (!toggle || !menu) return;

    if (!menu.id) {
      menu.id = "responsive-menu-" + (index + 1);
    }
    toggle.setAttribute("aria-controls", menu.id);

    function setExpanded(expanded) {
      container.classList.toggle("is-menu-open", expanded);
      toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
      if (isCompactViewport()) {
        menu.setAttribute("aria-hidden", expanded ? "false" : "true");
      } else {
        menu.removeAttribute("aria-hidden");
      }
    }

    function closeMenu() {
      setExpanded(false);
    }

    toggle.addEventListener("click", function () {
      setExpanded(!container.classList.contains("is-menu-open"));
    });

    menu.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        if (isCompactViewport()) closeMenu();
      });
    });

    document.addEventListener("click", function (event) {
      if (!isCompactViewport()) return;
      if (!container.classList.contains("is-menu-open")) return;
      if (container.contains(event.target)) return;
      closeMenu();
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && container.classList.contains("is-menu-open")) {
        closeMenu();
      }
    });

    window.addEventListener("resize", function () {
      if (!isCompactViewport()) closeMenu();
    });

    closeMenu();
  }

  initLogoutConfirmation();

  function initHeaderMenus() {
    document.querySelectorAll(".has-nav-menu").forEach(initMenu);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initHeaderMenus);
  } else {
    initHeaderMenus();
  }

  function isLogoutLink(link) {
    if (!link || !link.href) return false;

    var href = link.getAttribute("href") || "";
    return (
      href.indexOf("/auth/logout.php") !== -1 ||
      href.indexOf("/pages/admin/logout.php") !== -1 ||
      link.classList.contains("admin-logout-link") ||
      link.hasAttribute("data-logout-confirm")
    );
  }

  function getLogoutTheme(link) {
    var explicitTheme = link.getAttribute("data-logout-theme");
    if (explicitTheme === "admin" || explicitTheme === "customer") {
      return explicitTheme;
    }

    var href = link.getAttribute("href") || "";
    if (
      href.indexOf("/pages/admin/logout.php") !== -1 ||
      link.classList.contains("admin-logout-link") ||
      document.body.classList.contains("admin-dashboard")
    ) {
      return "admin";
    }

    return "customer";
  }

  function closeLogoutModal() {
    if (!activeLogoutModal) return;

    document.removeEventListener("keydown", handleLogoutModalKeydown);
    document.body.classList.remove("logout-confirm-open");
    restoreLogoutScrollLock();
    activeLogoutModal.remove();
    activeLogoutModal = null;

    if (previousFocus && typeof previousFocus.focus === "function") {
      previousFocus.focus({ preventScroll: true });
    }
    previousFocus = null;
  }

  function applyLogoutScrollLock() {
    if (scrollLockState) return;

    var body = document.body;
    var scrollbarWidth = Math.max(0, window.innerWidth - document.documentElement.clientWidth);

    scrollLockState = {
      paddingRight: body.style.paddingRight
    };

    if (scrollbarWidth > 0) {
      var currentPadding = parseFloat(window.getComputedStyle(body).paddingRight) || 0;
      body.style.paddingRight = (currentPadding + scrollbarWidth) + "px";
    }
  }

  function restoreLogoutScrollLock() {
    if (!scrollLockState) return;

    document.body.style.paddingRight = scrollLockState.paddingRight;
    scrollLockState = null;
  }

  function handleLogoutModalKeydown(event) {
    if (!activeLogoutModal) return;

    if (event.key === "Escape") {
      event.preventDefault();
      closeLogoutModal();
      return;
    }

    if (event.key !== "Tab") return;

    var focusable = activeLogoutModal.querySelectorAll(
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

  function ensureLogoutModalStyles() {
    if (document.getElementById("servitech-logout-confirm-styles")) return;

    var style = document.createElement("style");
    style.id = "servitech-logout-confirm-styles";
    style.textContent = [
      "body.logout-confirm-open{overflow:hidden!important;}",
      ".logout-confirm-overlay{position:fixed!important;inset:0!important;z-index:2147483000!important;display:flex!important;align-items:center!important;justify-content:center!important;padding:clamp(16px,4vw,32px)!important;background:rgba(45,21,15,.58)!important;}",
      ".logout-confirm-overlay--admin{background:rgba(8,21,39,.64)!important;}",
      ".logout-confirm-modal{box-sizing:border-box!important;width:min(100%,420px)!important;max-height:calc(100dvh - 32px)!important;overflow-y:auto!important;padding:clamp(22px,4vw,30px)!important;border:1px solid rgba(74,5,5,.14)!important;border-radius:18px!important;background:#fff!important;color:#32211a!important;box-shadow:0 24px 70px rgba(28,15,10,.34)!important;text-align:left!important;font-family:inherit!important;}",
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

    document.head.appendChild(style);
  }

  function openLogoutModal(link) {
    closeLogoutModal();
    ensureLogoutModalStyles();

    previousFocus = link;

    var logoutUrl = link.href;
    var theme = getLogoutTheme(link);
    var overlay = document.createElement("div");
    overlay.className = "logout-confirm-overlay logout-confirm-overlay--" + theme;
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
      window.location.href = logoutUrl;
    });

    document.body.appendChild(overlay);
    applyLogoutScrollLock();
    document.body.classList.add("logout-confirm-open");
    activeLogoutModal = overlay;
    document.addEventListener("keydown", handleLogoutModalKeydown);

    var cancelButton = overlay.querySelector("[data-logout-cancel]");
    if (cancelButton) {
      cancelButton.focus({ preventScroll: true });
    }
  }

  function initLogoutConfirmation() {
    document.addEventListener("click", function (event) {
      var link = event.target.closest ? event.target.closest("a") : null;
      if (!isLogoutLink(link)) return;

      event.preventDefault();
      openLogoutModal(link);
    });
  }
})();
