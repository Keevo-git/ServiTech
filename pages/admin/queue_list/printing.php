<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../_includes/url.php";
require_once __DIR__ . "/../_includes/queue_files.php";
require_once __DIR__ . "/_queue_ui_helpers.php";

function esc($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function status_class($status): string {
  $key = strtolower(trim((string)$status));
  $key = preg_replace('/[\s_]+/', '-', $key);
  return match ($key) {
    "ongoing" => "status-ongoing",
    "for-pick-up", "for-pickup" => "status-pickup",
    "done" => "status-done",
    "cancelled", "canceled" => "status-cancelled",
    default => "status-pending",
  };
}

function service_label($category, $details = null): string {
  if (is_string($details) && $details !== "") {
    $decoded = json_decode($details, true);
    if (is_array($decoded) && trim((string)($decoded["service_label"] ?? "")) !== "") {
      return trim((string)$decoded["service_label"]);
    }
  } elseif (is_array($details) && trim((string)($details["service_label"] ?? "")) !== "") {
    return trim((string)$details["service_label"]);
  }

  $map = [
    "printing" => "Document Printing",
    "online_printorder" => "Online Print Order",
    "xerox" => "Xerox",
    "rush-id" => "Rush ID",
    "laminating" => "Laminating",
  ];
  $key = strtolower(trim((string)$category));
  return $map[$key] ?? ucfirst($key);
}

function payment_label($value): string {
  $key = strtolower(trim((string)$value));
  if ($key === "gcash") return "GCash";
  if ($key === "cash") return "Cash";
  return "-";
}

function payment_status_label($method, $paymentStatus = null, $detailsStatus = null): string {
  $method = strtolower(trim((string)$method));
  $status = strtoupper(trim((string)($paymentStatus ?? $detailsStatus ?? "")));

  if ($method === "gcash") {
    if (in_array($status, ["PENDING", "SUBMITTED", "PENDING VERIFICATION"], true)) {
      return "Payment Submitted";
    }
    if (in_array($status, ["VERIFIED", "PAID", "COMPLETE"], true)) {
      return "Verified / Paid";
    }
    if (in_array($status, ["DECLINED", "REJECTED", "FAILED"], true)) {
      return "Rejected";
    }
  }

  if ($method === "cash") {
    if ($status === "" || $status === "PAY AT STORE") {
      return "Pay at Store";
    }
    if (in_array($status, ["PENDING", "UNPAID"], true)) {
      return "Pending Payment";
    }
    if (in_array($status, ["PAID", "VERIFIED", "COMPLETE", "DONE"], true)) {
      return "Paid";
    }
  }

  if ($status === "") {
    return "-";
  }

  return ucfirst(strtolower($status));
}

function payment_amount_label($amount, $detailsTotal = null): string {
  if (is_numeric($amount) && (float)$amount > 0) {
    return '₱' . number_format((float)$amount, 2);
  }
  if (is_string($detailsTotal) && trim($detailsTotal) !== '' && is_numeric(trim($detailsTotal))) {
    return '₱' . number_format((float)trim($detailsTotal), 2);
  }
  return "";
}

$stmt = $pdo->prepare("
  SELECT q.id, q.queue_code, q.category, q.status, q.details, q.created_at, u.fullname,
    p.payment_method, p.reference_number, p.status AS payment_status, p.amount,
    q.details->>'estimated_total' AS details_total,
    q.details->>'payment_status' AS details_payment_status
  FROM queues q
  JOIN users u ON u.id = q.user_id
  LEFT JOIN LATERAL (
    SELECT payment_method, reference_number, status, amount
    FROM payments
    WHERE queue_id = q.id
    ORDER BY id DESC
    LIMIT 1
  ) p ON TRUE
  WHERE (
    LOWER(TRIM(q.category)) IN ('online_printorder', 'printing_online', 'xerox', 'rush-id', 'laminating')
    OR (
      LOWER(TRIM(q.category)) = 'printing'
      AND LOWER(TRIM(COALESCE(q.details->>'order_type', ''))) = 'online'
    )
    OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
  )
    AND UPPER(TRIM(COALESCE(q.status, 'PENDING'))) != 'CANCELLED'
    AND q.created_at > (NOW() - INTERVAL '15 minutes')
  ORDER BY q.created_at DESC
");
$stmt->execute();
$rows = $stmt->fetchAll();
$adminNotificationCount = admin_queue_notification_count($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Queue Management - Printing</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/queueL.css?v=20260601-queue-ui-v2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/realtime.css?v=20260530') ?>">
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
    <a href="<?= admin_url('/pages/admin/queue_list/printing.php') ?>" class="admin-notification-link" aria-label="Queue notifications: <?= (int)$adminNotificationCount ?>">
      <span class="admin-notification-icon" aria-hidden="true"></span>
      <span>Notifications</span>
      <?php if ($adminNotificationCount > 0): ?>
        <strong class="admin-notification-badge"><?= (int)$adminNotificationCount ?></strong>
      <?php endif; ?>
    </a>
    <a href="<?= admin_url('/index.php') ?>">Services</a>
    <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>">Home</a>
    <a href="<?= admin_url('/pages/admin/customer_list/custoL.php') ?>">Customer List</a>
    <a href="<?= admin_url('/pages/admin/logout.php') ?>">Logout</a>
  </nav>
</header>

<div class="admin-wrapper">
  <section class="admin-hero">
    <h1>Queue Management</h1>
    <p>Monitor and update all service queue entries.</p>
  </section>

<main class="admin-container">
  <div class="page-frame">
    <div class="page-inner">
      <div class="panel">
        <div class="tabs" role="tablist">
          <a class="tab active" href="<?= admin_url('/pages/admin/queue_list/printing.php') ?>">Printing (Online)</a>
          <a class="tab" href="<?= admin_url('/pages/admin/queue_list/walkin.php') ?>">Printing (Walk-In)</a>
          <a class="tab" href="<?= admin_url('/pages/admin/queue_list/repair.php') ?>">Repair</a>
          <a class="tab" href="<?= admin_url('/pages/admin/queue_list/installation.php') ?>">Installation</a>
        </div>

        <?php queue_ui_render_filter_toolbar("onlineQueueTable", true); ?>
        <div class="table-scroll-wrapper">
          <table id="onlineQueueTable" class="table-content queue-table queue-table--files">
            <thead>
              <tr>
                <th>Order ID</th>
                <th>Customer Name</th>
                <th>Service Details</th>
                <th>Payment</th>
                <th>Submitted</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="7" style="text-align:center;padding:18px;color:#666;">No online printing queues yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php $paymentSummary = queue_ui_payment_summary($r); ?>
                <tr<?= queue_ui_row_attrs($r) ?>>
                  <td><?= esc($r["queue_code"]) ?></td>
                  <td><?= esc($r["fullname"]) ?></td>
                  <td><?= esc(service_label($r["category"], $r["details"] ?? null)) ?></td>
                  <td><?= esc($paymentSummary) ?></td>
                  <td>
                    <span class="submitted-stack">
                      <strong><?= esc(admin_queue_submitted_date($r["created_at"])) ?></strong>
                      <small><?= esc(admin_queue_submitted_time($r["created_at"])) ?></small>
                    </span>
                  </td>
                  <td>
                    <span class="status-badge <?= esc(status_class($r["status"])) ?>">
                      <?= esc($r["status"]) ?>
                    </span>
                  </td>
                  <td class="actions">
                    <button
                      class="queue-view-btn"
                      type="button"
                      data-queue="<?= queue_ui_payload_attr($r, service_label($r["category"], $r["details"] ?? null), $paymentSummary) ?>"
                    >View</button>
                    <div class="queue-inline-actions">
                      <div class="actions-group">
                        <button class="btn-start admin-file-action" data-id="<?= (int)$r["id"] ?>">Start</button>
                        <button class="btn-pickup admin-file-action" data-id="<?= (int)$r["id"] ?>">For Pick-up</button>
                        <button class="btn-done admin-file-action" data-id="<?= (int)$r["id"] ?>">Done</button>
                        <button class="btn-cancel admin-file-action" data-id="<?= (int)$r["id"] ?>">Cancel</button>
                        <button
                          class="btn-message admin-file-action"
                          data-id="<?= (int)$r["id"] ?>"
                          data-queue-code="<?= esc($r["queue_code"]) ?>"
                          data-customer="<?= esc($r["fullname"]) ?>"
                          data-service="<?= esc(service_label($r["category"], $r["details"] ?? null)) ?>"
                        >Message</button>
                        <button class="btn-delete admin-file-action" data-id="<?= (int)$r["id"] ?>" title="Delete">Delete</button>
                      </div>
                    </div>
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
</main>
</div>

<?php require_once __DIR__ . "/../_includes/admin_footer.php"; ?>
<?php require_once __DIR__ . "/_queue_details_modal.php"; ?>

<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<?php require_once __DIR__ . "/_queue_message_modal.php"; ?>
<script>
(function(){
  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");
  function sendAction(id, action){
    return fetch(<?= json_encode(admin_url_raw("/pages/admin/_includes/admin_actions.php")) ?>, {
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

<script src="<?= admin_url('/pages/admin/queue_list/realtime-polling.js') ?>" defer></script>
<script src="<?= admin_url('/pages/admin/queue_list/queueL.js?v=20260601-queue-ui-v2') ?>" defer></script>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>




