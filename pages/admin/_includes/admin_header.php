<?php
require_once __DIR__ . "/url.php";
require_once __DIR__ . "/queue_files.php";
require_once __DIR__ . "/admin_notification_center.php";

$adminHeaderVariant = $adminHeaderVariant ?? "default";
$adminHeaderMenuId = $adminHeaderMenuId ?? "admin-header-menu";
$adminHeaderShowNotificationOverlay = $adminHeaderShowNotificationOverlay ?? true;
$adminHeaderNotificationData = $adminHeaderNotificationData ?? null;
if (!is_array($adminHeaderNotificationData) && ($pdo ?? null) instanceof PDO && ($adminHeaderShowNotificationOverlay || !isset($adminNotificationCount))) {
    $adminHeaderNotificationData = admin_notification_center_data($pdo);
}
$adminNotificationCount = isset($adminNotificationCount)
    ? max(0, (int)$adminNotificationCount)
    : (int)($adminHeaderNotificationData["unread_count"] ?? 0);
$adminHeaderShowHome = $adminHeaderVariant !== "dashboard";
$adminHeaderShowServices = in_array($adminHeaderVariant, ["dashboard", "special"], true);
?>
<link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_toast.css?v=20260602-admin-toast') ?>">
<script src="<?= admin_url('/pages/admin/admin_toast.js?v=20260602-admin-toast') ?>"></script>
<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<script src="<?= admin_url('/assets/js/header-menu.js?v=20260608-admin-menu-toggle-2') ?>" defer></script>
<script src="<?= admin_url('/pages/admin/admin_logout_confirm.js?v=20260608-admin-logout-confirm-global') ?>" defer></script>
<style>
  @media (max-width: 900px) {
    .admin-shared-header {
      position: relative !important;
      z-index: 2200 !important;
      display: grid !important;
      grid-template-columns: minmax(0, 1fr) auto !important;
      align-items: center !important;
      gap: 10px 12px !important;
      overflow: visible !important;
    }

    .admin-shared-header .logo {
      grid-column: 1 !important;
      min-width: 0 !important;
      width: auto !important;
    }

    .admin-shared-header .logo h1 {
      overflow: hidden !important;
      text-overflow: ellipsis !important;
      white-space: nowrap !important;
    }

    .admin-shared-header .nav-toggle {
      grid-column: 2 !important;
      display: inline-flex !important;
      justify-self: end !important;
      align-self: center !important;
      pointer-events: auto !important;
      z-index: 2 !important;
    }

    .admin-shared-header nav[data-collapsible-menu] {
      grid-column: 1 / -1 !important;
      display: none !important;
      width: 100% !important;
      max-width: 100% !important;
      min-width: 0 !important;
      margin: 0 !important;
      padding: 10px !important;
      flex-direction: column !important;
      align-items: stretch !important;
      justify-content: flex-start !important;
      gap: 10px !important;
      overflow: visible !important;
      border: 1px solid rgba(255, 255, 255, 0.28) !important;
      border-radius: 12px !important;
      background: rgba(8, 30, 58, 0.32) !important;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08) !important;
    }

    .admin-shared-header.is-menu-open nav[data-collapsible-menu] {
      display: flex !important;
    }

    .admin-shared-header nav[data-collapsible-menu] a,
    .admin-shared-header nav[data-collapsible-menu] a:visited {
      display: flex !important;
      width: 100% !important;
      max-width: 100% !important;
      min-width: 0 !important;
      min-height: 46px !important;
      margin: 0 !important;
      align-items: center !important;
      justify-content: center !important;
      color: #ffffff !important;
      text-align: center !important;
      text-decoration: none !important;
      white-space: normal !important;
      overflow-wrap: anywhere !important;
    }

    .admin-shared-header nav[data-collapsible-menu] a.admin-notification-btn,
    .admin-shared-header nav[data-collapsible-menu] a.admin-notification-btn:visited {
      align-self: stretch !important;
      width: 100% !important;
      min-width: 0 !important;
      height: 46px !important;
      min-height: 46px !important;
    }
  }
</style>
<header class="navbar has-nav-menu admin-shared-header">
  <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>" class="logo">
    <img src="<?= admin_url('/assets/images/LOGO_SERVITECH.png') ?>" alt="ServiTech Logo">
    <h1>ServiTech Admin</h1>
  </a>
  <button
    class="nav-toggle"
    type="button"
    aria-label="Toggle navigation menu"
    aria-expanded="false"
    aria-controls="<?= htmlspecialchars($adminHeaderMenuId, ENT_QUOTES, 'UTF-8') ?>"
  >
    <span class="nav-toggle__bar"></span>
    <span class="nav-toggle__bar"></span>
    <span class="nav-toggle__bar"></span>
  </button>
  <nav id="<?= htmlspecialchars($adminHeaderMenuId, ENT_QUOTES, 'UTF-8') ?>" data-collapsible-menu>
    <?php if ($adminHeaderShowHome): ?>
      <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>">Home</a>
    <?php endif; ?>
    <?php if ($adminHeaderShowServices): ?>
      <a href="<?= admin_url('/index.php') ?>">Services</a>
    <?php endif; ?>
    <a
      href="<?= admin_queue_notification_link() ?>"
      class="admin-notification-btn"
      aria-label="Admin notifications: <?= $adminNotificationCount ?>"
      aria-controls="adminNotificationPanel"
      aria-expanded="false"
      title="Admin notifications"
    >
      <img
        src="<?= admin_url('/assets/images/white_notification.png?v=20260601-ringing-bell') ?>"
        alt=""
        class="admin-notification-icon"
        width="28"
        height="28"
      >
      <?php if ($adminNotificationCount > 0): ?>
        <span class="admin-notification-badge"><?= $adminNotificationCount ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= admin_url('/pages/admin/logout.php') ?>" class="admin-logout-link">Logout</a>
  </nav>
</header>
<script>
  (function () {
    var header = document.currentScript.previousElementSibling;
    if (!header || !header.classList || !header.classList.contains("admin-shared-header")) return;

    var toggle = header.querySelector(".nav-toggle");
    var menu = header.querySelector("[data-collapsible-menu]");
    if (!toggle || !menu) return;

    function isCompact() {
      return window.matchMedia("(max-width: 900px)").matches;
    }

    function setOpen(open) {
      header.classList.toggle("is-menu-open", open);
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      if (isCompact()) {
        menu.setAttribute("aria-hidden", open ? "false" : "true");
      } else {
        menu.removeAttribute("aria-hidden");
      }
    }

    toggle.addEventListener("click", function (event) {
      event.preventDefault();
      event.__servitechHeaderMenuHandled = true;
      setOpen(!header.classList.contains("is-menu-open"));
    });

    menu.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        if (isCompact()) setOpen(false);
      });
    });

    document.addEventListener("click", function (event) {
      if (!isCompact() || !header.classList.contains("is-menu-open") || header.contains(event.target)) return;
      setOpen(false);
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && header.classList.contains("is-menu-open")) {
        setOpen(false);
        toggle.focus();
      }
    });

    window.addEventListener("resize", function () {
      if (!isCompact()) setOpen(false);
    });

    setOpen(false);
  })();
</script>
<?php
if ($adminHeaderShowNotificationOverlay && is_array($adminHeaderNotificationData)) {
    admin_notification_render_center($adminHeaderNotificationData, [
        "mode" => "overlay",
        "id" => "adminNotificationPanel",
    ]);
    admin_notification_render_script();
}
?>
