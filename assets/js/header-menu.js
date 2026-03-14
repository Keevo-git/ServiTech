(function () {
  var MOBILE_BREAKPOINT = 900;

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
  });
})();
