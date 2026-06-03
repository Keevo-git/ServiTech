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
        "approved" => "status-approved",
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
        "APPROVED" => "Approved",
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
  SELECT q.id, q.queue_code, q.status, q.details, q.price, q.paid_amount, q.created_at, q.completed_at, u.fullname,
    p.payment_method, p.reference_number, p.amount,
    q.details->>'estimated_total' AS details_total
  FROM queues q
  JOIN users u ON u.id = q.user_id
  LEFT JOIN LATERAL (
    SELECT payment_method, reference_number, amount
    FROM payments
    WHERE queue_id = q.id
    ORDER BY id DESC
    LIMIT 1
  ) p ON TRUE
  WHERE UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
    AND (
      LOWER(TRIM(COALESCE(q.category, ''))) IN ('walkin', 'printing_walkin')
      OR (
        LOWER(TRIM(COALESCE(q.category, ''))) = 'printing'
        AND COALESCE(NULLIF(LOWER(TRIM(COALESCE(q.details->>'order_type', ''))), ''), 'walkin') = 'walkin'
        AND UPPER(TRIM(COALESCE(q.queue_code, ''))) NOT LIKE 'OP%'
      )
    )
  ORDER BY
    CASE
      WHEN UPPER(TRIM(COALESCE(q.status, ''))) IN ('DONE', 'CANCEL', 'CANCELLED', 'CANCELED') THEN 1
      ELSE 0
    END,
    q.created_at ASC,
    q.id ASC
");
$walkinStmt->execute();
$walkin = $walkinStmt->fetchAll();

$onlineStmt = $pdo->prepare("
  SELECT q.id, q.queue_code, q.status, q.details, q.price, q.paid_amount, q.created_at, q.completed_at, u.fullname,
    p.payment_method, p.reference_number, p.amount,
    q.details->>'estimated_total' AS details_total
  FROM queues q
  JOIN users u ON u.id = q.user_id
  LEFT JOIN LATERAL (
    SELECT payment_method, reference_number, amount
    FROM payments
    WHERE queue_id = q.id
    ORDER BY id DESC
    LIMIT 1
  ) p ON TRUE
  WHERE UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
    AND (
      LOWER(TRIM(COALESCE(q.category, ''))) IN ('online_printorder', 'printing_online')
      OR (
        LOWER(TRIM(COALESCE(q.category, ''))) = 'printing'
        AND LOWER(TRIM(COALESCE(q.details->>'order_type', ''))) = 'online'
      )
      OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
    )
  ORDER BY
    CASE
      WHEN UPPER(TRIM(COALESCE(q.status, ''))) IN ('DONE', 'CANCEL', 'CANCELLED', 'CANCELED') THEN 1
      ELSE 0
    END,
    q.created_at ASC,
    q.id ASC
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
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260601-customer-style-notification-v2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/queueL.css?v=20260603-modal-columns') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/order_management/orderM.css?v=20260603-modal-columns') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/realtime.css?v=20260530') ?>">
  <script src="<?= admin_url('/pages/admin/order_management/orderM.js?v=20260603-fifo-terminal-last') ?>" defer></script>
</head>
<body class="admin-dashboard" data-order-action-url="<?= htmlspecialchars(admin_url_raw('/pages/admin/queue_update_status.php'), ENT_QUOTES, 'UTF-8') ?>" data-admin-realtime-scope="order_<?= htmlspecialchars($printView, ENT_QUOTES, 'UTF-8') ?>">

<?php require __DIR__ . "/../_includes/admin_header.php"; ?>

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
              <?php om_render_filter_toolbar("walkinOrdersTable"); ?>
              <div class="table-scroll-wrapper">
                <table id="walkinOrdersTable" class="orders table-content order-table order-table--walkin">
                  <thead>
                    <tr><th>Order ID</th><th>Customer Name</th><th>Status</th><th>Payment</th><th>Submitted Date</th><th>Action</th></tr>
                  </thead>
                  <tbody>
                    <?php if (!$walkin): ?>
                      <tr><td colspan="6" style="color:#777;padding:14px;">No walk-in orders yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($walkin as $r): ?>
                        <?php $cls = status_class($r["status"]); ?>
                        <tr
                          class="order-data-row"
                          data-order-id="<?= htmlspecialchars(strtolower((string)$r["queue_code"]), ENT_QUOTES, "UTF-8") ?>"
                          data-customer="<?= htmlspecialchars(strtolower((string)$r["fullname"]), ENT_QUOTES, "UTF-8") ?>"
                          data-status="<?= htmlspecialchars(strtoupper(trim((string)$r["status"])), ENT_QUOTES, "UTF-8") ?>"
                          data-payment-method="<?= htmlspecialchars(om_payment_method_filter_value($r), ENT_QUOTES, "UTF-8") ?>"
                          data-submitted-date="<?= htmlspecialchars(om_order_filter_date($r["created_at"]), ENT_QUOTES, "UTF-8") ?>"
                          data-submitted-at="<?= htmlspecialchars((string)$r["created_at"], ENT_QUOTES, "UTF-8") ?>"
                        >
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
              <?php om_render_filter_toolbar("onlineOrdersTable", true, $online); ?>
              <div class="table-scroll-wrapper">
                <table id="onlineOrdersTable" class="orders table-content order-table order-table--online">
                  <thead>
                    <tr><th>Order ID</th><th>Customer Name</th><th>Status</th><th>Payment</th><th>Submitted Date</th><th>Action</th></tr>
                  </thead>
                  <tbody>
                    <?php if (!$online): ?>
                      <tr><td colspan="6" style="color:#777;padding:14px;">No online printing orders yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($online as $r): ?>
                        <?php $cls = status_class($r["status"]); ?>
                        <tr
                          class="order-data-row"
                          data-order-id="<?= htmlspecialchars(strtolower((string)$r["queue_code"]), ENT_QUOTES, "UTF-8") ?>"
                          data-customer="<?= htmlspecialchars(strtolower((string)$r["fullname"]), ENT_QUOTES, "UTF-8") ?>"
                          data-status="<?= htmlspecialchars(strtoupper(trim((string)$r["status"])), ENT_QUOTES, "UTF-8") ?>"
                          data-payment-method="<?= htmlspecialchars(om_payment_method_filter_value($r), ENT_QUOTES, "UTF-8") ?>"
                          data-submitted-date="<?= htmlspecialchars(om_order_filter_date($r["created_at"]), ENT_QUOTES, "UTF-8") ?>"
                          data-submitted-at="<?= htmlspecialchars((string)$r["created_at"], ENT_QUOTES, "UTF-8") ?>"
                        >
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
                              data-order="<?= om_order_payload_attr(array_merge($r, ["canMessage" => true, "allowApproved" => true]), "Online Print Order", "Document Printing") ?>"
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

<script src="<?= admin_url('/pages/admin/queue_list/realtime-polling.js?v=20260603-record-signature') ?>" defer></script>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>



