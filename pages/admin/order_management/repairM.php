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

$orderRecycleReady = admin_order_recycle_schema_ready($pdo);
$orderRecyclePredicate = admin_order_soft_delete_column_ready($pdo) ? "AND q.deleted_at IS NULL AND q.permanently_hidden_at IS NULL" : "";

$rows = $pdo->query("
  SELECT q.id, q.queue_code, q.status, q.details, q.price, q.paid_amount,
    q.customer_edit_required, q.send_back_message, q.created_at, q.completed_at,
    u.fullname, u.email AS customer_email,
    COALESCE(NULLIF(to_jsonb(u)->>'contact', ''), NULLIF(to_jsonb(u)->>'contacts', '')) AS customer_phone,
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
  WHERE q.category = 'repair'
    AND UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
    {$orderRecyclePredicate}
  ORDER BY
    CASE
      WHEN UPPER(TRIM(COALESCE(q.status, ''))) IN ('DONE', 'CANCEL', 'CANCELLED', 'CANCELED') THEN 1
      ELSE 0
    END,
    q.created_at ASC,
    q.id ASC
")->fetchAll();
$adminNotificationCount = admin_queue_notification_count($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Management - Repair</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260619-hero-actions') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/order_management/orderM.css?v=20260619-view-overlay') ?>">
  <script src="<?= admin_url('/pages/admin/order_management/orderM.js?v=20260619-view-overlay') ?>" defer></script>
</head>
<body class="admin-dashboard" data-order-action-url="<?= htmlspecialchars(admin_url_raw('/pages/admin/queue_update_status.php'), ENT_QUOTES, 'UTF-8') ?>" data-admin-realtime-scope="order_repair">

<?php
$adminHeaderVariant = "special";
require __DIR__ . "/../_includes/admin_header.php";
?>

<div class="admin-wrapper">
  <section class="admin-hero admin-hero--actions order-header">
    <div class="admin-hero-text">
      <h1>Order Management</h1>
      <p>Review repair orders and update statuses in real time.</p>
    </div>
    <div class="admin-hero-actions" aria-label="Order Management actions">
      <button type="button" class="hero-btn hero-btn-secondary" onclick="goAdminBack()">Back</button>
      <a class="hero-btn hero-btn-primary" href="<?= admin_url('/pages/admin/queue_list/printing.php') ?>">Queue Management</a>
    </div>
  </section>

<main class="admin-container">
  <div class="page-frame">
    <div class="page-inner">
      <div class="card-panel">
        <div class="panel-heading">
          <div class="panel-heading__copy">
            <h3>All Orders <small>Manage and update order statuses</small></h3>
          </div>
          <?php if ($orderRecycleReady): ?>
            <a class="recycle-bin-link" href="<?= admin_url('/pages/admin/order_management/recycle_bin.php') ?>">Recycle Bin</a>
          <?php endif; ?>
        </div>

        <div class="orders-scroll-wrapper">
          <div class="orders-content">
            <div class="order-tabs">
              <a class="tab" href="<?= admin_url('/pages/admin/order_management/printM.php') ?>">Print</a>
              <a class="tab active" href="<?= admin_url('/pages/admin/order_management/repairM.php') ?>">Repair</a>
              <a class="tab" href="<?= admin_url('/pages/admin/order_management/installationM.php') ?>">Installation</a>
            </div>

            <div class="table-section">
              <div class="walkin-title">Repair Queue - Manage and update order statuses</div>
              <?php om_render_filter_toolbar("repairOrdersTable"); ?>
              <div class="table-scroll-wrapper">
                <table id="repairOrdersTable" class="orders table-content order-table order-table--simple">
                  <thead>
                    <tr><th>Order ID</th><th>Customer Name</th><th class="status-cell">Status</th><th>Payment</th><th>Submitted Date</th><th class="action-cell">Action</th></tr>
                  </thead>
                  <tbody>
                    <?php if (!$rows): ?>
                      <tr><td colspan="6" style="color:#777;padding:14px;">No repair queues yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($rows as $r): ?>
                        <?php $cls = status_class($r["status"]); ?>
                        <tr
                          class="order-data-row"
                          data-order-id="<?= htmlspecialchars(strtolower((string)$r["queue_code"]), ENT_QUOTES, "UTF-8") ?>"
                          data-customer="<?= htmlspecialchars(strtolower((string)$r["fullname"]), ENT_QUOTES, "UTF-8") ?>"
                          data-customer-email="<?= htmlspecialchars(strtolower((string)($r["customer_email"] ?? "")), ENT_QUOTES, "UTF-8") ?>"
                          data-customer-phone="<?= htmlspecialchars(strtolower((string)($r["customer_phone"] ?? "")), ENT_QUOTES, "UTF-8") ?>"
                          data-status="<?= htmlspecialchars(strtoupper(trim((string)$r["status"])), ENT_QUOTES, "UTF-8") ?>"
                          data-payment-method="<?= htmlspecialchars(om_payment_method_filter_value($r), ENT_QUOTES, "UTF-8") ?>"
                          data-submitted-date="<?= htmlspecialchars(om_order_filter_date($r["created_at"]), ENT_QUOTES, "UTF-8") ?>"
                          data-submitted-at="<?= htmlspecialchars((string)$r["created_at"], ENT_QUOTES, "UTF-8") ?>"
                        >
                          <td><?= htmlspecialchars($r["queue_code"]) ?></td>
                          <td>
                            <span class="order-customer-stack">
                              <strong><?= htmlspecialchars($r["fullname"]) ?></strong>
                              <?php if (trim((string)($r["customer_email"] ?? "")) !== "" || trim((string)($r["customer_phone"] ?? "")) !== ""): ?>
                                <small><?= htmlspecialchars(trim(implode(" | ", array_filter([(string)($r["customer_email"] ?? ""), (string)($r["customer_phone"] ?? "")], fn($value) => trim($value) !== "")))) ?></small>
                              <?php endif; ?>
                            </span>
                          </td>
                          <td class="status-cell"><span class="status-badge <?= $cls ?>"><?= htmlspecialchars(status_label($r["status"])) ?></span></td>
                          <td><?= htmlspecialchars(om_payment_summary($r)) ?></td>
                          <td>
                            <span class="datetime-stack">
                              <strong><?= htmlspecialchars(admin_queue_submitted_date($r["created_at"])) ?></strong>
                              <small><?= htmlspecialchars(admin_queue_submitted_time($r["created_at"])) ?></small>
                            </span>
                          </td>
                          <td class="order-actions">
                            <div class="action-buttons">
                              <button
                                class="btn-primary view-order-btn"
                                type="button"
                                data-id="<?= (int)$r["id"] ?>"
                                data-order="<?= om_order_payload_attr($r, "Repair", "Repair Service") ?>"
                              >View</button>
                              <?php if ($orderRecycleReady): ?>
                                <button class="delete-order-btn" type="button" data-order-delete data-id="<?= (int)$r["id"] ?>" data-code="<?= htmlspecialchars($r["queue_code"], ENT_QUOTES, "UTF-8") ?>">Move to Bin</button>
                              <?php endif; ?>
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
      </div>
    </div>
  </div>
</main>
</div>

<?php require_once __DIR__ . "/../_includes/admin_footer.php"; ?>

<?php require_once __DIR__ . "/_order_details_modal.php"; ?>
<?php if ($orderRecycleReady) require_once __DIR__ . "/_order_delete_modal.php"; ?>

<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<?php if ($orderRecycleReady): ?>
  <script src="<?= admin_url('/pages/admin/order_management/order_recycle.js?v=20260618-recycle-system-hide') ?>" defer></script>
<?php endif; ?>

<script src="<?= admin_url('/pages/admin/queue_list/realtime-polling.js?v=20260618-modal-stays-open') ?>" defer></script>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>


