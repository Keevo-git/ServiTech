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
<script src="<?= admin_url('/assets/js/header-menu.js?v=20260608-logout-confirm-global') ?>" defer></script>
<script src="<?= admin_url('/pages/admin/admin_logout_confirm.js?v=20260608-admin-logout-confirm-global') ?>" defer></script>
<header class="navbar has-nav-menu">
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
<?php
if ($adminHeaderShowNotificationOverlay && is_array($adminHeaderNotificationData)) {
    admin_notification_render_center($adminHeaderNotificationData, [
        "mode" => "overlay",
        "id" => "adminNotificationPanel",
    ]);
    admin_notification_render_script();
}
?>
