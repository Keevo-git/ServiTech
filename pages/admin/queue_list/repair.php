<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";

function esc($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function status_class($status): string {
  $s = strtoupper(trim((string)$status));
  if ($s === "ONGOING") return "status-inprogress";
  if ($s === "FOR PICK-UP") return "status-pickup";
  if ($s === "DONE") return "status-complete";
  if ($s === "CANCELLED") return "status-cancelled";
  return "status-pending";
}

$stmt = $pdo->prepare("
  SELECT q.id, q.queue_code, q.status, q.created_at, u.fullname
  FROM queues q
  JOIN users u ON u.id = q.user_id
  WHERE q.category = 'repair'
  ORDER BY q.created_at ASC
");
$stmt->execute();
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Queue Management - Repair</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/pages/admin/admin.css">
  <link rel="stylesheet" href="/pages/admin/queue_list/css/queueL.css">
</head>
<body>

<header class="navbar">
  <a href="/pages/admin/admin_dashboard.php" class="logo">
    <img src="/assets/images/LOGO_SERVITECH.png" alt="ServiTech Logo" class="servitech-logo">
    <h1>ServiTech</h1>
  </a>
  <nav>
    <a href="/pages/admin/admin_dashboard.php">Dashboard</a>
    <a href="/pages/admin/logout.php">Logout</a>
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
          <a class="tab" href="/pages/admin/queue_list/printing.php">Printing (Online)</a>
          <a class="tab" href="/pages/admin/queue_list/walkin.php">Printing (Walk-In)</a>
          <a class="tab active" href="/pages/admin/queue_list/repair.php">Repair</a>
          <a class="tab" href="/pages/admin/queue_list/installation.php">Installation</a>
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
              <td colspan="5" style="text-align:center;padding:18px;color:#666;">No repair queues yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= esc($r["queue_code"]) ?></td>
                <td><?= esc($r["fullname"]) ?></td>
                <td>Repair Service</td>
                <td>
                  <span class="status-pill <?= esc(status_class($r["status"])) ?>">
                    <?= esc($r["status"]) ?>
                  </span>
                </td>
                <td class="actions">
                  <button class="btn-start" data-id="<?= (int)$r["id"] ?>">Start</button>
                  <button class="btn-pickup" data-id="<?= (int)$r["id"] ?>">For Pick-up</button>
                  <button class="btn-done" data-id="<?= (int)$r["id"] ?>">Done</button>
                  <button class="btn-cancel" data-id="<?= (int)$r["id"] ?>">Cancel</button>
                  <button class="btn-delete" data-id="<?= (int)$r["id"] ?>" title="Delete">x</button>
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
  <p class="footer-bottom">&copy; 2026 ServiTech: JC Repair Shop</p>
</footer>

<script src="/assets/js/csrf.js"></script>
<script>
(function(){
  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");
  function sendAction(id, action){
    return fetch("/pages/admin/_includes/admin_actions.php", {
      method: "POST",
      headers: {"Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": csrf()},
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

  document.querySelectorAll(".btn-start").forEach(btn => btn.addEventListener("click", () => doAction(btn, "ongoing")));
  document.querySelectorAll(".btn-pickup").forEach(btn => btn.addEventListener("click", () => doAction(btn, "pickup")));
  document.querySelectorAll(".btn-done").forEach(btn => btn.addEventListener("click", () => doAction(btn, "done")));
  document.querySelectorAll(".btn-cancel").forEach(btn => btn.addEventListener("click", () => doAction(btn, "cancel", "Cancel this queue?")));
  document.querySelectorAll(".btn-delete").forEach(btn => btn.addEventListener("click", () => doAction(btn, "delete", "Delete this queue permanently?")));
})();
</script>

</body>
</html>




