<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";

// Total customers (minus 1 for admin)
$customers = (int)$pdo->query("SELECT (COUNT(*) - 1) AS c FROM users")->fetchColumn();
if ($customers < 0) $customers = 0;

// Online queues (all queues)
$queues = (int)$pdo->query("SELECT COUNT(*) FROM queues")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ServiTech Admin Dashboard</title>
  <link rel="stylesheet" href="/ServiTech/Admin/admin.css">
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <span>ServiTech Admin</span>
    </div>
    <div class="actions">
      <a href="/ServiTech/Admin/logout.php" class="btn">Logout</a>
    </div>
  </div>
</header>

<section class="hero">
  <div class="hero-inner">
    <h1>Dashboard</h1>
    <p>Overview of system activity</p>
  </div>
</section>

<main class="container">

  <section class="stats">
    <div class="stat">
      <h4>CUSTOMERS</h4>
      <div class="value"><?= $customers ?></div>
    </div>

    <div class="stat">
      <h4>ONLINE ORDERS</h4>
      <div class="value">6</div>
    </div>

    <div class="stat">
      <h4>ONLINE QUEUE</h4>
      <div class="value"><?= $queues ?></div>
    </div>
  </section>

  <h3 class="section-title">Quick Access</h3>

  <section class="quick-grid">
    <a href="/ServiTech/Admin/Queue List/printing.php" class="card-link">
      <article class="card">
        <div class="icon">⏳</div>
        <h4>Queue List</h4>
        <p>View active queues</p>
      </article>
    </a>

    <a href="#" class="card-link">
      <article class="card">
        <div class="icon">📦</div>
        <h4>Order Management</h4>
        <p>Manage customer orders</p>
      </article>
    </a>

    <a href="#" class="card-link">
      <article class="card">
        <div class="icon">👥</div>
        <h4>Customer List</h4>
        <p>View registered customers</p>
      </article>
    </a>
  </section>

</main>

<footer class="site-footer">
  <div class="footer-inner">
    <div class="contact">ServiTech – JC Store</div>
    <div class="copyright">© 2026</div>
  </div>
</footer>

</body>
</html>
