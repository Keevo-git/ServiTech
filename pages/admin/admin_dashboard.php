<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/app.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/_includes/dashboard_stats.php";

function project_url(string $path): string
{
    return htmlspecialchars(servitech_url($path), ENT_QUOTES, "UTF-8");
}

$dashboardStats = fetch_admin_dashboard_stats($pdo);
$customers = $dashboardStats["customers"];
$onlineOrders = $dashboardStats["onlineOrders"];
$activeQueue = $dashboardStats["activeQueue"];
$dashboardNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech Admin Dashboard</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= project_url('/pages/admin/admin_dashboard.css?v=20260411h3') ?>">
</head>
<body class="admin-dashboard">

<header class="topbar has-nav-menu">
  <div class="topbar-inner">
    <div class="brand">
      <p class="brand-tag">Control Center</p>
      <span>ServiTech Admin</span>
    </div>
    <button
      class="nav-toggle"
      type="button"
      aria-label="Toggle navigation menu"
      aria-expanded="false"
      aria-controls="admin-header-menu"
    >
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
    </button>
    <div class="actions" id="admin-header-menu" data-collapsible-menu>
      <a href="<?= project_url('/index.php') ?>" class="btn btn-home">Home</a>
      <a href="<?= project_url('/pages/admin/logout.php') ?>" class="btn">Logout</a>
    </div>
  </div>
</header>

<main class="container">

  <section class="hero">
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

    <div class="stat">
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

    <div class="stat">
      <h4>ONLINE ORDERS</h4>
      <div 
        class="value" 
        id="ordersCount" 
        data-count="<?= $onlineOrders ?>"
      >
        <?= $onlineOrders ?>
      </div>
      <p class="stat-note">Web-based and pending jobs</p>
    </div>

    <div class="stat">
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

  <h3 class="section-title">Quick Access</h3>

  <section class="quick-grid">
    <a href="<?= project_url('/pages/admin/queue_list/printing.php') ?>" class="card-link">
      <article class="card">
        <div class="icon">&#x23F3;</div>
        <h4>Queue List</h4>
        <p>View and update queues</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/order_management/printM.php') ?>" class="card-link">
      <article class="card">
        <div class="icon">&#x1F4E6;</div>
        <h4>Order Management</h4>
        <p>Manage customer orders</p>
      </article>
    </a>

    <a href="<?= project_url('/pages/admin/customer_list/custoL.php') ?>" class="card-link">
      <article class="card">
        <div class="icon icon--customer-list" aria-hidden="true">
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

    <a href="<?= project_url('/pages/admin/Services/edit_services.php') ?>" class="card-link">
      <article class="card">
        <div class="icon">&#x270F;&#xFE0F;</div>
        <h4>Edit Services</h4>
        <p>Edit the shown services on the landing page</p>
      </article>
    </a>

  </section>



</main>

<?php require_once __DIR__ . "/_includes/admin_footer.php"; ?>

<script src="<?= project_url('/pages/admin/admin_dashboard.js') ?>" defer></script>

<script src="<?= project_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>


