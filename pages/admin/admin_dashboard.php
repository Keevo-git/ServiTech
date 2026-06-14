<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/app.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/_includes/dashboard_stats.php";
require_once __DIR__ . "/_includes/queue_files.php";

function project_url(string $path): string
{
    return htmlspecialchars(servitech_url($path), ENT_QUOTES, "UTF-8");
}

$dashboardStats = fetch_admin_dashboard_stats($pdo);
$customers = $dashboardStats["customers"];
$onlineOrders = $dashboardStats["onlineOrders"];
$activeQueue = $dashboardStats["activeQueue"];
$dashboardAnalytics = $dashboardStats["analytics"] ?? [];
$mostRequested = is_array($dashboardAnalytics["mostRequested"] ?? null) ? $dashboardAnalytics["mostRequested"] : [];
$serviceMix = is_array($dashboardAnalytics["serviceMix"] ?? null) ? $dashboardAnalytics["serviceMix"] : [];
$todayAnalytics = is_array($dashboardAnalytics["today"] ?? null) ? $dashboardAnalytics["today"] : [];
$dashboardNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
$adminNotificationCount = admin_queue_notification_count($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech Admin Dashboard</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= project_url('/pages/admin/admin.css?v=20260612header-global-type') ?>">
  <link rel="stylesheet" href="<?= project_url('/pages/admin/admin_dashboard.css?v=20260614-dashboard-polish-2') ?>">
</head>
<body class="admin-dashboard">

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
        <span>&#x1F512;</span>
        Admin Access
      </span>
      <span class="hero-time" id="adminNow">
        <?= htmlspecialchars($dashboardNow->format("M d, Y, h:i:s A"), ENT_QUOTES, "UTF-8") ?>
      </span>
    </div>
  </section>

  <section class="stats">

    <div class="stat stat--customers">
      <h4>CUSTOMERS</h4>
      <div 
        class="value" 
        id="customersCount" 
        data-count="<?= $customers ?>"
      >
        <?= $customers ?>
      </div>
      <p class="stat-note">Registered user accounts</p>
    </div>

    <div class="stat stat--orders">
      <h4>PRINTING ORDERS</h4>
      <div 
        class="value" 
        id="ordersCount" 
        data-count="<?= $onlineOrders ?>"
      >
        <?= $onlineOrders ?>
      </div>
      <p class="stat-note">Document printing requests</p>
    </div>

    <div class="stat stat--queue">
      <h4>ACTIVE QUEUE</h4>
      <div 
        class="value" 
        id="queueCount" 
        data-count="<?= $activeQueue ?>"
      >
        <?= $activeQueue ?>
      </div>
      <p class="stat-note">Currently waiting for service</p>
    </div>

  </section>

  <header class="admin-quick-access-header">
    <h3 class="section-title">Admin Quick Access</h3>
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

  </section>

  <h3 class="section-title">Live Analytics</h3>

  <section class="analytics-grid analytics-grid--visual">
    <article class="analytics-card analytics-card--wide analytics-card--dark">
      <div class="analytics-head">
        <div>
          <h4>Most Requested Services</h4>
          <p>Ranked by total queue requests</p>
        </div>
        <span class="live-pill">Live</span>
      </div>
      <div class="analytics-chart-shell">
        <div class="analytics-list analytics-list--ranked" id="mostRequestedList">
        <?php if (!$mostRequested): ?>
          <p class="analytics-empty">No queue requests yet.</p>
        <?php else: ?>
          <?php
            $maxMostRequested = max(array_map(static fn($item) => (int)($item["total"] ?? 0), $mostRequested));
            $maxMostRequested = max(1, $maxMostRequested);
          ?>
          <?php foreach ($mostRequested as $index => $item): ?>
            <?php
              $label = trim((string)($item["label"] ?? "Service"));
              $total = (int)($item["total"] ?? 0);
              $width = max(8, (int)round(($total / $maxMostRequested) * 100));
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
          <h4>Service Mix</h4>
          <p>All-time request share</p>
        </div>
      </div>
      <div class="analytics-bars analytics-bars--visual" id="serviceMixBars">
        <?php if (!$serviceMix): ?>
          <p class="analytics-empty">No service data yet.</p>
        <?php else: ?>
          <?php
            $maxServiceMix = max(array_map(static fn($item) => (int)($item["total"] ?? 0), $serviceMix));
            $maxServiceMix = max(1, $maxServiceMix);
          ?>
          <?php foreach ($serviceMix as $item): ?>
            <?php
              $label = trim((string)($item["label"] ?? "Service"));
              $total = (int)($item["total"] ?? 0);
              $width = max(6, (int)round(($total / $maxServiceMix) * 100));
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
          <p>Queue activity since midnight</p>
        </div>
      </div>
      <div class="today-metrics">
        <div>
          <i aria-hidden="true"></i>
          <span>New queues</span>
          <strong id="todayQueuesCount"><?= (int)($todayAnalytics["queues"] ?? 0) ?></strong>
        </div>
        <div>
          <i aria-hidden="true"></i>
          <span>Completed</span>
          <strong id="todayCompletedCount"><?= (int)($todayAnalytics["completed"] ?? 0) ?></strong>
        </div>
        <div>
          <i aria-hidden="true"></i>
          <span>Cancelled</span>
          <strong id="todayCancelledCount"><?= (int)($todayAnalytics["cancelled"] ?? 0) ?></strong>
        </div>
      </div>
    </article>
  </section>


</main>

<?php require_once __DIR__ . "/_includes/admin_footer.php"; ?>

<script src="<?= project_url('/pages/admin/admin_dashboard.js') ?>" defer></script>

<script src="<?= project_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>


