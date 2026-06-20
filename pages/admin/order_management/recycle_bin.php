<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../_includes/url.php";
require_once __DIR__ . "/../_includes/queue_files.php";
require_once __DIR__ . "/_order_modal_helpers.php";

function rb_status_class(string $status): string
{
    $key = strtolower(trim(preg_replace('/[\s_]+/', '-', $status)));
    return match ($key) {
        "approved" => "status-approved",
        "ongoing" => "status-ongoing",
        "for-pick-up", "for-pickup" => "status-pickup",
        "done", "completed" => "status-done",
        "cancelled", "canceled" => "status-cancelled",
        default => "status-pending",
    };
}

function rb_status_label(string $status): string
{
    $status = strtoupper(trim($status));
    return match ($status) {
        "FOR PICK-UP", "FOR PICK UP" => "For Pick-up",
        "APPROVED" => "Approved",
        "ONGOING" => "Ongoing",
        "DONE", "COMPLETED" => "Done",
        "CANCELLED", "CANCELED" => "Cancelled",
        default => "Pending",
    };
}

function rb_service_label(array $row): string
{
    $category = strtolower(trim((string)($row["category"] ?? "")));
    $details = admin_queue_details_array($row["details"] ?? null);

    if (in_array($category, ["online_printorder", "printing_online", "printing", "walkin", "printing_walkin"], true)) {
        return om_service_label($details, "Document Print");
    }

    return om_service_label($details, match ($category) {
        "repair" => "Repair Service",
        "installation" => "Installation Service",
        default => "Service",
    });
}

function rb_payment_method(array $row): string
{
    $details = admin_queue_details_array($row["details"] ?? null);
    $method = $row["payment_method"] ?? ($details["payment_method"] ?? "");
    return om_payment_method_label($method) ?: "-";
}

function rb_total_amount(array $row): string
{
    $details = admin_queue_details_array($row["details"] ?? null);
    $payment = servitech_queue_payment_values([
        "price" => $row["price"] ?? null,
        "paid_amount" => $row["paid_amount"] ?? null,
        "amount" => $row["amount"] ?? null,
        "details_total" => $row["details_total"] ?? ($details["estimated_total"] ?? null),
        "details" => $row["details"] ?? null,
    ]);

    $total = (float)($payment["price"] ?? 0);
    if ($total <= 0) {
        return "-";
    }

    return "PHP " . number_format($total, 2);
}

function rb_days_left($deletedAt): string
{
    $deleted = trim((string)$deletedAt);
    if ($deleted === "") {
        return "-";
    }

    try {
        $now = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
        $deletedDate = (new DateTimeImmutable($deleted))->setTimezone(new DateTimeZone("Asia/Manila"));
        $expires = $deletedDate->modify("+30 days");
        $seconds = $expires->getTimestamp() - $now->getTimestamp();
        if ($seconds <= 86400) {
            return "Deletes today";
        }

        $days = max(1, (int)floor($seconds / 86400));
        return $days . " day" . ($days === 1 ? "" : "s") . " left";
    } catch (Throwable $exception) {
        return "-";
    }
}

