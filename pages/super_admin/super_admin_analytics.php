<?php
require_once __DIR__ . "/../admin/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/../../config/app.php";
require_once __DIR__ . "/../admin/_includes/admin_db.php";
require_once __DIR__ . "/_includes/super_admin_analytics_views.php";

$context = analytics_load_context($pdo, $_GET);
$dashboardNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Owner Reports & Analytics | ServiTech</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260612header-global-type') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260630-analytics') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/super_admin/super_admin_analytics.css?v=20260701-card-image-icons') ?>">
</head>
<body class="admin-analytics-page">

<?php
$adminHeaderVariant = "dashboard";
require __DIR__ . "/../admin/_includes/admin_header.php";
?>

<main class="container main-container analytics-page analytics-landing-page">
  <section class="analytics-landing-header">
    <div>
      <p class="analytics-eyebrow">Super Admin Reporting</p>
      <h1>Owner Reports & Analytics</h1>
      <p>A simplified reporting center for reviewing ServiTech operations, service requests, queue performance, workflow progress, and monthly analytics exports.</p>
    </div>
    <div class="analytics-header-meta">
      <span>Current Cycle</span>
      <strong><?= analytics_h(($context["cycle"]["start_date"] ?? "-") . " to " . ($context["cycle"]["end_date"] ?? "-")) ?></strong>
      <small>Updated <?= analytics_h($dashboardNow->format("M d, Y h:i A")) ?></small>
    </div>
  </section>

  <?php if (!$context["ready"]): ?>
    <div class="analytics-warning" role="status">
      <?= analytics_h($context["error"] !== "" ? $context["error"] : "Analytics are temporarily unavailable.") ?>
    </div>
  <?php endif; ?>

  <?php analytics_render_landing_cards($context); ?>
</main>

<?php require_once __DIR__ . "/../admin/_includes/admin_footer.php"; ?>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>
