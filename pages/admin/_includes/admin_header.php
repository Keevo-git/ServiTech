<?php
require_once __DIR__ . "/../../../config/session_check.php";
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
<script src="<?= admin_url('/assets/js/header-menu.js?v=20260608-admin-menu-controller') ?>" defer></script>
<script src="<?= admin_url('/pages/admin/admin_logout_confirm.js?v=20260608-admin-logout-confirm-global') ?>" defer></script>
<style>
  .admin-shared-header {
    position: sticky;
    top: 0;
    z-index: 1000;
  }

  .admin-shared-header .logo {
    order: 1;
  }

  .admin-shared-header .logo h1 {
    font-size: clamp(17px, 2vw, 22px);
    line-height: 1.1;
  }

  .admin-shared-header nav[data-collapsible-menu] {
    order: 2;
    margin-left: auto;
  }

  .admin-shared-header nav[data-collapsible-menu] a {
    font-size: 15px;
    line-height: 1.2;
  }

  .admin-shared-header .admin-header-actions {
    order: 3;
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
  }

  .admin-shared-header .admin-header-actions .admin-notification-btn,
  .admin-shared-header .admin-header-actions .admin-notification-btn:visited {
    position: relative;
    display: inline-flex;
    flex: 0 0 46px;
    width: 46px;
    min-width: 46px;
    height: 46px;
    min-height: 46px;
    margin: 0;
    padding: 0;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.42);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    text-decoration: none;
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.16),
      0 8px 18px rgba(10, 27, 49, 0.22);
    transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.18s ease, box-shadow 0.2s ease;
  }

  .admin-shared-header .admin-header-actions .admin-notification-btn:hover {
    border-color: rgba(255, 255, 255, 0.68);
    background: rgba(255, 255, 255, 0.22);
    transform: translateY(-1px);
  }

  .admin-shared-header .admin-header-actions .admin-notification-btn:focus-visible {
    outline: 2px solid rgba(198, 235, 255, 0.94);
    outline-offset: 2px;
  }

  @media (max-width: 900px) {
    .admin-shared-header {
      position: sticky !important;
      top: 0 !important;
      z-index: 2200 !important;
      display: grid !important;
      grid-template-columns: minmax(0, 1fr) auto !important;
      grid-template-areas:
        "brand actions"
        "menu menu" !important;
      align-items: center !important;
      gap: 10px 12px !important;
      overflow: visible !important;
    }

    .admin-shared-header .logo {
      grid-area: brand !important;
      min-width: 0 !important;
      width: auto !important;
    }

    .admin-shared-header .logo h1 {
      overflow: hidden !important;
      text-overflow: ellipsis !important;
      white-space: nowrap !important;
    }

    .admin-shared-header .admin-header-actions {
      grid-area: actions !important;
      display: inline-flex !important;
      justify-self: end !important;
      gap: 8px !important;
    }

    .admin-shared-header .admin-header-actions .admin-notification-btn,
    .admin-shared-header .admin-header-actions .admin-notification-btn:visited,
    .admin-shared-header .nav-toggle {
      display: inline-flex !important;
      align-self: center !important;
      flex: 0 0 42px !important;
      width: 42px !important;
      min-width: 42px !important;
      height: 42px !important;
      min-height: 42px !important;
      margin: 0 !important;
      pointer-events: auto !important;
      z-index: 2 !important;
    }

    .admin-shared-header nav[data-collapsible-menu] {
      grid-area: menu !important;
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

    .admin-shared-header .admin-header-actions .admin-notification-badge {
      top: -6px !important;
      right: -7px !important;
      min-width: 23px;
      height: 23px;
      padding-inline: 5px;
      font-size: 12px;
      line-height: 23px;
    }
  }

  @media (max-width: 420px) {
    .admin-shared-header {
      column-gap: 8px !important;
    }

    .admin-shared-header .admin-header-actions {
      gap: 6px !important;
    }

    .admin-shared-header .admin-header-actions .admin-notification-btn,
    .admin-shared-header .admin-header-actions .admin-notification-btn:visited,
    .admin-shared-header .nav-toggle {
      flex-basis: 40px !important;
      width: 40px !important;
      min-width: 40px !important;
      height: 40px !important;
      min-height: 40px !important;
      border-radius: 11px !important;
    }

    .admin-shared-header .admin-notification-icon {
      width: 24px;
      height: 24px;
    }
  }
</style>
<header class="navbar has-nav-menu admin-shared-header">
  <a href="<?= htmlspecialchars(servitech_brand_home_url(), ENT_QUOTES, 'UTF-8') ?>" class="logo">
    <img src="<?= admin_url('/assets/images/LOGO_SERVITECH.png') ?>" alt="ServiTech Logo">
    <h1>ServiTech Admin</h1>
  </a>
  <div class="admin-header-actions">
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
  </div>
  <nav id="<?= htmlspecialchars($adminHeaderMenuId, ENT_QUOTES, 'UTF-8') ?>" data-collapsible-menu>
    <?php if ($adminHeaderShowHome): ?>
      <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>">Home</a>
    <?php endif; ?>
    <?php if ($adminHeaderShowServices): ?>
      <a href="<?= admin_url('/index.php') ?>">Services</a>
    <?php endif; ?>
    <a href="<?= admin_url('/pages/admin/logout.php') ?>" class="admin-logout-link">Logout</a>
  </nav>
</header>
<script>
  (function () {
    if (window.ServiTechAdminMobileNavInitialized) return;
    window.ServiTechAdminMobileNavInitialized = true;

    function isCompact() {
      return window.matchMedia("(max-width: 900px)").matches;
    }

    function menuParts(header) {
      return {
        toggle: header ? header.querySelector(".nav-toggle") : null,
        menu: header ? header.querySelector("[data-collapsible-menu]") : null
      };
    }

    function setOpen(header, open) {
      var parts = menuParts(header);
      if (!header || !parts.toggle || !parts.menu) return;

      open = !!open && isCompact();
      header.classList.toggle("is-menu-open", open);
      parts.toggle.setAttribute("aria-expanded", open ? "true" : "false");
      if (isCompact()) {
        parts.menu.setAttribute("aria-hidden", open ? "false" : "true");
      } else {
        parts.menu.removeAttribute("aria-hidden");
      }
    }

    function closeAllAdminMenus() {
      document.querySelectorAll(".admin-shared-header.is-menu-open").forEach(function (header) {
        setOpen(header, false);
      });
    }

    document.querySelectorAll(".admin-shared-header").forEach(function (header) {
      setOpen(header, false);
    });

    document.addEventListener("click", function (event) {
      if (!event.target || !event.target.closest) return;

      var toggle = event.target.closest(".admin-shared-header .nav-toggle");
      if (!toggle) return;

      var header = toggle.closest(".admin-shared-header");
      if (!header || !menuParts(header).menu) return;

      event.preventDefault();
      event.stopPropagation();
      if (typeof event.stopImmediatePropagation === "function") {
        event.stopImmediatePropagation();
      }
      event.__servitechHeaderMenuHandled = true;
      setOpen(header, !header.classList.contains("is-menu-open"));
    }, true);

    document.addEventListener("click", function (event) {
      if (!isCompact() || !event.target || !event.target.closest) return;

      document.querySelectorAll(".admin-shared-header.is-menu-open").forEach(function (header) {
        if (!header.contains(event.target)) {
          setOpen(header, false);
        }
      });
    });

    document.addEventListener("click", function (event) {
      if (!isCompact() || !event.target || !event.target.closest) return;

      var link = event.target.closest(".admin-shared-header nav[data-collapsible-menu] a");
      if (!link) return;

      var header = link.closest(".admin-shared-header");
      if (header) {
        window.setTimeout(function () {
          setOpen(header, false);
        }, 0);
      }
    }, true);

    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape") return;

      var openHeader = document.querySelector(".admin-shared-header.is-menu-open");
      if (openHeader) {
        var parts = menuParts(openHeader);
        setOpen(openHeader, false);
        if (parts.toggle) parts.toggle.focus();
      }
    });

    window.addEventListener("resize", function () {
      if (!isCompact()) closeAllAdminMenus();
    });
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
