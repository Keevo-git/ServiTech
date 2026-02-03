<?php
session_start();

if (!isset($_SESSION["admin_logged_in"])) {
    header("Location: main.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ServiTech Admin Dashboard</title>

  <!-- ADMIN DASHBOARD CSS -->
  <link rel="stylesheet" href="/ServiTech/Admin/admin.css">
</head>
<body>

<!-- TOP BAR -->
<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <span>ServiTech Admin</span>
    </div>
    <div class="actions">
      <a href="logout.php" class="btn">Logout</a>
    </div>
  </div>
</header>

<!-- HERO -->
<section class="hero">
  <div class="hero-inner">
    <h1>Dashboard</h1>
    <p>Overview of system activity</p>
  </div>
</section>

<!-- MAIN CONTENT -->
<main class="container">

  <!-- STATS -->
  <section class="stats">
    <div class="stat">
      <h4>CUSTOMERS</h4>
      <div class="value">32</div>
    </div>

    <div class="stat">
      <h4>ONLINE ORDERS</h4>
      <div class="value">6</div>
    </div>

    <div class="stat">
      <h4>ONGOING QUEUE</h4>
      <div class="value">16</div>
    </div>
  </section>

  <h3 class="section-title">Quick Access</h3>

  <!-- QUICK ACCESS -->
  <section class="quick-grid">

    <a href="#" class="card-link">
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

<!-- FOOTER -->
<footer class="site-footer">
  <div class="footer-inner">
    <div class="contact">ServiTech – JC Store</div>
    <div class="copyright">© 2026</div>
  </div>
</footer>

</body>
</html>
