<?php
require_once __DIR__ . "/../inc/admin_auth.php";
require_once __DIR__ . "/../inc/db.php";

$sql = "
SELECT 
  q.id, q.queue_code, q.category, q.service_label, q.status, q.created_at,
  u.fullname
FROM queues q
JOIN users u ON u.id = q.user_id
WHERE q.category = 'repair'
ORDER BY q.created_at DESC
";
$rows = $pdo->query($sql)->fetchAll();

<<<<<<< HEAD
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function status_class($s){
  $s = strtolower(trim($s));
  if ($s === "completed") return "status-complete";
  if ($s === "in progress") return "status-inprogress";
  if ($s === "on hold") return "status-inprogress";
=======
function pill_class($status) {
  $s = strtoupper(trim((string)$status));
  if ($s === "PENDING") return "status-pending";
  if ($s === "ONGOING") return "status-inprogress";
  if ($s === "FOR PICK-UP") return "status-pickup";
  if ($s === "DONE") return "status-complete";
  if ($s === "CANCELLED") return "status-cancelled";
>>>>>>> 8034001ee4043c08d2f502130b7af02091e33768
  return "status-pending";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Queue Management - Repair</title>
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
          <a class="tab" href="walkin.php">Printing (Walk-In)</a>
          <a class="tab active" href="repair.php">Repair</a>
          <a class="tab" href="installation.php">Installation</a>
        </div>

        <table>
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Customer Name</th>
              <th>Service Details</th>
              <th>Status</th>
              <th style="width:220px">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5" style="text-align:center;padding:18px;color:#666;">No repair queues yet.</td></tr>
          <?php else: ?>
            <?php foreach($rows as $r): ?>
              <tr data-id="<?= (int)$r["id"] ?>">
                <td><?= esc($r["queue_code"]) ?></td>
                <td><?= esc($r["fullname"] ?? "Customer") ?></td>
                <td><?= esc($r["service_label"]) ?></td>
                <td>
                  <span class="status-pill <?= esc(status_class($r["status"])) ?>">
                    <?= esc($r["status"]) ?>
                  </span>
                </td>
                <td class="actions">
                  <button class="btn-start" data-action="start">Start</button>
                  <button class="btn-hold" data-action="hold">On Hold</button>
                  <button class="btn-delete" data-action="delete" title="Delete">✖</button>
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
  async function post(url, data){
    const body = new URLSearchParams(data);
    const res = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {"Content-Type":"application/x-www-form-urlencoded"},
      body
    });
    const text = await res.text();
    try { return JSON.parse(text); }
    catch(e){ console.error("Non-JSON:", text); return {ok:false, error:"Server returned non-JSON"}; }
  }

<<<<<<< HEAD
  document.addEventListener("click", async (e) => {
    const btn = e.target.closest("button[data-action]");
    if (!btn) return;

    const tr = btn.closest("tr[data-id]");
    if (!tr) return;

    const id = tr.dataset.id;
    const action = btn.dataset.action;

    if (action === "delete") {
      if (!confirm("Delete this queue?")) return;
      const r = await post("queue_delete.php", {id});
      if (!r.ok) { alert(r.error || "Delete failed"); return; }
      tr.remove();
      return;
    }

    let status = "Pending";
    if (action === "start") status = "In Progress";
    if (action === "hold") status = "On Hold";

    const r = await post("queue_update_status.php", {id, status});
    if (!r.ok) { alert(r.error || "Update failed"); return; }

    const pill = tr.querySelector(".status-pill");
    if (pill) {
      pill.textContent = status;
      pill.classList.remove("status-pending","status-inprogress","status-complete");
      pill.classList.add(status.toLowerCase() === "completed" ? "status-complete"
        : status.toLowerCase() === "pending" ? "status-pending"
        : "status-inprogress");
    }
=======
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
>>>>>>> 8034001ee4043c08d2f502130b7af02091e33768
  });
})();
</script>

</body>
</html>
