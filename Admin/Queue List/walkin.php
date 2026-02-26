<?php
require_once __DIR__ . "/../inc/admin_auth.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Queue Management - Printing (Walk-In)</title>
  <link rel="stylesheet" href="../../main/style.css">
  <link rel="stylesheet" href="../admin.css">
  <link rel="stylesheet" href="css/queueL.css">
</head>
<body>

<header class="navbar">
  <a href="/ServiTech/Admin/admin_dashboard.php" class="logo">
    <img src="../../main/IMAGES/LOGO_SERVITECH.png" alt="ServiTech Logo" class="servitech-logo">
    <h1>ServiTech</h1>
  </a>
  <nav>
    <a href="/ServiTech/Admin/admin_dashboard.php">Dashboard</a>
    <a href="/ServiTech/Admin/logout.php">Logout</a>
  </nav>
</header>

<main>
  <div class="page-frame">
    <div class="page-inner" style="padding:28px 30px;min-height:600px">
      <div class="page-head">
        <h2 style="color:var(--maroon)">Queue Management</h2>
      </div>

      <div class="panel">
        <div class="tabs" role="tablist">
          <a class="tab" href="printing.php">Printing (Online)</a>
          <a class="tab active" href="walkin.php">Printing (Walk-In)</a>
          <a class="tab" href="repair.php">Repair</a>
          <a class="tab" href="installation.php">Installation</a>
        </div>

        <p style="padding:16px;color:#666;">Walk-in queues page is blank for now.</p>
      </div>
    </div>
  </div>
</main>

<footer class="footer">
  <p class="footer-bottom">© 2026 ServiTech: JC Repair Shop</p>
</footer>

</body>
</html>
