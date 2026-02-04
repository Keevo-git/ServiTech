<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";

$stmt = $pdo->prepare("
  SELECT q.id, q.queue_code, q.service_label, q.status, u.fullname
  FROM queues q
  JOIN users u ON u.id = q.user_id
  WHERE q.category = 'printing'
  ORDER BY q.created_at ASC
");
$stmt->execute();
$rows = $stmt->fetchAll();

function pill_class($status) {
  $s = strtolower(trim((string)$status));
  if ($s === "pending") return "status-pending";
  if ($s === "in progress") return "status-inprogress";
  if ($s === "on hold") return "status-inprogress";
  if ($s === "completed") return "status-complete";
  return "status-pending";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Queue Management - Printing</title>

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
          <a class="tab active" href="printing.php">Printing (Online)</a>
          <a class="tab" href="walkin.php">Printing (Walk-In)</a>
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
          <?php if (!$rows): ?>
            <tr>
              <td colspan="5" style="text-align:center;padding:18px;color:#555;">No printing queues yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r["queue_code"]) ?></td>
                <td><?= htmlspecialchars($r["fullname"]) ?></td>
                <td><?= htmlspecialchars($r["service_label"]) ?></td>
                <td>
                  <span class="status-pill <?= pill_class($r["status"]) ?>">
                    <?= htmlspecialchars($r["status"]) ?>
                  </span>
                </td>
                <td class="actions">
                  <button class="btn-start" data-id="<?= (int)$r["id"] ?>">Start</button>
                  <button class="btn-hold" data-id="<?= (int)$r["id"] ?>">On Hold</button>
                  <button class="btn-delete" data-id="<?= (int)$r["id"] ?>" title="Delete">✖</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>

      </div>
    </div>
  </div>
</main>

<footer class="footer">
  <div class="footer-container">
    <div class="footer-left">
      <h3>Contact Us:</h3>
      <div class="contact-item">
        <img src="../../main/IMAGES/FOOTER_FB.png" alt="Facebook">
        <a href="https://www.facebook.com/" target="_blank">JC Repair Shop</a>
      </div>
      <div class="contact-item">
        <img src="../../main/IMAGES/FOOTER_EMAIL.png" alt="Email">
        <a href="mailto:servitech@gmail.com">servitech@gmail.com</a>
      </div>
      <div class="contact-item">
        <img src="../../main/IMAGES/FOOTER_PHONE.png" alt="Phone">
        <span>+63 912 393 4321</span>
      </div>
    </div>
    <div class="footer-right">
      <a href="/ServiTech/Admin/admin_dashboard.php" class="footer-logo-link">
        <img src="../../main/IMAGES/LOGO_SERVITECH.png" alt="ServiTech Logo" class="footer-servitech-logo">
        <h1>ServiTech: JC Repair Shop</h1>
      </a>
    </div>
  </div>
  <p class="footer-bottom">© 2026 ServiTech: JC Repair Shop</p>
</footer>

<script>
(function(){
  function sendAction(id, action){
    return fetch("../_includes/admin_actions.php", {
      method: "POST",
      headers: {"Content-Type": "application/x-www-form-urlencoded"},
      body: "id=" + encodeURIComponent(id) + "&action=" + encodeURIComponent(action)
    }).then(r => r.json());
  }

  document.querySelectorAll(".btn-start").forEach(btn => {
    btn.addEventListener("click", async () => {
      const id = btn.dataset.id;
      const data = await sendAction(id, "start");
      if (data.ok) location.reload();
      else alert(data.error || "Action failed");
    });
  });

  document.querySelectorAll(".btn-hold").forEach(btn => {
    btn.addEventListener("click", async () => {
      const id = btn.dataset.id;
      const data = await sendAction(id, "hold");
      if (data.ok) location.reload();
      else alert(data.error || "Action failed");
    });
  });

  document.querySelectorAll(".btn-delete").forEach(btn => {
    btn.addEventListener("click", async () => {
      if (!confirm("Delete this queue?")) return;
      const id = btn.dataset.id;
      const data = await sendAction(id, "delete");
      if (data.ok) location.reload();
      else alert(data.error || "Delete failed");
    });
  });
})();
</script>

</body>
</html>
