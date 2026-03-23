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

$hasQueuesTable = table_exists($pdo, "queues");

// =========================
// 📊 DATA COUNTS (SYNCED)
// =========================

// Customers
$customers = safe_count($pdo, "SELECT COUNT(*) FROM users");

// Online Orders (pending + ongoing + for pick-up)
$onlineOrders = $hasQueuesTable
    ? safe_count($pdo, "
        SELECT COUNT(*) FROM queues 
        WHERE LOWER(TRIM(status)) IN ('pending','ongoing','for pick-up')
    ")
    : 0;

// Active Queue (currently being processed)
$activeQueue = $hasQueuesTable
    ? safe_count($pdo, "
        SELECT COUNT(*) FROM queues 
        WHERE LOWER(TRIM(status)) IN ('pending','ongoing')
    ")
    : 0;

// Progress (% DONE)
$totalQueue = $hasQueuesTable
    ? safe_count($pdo, "SELECT COUNT(*) FROM queues")
    : 0;

$doneQueue = $hasQueuesTable
    ? safe_count($pdo, "
        SELECT COUNT(*) FROM queues 
        WHERE LOWER(TRIM(status)) = 'done'
    ")
    : 0;

$progress = $totalQueue > 0 ? round(($doneQueue / $totalQueue) * 100) : 0;

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

    <div class="actions">
      <a href="<?= project_url('/index.php') ?>" class="btn btn-home">Home</a>
      <a href="<?= project_url('/pages/admin/logout.php') ?>" class="btn">Logout</a>
    </div>
  </div>
</header>

<section class="hero">
  <div class="hero-inner">
    <h1>Operations Dashboard</h1>
    <p>Live overview of customer activity, orders, and service queue.</p>
  </div>
</section>

<main class="container">

  <!-- ===================== -->
  <!-- 📊 STATS -->
  <!-- ===================== -->
  <section class="stats">

    <div class="stat">
      <h4>CUSTOMERS</h4>
      <div class="value"><?= $customers ?></div>
      <p>Registered users</p>
    </div>

    <div class="stat">
      <h4>ONLINE ORDERS</h4>
      <div class="value"><?= $onlineOrders ?></div>
      <p>Pending + ongoing + pickup</p>
    </div>

    <div class="stat">
      <h4>ACTIVE QUEUE</h4>
      <div class="value"><?= $activeQueue ?></div>
      <p>Currently processing</p>
    </div>

    <div class="stat">
      <h4>PROGRESS</h4>
      <div class="value"><?= $progress ?>%</div>
      <p>Completed jobs</p>
    </div>

  </section>

  <!-- ===================== -->
  <!-- 🚀 QUICK ACCESS -->
  <!-- ===================== -->
  <h3>Quick Access</h3>

  <section class="quick-grid">

    <a href="<?= project_url('/pages/admin/queue_list/printing.php') ?>">
      <div class="card">
        <h4>Queue List</h4>
        <p>Manage queues</p>
      </div>
    </a>

    <a href="<?= project_url('/pages/admin/order_management/printM.php') ?>">
      <div class="card">
        <h4>Order Management</h4>
        <p>Manage orders</p>
      </div>
    </a>

    <a href="<?= project_url('/pages/admin/customer_list/custoL.php') ?>">
      <div class="card">
        <h4>Customers</h4>
        <p>View users</p>
      </div>
    </a>

    <a href="<?= project_url('/pages/admin/Services/edit_services.php') ?>">
      <div class="card">
        <h4>Services</h4>
        <p>Edit services</p>
      </div>
    </a>

  </section>

</main>

</body>
</html>