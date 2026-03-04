<?php
require_once __DIR__ . "/main/session_check.php"; // use your consistent session setup
$is_logged_in = !empty($_SESSION["user_id"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ServiTech: JC Repair Shop</title>
  <link rel="stylesheet" href="main/style.css">
</head>
<body>

  <!-- NAVBAR -->
  <header class="navbar">
    <a href="index.php" class="logo">
      <img src="main/IMAGES/LOGO_SERVITECH.png" alt="ServiTech Logo" class="servitech-logo">
      <h1>ServiTech</h1>
    </a>
    <nav>
      <?php if ($is_logged_in): ?>
        <a href="main/customer_dash.php">Home</a>
        <a href="main/logout.php">Logout</a>
      <?php else: ?>
        <a href="main/regis.html">Register</a>
        <a href="main/log_in.html">Login</a>
      <?php endif; ?>
    </nav>
  </header>

  <!-- HERO -->
  <section class="hero">
    <h2>Welcome to ServiT: JC Repair Shop</h2>
    <p>Offering printing, repairing, and installation services</p>

    <div class="hero-cards">
      <a href="<?php echo $is_logged_in ? 'main/custo_place_queueing.php' : 'main/log_in.html'; ?>" class="hero-card">
        <img src="main/IMAGES/LANDING_QUEUEING.png" alt="Queueing" class="hero-icon">
        <h4>QUEUEING</h4>
      </a>

      <a href="<?php echo $is_logged_in ? 'main/custo_service_status.php' : 'main/log_in.html'; ?>" class="hero-card">
        <img src="main/IMAGES/LANDING_SERVICE-STAT.png" alt="Service Status" class="hero-icon">
        <h4>SERVICE STATUS</h4>
      </a>

      <a href="<?php echo $is_logged_in ? 'main/custo_print_order.php' : 'main/log_in.html'; ?>" class="hero-card">
        <img src="main/IMAGES/LANDING_PRINT-ORD.png" alt="Print Order" class="hero-icon">
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
        <img src="main/IMAGES/CARD_PRINTING.png" alt="Printing Service">
        <h3>Printing Service</h3>
      </div>

      <div class="service-type-card" onclick="openServiceModal('repair')">
        <img src="main/IMAGES/CARD_REPAIR.png" alt="Device Repair">
        <h3>Device Repair Service</h3>
      </div>

      <div class="service-type-card" onclick="openServiceModal('installation')">
        <img src="main/IMAGES/CARD_INSTALLATION.png" alt="Installation Service">
        <h3>Installation / Software</h3>
      </div>
    </div>

    <br><br><br><br>

    <!-- Your service content stays the same, just fix image paths if needed -->
    <!-- (Keep the rest of your HTML exactly as is, but replace ./IMAGES/... with main/IMAGES/...) -->

  </section>

  <!-- FOOTER -->
  <footer class="footer">
    <div class="footer-container">
      <div class="footer-left">
        <h3>Contact Us:</h3>

        <div class="contact-item">
          <img src="main/IMAGES/FOOTER_FB.png" alt="Facebook">
          <a href="https://www.facebook.com/" target="_blank">JC Repair Shop</a>
        </div>

        <div class="contact-item">
          <img src="main/IMAGES/FOOTER_EMAIL.png" alt="Email">
          <a href="mailto:servitech@gmail.com">servitech@gmail.com</a>
        </div>

        <div class="contact-item">
          <img src="main/IMAGES/FOOTER_PHONE.png" alt="Phone">
          <span>+63 912 393 4321</span>
        </div>
      </div>

      <div class="footer-right">
        <a href="index.php" class="footer-logo-link">
          <img src="main/IMAGES/LOGO_SERVITECH.png" alt="ServiTech Logo" class="footer-servitech-logo">
          <h1>ServiTech: JC Repair Shop</h1>
        </a>
      </div>
    </div>

    <p class="footer-bottom">© 2026 ServiTech: JC Repair Shop</p>
  </footer>

  <script src="main/main.js"></script>
</body>
</html>