try {
    $schemaReady = admin_order_recycle_schema_ready($pdo);
    if (!$schemaReady) {
        $rows = [];
    } else {
    $pdo->exec("
      UPDATE queues
      SET permanently_hidden_at = COALESCE(permanently_hidden_at, NOW()),
          updated_at = NOW()
      WHERE UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'ORDER'
        AND deleted_at IS NOT NULL
        AND permanently_hidden_at IS NULL
        AND deleted_at <= NOW() - INTERVAL '30 days'
    ");

    $stmt = $pdo->query("
      SELECT q.id, q.queue_code, q.category, q.status, q.details, q.price, q.paid_amount,
        q.created_at, q.deleted_at, u.fullname,
        p.payment_method, p.amount,
        q.details->>'estimated_total' AS details_total
      FROM queues q
      JOIN users u ON u.id = q.user_id
      LEFT JOIN LATERAL (
        SELECT payment_method, amount
        FROM payments
        WHERE queue_id = q.id
        ORDER BY id DESC
        LIMIT 1
      ) p ON TRUE
      WHERE UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
        AND q.deleted_at IS NOT NULL
        AND q.permanently_hidden_at IS NULL
      ORDER BY q.deleted_at DESC, q.id DESC
    ");
    $rows = $stmt->fetchAll();
    }
} catch (Throwable $exception) {
    error_log("order recycle bin query error: " . $exception->getMessage());
    $rows = [];
    $schemaReady = false;
}

$adminNotificationCount = admin_queue_notification_count($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Management - Recycle Bin</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260612header-global-type') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/order_management/orderM.css?v=20260620-recycle-bulk-select') ?>">
</head>
<body class="admin-dashboard">

<?php
$adminHeaderVariant = "special";
require __DIR__ . "/../_includes/admin_header.php";
?>

<div class="admin-wrapper">
  <section class="admin-hero order-header">
    <h1>Order Recycle Bin</h1>
    <p>Restore archived orders or remove them from the system view while keeping database records stored.</p>
  </section>

  <main class="admin-container">
    <div class="page-frame">
      <div class="page-inner">
        <div class="card-panel recycle-bin-panel">
          <div class="panel-heading">
            <div class="panel-heading__copy">
              <h3>Deleted Orders <small><?= count($rows) ?> archived record<?= count($rows) === 1 ? "" : "s" ?></small></h3>
            </div>
            <a class="recycle-bin-link" href="<?= admin_url('/pages/admin/order_management/printM.php') ?>">Back to Orders</a>
          </div>

          <?php if (!$schemaReady): ?>
            <div class="recycle-bin-notice">
              Apply <strong>database/migrations/20260616_add_order_recycle_bin.sql</strong> before using the Recycle Bin.
            </div>
          <?php elseif (!$rows): ?>
            <div class="recycle-bin-empty">
              <h2>Recycle Bin is empty</h2>
              <p>Orders moved here remain restorable until removed from the system view.</p>
            </div>
          <?php else: ?>
            <div class="order-bulk-toolbar recycle-bulk-toolbar" data-order-bulk-toolbar data-table-id="recycleBinOrdersTable">
              <span data-order-bulk-count>No orders selected</span>
              <div class="recycle-bulk-actions">
                <button type="button" class="restore-order-btn" data-order-bulk-action="bulk_restore" disabled>Restore Selected</button>
                <button type="button" class="permanent-delete-btn" data-order-bulk-action="bulk_permanent_delete" disabled>Delete Selected</button>
              </div>
            </div>
            <div class="table-scroll-wrapper table-responsive">
              <table id="recycleBinOrdersTable" class="orders table-content order-table recycle-bin-table recycle-bin-table--selectable">
                <thead>
                  <tr>
                    <th class="select-cell">
                      <input type="checkbox" data-order-select-all aria-label="Select all deleted orders">
                    </th>
                    <th>Order ID</th>
                    <th>Customer Name</th>
                    <th>Service</th>
                    <th class="status-cell">Status</th>
                    <th>Payment</th>
                    <th>Total Amount</th>
                    <th>Submitted Date</th>
                    <th>Moved to Bin Date</th>
                    <th>Days Left</th>
                    <th class="action-cell">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $row): ?>
                    <tr class="order-data-row">
                      <td class="select-cell">
                        <input
                          type="checkbox"
                          data-order-select
                          data-id="<?= (int)$row["id"] ?>"
                          data-code="<?= htmlspecialchars((string)$row["queue_code"], ENT_QUOTES, "UTF-8") ?>"
                          aria-label="Select deleted order <?= htmlspecialchars((string)$row["queue_code"], ENT_QUOTES, "UTF-8") ?>"
                        >
                      </td>
                      <td><?= htmlspecialchars((string)$row["queue_code"], ENT_QUOTES, "UTF-8") ?></td>
                      <td><strong><?= htmlspecialchars((string)$row["fullname"], ENT_QUOTES, "UTF-8") ?></strong></td>
                      <td><?= htmlspecialchars(rb_service_label($row), ENT_QUOTES, "UTF-8") ?></td>
                      <td class="status-cell">
                        <span class="status-badge <?= htmlspecialchars(rb_status_class((string)$row["status"]), ENT_QUOTES, "UTF-8") ?>">
                          <?= htmlspecialchars(rb_status_label((string)$row["status"]), ENT_QUOTES, "UTF-8") ?>
                        </span>
                      </td>
                      <td><?= htmlspecialchars(rb_payment_method($row), ENT_QUOTES, "UTF-8") ?></td>
                      <td><?= htmlspecialchars(rb_total_amount($row), ENT_QUOTES, "UTF-8") ?></td>
                      <td>
                        <span class="datetime-stack">
                          <strong><?= htmlspecialchars(admin_queue_submitted_date($row["created_at"]), ENT_QUOTES, "UTF-8") ?></strong>
                          <small><?= htmlspecialchars(admin_queue_submitted_time($row["created_at"]), ENT_QUOTES, "UTF-8") ?></small>
                        </span>
                      </td>
                      <td>
                        <span class="datetime-stack datetime-stack--muted">
                          <strong><?= htmlspecialchars(admin_queue_submitted_date($row["deleted_at"]), ENT_QUOTES, "UTF-8") ?></strong>
                          <small><?= htmlspecialchars(admin_queue_submitted_time($row["deleted_at"]), ENT_QUOTES, "UTF-8") ?></small>
                        </span>
                      </td>
                      <td><span class="days-left-pill"><?= htmlspecialchars(rb_days_left($row["deleted_at"]), ENT_QUOTES, "UTF-8") ?></span></td>
                      <td class="order-actions">
                        <div class="action-buttons recycle-actions">
                          <button
                            class="restore-order-btn"
                            type="button"
                            data-recycle-action="restore"
                            data-id="<?= (int)$row["id"] ?>"
                            data-code="<?= htmlspecialchars((string)$row["queue_code"], ENT_QUOTES, "UTF-8") ?>"
                          >Restore</button>
                          <button
                            class="permanent-delete-btn"
                            type="button"
                            data-recycle-action="permanent_delete"
                            data-id="<?= (int)$row["id"] ?>"
                            data-code="<?= htmlspecialchars((string)$row["queue_code"], ENT_QUOTES, "UTF-8") ?>"
                          >Delete</button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</div>

<?php require_once __DIR__ . "/../_includes/admin_footer.php"; ?>
<?php if ($schemaReady) require_once __DIR__ . "/_order_delete_modal.php"; ?>

<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<?php if ($schemaReady): ?>
  <script src="<?= admin_url('/pages/admin/order_management/order_recycle.js?v=20260620-recycle-bulk-select') ?>" defer></script>
<?php endif; ?>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>
