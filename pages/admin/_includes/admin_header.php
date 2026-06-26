<?php
require_once __DIR__ . "/../../../config/session_check.php";
require_once __DIR__ . "/url.php";
require_once __DIR__ . "/queue_files.php";
require_once __DIR__ . "/admin_notification_center.php";
require_once __DIR__ . "/../../../components/cookie_consent.php";

$adminHeaderVariant = $adminHeaderVariant ?? "default";
$adminHeaderMenuId = $adminHeaderMenuId ?? "admin-header-menu";
$adminHeaderShowNotificationOverlay = $adminHeaderShowNotificationOverlay ?? true;
$adminHeaderNotificationData = $adminHeaderNotificationData ?? null;
if (!is_array($adminHeaderNotificationData) && ($pdo ?? null) instanceof PDO && ($adminHeaderShowNotificationOverlay || !isset($adminNotificationCount))) {
    $adminHeaderNotificationData = admin_notification_center_data($pdo);
}
$adminNotificationCount = is_array($adminHeaderNotificationData)
    ? max(0, (int)($adminHeaderNotificationData["unread_count"] ?? 0))
    : max(0, (int)($adminNotificationCount ?? 0));
$GLOBALS["adminNotificationRealtimeConfig"] = [
    "enabled" => false,
    "url" => "",
    "anon_key" => "",
    "target_user_id" => 0,
];
if (($pdo ?? null) instanceof PDO) {
    try {
        $GLOBALS["adminNotificationRealtimeConfig"] = admin_notification_realtime_config($pdo, (string)($host ?? ""));
    } catch (Throwable $exception) {
        error_log("admin notification realtime config error: " . $exception->getMessage());
    }
}
$adminHeaderShowHome = $adminHeaderVariant !== "dashboard";
$adminHeaderRole = servitech_current_role();
$adminHeaderIsSuperAdmin = servitech_is_super_admin();
$adminHeaderRoleLabel = servitech_role_label($adminHeaderRole);
$adminHeaderNavItems = $adminHeaderIsSuperAdmin
    ? [
        ["label" => "Home", "href" => "/pages/admin/admin_dashboard.php", "roles" => ["super_admin"]],
        ["label" => "Services", "href" => "/index.php", "roles" => ["super_admin"]],
    ]
    : [
        ["label" => "Dashboard", "href" => "/pages/admin/admin_dashboard.php", "roles" => ["admin"]],
        ["label" => "Orders", "href" => "/pages/admin/order_management/printM.php", "roles" => ["admin"]],
        ["label" => "Queue Monitor", "href" => "/pages/admin/queue_list/printing.php", "roles" => ["admin"]],
        ["label" => "Customers", "href" => "/pages/admin/customer_list/custoL.php", "roles" => ["admin"]],
    ];
?>
<link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_toast.css?v=20260621-modal-stack-toast') ?>">
<script src="<?= admin_url('/pages/admin/admin_toast.js?v=20260602-admin-toast') ?>"></script>
<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<script src="<?= admin_url('/assets/js/header-menu.js?v=20260608-admin-menu-controller') ?>" defer></script>
<script src="<?= admin_url('/pages/admin/admin_logout_confirm.js?v=20260608-admin-logout-confirm-global') ?>" defer></script>
<script>
  function goAdminBack() {
    if (window.history.length > 1) {
      window.history.back();
      return;
    }

    window.location.href = "<?= admin_url('/pages/admin/admin_dashboard.php') ?>";
  }
</script>
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
    flex: 0 1 auto;
  }

  .admin-shared-header nav[data-collapsible-menu] a,
  .admin-shared-header nav[data-collapsible-menu] button {
    font-size: 15px;
    line-height: 1.2;
  }

  .admin-shared-header nav[data-collapsible-menu] button {
    background: transparent;
    border: 0;
    color: inherit;
    cursor: pointer;
    font: inherit;
  }

  .admin-shared-header .admin-header-actions {
    order: 3;
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
  }

  .admin-shared-header .admin-header-actions--super {
    gap: 12px;
  }

  .admin-shared-header .admin-role-badge {
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 7px 10px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.34);
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    font-size: 12px;
    font-weight: 800;
    line-height: 1.1;
    white-space: nowrap;
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

  .admin-shared-header .admin-header-actions .admin-logout-link,
  .admin-shared-header .admin-header-actions .admin-logout-link:visited {
    display: inline-flex;
    flex: 0 0 auto;
    min-height: 46px;
    margin: 0;
    padding: 11px 20px;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 190, 198, 0.64);
    border-radius: 12px;
    background: rgba(174, 45, 62, 0.28);
    color: #ffffff;
    font-size: 15px;
    font-weight: 700;
    line-height: 1.2;
    text-decoration: none;
    white-space: nowrap;
    transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.18s ease;
  }

  .admin-shared-header .admin-header-actions .admin-logout-link:hover {
    border-color: rgba(255, 210, 216, 0.86);
    background: rgba(201, 55, 74, 0.62);
    color: #ffffff;
    transform: translateY(-1px);
  }

  .admin-shared-header .admin-header-actions .admin-logout-link:focus-visible {
    outline: 2px solid rgba(255, 220, 224, 0.94);
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

    .admin-shared-header .admin-header-actions .admin-logout-link,
    .admin-shared-header .admin-header-actions .admin-logout-link:visited {
      min-height: 42px;
      padding: 10px 14px;
      font-size: 14px;
    }

    .admin-shared-header .admin-role-badge {
      min-height: 30px;
      padding: 6px 8px;
      font-size: 11px;
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
    .admin-shared-header nav[data-collapsible-menu] a:visited,
    .admin-shared-header nav[data-collapsible-menu] button {
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

    .admin-shared-header .admin-header-actions .admin-logout-link,
    .admin-shared-header .admin-header-actions .admin-logout-link:visited {
      min-height: 40px;
      padding-inline: 11px;
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
  <nav id="<?= htmlspecialchars($adminHeaderMenuId, ENT_QUOTES, 'UTF-8') ?>" data-collapsible-menu>
    <?php foreach ($adminHeaderNavItems as $item): ?>
      <?php if (in_array($adminHeaderRole, $item["roles"], true)): ?>
        <?php if (!$adminHeaderIsSuperAdmin && !$adminHeaderShowHome && $item["href"] === "/pages/admin/admin_dashboard.php") continue; ?>
        <a href="<?= admin_url($item["href"]) ?>"><?= htmlspecialchars($item["label"], ENT_QUOTES, 'UTF-8') ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <div class="admin-header-actions<?= $adminHeaderIsSuperAdmin ? ' admin-header-actions--super' : '' ?>">
    <?php if (!$adminHeaderIsSuperAdmin): ?>
      <span class="admin-role-badge"><?= htmlspecialchars($adminHeaderRoleLabel, ENT_QUOTES, 'UTF-8') ?></span>
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
