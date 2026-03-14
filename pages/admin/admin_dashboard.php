<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/app.php";
require_once __DIR__ . "/../../config/db.php";

function table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = ANY (current_schemas(false))
                  AND table_name = :table_name
            )
        ");
        $stmt->execute([":table_name" => strtolower(trim($tableName))]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function safe_count(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function project_url(string $path): string
{
    return htmlspecialchars(servitech_url($path), ENT_QUOTES, "UTF-8");
}

$hasOrdersTable = table_exists($pdo, "orders");
$hasQueueTable = table_exists($pdo, "queue");
$hasQueuesTable = table_exists($pdo, "queues");

// Customers card: total users
$customers = safe_count($pdo, "SELECT COUNT(*) FROM users");

// Online Orders card
if ($hasOrdersTable) {
    $onlineOrders = safe_count(
        $pdo,
        "SELECT COUNT(*) FROM orders WHERE LOWER(COALESCE(order_type, '')) = 'online' OR LOWER(COALESCE(status, '')) = 'pending'"
    );
} elseif ($hasQueuesTable) {
    // Fallback for current schema where print orders are tracked in queues.
    $onlineOrders = safe_count(
        $pdo,
        "SELECT COUNT(*) FROM queues WHERE LOWER(COALESCE(category, '')) = 'printing' OR LOWER(COALESCE(status, '')) = 'pending'"
    );
} elseif ($hasQueueTable) {
    $onlineOrders = safe_count(
        $pdo,
        "SELECT COUNT(*) FROM queue WHERE LOWER(COALESCE(order_type, '')) = 'online' OR LOWER(COALESCE(status, '')) = 'pending'"
    );
} else {
    $onlineOrders = 0;
}

// Active Queue card
if ($hasQueueTable) {
    $activeQueue = safe_count($pdo, "SELECT COUNT(*) FROM queue WHERE LOWER(COALESCE(status, '')) = 'waiting'");
} elseif ($hasQueuesTable) {
    // Fallback keeps existing queues data visible where waiting is represented as pending.
    $activeQueue = safe_count($pdo, "SELECT COUNT(*) FROM queues WHERE LOWER(COALESCE(status, '')) IN ('waiting', 'pending')");
} else {
    $activeQueue = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech Admin Dashboard</title>
  <link rel="stylesheet" href="<?= project_url('/pages/admin/admin_dashboard.css?v=20260315h2') ?>">
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

<section class="hero">
  <div class="hero-inner">
    <h1>Operations Dashboard</h1>
    <p>Live overview of customer activity, orders, and service queue.</p>
    <div class="hero-meta">
      <span class="hero-chip">Admin Access</span>
      <span class="hero-time" id="adminNow">--</span>
    </div>
  </div>
</section>

<main class="container">

  <section class="stats">
    <div class="stat">
      <h4>CUSTOMERS</h4>
      <div class="value" data-count="<?= $customers ?>"><?= $customers ?></div>
      <p class="stat-note">Registered user accounts</p>
    </div>

    <div class="stat">
      <h4>ONLINE ORDERS</h4>
      <div class="value" data-count="<?= $onlineOrders ?>"><?= $onlineOrders ?></div>
      <p class="stat-note">Web-based and pending jobs</p>
    </div>

    <div class="stat">
      <h4>ACTIVE QUEUE</h4>
      <div class="value" data-count="<?= $activeQueue ?>"><?= $activeQueue ?></div>
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
        <div class="icon">&#x1F465;</div>
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

<footer class="footer">
  <div class="footer-container">
    <div class="footer-left">
      <h3>Contact Us:</h3>

      <div class="contact-item">
        <img src="<?= project_url('/assets/images/FOOTER_FB.png') ?>" alt="Facebook">
        <a href="https://www.facebook.com/" target="_blank">JC Store</a>
      </div>

      <div class="contact-item">
        <img src="<?= project_url('/assets/images/FOOTER_EMAIL.png') ?>" alt="Email">
        <a href="mailto:servitech@gmail.com">servitech@gmail.com</a>
      </div>

      <div class="contact-item">
        <img src="<?= project_url('/assets/images/FOOTER_PHONE.png') ?>" alt="Phone">
        <span>+63 912 393 4321</span>
      </div>
    </div>

    <div class="footer-right">
      <a href="<?= project_url('/index.php') ?>" class="footer-logo-link">
        <img src="<?= project_url('/assets/images/LOGO_SERVITECH.png') ?>" alt="ServiTech Logo" class="footer-servitech-logo">
        <h1>ServiTech: JC Store</h1>
      </a>
    </div>
  </div>

  <p class="footer-bottom">&copy; 2026 ServiTech: JC Store</p>
</footer>

<script src="<?= project_url('/pages/admin/admin_dashboard.js') ?>" defer></script>

<script src="<?= project_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>


