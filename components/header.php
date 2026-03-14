<?php
// Simple shared header for customer pages
?>
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
    aria-controls="customer-header-menu"
  >
    <span class="nav-toggle__bar"></span>
    <span class="nav-toggle__bar"></span>
    <span class="nav-toggle__bar"></span>
  </button>
  <nav id="customer-header-menu" data-collapsible-menu>
    <a href="/pages/customer/customer_dash.php">Dashboard</a>
    <a href="/index.php">Services</a>
    <a href="/auth/logout.php">Logout</a>
  </nav>
</header>
<script src="/assets/js/header-menu.js" defer></script>

