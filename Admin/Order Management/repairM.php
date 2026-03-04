<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";

function status_class(string $s): string {
  $s = strtoupper(trim($s));
  return match ($s) {
    "PENDING" => "status-pending",
    "ONGOING" => "status-inprogress",
    "DONE" => "status-complete",
    "FOR PICK-UP" => "status-inprogress",
    "CANCELLED" => "status-onhold",
    default => "status-pending"
  };
}
function status_label(string $s): string {
  $s = strtoupper(trim($s));
  return match ($s) {
    "FOR PICK-UP" => "For Pick-up",
    default => ucfirst(strtolower($s))
  };
}

$rows = $pdo->query("
  SELECT q.id, q.queue_code, q.status, q.created_at, u.fullname
  FROM queues q
  JOIN users u ON u.id = q.user_id
  WHERE q.category = 'repair'
  ORDER BY q.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Management — Repair</title>
  <link rel="stylesheet" href="../../main/style.css">
  <link rel="stylesheet" href="../admin.css">
  <link rel="stylesheet" href="orderM.css">
  <script src="orderM.js" defer></script>
</head>
<body>

<header class="navbar">
  <a href="../admin_dashboard.php" class="logo">
    <img src="../../main/IMAGES/LOGO_SERVITECH.png" alt="ServiTech Logo" class="servitech-logo">
    <h1>ServiTech</h1>
  </a>
  <nav>
    <a href="../Customer%20List/custoL.php">Customer List</a>
    <a href="../logout.php">Logout</a>
  </nav>
</header>

<main>
  <div class="page-frame">
    <div class="page-inner">
      <h2 style="color:var(--maroon)">Order Management</h2>
      <p>View and manage all orders across services</p>

      <div class="card-panel">
        <div class="panel-heading">
          <h3>All Orders <small style="color:#666;font-weight:600">Manage and update order statuses</small></h3>
        </div>

        <div class="tab-container">
          <div class="tab-list">
            <a class="tab" href="printM.php">Printing</a>
            <a class="tab active" href="repairM.php">Repair</a>
            <a class="tab" href="installationM.php">Installation</a>
          </div>
        </div>

        <div class="walkin-title">Repair Queue — Manage and update order statuses</div>
        <table class="orders">
          <thead>
            <tr><th>Queue ID</th><th>Customer Name</th><th>Status</th><th>Date</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="5" style="color:#777;padding:14px;">No repair queues yet.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php $cls = status_class($r["status"]); ?>
                <tr>
                  <td><?= htmlspecialchars($r["queue_code"]) ?></td>
                  <td><?= htmlspecialchars($r["fullname"]) ?></td>
                  <td><span class="status-pill <?= $cls ?>"><?= htmlspecialchars(status_label($r["status"])) ?></span></td>
                  <td><?= htmlspecialchars(date("m/d/Y", strtotime($r["created_at"]))) ?></td>
                  <td>
                    <button class="update-btn"
                            data-id="<?= (int)$r["id"] ?>"
                            data-code="<?= htmlspecialchars($r["queue_code"]) ?>"
                            data-status="<?= htmlspecialchars($r["status"]) ?>"
                            data-customer="<?= htmlspecialchars($r["fullname"]) ?>"
                    >Update Status</button>
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

<!-- ✅ Modal starts hidden even if CSS fails -->
<div class="om-modalOverlay" id="statusModal"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); align-items:center; justify-content:center; z-index:9999;">
  <div class="om-modalCard" role="dialog" aria-modal="true"
       style="width:min(520px,92vw); background:#fff; border-radius:18px; padding:18px 18px 16px; box-shadow:0 26px 70px rgba(0,0,0,.28); position:relative;">

    <button class="om-modalX" type="button" id="omClose">×</button>

    <div class="om-modalHead">
      <h3>Update Status</h3>
      <span class="om-pill" id="omQueueCode">—</span>
    </div>

    <div class="om-modalBody">
      <div class="om-row">
        <span class="om-label">Customer</span>
        <div id="omCustomer" style="font-weight:700;color:#111;">—</div>
      </div>

      <div class="om-row">
        <label class="om-label" for="omStatus">Select Status</label>
        <select class="om-select" id="omStatus">
          <option value="PENDING">Pending</option>
          <option value="ONGOING">Ongoing</option>
          <option value="FOR PICK-UP">For Pick-up</option>
          <option value="DONE">Done</option>
          <option value="CANCELLED">Cancelled</option>
        </select>
      </div>

      <div id="omError" style="display:none;background:#ffe1e1;border:1px solid #ffb6b6;color:#8b0000;padding:10px;border-radius:10px;font-weight:600;"></div>

      <div class="om-actions">
        <button class="om-btn om-btn--danger" type="button" id="omDelete">Delete</button>
        <button class="om-btn om-btn--light" type="button" id="omCancel">Cancel</button>
        <button class="om-btn om-btn--maroon" type="button" id="omSave">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
  const modal = document.getElementById("statusModal");
  const omQueueCode = document.getElementById("omQueueCode");
  const omCustomer = document.getElementById("omCustomer");
  const omStatus = document.getElementById("omStatus");
  const omError = document.getElementById("omError");

  const omErrorShow = (msg) => {
    omError.textContent = msg;
    omError.style.display = "block";
  };
  const omErrorHide = () => {
    omError.textContent = "";
    omError.style.display = "none";
  };

  const omClose = () => {
    modal.style.display = "none";
    omErrorHide();
  };

  let currentId = null;

  async function postAction(id, action){
    const fd = new FormData();
    fd.append("id", id);
    fd.append("action", action);

    const res = await fetch("../_includes/admin_actions.php", {
      method: "POST",
      body: fd,
      credentials: "same-origin"
    });

    const txt = await res.text();
    try { return JSON.parse(txt); }
    catch(e){ return {ok:false, error:"Server returned non-JSON: " + txt}; }
  }

  const actionMap = {
    "PENDING": "pending",
    "ONGOING": "ongoing",
    "FOR PICK-UP": "pickup",
    "DONE": "done",
    "CANCELLED": "cancel"
  };

  document.querySelectorAll(".update-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      currentId = btn.dataset.id;
      omQueueCode.textContent = btn.dataset.code || "—";
      omCustomer.textContent = btn.dataset.customer || "—";

      const curr = (btn.dataset.status || "PENDING").trim().toUpperCase();
      const exists = Array.from(omStatus.options).some(o => o.value === curr);
      omStatus.value = exists ? curr : "PENDING";

      omErrorHide();
      modal.style.display = "flex";
    });
  });

  document.getElementById("omClose")?.addEventListener("click", omClose);
  document.getElementById("omCancel")?.addEventListener("click", omClose);
  modal?.addEventListener("click", (e) => { if (e.target === modal) omClose(); });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && modal.style.display === "flex") omClose();
  });

  document.getElementById("omSave")?.addEventListener("click", async () => {
    if (!currentId) return;

    const selected = omStatus.value;
    const action = actionMap[selected];
    if (!action) return omErrorShow("Invalid status selected.");

    const out = await postAction(currentId, action);
    if (!out.ok) return omErrorShow(out.error || "Failed to update status.");

    location.reload();
  });

  document.getElementById("omDelete")?.addEventListener("click", async () => {
    if (!currentId) return;
    if (!confirm("Delete this queue/order?")) return;

    const out = await postAction(currentId, "delete");
    if (!out.ok) return omErrorShow(out.error || "Failed to delete.");

    location.reload();
  });
</script>

</body>
</html>
