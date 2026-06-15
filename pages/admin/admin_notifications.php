<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";
require_once __DIR__ . "/_includes/admin_notification_center.php";

$adminNotificationData = admin_notification_center_data($pdo);
$adminNotificationCount = (int)($adminNotificationData["unread_count"] ?? 0);
$adminHeaderShowNotificationOverlay = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Notifications - Admin</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260612header-global-type') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <style>
    body.admin-dashboard.admin-notifications-page {
      background: #eef4fb;
    }
  </style>
</head>
<body class="admin-dashboard admin-notifications-page">

<?php require __DIR__ . "/_includes/admin_header.php"; ?>

<div class="admin-wrapper">
  <?php admin_notification_render_center($adminNotificationData, ["mode" => "page", "id" => "adminNotificationPagePanel"]); ?>
</div>

<?php require_once __DIR__ . "/_includes/admin_footer.php"; ?>

<?php admin_notification_render_script(); ?>
<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>
