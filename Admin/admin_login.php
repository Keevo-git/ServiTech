<?php
// Admin/main.php
session_name("SERVITECH_ADMIN");
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/ServiTech/",
    "domain"   => "",
    "secure"   => false,
    "httponly" => true,
    "samesite" => "Lax"
]);
if (session_status() === PHP_SESSION_NONE) session_start();

// if already logged in
if (!empty($_SESSION["admin_logged_in"])) {
    header("Location: /ServiTech/Admin/admin_dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ServiTech Admin Login</title>
  <link rel="stylesheet" href="../main/style.css">
</head>
<body>

  <header class="navbar">
    <a href="/ServiTech/main/landing.php" class="logo">
      <img src="/ServiTech/main/IMAGES/LOGO_SERVITECH.png" alt="ServiTech Logo" class="servitech-logo">
      <h1>ServiTech</h1>
    </a>
    <nav>
      <a href="/ServiTech/main/landing.php">Services Home</a>
      <a href="/ServiTech/main/log_in.html">Customer Login</a>
    </nav>
  </header>

  <div class="auth-container">
    <h1>Admin Login</h1>

    <form method="POST" action="/ServiTech/Admin/admin.php" autocomplete="on">
      <label>Email</label>
      <input type="email" name="email" required>

      <label>Password</label>
      <input type="password" name="password" required>

      <button type="submit">Login</button>
    </form>
  </div>

  <footer class="footer">
    <p class="footer-bottom">© 2026 ServiTech: JC Repair Shop</p>
  </footer>

</body>
</html>
