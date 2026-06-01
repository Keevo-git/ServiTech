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

$stmt = $pdo->prepare("
  SELECT q.id, q.queue_code, q.status, q.details, q.created_at, u.fullname
  FROM queues q
  JOIN users u ON u.id = q.user_id
  WHERE (
    LOWER(TRIM(q.category)) IN ('walkin', 'printing_walkin')
    OR (
      LOWER(TRIM(q.category)) = 'printing'
      AND COALESCE(NULLIF(LOWER(TRIM(COALESCE(q.details->>'order_type', ''))), ''), 'walkin') = 'walkin'
    )
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
  <title>Queue Management - Printing (Walk-In)</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260601-customer-style-notification-v2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/queueL.css?v=20260601-queue-ui-v3') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/realtime.css?v=20260530') ?>">
</head>
<body class="admin-dashboard">

<?php require __DIR__ . "/../_includes/admin_header.php"; ?>

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
          <a class="tab" href="<?= admin_url('/pages/admin/queue_list/printing.php') ?>">Printing (Online)</a>
          <a class="tab active" href="<?= admin_url('/pages/admin/queue_list/walkin.php') ?>">Printing (Walk-In)</a>
          <a class="tab" href="<?= admin_url('/pages/admin/queue_list/repair.php') ?>">Repair</a>
          <a class="tab" href="<?= admin_url('/pages/admin/queue_list/installation.php') ?>">Installation</a>
        </div>

        <?php queue_ui_render_filter_toolbar("walkinQueueTable"); ?>
        <div class="table-scroll-wrapper">
          <table id="walkinQueueTable" class="table-content queue-table queue-table--simple">
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
              <td colspan="5" style="text-align:center;padding:18px;color:#666;">No walk-in queues yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr<?= queue_ui_row_attrs($r) ?>>
                <td><?= esc($r["queue_code"]) ?></td>
                <td><?= esc($r["fullname"]) ?></td>
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
                    data-queue="<?= queue_ui_payload_attr($r, "Walk-in Printing") ?>"
                  >View</button>
                  <div class="queue-inline-actions">
                    <div class="actions-group">
                      <?php queue_ui_render_transition_buttons($r); ?>
                      <button
                        class="btn-message admin-file-action"
                        data-id="<?= (int)$r["id"] ?>"
                        data-queue-code="<?= esc($r["queue_code"]) ?>"
                        data-customer="<?= esc($r["fullname"]) ?>"
                        data-service="Walk-in Printing"
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
  function sendAction(id, action, notes = ""){
    return fetch(<?= json_encode(admin_url_raw("/pages/admin/_includes/admin_actions.php")) ?>, {
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
        alert("Cancellation dialog is unavailable. Refresh the page and try again.");
        return;
      }
      notes = await window.servitechRequestCancellationReason();
      if (!notes) return;
    }
    const data = await sendAction(id, action, notes);
    if (data.ok) location.reload();
    else alert(data.error || "Action failed");
  }

  document.querySelectorAll("[data-action]").forEach(btn => btn.addEventListener("click", () => doAction(btn, btn.dataset.action)));
})();
</script>

<script src="<?= admin_url('/pages/admin/queue_list/realtime-polling.js') ?>" defer></script>
<script src="<?= admin_url('/pages/admin/queue_list/queueL.js?v=20260601-queue-ui-v2') ?>" defer></script>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>


