<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/app.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/dashboard_stats.php";
require_once __DIR__ . "/_includes/queue_files.php";

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
$topServices = is_array($dashboardAnalytics["topServices"] ?? null) ? $dashboardAnalytics["topServices"] : [];
$categoryMix = is_array($dashboardAnalytics["categoryMix"] ?? null) ? $dashboardAnalytics["categoryMix"] : [];
$todayAnalytics = is_array($dashboardAnalytics["today"] ?? null) ? $dashboardAnalytics["today"] : [];
$dashboardNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
$adminNotificationCount = admin_queue_notification_count($pdo);
$isSuperAdmin = servitech_is_super_admin();
$roleLabel = servitech_role_label();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech Admin Dashboard</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= project_url('/pages/admin/admin.css?v=20260612header-global-type') ?>">
  <link rel="stylesheet" href="<?= project_url('/pages/admin/admin_dashboard.css?v=20260624-analytics-rebuild') ?>">
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
    <h1>Operations Dashboard</h1>
    <p>Live overview of customer activity, orders, and service queue.</p>
    <div class="hero-meta">
      <span class="hero-chip">
        <?= htmlspecialchars($roleLabel, ENT_QUOTES, "UTF-8") ?> Access
      </span>
      <span class="hero-time" id="adminNow">
        <?= htmlspecialchars($dashboardNow->format("M d, Y, h:i:s A"), ENT_QUOTES, "UTF-8") ?>
      </span>
    </div>
  </section>

  <section class="stats">

    <div class="stat stat--customers">
      <h4>ACTIVE REQUESTS</h4>
      <div 
        class="value" 
        id="activeRequestsCount"
        data-count="<?= $activeRequests ?>"
      >
        <?= $analyticsAvailable ? $activeRequests : "—" ?>
      </div>
      <p class="stat-note">Pending through For Pick-Up, excluding Bin</p>
    </div>

    <div class="stat stat--orders">
      <h4>ACTIVE QUEUE</h4>
      <div 
        class="value" 
        id="queueCount"
        data-count="<?= $activeQueue ?>"
      >
        <?= $analyticsAvailable ? $activeQueue : "—" ?>
      </div>
      <p class="stat-note">Active rows in Queue Management</p>
    </div>

    <div class="stat stat--queue">
      <h4>VISIBLE ORDERS</h4>
      <div 
        class="value" 
        id="ordersCount"
        data-count="<?= $visibleOrders ?>"
      >
        <?= $analyticsAvailable ? $visibleOrders : "—" ?>
      </div>
      <p class="stat-note">All rows in Order Management, excluding Bin</p>
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
    <h3 class="section-title"><?= $isSuperAdmin ? "Super Admin Quick Access" : "Admin Quick Access" ?></h3>
    <div class="admin-section-divider" aria-hidden="true"></div>
  </header>

  <section class="quick-grid quick-access-section quick-access-grid admin-quick-grid">
    <a href="<?= project_url('/pages/admin/queue_list/printing.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon admin-quick-icon">&#x23F3;</div>
        <h4>Queue List</h4>
        <p>View and update queues</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/order_management/printM.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon admin-quick-icon">&#x1F4E6;</div>
        <h4>Order Management</h4>
        <p>Manage customer orders</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/customer_list/custoL.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon icon--customer-list admin-quick-icon" aria-hidden="true">
          <img
            src="<?= project_url('/pages/admin/IMAGES/LANDING_CUSTOMER_LIST.png?v=20260411h4') ?>"
            alt=""
            class="icon-image icon-image--customer-list"
          >
        </div>
        <h4>Customer List</h4>
        <p>View registered customers</p>
      </article>
    </a>

    <?php if ($isSuperAdmin): ?>
    <a href="<?= project_url('/pages/admin/Services/edit_services.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon admin-quick-icon">&#x270F;&#xFE0F;</div>
        <h4>Edit Services</h4>
        <p>Edit the shown services on the landing page</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/announcement.php') ?>" class="card-link admin-quick-card-link">
      <article class="card card--announcement admin-quick-card">
        <div class="icon admin-quick-icon" aria-hidden="true">&#x1F4E2;</div>
        <h4>Announcement</h4>
        <p>Post a notice on the landing page</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/store_availability.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon admin-quick-icon" aria-hidden="true">&#x1F552;</div>
        <h4>Store Availability</h4>
        <p>Manage shop hours, cutoffs, holidays, and service status</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/staff_accounts.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon admin-quick-icon" aria-hidden="true">SA</div>
        <h4>Staff Accounts</h4>
        <p>Create, update, deactivate, and reset admin accounts</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/activity_logs.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon admin-quick-icon" aria-hidden="true">LOG</div>
        <h4>Activity Logs</h4>
        <p>Review employee actions and account changes</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/system_settings.php') ?>" class="card-link admin-quick-card-link">
      <article class="card admin-quick-card">
        <div class="icon admin-quick-icon" aria-hidden="true">SET</div>
        <h4>System Settings</h4>
        <p>Open owner-level configuration and diagnostics</p>
      </article>
    </a>
    <?php endif; ?>

  </section>

  <header class="admin-quick-access-header admin-analytics-header" id="operations-analytics">
    <h3 class="section-title">Operations Analytics</h3>
    <div class="admin-section-divider" aria-hidden="true"></div>
  </header>

  <section class="analytics-grid analytics-grid--visual">
    <article class="analytics-card analytics-card--status">
      <div class="analytics-head">
        <div>
          <h4>Active Requests by Status</h4>
          <p>Current non-binned requests across Queue and Order Management</p>
        </div>
        <span class="live-pill">Current</span>
      </div>
      <div class="operational-status-metrics">
        <div class="status-metric status-metric--pending">
          <span>Pending</span>
          <strong id="statusPendingCount"><?= (int)($statusAnalytics["pending"] ?? 0) ?></strong>
        </div>
        <div class="status-metric status-metric--approved">
          <span>Approved</span>
          <strong id="statusApprovedCount"><?= (int)($statusAnalytics["approved"] ?? 0) ?></strong>
        </div>
        <div class="status-metric status-metric--ongoing">
          <span>Ongoing</span>
          <strong id="statusOngoingCount"><?= (int)($statusAnalytics["ongoing"] ?? 0) ?></strong>
        </div>
        <div class="status-metric status-metric--pickup">
          <span>For Pick-Up</span>
          <strong id="statusForPickupCount"><?= (int)($statusAnalytics["forPickup"] ?? 0) ?></strong>
        </div>
      </div>
    </article>

    <article class="analytics-card analytics-card--wide analytics-card--dark">
      <div class="analytics-head">
        <div>
          <h4>Top Requested Services</h4>
          <p>Visible requests created in the last 30 Manila calendar days</p>
        </div>
        <span class="live-pill">30 days</span>
      </div>
      <div class="analytics-chart-shell">
        <div class="analytics-list analytics-list--ranked" id="topServicesList">
        <?php if (!$topServices): ?>
          <p class="analytics-empty">No visible requests in this period.</p>
        <?php else: ?>
          <?php
            $maxTopServices = max(array_map(static fn($item) => (int)($item["total"] ?? 0), $topServices));
            $maxTopServices = max(1, $maxTopServices);
          ?>
          <?php foreach ($topServices as $index => $item): ?>
            <?php
              $label = trim((string)($item["label"] ?? "Service"));
              $total = (int)($item["total"] ?? 0);
              $width = max(8, (int)round(($total / $maxTopServices) * 100));
            ?>
            <div class="analytics-row">
              <span class="analytics-rank"><?= $index + 1 ?></span>
              <span class="analytics-row__body">
                <span class="analytics-label"><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></span>
                <span class="analytics-mini-track"><span style="width: <?= $width ?>%"></span></span>
              </span>
              <strong><?= $total ?></strong>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        </div>
      </div>
    </article>

    <article class="analytics-card analytics-card--chart">
      <div class="analytics-head">
        <div>
          <h4>Active Requests by Category</h4>
          <p>Current non-binned Print, Repair, and Installation requests</p>
        </div>
      </div>
      <div class="analytics-bars analytics-bars--visual" id="categoryMixBars">
        <?php if (!$categoryMix): ?>
          <p class="analytics-empty">No active requests.</p>
        <?php else: ?>
          <?php
            $maxCategoryMix = max(array_map(static fn($item) => (int)($item["total"] ?? 0), $categoryMix));
            $maxCategoryMix = max(1, $maxCategoryMix);
          ?>
          <?php foreach ($categoryMix as $item): ?>
            <?php
              $label = trim((string)($item["label"] ?? "Service"));
              $total = (int)($item["total"] ?? 0);
              $width = $total > 0 ? max(6, (int)round(($total / $maxCategoryMix) * 100)) : 0;
            ?>
            <div class="analytics-bar">
              <div class="analytics-bar__meta">
                <span><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></span>
                <strong><?= $total ?></strong>
              </div>
              <div class="analytics-bar__track">
                <span style="width: <?= $width ?>%"></span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </article>

    <article class="analytics-card analytics-card--today">
      <div class="analytics-head">
        <div>
          <h4>Today</h4>
          <p>Visible activity by Manila calendar day</p>
        </div>
      </div>
      <div class="today-metrics">
        <div>
          <i aria-hidden="true"></i>
          <span>New Requests</span>
          <strong id="todayNewRequestsCount"><?= (int)($todayAnalytics["newRequests"] ?? 0) ?></strong>
        </div>
        <div>
          <i aria-hidden="true"></i>
          <span>Completed Today</span>
          <strong id="todayCompletedCount"><?= (int)($todayAnalytics["completed"] ?? 0) ?></strong>
        </div>
        <div>
          <i aria-hidden="true"></i>
          <span>Cancelled Today</span>
          <strong id="todayCancelledCount"><?= (int)($todayAnalytics["cancelled"] ?? 0) ?></strong>
        </div>
      </div>
    </article>
  </section>


</main>

<?php require_once __DIR__ . "/_includes/admin_footer.php"; ?>

<script src="<?= project_url('/pages/admin/admin_dashboard.js?v=20260624-analytics-rebuild') ?>" defer></script>

<script src="<?= project_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>


