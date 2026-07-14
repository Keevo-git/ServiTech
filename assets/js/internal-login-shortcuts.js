(function () {
  "use strict";

  var superAdminLoginPath = "/auth/super_admin_login.php";
  var adminLoginPath = "/auth/admin_login.php";
  var page = document.body ? document.body.getAttribute("data-page") : "";

  if (page !== "public-login") {
    return;
  }

  function isEditableTarget(target) {
    if (!target || !target.closest) return false;
    return Boolean(target.isContentEditable || target.closest("input, textarea, select, button, [contenteditable]"));
  }

  function goTo(path) {
    if (!path || window.location.pathname === path) return;
    window.location.href = path;
  }

  document.addEventListener("keydown", function (event) {
    if (
      event.defaultPrevented
      || event.repeat
      || event.metaKey
      || event.shiftKey
      || !event.ctrlKey
      || !event.altKey
      || (event.getModifierState && event.getModifierState("AltGraph"))
    ) {
      return;
    }
    if (isEditableTarget(event.target)) {
      return;
    }

    var key = String(event.key || "").toLowerCase();
    if (key === "s") {
      event.preventDefault();
      goTo(superAdminLoginPath);
    } else if (key === "a") {
      event.preventDefault();
      goTo(adminLoginPath);
    }
  });
})();
