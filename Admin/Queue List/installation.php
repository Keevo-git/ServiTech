<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";

$stmt = $pdo->prepare("
  SELECT q.id, q.queue_code, q.service_label, q.status, u.fullname
  FROM queues q
  JOIN users u ON u.id = q.user_id
  WHERE q.category = 'installation'
  ORDER BY q.created_at ASC
");
$stmt->execute();
$rows = $stmt->fetchAll();

function pill_class($status) {
  $s = strtoupper(trim((string)$status));
  if ($s === "PENDING") return "status-pending";
  if ($s === "ONGOING") return "status-inprogress";
  if ($s === "FOR PICK-UP") return "status-pickup";
  if ($s === "DONE") return "status-complete";
  if ($s === "CANCELLED") return "status-cancelled";
  return "status-pending";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Queue Management - Installation</title>

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
          <a class="tab" href="walkin.php">Printing (Walk-In)</a>
          <a class="tab" href="repair.php">Repair</a>
          <a class="tab active" href="installation.php">Installation</a>
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
              <td colspan="5" style="text-align:center;padding:18px;color:#555;">No installation queues yet.</td>
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

  async function doAction(btn, action, confirmMsg){
    const id = btn.dataset.id;
    if (confirmMsg && !confirm(confirmMsg)) return;
    const data = await sendAction(id, action);
    if (data.ok) location.reload();
    else alert(data.error || "Action failed");
  }

  document.querySelectorAll(".btn-start").forEach(btn => {
    btn.addEventListener("click", () => doAction(btn, "start"));
  });
  document.querySelectorAll(".btn-pickup").forEach(btn => {
    btn.addEventListener("click", () => doAction(btn, "pickup"));
  });
  document.querySelectorAll(".btn-done").forEach(btn => {
    btn.addEventListener("click", () => doAction(btn, "done"));
  });
  document.querySelectorAll(".btn-cancel").forEach(btn => {
    btn.addEventListener("click", () => doAction(btn, "cancel", "Cancel this queue?"));
  });
  document.querySelectorAll(".btn-delete").forEach(btn => {
    btn.addEventListener("click", () => doAction(btn, "delete", "Delete this queue permanently?"));
  });
})();
</script>

</body>
</html>
