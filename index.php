<?php
require_once __DIR__ . "/config/session_check.php"; // use your consistent session setup
$is_logged_in = !empty($_SESSION["user_id"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: JC Repair Shop</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

  <!-- NAVBAR -->
  <header class="navbar">
    <a href="/index.php" class="logo">
      <img src="/assets/images/LOGO_SERVITECH.png" alt="ServiTech Logo" class="servitech-logo">
      <h1>ServiTech</h1>
    </a>
    <nav>
      <?php if ($is_logged_in): ?>
        <a href="/pages/customer/customer_dash.php">Home</a>
        <a href="/auth/logout.php">Logout</a>
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
      <a href="<?php echo $is_logged_in ? '/pages/customer/custo_place_queueing.php' : '/auth/log_in.html'; ?>" class="hero-card">
        <img src="/assets/images/LANDING_QUEUEING.png" alt="Queueing" class="hero-icon">
        <h4>QUEUEING</h4>
      </a>

      <a href="<?php echo $is_logged_in ? '/pages/customer/custo_service_status.php' : '/auth/log_in.html'; ?>" class="hero-card">
        <img src="/assets/images/LANDING_SERVICE-STAT.png" alt="Service Status" class="hero-icon">
        <h4>SERVICE STATUS</h4>
      </a>

      <a href="<?php echo $is_logged_in ? '/pages/customer/custo_print_order.php' : '/auth/log_in.html'; ?>" class="hero-card">
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

  <!-- FOOTER -->
  <footer class="footer">
    <div class="footer-container">
      <div class="footer-left">
        <h3>Contact Us:</h3>

        <div class="contact-item">
          <img src="/assets/images/FOOTER_FB.png" alt="Facebook">
          <a href="https://www.facebook.com/" target="_blank">JC Repair Shop</a>
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
          <h1>ServiTech: JC Repair Shop</h1>
        </a>
      </div>
    </div>

    <p class="footer-bottom">&copy; 2026 ServiTech: JC Repair Shop</p>
  </footer>

  <script src="/assets/js/csrf.js"></script>
<script src="/assets/js/main.js"></script>
</body>
</html>



