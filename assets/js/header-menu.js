(function () {
  var MOBILE_BREAKPOINT = 900;
  var activeLogoutModal = null;
  var previousFocus = null;

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

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".has-nav-menu").forEach(initMenu);
    initLogoutConfirmation();
  });

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
    activeLogoutModal.remove();
    activeLogoutModal = null;

    if (previousFocus && typeof previousFocus.focus === "function") {
      previousFocus.focus({ preventScroll: true });
    }
    previousFocus = null;
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

  function openLogoutModal(link) {
    closeLogoutModal();

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
