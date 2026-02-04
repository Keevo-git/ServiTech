<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Queue Management - Walk-In</title>

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
        <a class="btn small" href="/ServiTech/Admin/admin_dashboard.php"
           style="background:var(--maroon);color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px">
          Back to Dashboard
        </a>
      </div>

      <div class="panel">
        <div class="tabs" role="tablist">
          <a class="tab" href="printing.php">Printing (Online)</a>
          <a class="tab active" href="walkin.php">Printing (Walk-In)</a>
          <a class="tab" href="repair.php">Repair</a>
          <a class="tab" href="installation.php">Installation</a>
        </div>

        <table>
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Customer Name</th>
              <th>Service Details</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
            <tr>
              <td colspan="5" style="text-align:center;padding:18px;color:#555;">
                Walk-in queue is empty for now.
              </td>
            </tr>
          </tbody>
        </table>

      </div>
    </div>
  </div>
</main>

<footer class="footer">
  <p class="footer-bottom">© 2026 ServiTech: JC Repair Shop</p>
</footer>

</body>
</html>
