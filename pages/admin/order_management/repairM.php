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

$rows = $pdo->query("
  SELECT q.id, q.queue_code, q.status, q.created_at, u.fullname
  FROM queues q
  JOIN users u ON u.id = q.user_id
  WHERE q.category = 'repair'
    AND (
      q.created_at <= (NOW() - INTERVAL '15 minutes')
      OR UPPER(TRIM(COALESCE(q.status, 'PENDING'))) = 'CANCELLED'
    )
  ORDER BY q.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Management - Repair</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260521responsive') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260521responsive') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/order_management/orderM.css?v=20260524match-printing') ?>">
  <script src="<?= admin_url('/pages/admin/order_management/orderM.js') ?>" defer></script>
</head>
<body class="admin-dashboard">

<header class="navbar has-nav-menu">
  <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>" class="logo">
    <img src="<?= admin_url('/assets/images/LOGO_SERVITECH.png') ?>" alt="ServiTech Logo">
    <h1>ServiTech Admin</h1>
  </a>
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
  <nav id="admin-header-menu" data-collapsible-menu>
    <a href="<?= admin_url('/index.php') ?>">Home</a>
    <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>">Dashboard</a>
    <a href="<?= admin_url('/pages/admin/customer_list/custoL.php') ?>">Customer List</a>
    <a href="<?= admin_url('/pages/admin/logout.php') ?>">Logout</a>
  </nav>
</header>

<div class="admin-wrapper">
  <section class="admin-hero order-header">
    <h1>Order Management</h1>
    <p>Review repair orders and update statuses in real time.</p>
  </section>

<main class="admin-container">
  <div class="page-frame">
    <div class="page-inner">
      <h2>Order Management</h2>
      <p>View and manage all orders across services.</p>

      <div class="card-panel">
        <div class="panel-heading">
          <h3>All Orders <small>Manage and update order statuses</small></h3>
        </div>

        <div class="orders-scroll-wrapper">
          <div class="orders-content">
            <div class="order-tabs">
              <a class="tab" href="<?= admin_url('/pages/admin/order_management/printM.php') ?>">Printing</a>
              <a class="tab active" href="<?= admin_url('/pages/admin/order_management/repairM.php') ?>">Repair</a>
              <a class="tab" href="<?= admin_url('/pages/admin/order_management/installationM.php') ?>">Installation</a>
            </div>

            <div class="table-section">
              <div class="walkin-title">Repair Queue - Manage and update order statuses</div>
              <table class="orders table-content">
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
      </div>
    </div>
  </div>
</main>
</div>

<?php require_once __DIR__ . "/../_includes/admin_footer.php"; ?>

<div class="om-modalOverlay" id="statusModal">
  <div class="om-modalCard" role="dialog" aria-modal="true">
    <div class="modal-header-custom om-modalHead">
      <h2>Update Status</h2>
      <button class="modal-close om-modalX" type="button" id="omClose">&times;</button>
    </div>

    <div class="modal-body om-modalBody">
      <div class="modal-field om-row">
        <label class="om-label">Order ID</label>
        <p class="order-id" id="omQueueCode">-</p>
      </div>

      <div class="modal-field om-row">
        <label class="om-label">Customer</label>
        <p class="modal-value" id="omCustomer">-</p>
      </div>

      <div class="modal-field om-row">
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

      <div class="modal-actions om-actions">
        <button class="btn-delete om-btn om-btn--danger" type="button" id="omDelete">Delete</button>
        <button class="btn-cancel om-btn om-btn--light" type="button" id="omCancel">Cancel</button>
        <button class="btn-save om-btn om-btn--maroon" type="button" id="omSave">Save</button>
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


