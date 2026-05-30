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

function service_label($details = null): string
{
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

$rows = $pdo->query("
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
  WHERE q.category = 'installation'
    AND (
      q.created_at <= (NOW() - INTERVAL '15 minutes')
      OR UPPER(TRIM(COALESCE(q.status, 'PENDING'))) = 'CANCELLED'
    )
  ORDER BY q.created_at DESC
")->fetchAll();
$adminNotificationCount = admin_queue_notification_count($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Management - Installation</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/order_management/orderM.css?v=20260530-modal-type-spacing') ?>">
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
    <p>Review installation orders and update statuses in real time.</p>
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
              <a class="tab" href="<?= admin_url('/pages/admin/order_management/printM.php?view=online') ?>">Online Printing</a>
              <a class="tab" href="<?= admin_url('/pages/admin/order_management/printM.php?view=walkin') ?>">Walk-in Printing</a>
              <a class="tab" href="<?= admin_url('/pages/admin/order_management/repairM.php') ?>">Repair</a>
              <a class="tab active" href="<?= admin_url('/pages/admin/order_management/installationM.php') ?>">Installation</a>
            </div>

            <div class="table-section">
              <div class="walkin-title">Installation Queue - Manage and update order statuses</div>
              <div class="table-scroll-wrapper">
                <table class="orders table-content order-table order-table--simple">
                  <thead>
                    <tr><th>Order ID</th><th>Customer Name</th><th>Status</th><th>Payment</th><th>Submitted Date</th><th>Action</th></tr>
                  </thead>
                  <tbody>
                    <?php if (!$rows): ?>
                      <tr><td colspan="6" style="color:#777;padding:14px;">No installation queues yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($rows as $r): ?>
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
                          <td>
                            <button
                              class="btn-primary view-order-btn"
                              type="button"
                              data-id="<?= (int)$r["id"] ?>"
                              data-order="<?= om_order_payload_attr($r, "Installation Queue", "Installation Service") ?>"
                            >View</button>
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

<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>

<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>


