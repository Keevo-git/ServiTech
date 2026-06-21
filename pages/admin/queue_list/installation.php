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

function service_label($details = null): string {
  if (is_string($details) && $details !== "") {
    $decoded = json_decode($details, true);
    if (is_array($decoded) && trim((string)($decoded["service_label"] ?? "")) !== "") {
      return trim((string)$decoded["service_label"]);
    }
  } elseif (is_array($details) && trim((string)($details["service_label"] ?? "")) !== "") {
    return trim((string)$details["service_label"]);
  }

  return "Installation Service";
}

$stmt = $pdo->prepare("
  SELECT q.id, q.queue_code, q.status, q.details, q.price, q.paid_amount,
    q.customer_edit_required, q.send_back_message, q.created_at, q.completed_at,
    u.fullname, u.email AS customer_email,
    COALESCE(NULLIF(to_jsonb(u)->>'contact', ''), NULLIF(to_jsonb(u)->>'contacts', '')) AS customer_phone,
    p.payment_method, p.reference_number, p.amount, p.status AS payment_status
  FROM queues q
  JOIN users u ON u.id = q.user_id
  LEFT JOIN LATERAL (
    SELECT payment_method, reference_number, amount, status
    FROM payments WHERE queue_id = q.id ORDER BY id DESC LIMIT 1
  ) p ON TRUE
  WHERE q.category = 'installation'
    AND UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'QUEUE'
  ORDER BY q.created_at ASC, q.id ASC
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
  <title>Queue Management - Installation</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260619-hero-actions') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/queueL.css?v=20260621-global-ui-polish') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/realtime.css?v=20260530') ?>">
</head>
<body class="admin-dashboard" data-admin-realtime-scope="queue_installation">

<?php
$adminHeaderVariant = "special";
require __DIR__ . "/../_includes/admin_header.php";
?>

<div class="admin-wrapper">
  <section class="admin-hero admin-hero--actions">
    <div class="admin-hero-text">
      <h1>Queue Management</h1>
      <p>Monitor and update all service queue entries.</p>
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
          <a class="tab" href="<?= admin_url('/pages/admin/queue_list/printing.php') ?>">Print</a>
          <a class="tab" href="<?= admin_url('/pages/admin/queue_list/repair.php') ?>">Repair</a>
          <a class="tab active" href="<?= admin_url('/pages/admin/queue_list/installation.php') ?>">Installation</a>
        </div>

        <?php queue_ui_render_filter_toolbar("installationQueueTable"); ?>
        <div class="table-scroll-wrapper">
          <table id="installationQueueTable" class="table-content queue-table queue-table--simple">
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Customer Name</th>
              <th>Submitted</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="5" style="text-align:center;padding:18px;color:#666;">No installation queues yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php $serviceLabel = service_label($r["details"] ?? null); ?>
              <tr<?= queue_ui_row_attrs($r) ?>>
                <td><?= esc($r["queue_code"]) ?></td>
                <td>
                  <span class="customer-stack">
                    <strong><?= esc($r["fullname"]) ?></strong>
                    <?php if (trim((string)($r["customer_email"] ?? "")) !== "" || trim((string)($r["customer_phone"] ?? "")) !== ""): ?>
                      <small><?= esc(trim(implode(" | ", array_filter([(string)($r["customer_email"] ?? ""), (string)($r["customer_phone"] ?? "")], fn($value) => trim($value) !== "")))) ?></small>
                    <?php endif; ?>
                  </span>
                </td>
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
                    data-queue="<?= queue_ui_payload_attr($r, $serviceLabel) ?>"
                  >View</button>
                  <div class="queue-inline-actions">
                    <div class="actions-group">
                      <?php queue_ui_render_transition_buttons($r); ?>
                      <button
                        class="btn-message admin-file-action"
                        data-id="<?= (int)$r["id"] ?>"
                        data-queue-code="<?= esc($r["queue_code"]) ?>"
                        data-customer="<?= esc($r["fullname"]) ?>"
                        data-service="<?= esc($serviceLabel) ?>"
                      >Message</button>
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

  document.querySelectorAll("[data-action]").forEach(btn => btn.addEventListener("click", () => doAction(btn, btn.dataset.action)));
})();
</script>

<script src="<?= admin_url('/pages/admin/queue_list/realtime-polling.js?v=20260614-queue-modal-fix2') ?>" defer></script>
<script src="<?= admin_url('/pages/admin/queue_list/queueL.js?v=20260621-gcash-approval') ?>" defer></script>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>


