<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/app.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/dashboard_stats.php";
require_once __DIR__ . "/_includes/queue_files.php";

if (servitech_is_super_admin()) {
    header("Location: " . servitech_url("/pages/super_admin/super_admin_dashboard.php"));
    exit();
}

function project_url(string $path): string
{
    return htmlspecialchars(servitech_url($path), ENT_QUOTES, "UTF-8");
}

$dashboardStats = fetch_admin_dashboard_stats($pdo);
$analyticsAvailable = (bool)($dashboardStats["available"] ?? false);
$analyticsError = trim((string)($dashboardStats["error"] ?? ""));
$activeRequests = (int)($dashboardStats["activeRequests"] ?? 0);
$activeQueue = (int)($dashboardStats["activeQueue"] ?? 0);
$visibleOrders = (int)($dashboardStats["visibleOrders"] ?? 0);
$dashboardAnalytics = $dashboardStats["analytics"] ?? [];
$statusAnalytics = is_array($dashboardAnalytics["status"] ?? null) ? $dashboardAnalytics["status"] : [];
$pendingRequests = (int)($statusAnalytics["pending"] ?? 0);
$dashboardNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
$adminNotificationCount = admin_queue_notification_count($pdo);
$dashboardTitle = servitech_admin_employee_banner_title($pdo, "Employee Operations Dashboard");
$dashboardSubtitle = "Manage today's queue, orders, and customer service tasks.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech Employee Admin Dashboard</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= project_url('/pages/admin/admin.css?v=20260612header-global-type') ?>">
  <link rel="stylesheet" href="<?= project_url('/pages/admin/admin_dashboard.css?v=20260628-quick-access-blue-grid') ?>">
</head>
<body
  class="admin-dashboard"
  data-dashboard-stats-url="<?= project_url('/pages/admin/get_dashboard_stats.php') ?>"
  data-analytics-available="<?= $analyticsAvailable ? 'true' : 'false' ?>"
>

<?php
$adminHeaderVariant = "dashboard";
require __DIR__ . "/_includes/admin_header.php";
?>

<main class="container main-container dashboard-content">

  <section class="hero hero-wrapper hero-container">
    <h1><?= htmlspecialchars($dashboardTitle, ENT_QUOTES, "UTF-8") ?></h1>
    <p><?= htmlspecialchars($dashboardSubtitle, ENT_QUOTES, "UTF-8") ?></p>
    <div class="hero-meta">
      <span class="hero-chip">
        Admin / Employee Access
      </span>
      <span class="hero-time" id="adminNow">
        <?= htmlspecialchars($dashboardNow->format("M d, Y, h:i:s A"), ENT_QUOTES, "UTF-8") ?>
      </span>
    </div>
  </section>

  <section class="stats">

    <div class="stat stat--customers">
      <h4>PENDING REQUESTS</h4>
      <div 
        class="value" 
        id="activeRequestsCount"
        data-count="<?= $pendingRequests ?>"
      >
        <?= $analyticsAvailable ? $pendingRequests : "&mdash;" ?>
      </div>
      <p class="stat-note">Requests waiting for employee action</p>
    </div>

    <div class="stat stat--orders">
      <h4>TODAY'S QUEUE</h4>
      <div 
        class="value" 
        id="queueCount"
        data-count="<?= $activeQueue ?>"
      >
        <?= $analyticsAvailable ? $activeQueue : "&mdash;" ?>
      </div>
      <p class="stat-note">Open queue work for daily operations</p>
    </div>

    <div class="stat stat--queue">
      <h4>ACTIVE ORDERS</h4>
      <div 
        class="value" 
        id="ordersCount"
        data-count="<?= $visibleOrders ?>"
      >
        <?= $analyticsAvailable ? $visibleOrders : "&mdash;" ?>
      </div>
      <p class="stat-note">Orders visible in employee workflow</p>
    </div>

  </section>

  <?php if (!$analyticsAvailable): ?>
    <div class="analytics-warning" id="analyticsWarning" role="status">
      <?= htmlspecialchars($analyticsError !== "" ? $analyticsError : "Analytics are temporarily unavailable.", ENT_QUOTES, "UTF-8") ?>
    </div>
  <?php else: ?>
    <div class="analytics-warning" id="analyticsWarning" role="status" hidden></div>
  <?php endif; ?>

  <header class="admin-quick-access-header">
    <h3 class="section-title">Employee Daily Operations</h3>
    <div class="admin-section-divider" aria-hidden="true"></div>
  </header>

  <section class="quick-grid quick-access-section quick-access-grid admin-quick-grid admin-quick-grid--employee">
    <a href="<?= project_url('/pages/admin/queue_list/printing.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon admin-quick-icon" aria-hidden="true">
          <img
            src="<?= project_url('/assets/images/QUEUE MANAGEMENT.png?v=20260628-dashboard-icons') ?>"
            alt=""
            class="icon-image admin-quick-icon-image icon-image--queue-management"
          >
        </div>
        <h4>Queue Management</h4>
        <p>Manage today's queue and active service requests.</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/order_management/printM.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon admin-quick-icon" aria-hidden="true">
          <img
            src="<?= project_url('/assets/images/ORDER MANAGEMENT.png?v=20260628-dashboard-icons') ?>"
            alt=""
            class="icon-image admin-quick-icon-image icon-image--order-management"
          >
        </div>
        <h4>Order Management</h4>
        <p>Manage and update customer order processing.</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/customer_list/custoL.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon icon--customer-list admin-quick-icon" aria-hidden="true">
          <img
            src="<?= project_url('/pages/admin/IMAGES/LANDING_CUSTOMER_LIST.png?v=20260411h4') ?>"
            alt=""
            class="icon-image admin-quick-icon-image icon-image--customer-list"
          >
        </div>
        <h4>Customer Lookup</h4>
        <p>Find customer details needed for service requests</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/admin_profile.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon admin-quick-icon" aria-hidden="true">
          <img
            src="<?= project_url('/assets/images/ADMIN_MYPROFILE.png?v=20260628-dashboard-icons') ?>"
            alt=""
            class="icon-image admin-quick-icon-image icon-image--my-profile"
          >
        </div>
        <h4>My Profile</h4>
        <p>View your staff account and password options</p>
      </article>
    </a>
  </section>

  <header class="admin-quick-access-header">
    <h3 class="section-title">Employee Task Focus</h3>
    <div class="admin-section-divider" aria-hidden="true"></div>
  </header>

  <section class="employee-focus-grid">
    <article class="employee-focus-card">
      <h4>Process Queue</h4>
      <p>Open the queue pages to accept, review, and move service requests through the daily workflow.</p>
      <a href="<?= project_url('/pages/admin/queue_list/printing.php') ?>">Open Queue</a>
    </article>
    <article class="employee-focus-card">
      <h4>Update Orders</h4>
      <p>Use Order Management to mark customer work as ongoing, ready for pick-up, or done.</p>
      <a href="<?= project_url('/pages/admin/order_management/printM.php') ?>">Open Orders</a>
    </article>
    <article class="employee-focus-card">
      <h4>Message Customers</h4>
      <p>Use customer and queue message tools for service updates needed during operations.</p>
      <a href="<?= project_url('/pages/admin/customer_list/custoL.php') ?>">Open Customers</a>
    </article>
  </section>

</main>

<?php require_once __DIR__ . "/_includes/admin_footer.php"; ?>

<script src="<?= project_url('/pages/admin/admin_dashboard.js?v=20260624-analytics-rebuild') ?>" defer></script>

<script src="<?= project_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>


