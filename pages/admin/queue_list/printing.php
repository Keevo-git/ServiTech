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
    "approved" => "status-approved",
    "ongoing" => "status-ongoing",
    "for-pick-up", "for-pickup" => "status-pickup",
    "done" => "status-done",
    "cancelled", "canceled" => "status-cancelled",
    default => "status-pending",
  };
}

function service_label($category, $details = null): string {
  $legacyPrintingLabels = [
    "document printing",
    "document print",
    "walk-in document printing",
    "walk-in document print",
    "walk-in printing",
    "walkin printing",
    "print walk-in",
  ];
  $isDocumentPrintLabel = static function (string $label) use ($legacyPrintingLabels): bool {
    $normalized = strtolower(trim($label));
    return in_array($normalized, $legacyPrintingLabels, true)
      || (str_contains($normalized, "document") && str_contains($normalized, "print"))
      || (str_contains($normalized, "print") && str_contains($normalized, "order"));
  };

  if (is_string($details) && $details !== "") {
    $decoded = json_decode($details, true);
    if (is_array($decoded) && trim((string)($decoded["service_name_snapshot"] ?? ($decoded["service_label"] ?? ""))) !== "") {
      $label = trim((string)($decoded["service_name_snapshot"] ?? $decoded["service_label"]));
      return $isDocumentPrintLabel($label) ? "Document Print" : (strcasecmp($label, "xerox") === 0 ? "Photocopy" : $label);
    }
  } elseif (is_array($details) && trim((string)($details["service_name_snapshot"] ?? ($details["service_label"] ?? ""))) !== "") {
    $label = trim((string)($details["service_name_snapshot"] ?? $details["service_label"]));
    return $isDocumentPrintLabel($label) ? "Document Print" : (strcasecmp($label, "xerox") === 0 ? "Photocopy" : $label);
  }

  $map = [
    "printing" => "Document Print",
    "online_printorder" => "Document Print",
    "printing_online" => "Document Print",
    "walkin" => "Document Print",
    "printing_walkin" => "Document Print",
    "xerox" => "Photocopy",
    "photocopy" => "Photocopy",
    "rush-id" => "Rush ID",
    "laminating" => "Laminating",
    "scanning" => "Scanning",
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

function payment_amount_label($amount, $detailsTotal = null): string {
  if (is_numeric($amount) && (float)$amount > 0) {
    return '₱' . number_format((float)$amount, 2);
  }
  if (is_string($detailsTotal) && trim($detailsTotal) !== '' && is_numeric(trim($detailsTotal))) {
    return '₱' . number_format((float)trim($detailsTotal), 2);
  }
  return "";
}

$queueVisibilityPredicate = admin_order_soft_delete_column_ready($pdo)
  ? "AND q.deleted_at IS NULL AND q.permanently_hidden_at IS NULL"
  : "";

$stmt = $pdo->prepare("
  SELECT q.id, q.queue_code, q.category, q.status, q.details, q.price, q.paid_amount,
    q.customer_edit_required, q.send_back_message, q.created_at, q.completed_at,
    u.fullname, u.email AS customer_email,
    COALESCE(NULLIF(to_jsonb(u)->>'contact', ''), NULLIF(to_jsonb(u)->>'contacts', '')) AS customer_phone,
    p.payment_method, p.reference_number, p.amount, p.status AS payment_status,
    q.details->>'estimated_total' AS details_total
  FROM queues q
  JOIN users u ON u.id = q.user_id
  LEFT JOIN LATERAL (
    SELECT payment_method, reference_number, amount, status
    FROM payments
    WHERE queue_id = q.id
    ORDER BY id DESC
    LIMIT 1
  ) p ON TRUE
  WHERE (
    LOWER(TRIM(q.category)) IN (
      'online_printorder', 'printing_online', 'printing', 'walkin', 'printing_walkin',
      'xerox', 'photocopy', 'rush-id', 'laminating', 'scanning'
    )
    OR (
      LOWER(TRIM(q.category)) = 'printing'
      AND LOWER(TRIM(COALESCE(q.details->>'order_type', ''))) = 'online'
    )
    OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
  )
    AND UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'QUEUE'
    {$queueVisibilityPredicate}
  ORDER BY q.created_at ASC, q.id ASC
");
$stmt->execute();
$rows = $stmt->fetchAll();
$adminNotificationCount = admin_queue_notification_count($pdo);
$queuePageTitle = servitech_admin_employee_banner_title($pdo, "Queue Management");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Queue Management - Print</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260619-hero-actions') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/queueL.css?v=20260628-payment-wrap') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/realtime.css?v=20260530') ?>">
</head>
<body class="admin-dashboard" data-admin-realtime-scope="queue_printing">

<?php
$adminHeaderVariant = "special";
require __DIR__ . "/../_includes/admin_header.php";
?>

<div class="admin-wrapper">
  <section class="admin-hero admin-hero--actions">
    <div class="admin-hero-text">
      <h1><?= htmlspecialchars($queuePageTitle, ENT_QUOTES, "UTF-8") ?></h1>
      <p>Manage today's queue and currently serving customers.</p>
    </div>
    <div class="admin-hero-actions" aria-label="Queue Management actions">
      <button type="button" class="hero-btn hero-btn-secondary" onclick="goAdminBack()">Back</button>
      <a class="hero-btn hero-btn-primary" href="<?= admin_url('/pages/admin/order_management/printM.php') ?>">Order Management</a>
    </div>
  </section>

<main class="admin-container">
  <div class="page-frame">
    <div class="page-inner">
      <div class="panel">
        <div class="tabs" role="tablist">
          <a class="tab active" href="<?= admin_url('/pages/admin/queue_list/printing.php') ?>">Print</a>
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
                <th>Payment</th>
                <th>Submitted</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php queue_ui_render_table_rows($rows, "queue_printing"); ?>
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
  const actionMessages = {
    approved: "Status updated to Approved.",
    ongoing: "Status updated to Ongoing.",
    pickup: "Status updated to For Pick-up.",
    done: "Status updated to Done.",
    cancel: "Order cancelled successfully."
  };
  function sendAction(id, action, notes = ""){
    return fetch(<?= json_encode(admin_url_raw("/pages/admin/queue_update_status.php")) ?>, {
      method: "POST",
      headers: {"Content-Type": "application/x-www-form-urlencoded", "X-CSRF-Token": csrf()},
      body: "id=" + encodeURIComponent(id) + "&action=" + encodeURIComponent(action) + "&notes=" + encodeURIComponent(notes)
    }).then(r => r.json());
  }

  async function doAction(btn, action){
    const id = btn.dataset.id;
    let notes = "";
    if (action === "cancel") {
      if (typeof window.servitechRequestCancellationReason !== "function") {
        window.servitechAdminToast?.error("Cancellation dialog is unavailable. Refresh the page and try again.");
        return;
      }
      notes = await window.servitechRequestCancellationReason();
      if (!notes) return;
    }
    try {
      const data = await sendAction(id, action, notes);
      if (!data.ok) {
        window.servitechAdminToast?.error(data.error || "Action failed.");
        return;
      }
      window.servitechAdminToast?.persist(actionMessages[action] || "Order status updated successfully.");
      location.reload();
    } catch (error) {
      window.servitechAdminToast?.error("Unable to update the order status.");
    }
  }

  document.addEventListener("click", (event) => {
    const btn = event.target.closest(".queue-data-row [data-action]");
    if (btn) doAction(btn, btn.dataset.action);
  });
})();
</script>

<script src="<?= admin_url('/pages/admin/queue_list/realtime-polling.js?v=20260624-queue-inplace-sync') ?>" defer></script>
<script src="<?= admin_url('/pages/admin/queue_list/queueL.js?v=20260628-payment-wrap') ?>" defer></script>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>




