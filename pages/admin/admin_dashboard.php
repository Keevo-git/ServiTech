<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";

// Total customers (role customer)
$customers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();

// Online orders (printing category only)
$onlineOrders = (int)$pdo->query("SELECT COUNT(*) FROM queues WHERE category='printing'")->fetchColumn();

// Active queue (not done/cancelled)
$activeQueue = (int)$pdo->query("SELECT COUNT(*) FROM queues WHERE status IN ('PENDING','ONGOING','FOR PICK-UP')")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech Admin Dashboard</title>
  <link rel="stylesheet" href="/pages/admin/admin.css">
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <span>ServiTech Admin</span>
    </div>
    <div class="actions">
      <a href="/pages/admin/logout.php" class="btn">Logout</a>
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
      <div class="value"><?= $onlineOrders ?></div>
    </div>

    <div class="stat">
      <h4>ACTIVE QUEUE</h4>
      <div class="value"><?= $activeQueue ?></div>
    </div>
  </section>

  <h3 class="section-title">Quick Access</h3>

  <section class="quick-grid">
    <a href="/pages/admin/queue_list/printing.php" class="card-link">
      <article class="card">
        <div class="icon">â³</div>
        <h4>Queue List</h4>
        <p>View and update queues</p>
      </article>
    </a>

    <a href="/pages/admin/order_management/printM.php" class="card-link">
      <article class="card">
        <div class="icon">ðŸ“¦</div>
        <h4>Order Management</h4>
        <p>Manage customer orders</p>
      </article>
    </a>

    <a href="/pages/admin/customer_list/custoL.php" class="card-link">
      <article class="card">
        <div class="icon">ðŸ‘¥</div>
        <h4>Customer List</h4>
        <p>View registered customers</p>
      </article>
    </a>

    <a href="/pages/admin/Services/edit_services.php" class="card-link">
      <article class="card">
        <div class="icon">âœï¸</div>
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
        <img src="/assets/images/FOOTER_FB.png" alt="Facebook">
        <a href="https://www.facebook.com/" target="_blank">JC Store</a>
      </div>

      <div class="contact-item">
        <img src="/assets/images/FOOTER_EMAIL.png" alt="Email">
        <a href="mailto:servitech@gmail.com">servitech@gmail.com</a>
      </div>

      <div class="contact-item">
        <img src="/assets/images/FOOTER_PHONE.png" alt="Phone">
        <span>+63 912 393 4321</span>
      </div>
    </div>

    <div class="footer-right">
      <a href="/index.php" class="footer-logo-link">
        <img src="/assets/images/LOGO_SERVITECH.png" alt="ServiTech Logo" class="footer-servitech-logo">
        <h1>ServiTech: JC Store</h1>
      </a>
    </div>
  </div>

  <p class="footer-bottom">Â© 2026 ServiTech: JC Store</p>
</footer>


</body>
</html>



