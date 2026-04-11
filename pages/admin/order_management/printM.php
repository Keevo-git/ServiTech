<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../_includes/url.php";

function status_class(string $s): string
{
    $s = strtoupper(trim($s));
    return match ($s) {
        "PENDING" => "status-pending",
        "ONGOING", "FOR PICK-UP" => "status-inprogress",
        "DONE" => "status-complete",
        "CANCELLED" => "status-onhold",
        default => "status-pending",
    };
}

function status_label(string $s): string
{
    $s = strtoupper(trim($s));
    return match ($s) {
        "FOR PICK-UP" => "For Pick-up",
        default => ucfirst(strtolower($s)),
    };
}

$walkinStmt = $pdo->prepare("
  SELECT q.id, q.queue_code, q.status, q.created_at, u.fullname
  FROM queues q
  JOIN users u ON u.id = q.user_id
  WHERE (
      q.created_at <= (NOW() - INTERVAL '24 hours')
      OR UPPER(TRIM(COALESCE(q.status, 'PENDING'))) = 'CANCELLED'
    )
    AND (
      LOWER(TRIM(COALESCE(q.category, ''))) IN ('walkin', 'printing_walkin')
      OR (
        LOWER(TRIM(COALESCE(q.category, ''))) = 'printing'
        AND COALESCE(NULLIF(LOWER(TRIM(COALESCE(q.details->>'order_type', ''))), ''), 'walkin') = 'walkin'
        AND UPPER(TRIM(COALESCE(q.queue_code, ''))) NOT LIKE 'OP%'
      )
    )
  ORDER BY q.created_at DESC
");
$walkinStmt->execute();
$walkin = $walkinStmt->fetchAll();

$onlineStmt = $pdo->prepare("
  SELECT q.id, q.queue_code, q.status, q.created_at, u.fullname
  FROM queues q
  JOIN users u ON u.id = q.user_id
  WHERE (
      q.created_at <= (NOW() - INTERVAL '24 hours')
      OR UPPER(TRIM(COALESCE(q.status, 'PENDING'))) = 'CANCELLED'
    )
    AND (
      LOWER(TRIM(COALESCE(q.category, ''))) IN ('online_printorder', 'printing_online')
      OR (
        LOWER(TRIM(COALESCE(q.category, ''))) = 'printing'
        AND LOWER(TRIM(COALESCE(q.details->>'order_type', ''))) = 'online'
      )
      OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
    )
  ORDER BY q.created_at DESC
");
$onlineStmt->execute();
$online = $onlineStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Management - Printing</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260315h2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260315h2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/order_management/orderM.css') ?>">
  <script src="<?= admin_url('/pages/admin/order_management/orderM.js') ?>" defer></script>
</head>
<body class="admin-dashboard">

<header class="topbar has-nav-menu">
  <div class="topbar-inner">
    <div class="brand">
      <p class="brand-tag">Operations</p>
      <span>ServiTech Admin</span>
    </div>
    <button
      class="nav-toggle"
      type="button"
      aria-label="Toggle navigation menu"
      aria-expanded="false"
      aria-controls="admin-header-menu"
    >
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
      <span class="nav-toggle__bar"></span>
    </button>
    <div class="actions" id="admin-header-menu" data-collapsible-menu>
      <a href="<?= admin_url('/index.php') ?>" class="btn btn-home">Home</a>
      <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>" class="btn">Dashboard</a>
      <a href="<?= admin_url('/pages/admin/customer_list/custoL.php') ?>" class="btn">Customer List</a>
      <a href="<?= admin_url('/pages/admin/logout.php') ?>" class="btn">Logout</a>
    </div>
  </div>
</header>

<section class="hero">
  <div class="hero-inner">
    <h1>Order Management</h1>
    <p>Review printing orders and update statuses in real time.</p>
  </div>
</section>

<main class="container">
  <div class="page-frame">
    <div class="page-inner">
      <h2>Order Management</h2>
      <p>View and manage all orders across services.</p>

      <div class="card-panel">
        <div class="panel-heading">
          <h3>All Orders <small>Manage and update order statuses</small></h3>
        </div>

        <div class="tab-container">
          <div class="tab-list">
            <a class="tab active" href="<?= admin_url('/pages/admin/order_management/printM.php') ?>">Printing</a>
            <a class="tab" href="<?= admin_url('/pages/admin/order_management/repairM.php') ?>">Repair</a>
            <a class="tab" href="<?= admin_url('/pages/admin/order_management/installationM.php') ?>">Installation</a>
          </div>
        </div>

        <div class="walkin-title">Walk-in Queue - Manage and update order statuses</div>
        <table class="orders">
          <thead>
            <tr><th>Queue ID</th><th>Customer Name</th><th>Status</th><th>Date</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php if (!$walkin): ?>
              <tr><td colspan="5" style="color:#777;padding:14px;">No walk-in queues older than 24 hours yet.</td></tr>
            <?php else: ?>
              <?php foreach ($walkin as $r): ?>
                <?php $cls = status_class($r["status"]); ?>
                <tr>
                  <td><?= htmlspecialchars($r["queue_code"]) ?></td>
                  <td><?= htmlspecialchars($r["fullname"]) ?></td>
                  <td><span class="status-pill <?= $cls ?>"><?= htmlspecialchars(status_label($r["status"])) ?></span></td>
                  <td><?= htmlspecialchars(date("m/d/Y", strtotime($r["created_at"]))) ?></td>
                  <td>
                    <button
                      class="update-btn"
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

        <div class="section-title-small" style="margin-top:18px;">Online Orders - Pre-ordered printing requests</div>
        <table class="orders">
          <thead>
            <tr><th>Order ID</th><th>Customer Name</th><th>Status</th><th>Date</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php if (!$online): ?>
              <tr><td colspan="5" style="color:#777;padding:14px;">No online printing orders older than 24 hours yet.</td></tr>
            <?php else: ?>
              <?php foreach ($online as $r): ?>
                <?php $cls = status_class($r["status"]); ?>
                <tr>
                  <td><?= htmlspecialchars($r["queue_code"]) ?></td>
                  <td><?= htmlspecialchars($r["fullname"]) ?></td>
                  <td><span class="status-pill <?= $cls ?>"><?= htmlspecialchars(status_label($r["status"])) ?></span></td>
                  <td><?= htmlspecialchars(date("m/d/Y", strtotime($r["created_at"]))) ?></td>
                  <td>
                    <button
                      class="update-btn"
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

<?php require_once __DIR__ . "/../_includes/admin_footer.php"; ?>

<div class="om-modalOverlay" id="statusModal">
  <div class="om-modalCard" role="dialog" aria-modal="true">
    <button class="om-modalX" type="button" id="omClose">&times;</button>

    <div class="om-modalHead">
      <h3>Update Status</h3>
      <span class="om-pill" id="omQueueCode">-</span>
    </div>

    <div class="om-modalBody">
      <div class="om-row">
        <span class="om-label">Customer</span>
        <div class="om-value" id="omCustomer">-</div>
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

      <div class="om-error" id="omError"></div>

      <div class="om-actions">
        <button class="om-btn om-btn--danger" type="button" id="omDelete">Delete</button>
        <button class="om-btn om-btn--light" type="button" id="omCancel">Cancel</button>
        <button class="om-btn om-btn--maroon" type="button" id="omSave">Save</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<script>
  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");
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

  async function postAction(id, action) {
    const fd = new FormData();
    fd.append("id", id);
    fd.append("action", action);

    const res = await fetch(<?= json_encode(admin_url_raw("/pages/admin/_includes/admin_actions.php")) ?>, {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { "X-CSRF-Token": csrf() }
    });

    const txt = await res.text();
    try { return JSON.parse(txt); }
    catch (e) { return { ok: false, error: "Server returned non-JSON: " + txt }; }
  }

  const actionMap = {
    "PENDING": "pending",
    "ONGOING": "ongoing",
    "FOR PICK-UP": "pickup",
    "DONE": "done",
    "CANCELLED": "cancel"
  };

  document.querySelectorAll(".update-btn").forEach((btn) => {
    btn.addEventListener("click", () => {
      currentId = btn.dataset.id;
      omQueueCode.textContent = btn.dataset.code || "-";
      omCustomer.textContent = btn.dataset.customer || "-";

      const curr = (btn.dataset.status || "PENDING").trim().toUpperCase();
      const exists = Array.from(omStatus.options).some((o) => o.value === curr);
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

<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>



