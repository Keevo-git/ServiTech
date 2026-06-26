(function () {
  "use strict";

  var superAdminLoginPath = "/auth/super_admin_login.php";
  var adminLoginPath = "/auth/admin_login.php";

  function isEditableTarget(target) {
    if (!target || !target.closest) return false;
    return Boolean(target.closest("input, textarea, select, button, [contenteditable='true'], [contenteditable='']"));
  }

  function goTo(path) {
    if (!path || window.location.pathname === path) return;
    window.location.href = path;
  }

  document.addEventListener("keydown", function (event) {
    if (!event.ctrlKey || !event.altKey || event.metaKey || event.shiftKey || event.defaultPrevented) {
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
