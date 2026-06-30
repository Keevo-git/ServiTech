<?php
require_once __DIR__ . "/../admin/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/../../config/app.php";
require_once __DIR__ . "/../admin/_includes/admin_db.php";
require_once __DIR__ . "/_includes/super_admin_analytics_views.php";

$context = analytics_load_context($pdo, $_GET);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Staff Workload & Productivity | ServiTech</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260612header-global-type') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260630-analytics') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/super_admin/super_admin_analytics.css?v=20260701-individual-reports') ?>">
</head>
<body class="admin-analytics-page">
<?php $adminHeaderVariant = "dashboard"; require __DIR__ . "/../admin/_includes/admin_header.php"; ?>
<main class="container main-container analytics-page analytics-report-page">
  <?php analytics_render_report_header("Staff Workload & Productivity", "Monitor handled requests, completed requests, active workload, and staff status updates.", $context); ?>
  <?php if (!$context["ready"]): ?>
    <div class="analytics-warning" role="status"><?= analytics_h($context["error"] ?: "Analytics are temporarily unavailable.") ?></div>
  <?php else: ?>
    <?php analytics_render_filters($context, "super_admin_analytics_staff.php", ["cycle", "date", "service", "status", "source", "staff"]); ?>
    <?php analytics_render_staff($context); ?>
    <?php analytics_render_export_row($context, "super_admin_analytics_staff.php"); ?>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . "/../admin/_includes/admin_footer.php"; ?>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>
