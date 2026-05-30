<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../_includes/url.php";
require_once __DIR__ . "/../_includes/queue_files.php";

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
  SELECT q.id, q.queue_code, q.status, q.details, q.created_at, u.fullname
  FROM queues q
  JOIN users u ON u.id = q.user_id
  WHERE q.category = 'installation'
    AND UPPER(TRIM(COALESCE(q.status, 'PENDING'))) != 'CANCELLED'
    AND q.created_at > (NOW() - INTERVAL '15 minutes')
  ORDER BY q.created_at ASC
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
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/queue_list/css/queueL.css?v=20260530admin-ui') ?>">
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
          <a class="tab" href="<?= admin_url('/pages/admin/queue_list/printing.php') ?>">Printing (Online)</a>
          <a class="tab" href="<?= admin_url('/pages/admin/queue_list/walkin.php') ?>">Printing (Walk-In)</a>
          <a class="tab" href="<?= admin_url('/pages/admin/queue_list/repair.php') ?>">Repair</a>
          <a class="tab active" href="<?= admin_url('/pages/admin/queue_list/installation.php') ?>">Installation</a>
        </div>

        <div class="table-scroll-wrapper">
          <table class="table-content queue-table queue-table--simple">
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Customer Name</th>
              <th>Service Details</th>
              <th>Submitted</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="6" style="text-align:center;padding:18px;color:#666;">No installation queues yet.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php $serviceLabel = service_label($r["details"] ?? null); ?>
              <tr data-queue-id="<?= esc($r["queue_code"]) ?>">
                <td><?= esc($r["queue_code"]) ?></td>
                <td><?= esc($r["fullname"]) ?></td>
                <td><?= esc($serviceLabel) ?></td>
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
                      data-service="<?= esc($serviceLabel) ?>"
                    >Message</button>
                    <button class="btn-delete admin-file-action" data-id="<?= (int)$r["id"] ?>" title="Delete">Delete</button>
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
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>


