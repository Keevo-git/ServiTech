<?php
require_once __DIR__ . "/config/session_check.php"; // use your consistent session setup
$is_logged_in = servitech_is_logged_in();
$is_admin = servitech_is_admin();
$queue_url = $is_admin
  ? "/pages/admin/queue_list/printing.php"
  : ($is_logged_in ? "/pages/customer/custo_place_queueing.php" : "/auth/log_in.html");
$status_url = $is_admin
  ? "/pages/admin/order_management/printM.php"
  : ($is_logged_in ? "/pages/customer/custo_service_status.php" : "/auth/log_in.html");
$print_url = $is_admin
  ? "/pages/admin/order_management/printM.php"
  : ($is_logged_in ? "/pages/customer/custo2_docu_printing.php" : "/auth/log_in.html");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: JC Repair Shop</title>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h2">
</head>
<body>

  <!-- NAVBAR -->
  <header class="navbar has-nav-menu">
    <a href="/index.php" class="logo">
      <img src="/assets/images/LOGO_SERVITECH.png" alt="ServiTech Logo" class="servitech-logo">
      <h1>ServiTech</h1>
    </a>
    <button
      class="nav-toggle"
      type="button"
      aria-label="Toggle navigation menu"
      aria-expanded="false"
      aria-controls="landing-header-menu"
    >
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
    </button>
    <nav id="landing-header-menu" data-collapsible-menu>
      <?php if ($is_logged_in): ?>
        <?php if ($is_admin): ?>
          <a href="/pages/admin/admin_dashboard.php">Admin Dashboard</a>
          <a href="/pages/admin/logout.php">Logout</a>
        <?php else: ?>
          <a href="/pages/customer/customer_dash.php">Home</a>
          <a href="/auth/logout.php">Logout</a>
        <?php endif; ?>
      <?php else: ?>
        <a href="/auth/regis.html">Register</a>
        <a href="/auth/log_in.html">Login</a>
      <?php endif; ?>
    </nav>
  </header>

  <!-- HERO -->
  <section class="hero">
    <h2>Welcome to ServiTech: JC Repair Shop</h2>
    <p>Offering printing, repairing, and installation services</p>

    <div class="hero-cards">
      <a href="<?php echo $queue_url; ?>" class="hero-card">
        <img src="/assets/images/LANDING_QUEUEING.png" alt="Queueing" class="hero-icon">
        <h4>QUEUEING</h4>
      </a>

      <a href="<?php echo $status_url; ?>" class="hero-card">
        <img src="/assets/images/LANDING_SERVICE-STAT.png" alt="Service Status" class="hero-icon">
        <h4>SERVICE STATUS</h4>
      </a>

      <a href="<?php echo $print_url; ?>" class="hero-card">
        <img src="/assets/images/LANDING_PRINT-ORD.png" alt="Print Order" class="hero-icon">
        <h4>PRINT ORDER</h4>
      </a>
    </div>
  </section>

  <!-- SERVICES -->
  <section class="services">
    <h2>JC Repair Shop's Services</h2>
    <p>(Note: these are only the common requested services, you may contact us for inquiry of other services)</p>

    <div class="service-type-cards">
      <div class="service-type-card" onclick="openServiceModal('printing')">
        <img src="/assets/images/CARD_PRINTING.png" alt="Printing Service">
        <h3>Printing Service</h3>
      </div>

      <div class="service-type-card" onclick="openServiceModal('repair')">
        <img src="/assets/images/CARD_REPAIR.png" alt="Device Repair">
        <h3>Device Repair Service</h3>
      </div>

      <div class="service-type-card" onclick="openServiceModal('installation')">
        <img src="/assets/images/CARD_INSTALLATION.png" alt="Installation Service">
        <h3>Installation / Software</h3>
      </div>
    </div>

    <br><br><br><br>

    <!-- Service cards -->

  </section>

  <?php include __DIR__ . "/components/footer.php"; ?>

  <script src="/assets/js/csrf.js"></script>
  <script src="/assets/js/main.js"></script>
  <script src="/assets/js/header-menu.js" defer></script>
</body>
</html>



