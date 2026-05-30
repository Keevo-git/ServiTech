<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../_includes/url.php";
require_once __DIR__ . "/../_includes/queue_files.php";
require_once __DIR__ . "/_order_modal_helpers.php";

function status_class(string $s): string
{
    $key = strtolower(trim($s));
    $key = preg_replace('/[\s_]+/', '-', $key);
    return match ($key) {
        "ongoing" => "status-ongoing",
        "for-pick-up", "for-pickup" => "status-pickup",
        "done" => "status-done",
        "cancelled", "canceled" => "status-cancelled",
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

function payment_label($value): string
{
    $key = strtolower(trim((string)$value));
    if ($key === "gcash") return "GCash";
    if ($key === "cash") return "Cash";
    return "-";
}

function payment_status_label($method, $paymentStatus = null, $detailsStatus = null): string
{
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

function payment_amount_label($amount, $detailsTotal = null): string
{
    if (is_numeric($amount) && (float)$amount > 0) {
        return '₱' . number_format((float)$amount, 2);
    }
    if (is_string($detailsTotal) && trim($detailsTotal) !== '' && is_numeric(trim($detailsTotal))) {
        return '₱' . number_format((float)trim($detailsTotal), 2);
    }
    return "";
}

$walkinStmt = $pdo->prepare("
  SELECT q.id, q.queue_code, q.status, q.details, q.created_at, q.completed_at, u.fullname,
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
      q.created_at <= (NOW() - INTERVAL '15 minutes')
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
  SELECT q.id, q.queue_code, q.status, q.details, q.created_at, q.completed_at, u.fullname,
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
      q.created_at <= (NOW() - INTERVAL '15 minutes')
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
$adminNotificationCount = admin_queue_notification_count($pdo);
$printView = strtolower(trim((string)($_GET["view"] ?? "online")));
if (!in_array($printView, ["online", "walkin"], true)) {
    $printView = "online";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Management - Printing</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/queueL.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/order_management/orderM.css?v=20260530-action-align') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/realtime.css?v=20260530') ?>">
  <script src="<?= admin_url('/pages/admin/order_management/orderM.js?v=20260530-order-modal-fix') ?>" defer></script>
</head>
<body class="admin-dashboard" data-order-action-url="<?= htmlspecialchars(admin_url_raw('/pages/admin/_includes/admin_actions.php'), ENT_QUOTES, 'UTF-8') ?>">

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
  <section class="admin-hero order-header">
    <h1>Order Management</h1>
    <p>Review printing orders and update statuses in real time.</p>
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
              <a class="tab <?= $printView === "online" ? "active" : "" ?>" href="<?= admin_url('/pages/admin/order_management/printM.php?view=online') ?>">Online Printing</a>
              <a class="tab <?= $printView === "walkin" ? "active" : "" ?>" href="<?= admin_url('/pages/admin/order_management/printM.php?view=walkin') ?>">Walk-in Printing</a>
              <a class="tab" href="<?= admin_url('/pages/admin/order_management/repairM.php') ?>">Repair</a>
              <a class="tab" href="<?= admin_url('/pages/admin/order_management/installationM.php') ?>">Installation</a>
            </div>

            <div class="table-section">
              <?php if ($printView === "walkin"): ?>
              <div class="walkin-title">Walk-in Printing - Manage and update order statuses</div>
              <div class="table-scroll-wrapper">
                <table class="orders table-content order-table order-table--walkin">
                  <thead>
                    <tr><th>Order ID</th><th>Customer Name</th><th>Status</th><th>Payment</th><th>Submitted Date</th><th>Action</th></tr>
                  </thead>
                  <tbody>
                    <?php if (!$walkin): ?>
                      <tr><td colspan="6" style="color:#777;padding:14px;">No walk-in queues older than 15 minutes yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($walkin as $r): ?>
                        <?php $cls = status_class($r["status"]); ?>
                        <tr>
                          <td><?= htmlspecialchars($r["queue_code"]) ?></td>
                          <td><?= htmlspecialchars($r["fullname"]) ?></td>
                          <td><span class="status-badge <?= $cls ?>"><?= htmlspecialchars(status_label($r["status"])) ?></span></td>
                          <td><?= htmlspecialchars(om_payment_summary($r)) ?></td>
                          <td>
                            <span class="datetime-stack">
                              <strong><?= htmlspecialchars(admin_queue_submitted_date($r["created_at"])) ?></strong>
                              <small><?= htmlspecialchars(admin_queue_submitted_time($r["created_at"])) ?></small>
                            </span>
                          </td>
                          <td class="order-actions">
                            <button
                              class="btn-primary view-order-btn"
                              type="button"
                              data-id="<?= (int)$r["id"] ?>"
                              data-order="<?= om_order_payload_attr(array_merge($r, ["canMessage" => true]), "Walk-in Queue: Print", "Walk-in Printing") ?>"
                            >View</button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php else: ?>

              <div class="section-title-small">Online Printing - Pre-ordered printing requests</div>
              <div class="table-scroll-wrapper">
                <table class="orders table-content order-table order-table--online">
                  <thead>
                    <tr><th>Order ID</th><th>Customer Name</th><th>Status</th><th>Payment</th><th>Submitted Date</th><th>Action</th></tr>
                  </thead>
                  <tbody>
                    <?php if (!$online): ?>
                      <tr><td colspan="6" style="color:#777;padding:14px;">No online printing orders older than 15 minutes yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($online as $r): ?>
                        <?php $cls = status_class($r["status"]); ?>
                        <tr>
                          <td><?= htmlspecialchars($r["queue_code"]) ?></td>
                          <td><?= htmlspecialchars($r["fullname"]) ?></td>
                          <td><span class="status-badge <?= $cls ?>"><?= htmlspecialchars(status_label($r["status"])) ?></span></td>
                          <td>
                            <span class="datetime-stack">
                              <strong><?= htmlspecialchars(om_payment_summary($r)) ?></strong>
                              <small><?= htmlspecialchars(om_payment_status_label($r["payment_method"], $r["payment_status"], $r["details_payment_status"])) ?></small>
                            </span>
                          </td>
                          <td>
                            <span class="datetime-stack">
                              <strong><?= htmlspecialchars(admin_queue_submitted_date($r["created_at"])) ?></strong>
                              <small><?= htmlspecialchars(admin_queue_submitted_time($r["created_at"])) ?></small>
                            </span>
                          </td>
                          <td class="order-actions">
                            <button
                              class="btn-primary view-order-btn"
                              type="button"
                              data-id="<?= (int)$r["id"] ?>"
                              data-order="<?= om_order_payload_attr(array_merge($r, ["canMessage" => true]), "Online Print Order", "Document Printing") ?>"
                            >View</button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
</div>

<?php require_once __DIR__ . "/../_includes/admin_footer.php"; ?>

<?php require_once __DIR__ . "/_order_details_modal.php"; ?>

<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<?php require_once __DIR__ . "/../queue_list/_queue_message_modal.php"; ?>

<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>



