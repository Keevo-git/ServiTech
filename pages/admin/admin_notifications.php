<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";
require_once __DIR__ . "/_includes/queue_files.php";

function esc($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function admin_notification_type_key(string $value): string {
  $value = strtolower(trim($value));
  return preg_replace('/[\s-]+/', '_', $value) ?: "";
}

function admin_notification_center_category(array $row): string {
  $type = admin_notification_type_key((string)($row["type"] ?? ""));
  $message = strtolower((string)($row["message"] ?? ""));

  if ($type === "admin_stalled" || str_contains($message, "without admin action")) {
    return "stalled-orders";
  }
  if ($type === "admin_cancelled" || str_contains($message, "cancelled") || str_contains($message, "canceled")) {
    return "cancelled";
  }
  if (
    in_array($type, ["admin_new_order", "admin_payment_review"], true)
    || str_contains($message, "new customer")
    || str_contains($message, "new gcash")
    || str_contains($message, "new order")
  ) {
    return "new-orders";
  }

  return "general";
}

function admin_notification_type_label(array $row): string {
  return match (admin_notification_center_category($row)) {
    "new-orders" => admin_notification_type_key((string)($row["type"] ?? "")) === "admin_payment_review" ? "Payment Review" : "New Order",
    "cancelled" => "Cancelled",
    "stalled-orders" => "Stalled Order",
    default => "General",
  };
}

function admin_notification_target_url(array $row): string {
  $queueId = (int)($row["reference_id"] ?? 0);
  $category = strtolower(trim((string)($row["queue_category"] ?? "")));
  $lifecycleStage = strtoupper(trim((string)($row["lifecycle_stage"] ?? "QUEUE")));
  $isOrder = $lifecycleStage === "ORDER";

  if ($category === "repair") {
    $path = $isOrder ? "/pages/admin/order_management/repairM.php" : "/pages/admin/queue_list/repair.php";
  } elseif ($category === "installation") {
    $path = $isOrder ? "/pages/admin/order_management/installationM.php" : "/pages/admin/queue_list/installation.php";
  } elseif (in_array($category, ["walkin", "printing_walkin"], true)) {
    $path = $isOrder ? "/pages/admin/order_management/printM.php" : "/pages/admin/queue_list/walkin.php";
  } else {
    $path = $isOrder ? "/pages/admin/order_management/printM.php" : "/pages/admin/queue_list/printing.php";
  }

  $url = admin_url($path);
  if ($queueId > 0) {
    $url .= (str_contains($url, "?") ? "&" : "?") . "queue_id=" . rawurlencode((string)$queueId) . "&open=notification";
  }

  return $url;
}

admin_notifications_sync_stalled($pdo);

$stmt = $pdo->prepare("
  WITH ranked_notifications AS (
    SELECT
      n.id,
      n.type,
      n.reference_id,
      n.message,
      n.event_key,
      n.is_read,
      n.created_at,
      q.queue_code,
      q.status AS queue_status,
      q.category AS queue_category,
      q.lifecycle_stage,
      q.details::text AS details,
      cancel_history.notes AS cancel_note,
      ROW_NUMBER() OVER (
        PARTITION BY n.user_id, LOWER(TRIM(COALESCE(n.type, 'queue'))), COALESCE(n.reference_id, 0),
          COALESCE(NULLIF(TRIM(n.event_key), ''), MD5(TRIM(COALESCE(n.message, ''))))
        ORDER BY n.created_at DESC, n.id DESC
      ) AS duplicate_rank
    FROM notifications n
    LEFT JOIN queues q ON q.id = n.reference_id
    LEFT JOIN LATERAL (
      SELECT notes
      FROM queue_status_history h
      WHERE h.queue_id = q.id
        AND UPPER(TRIM(COALESCE(h.new_status, ''))) IN ('CANCELLED', 'CANCELED')
      ORDER BY h.created_at DESC, h.id DESC
      LIMIT 1
    ) cancel_history ON TRUE
    WHERE n.user_id IN (
      SELECT id
      FROM users
      WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
    )
      AND n.deleted_at IS NULL
  )
  SELECT id, type, reference_id, message, event_key, is_read, created_at,
         queue_code, queue_status, queue_category, lifecycle_stage, details, cancel_note
  FROM ranked_notifications
  WHERE duplicate_rank = 1
  ORDER BY COALESCE(is_read, FALSE) ASC, created_at DESC, id DESC
  LIMIT 100
");
$stmt->execute();
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoryCounts = [
  "all" => count($notifications),
  "unread" => 0,
  "new-orders" => 0,
  "cancelled" => 0,
  "stalled-orders" => 0,
  "general" => 0,
];

foreach ($notifications as $notification) {
  if (!admin_notification_bool($notification["is_read"] ?? false)) {
    $categoryCounts["unread"]++;
  }
  $category = admin_notification_center_category($notification);
  if (isset($categoryCounts[$category])) {
    $categoryCounts[$category]++;
  }
}

$unreadCount = $categoryCounts["unread"];
$adminNotificationCount = $unreadCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Notifications - Admin</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260604-admin-mobile-nav') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <style>
    body.admin-dashboard.admin-notifications-page {
      background: #eef4fb;
    }

    .admin-notification-shell {
      width: min(1120px, calc(100vw - 36px));
      margin: 0 auto;
      padding: 24px 0 44px;
    }

    .admin-notification-center {
      display: flex;
      flex-direction: column;
      min-height: min(720px, calc(100dvh - 170px));
      padding: 20px;
      border: 1px solid rgba(31, 74, 138, 0.14);
      border-radius: 20px;
      background: rgba(255, 255, 255, 0.98);
      box-shadow: 0 22px 55px rgba(10, 27, 49, 0.18);
    }

    .admin-notification-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      padding-bottom: 16px;
      border-bottom: 1px solid rgba(31, 74, 138, 0.12);
    }

    .admin-notification-head h1 {
      margin: 0;
      color: #173967;
      font-size: 1.42rem;
      line-height: 1.2;
    }

    .admin-notification-head p {
      margin: 5px 0 0;
      color: #607590;
      font-size: 0.88rem;
    }

    .admin-notification-head strong {
      color: #b42318;
    }

    .admin-notification-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      padding: 10px 14px;
      border: 1px solid rgba(31, 74, 138, 0.16);
      border-radius: 11px;
      background: #f4f8fd;
      color: #1f4a8a;
      font-size: 0.84rem;
      font-weight: 800;
      text-decoration: none;
      white-space: nowrap;
    }

    .admin-notification-body {
      display: grid;
      grid-template-columns: 205px minmax(0, 1fr);
      flex: 1 1 auto;
      min-height: 0;
      gap: 18px;
      padding-top: 16px;
    }

    .admin-notification-filters {
      min-width: 0;
      padding-right: 16px;
      border-right: 1px solid rgba(31, 74, 138, 0.1);
    }

    .admin-notification-filters__title {
      margin: 0 0 10px;
      color: #607590;
      font-size: 0.76rem;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .admin-notification-filters__list {
      display: flex;
      flex-direction: column;
      gap: 7px;
    }

    .admin-notification-filter {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      width: 100%;
      min-height: 42px;
      padding: 9px 10px;
      border: 1px solid transparent;
      border-radius: 11px;
      background: transparent;
      color: #365678;
      font-size: 0.84rem;
      font-weight: 800;
      text-align: left;
      cursor: pointer;
      transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
    }

    .admin-notification-filter:hover,
    .admin-notification-filter.is-active {
      border-color: rgba(31, 74, 138, 0.22);
      background: #edf5ff;
      color: #173967;
    }

    .admin-notification-filter strong {
      min-width: 24px;
      padding: 2px 7px;
      border-radius: 999px;
      background: rgba(31, 74, 138, 0.09);
      color: #1f4a8a;
      font-size: 0.72rem;
      text-align: center;
    }

    .admin-notification-main {
      display: flex;
      flex-direction: column;
      min-width: 0;
      min-height: 0;
    }

    .admin-notification-actions {
      display: flex;
      flex: 0 0 auto;
      align-items: center;
      gap: 9px;
      padding-bottom: 12px;
    }

    .admin-notification-select-all {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      color: #244462;
      font-size: 0.84rem;
      font-weight: 800;
      cursor: pointer;
    }

    .admin-notification-select-all input,
    .admin-notification-item__select input {
      width: 17px;
      height: 17px;
      margin: 0;
      accent-color: #1f4a8a;
      cursor: pointer;
    }

    .admin-notification-selection-summary {
      margin-right: auto;
      color: #607590;
      font-size: 0.8rem;
      white-space: nowrap;
    }

    .admin-notification-action-btn {
      min-height: 40px;
      padding: 8px 13px;
      border: 1px solid rgba(31, 74, 138, 0.14);
      border-radius: 11px;
      background: #f4f8fd;
      color: #1f4a8a;
      font-size: 0.82rem;
      font-weight: 800;
      cursor: pointer;
      transition: background-color 0.2s ease, transform 0.18s ease;
    }

    .admin-notification-action-btn:hover:not(:disabled) {
      background: #e8f2ff;
      transform: translateY(-1px);
    }

    .admin-notification-action-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .admin-notification-action-btn--danger {
      background: #fff1f2;
      color: #b42318;
    }

    .admin-notification-action-btn--danger:hover:not(:disabled) {
      background: #ffe4e8;
    }

    .admin-notification-list {
      display: flex;
      flex: 1 1 auto;
      min-height: 0;
      flex-direction: column;
      gap: 10px;
      overflow-y: auto;
      overscroll-behavior: contain;
      padding: 2px 5px 5px 1px;
    }

    .admin-notification-empty {
      display: flex;
      flex-direction: column;
      gap: 5px;
      padding: 30px 14px;
      border: 1px dashed rgba(96, 117, 144, 0.32);
      border-radius: 14px;
      background: #f8fbff;
      color: #607590;
      text-align: center;
      font-size: 0.95rem;
    }

    .admin-notification-empty[hidden],
    .admin-notification-item[hidden] {
      display: none;
    }

    .admin-notification-empty strong {
      color: #173967;
      font-size: 0.98rem;
    }

    .admin-notification-empty span {
      color: #607590;
      font-size: 0.84rem;
    }

    .admin-notification-item {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      gap: 11px;
      width: 100%;
      padding: 13px 14px;
      border: 1px solid rgba(31, 74, 138, 0.1);
      border-radius: 14px;
      background: #ffffff;
      color: #1d3045;
      transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.18s ease, box-shadow 0.2s ease;
    }

    .admin-notification-item:hover {
      border-color: rgba(31, 74, 138, 0.26);
      box-shadow: 0 10px 18px rgba(10, 27, 49, 0.09);
      transform: translateY(-1px);
    }

    .admin-notification-item.is-unread {
      background: #f7fbff;
      border-color: rgba(31, 74, 138, 0.28);
    }

    .admin-notification-item__select {
      display: flex;
      padding-top: 4px;
    }

    .admin-notification-item__open {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 9px;
      min-width: 0;
      padding: 0;
      border: 0;
      background: transparent;
      color: inherit;
      text-align: left;
      cursor: pointer;
    }

    .admin-notification-item__content {
      display: flex;
      min-width: 0;
      flex-direction: column;
      gap: 6px;
    }

    .admin-notification-item__topline,
    .admin-notification-item__details {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 6px 12px;
    }

    .admin-notification-item__category {
      padding: 3px 7px;
      border-radius: 999px;
      background: #eaf2fb;
      color: #1f4a8a;
      font-size: 0.68rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .admin-notification-item__category--cancelled {
      background: #fff1f2;
      color: #b42318;
    }

    .admin-notification-item__category--stalled-orders {
      background: #fff7e6;
      color: #8a4b00;
    }

    .admin-notification-item__message {
      color: #173967;
      font-size: 0.92rem;
      font-weight: 600;
      line-height: 1.4;
      word-break: break-word;
    }

    .admin-notification-item__details {
      color: #496985;
      font-size: 0.78rem;
      line-height: 1.35;
    }

    .admin-notification-item__details strong {
      color: #173967;
    }

    .admin-notification-item__time {
      color: #607590;
      font-size: 0.75rem;
      line-height: 1.3;
    }

    .admin-notification-item__indicator {
      width: 10px;
      height: 10px;
      margin-top: 7px;
      border-radius: 999px;
      background: #fbbf24;
      box-shadow: 0 0 0 4px rgba(251, 191, 36, 0.18);
    }

    .admin-notification-item:not(.is-unread) .admin-notification-item__indicator {
      opacity: 0;
    }

    .admin-notification-filter:focus-visible,
    .admin-notification-action-btn:focus-visible,
    .admin-notification-item__open:focus-visible,
    .admin-notification-link:focus-visible {
      outline: 2px solid #fbbf24;
      outline-offset: 2px;
    }

    @media (max-width: 768px) {
      .admin-notification-shell {
        width: min(100%, calc(100vw - 24px));
        padding-top: 14px;
      }

      .admin-notification-center {
        min-height: calc(100dvh - 128px);
        padding: 16px;
        border-radius: 16px;
      }

      .admin-notification-head {
        align-items: flex-start;
        flex-direction: column;
      }

      .admin-notification-body {
        display: flex;
        flex-direction: column;
        gap: 12px;
        padding-top: 12px;
      }

      .admin-notification-filters {
        padding: 0 0 10px;
        border-right: 0;
        border-bottom: 1px solid rgba(31, 74, 138, 0.1);
      }

      .admin-notification-filters__list {
        flex-direction: row;
        gap: 6px;
        overflow-x: auto;
        padding-bottom: 2px;
      }

      .admin-notification-filter {
        flex: 0 0 auto;
        width: auto;
        min-height: 38px;
        white-space: nowrap;
      }

      .admin-notification-list {
        padding: 2px 2px 24px;
      }
    }

    @media (max-width: 460px) {
      .admin-notification-actions {
        flex-wrap: wrap;
      }

      .admin-notification-select-all {
        flex: 1 1 auto;
      }

      .admin-notification-selection-summary {
        margin-right: 0;
      }

      .admin-notification-action-btn {
        flex: 1 1 calc(50% - 5px);
      }
    }
  </style>
</head>
<body class="admin-dashboard admin-notifications-page">

<?php require __DIR__ . "/_includes/admin_header.php"; ?>

<div class="admin-wrapper">
  <div class="admin-notification-shell">
    <section class="admin-notification-center" aria-labelledby="adminNotificationTitle">
      <div class="admin-notification-head">
        <div>
          <h1 id="adminNotificationTitle">Notification Center</h1>
          <p><strong data-admin-notification-summary><?= (int)$unreadCount ?> unread</strong> order requests, cancellations, and stalled-work reminders</p>
        </div>
        <a class="admin-notification-link" href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>">Back to Dashboard</a>
      </div>

      <div class="admin-notification-body">
        <aside class="admin-notification-filters" aria-label="Notification categories">
          <p class="admin-notification-filters__title">Categories</p>
          <div class="admin-notification-filters__list">
            <button type="button" class="admin-notification-filter is-active" data-admin-notification-filter="all">
              <span>All</span><strong data-admin-filter-count="all"><?= (int)$categoryCounts["all"] ?></strong>
            </button>
            <button type="button" class="admin-notification-filter" data-admin-notification-filter="unread">
              <span>Unread</span><strong data-admin-filter-count="unread"><?= (int)$categoryCounts["unread"] ?></strong>
            </button>
            <button type="button" class="admin-notification-filter" data-admin-notification-filter="new-orders">
              <span>New Orders</span><strong data-admin-filter-count="new-orders"><?= (int)$categoryCounts["new-orders"] ?></strong>
            </button>
            <button type="button" class="admin-notification-filter" data-admin-notification-filter="cancelled">
              <span>Cancelled</span><strong data-admin-filter-count="cancelled"><?= (int)$categoryCounts["cancelled"] ?></strong>
            </button>
            <button type="button" class="admin-notification-filter" data-admin-notification-filter="stalled-orders">
              <span>Stalled Orders</span><strong data-admin-filter-count="stalled-orders"><?= (int)$categoryCounts["stalled-orders"] ?></strong>
            </button>
            <button type="button" class="admin-notification-filter" data-admin-notification-filter="general">
              <span>General</span><strong data-admin-filter-count="general"><?= (int)$categoryCounts["general"] ?></strong>
            </button>
          </div>
        </aside>

        <main class="admin-notification-main">
          <div class="admin-notification-actions">
            <label class="admin-notification-select-all">
              <input type="checkbox" data-admin-notification-select-all>
              <span>Select visible</span>
            </label>
            <span class="admin-notification-selection-summary" data-admin-notification-selection-summary>0 selected</span>
            <button type="button" class="admin-notification-action-btn" data-admin-notification-mark-selected>Mark as Read</button>
            <button type="button" class="admin-notification-action-btn" data-admin-notification-mark-all>Mark All Read</button>
            <button type="button" class="admin-notification-action-btn admin-notification-action-btn--danger" data-admin-notification-delete-selected>Delete</button>
          </div>

          <div class="admin-notification-list" data-admin-notification-list>
            <div class="admin-notification-empty" data-admin-notification-empty <?= empty($notifications) ? "" : "hidden" ?>>
              <strong>No notifications yet.</strong>
              <span>New requests, cancellations, and stalled orders will appear here.</span>
            </div>

            <?php foreach ($notifications as $notification): ?>
              <?php
                $isRead = admin_notification_bool($notification["is_read"] ?? false);
                $category = admin_notification_center_category($notification);
                $typeLabel = admin_notification_type_label($notification);
                $queueCode = trim((string)($notification["queue_code"] ?? ""));
                $status = strtoupper(trim((string)($notification["queue_status"] ?? "")));
                $service = admin_notification_service_label($notification);
                $createdLabel = admin_notification_format_timestamp((string)($notification["created_at"] ?? ""));
                $cancelNote = trim((string)($notification["cancel_note"] ?? ""));
                $targetUrl = admin_notification_target_url($notification);
              ?>
              <article
                class="admin-notification-item<?= $isRead ? "" : " is-unread" ?>"
                data-admin-notification-id="<?= (int)$notification["id"] ?>"
                data-admin-notification-read="<?= $isRead ? "true" : "false" ?>"
                data-admin-notification-category="<?= esc($category) ?>"
                data-admin-notification-url="<?= esc($targetUrl) ?>"
              >
                <label class="admin-notification-item__select" aria-label="Select notification">
                  <input type="checkbox" data-admin-notification-select>
                </label>
                <button type="button" class="admin-notification-item__open" data-admin-notification-open>
                  <span class="admin-notification-item__content">
                    <span class="admin-notification-item__topline">
                      <span class="admin-notification-item__category admin-notification-item__category--<?= esc($category) ?>"><?= esc($typeLabel) ?></span>
                      <span class="admin-notification-item__time"><?= esc($createdLabel) ?></span>
                    </span>
                    <span class="admin-notification-item__message"><?= esc($notification["message"] ?? "New notification") ?></span>
                    <span class="admin-notification-item__details">
                      <?php if ($queueCode !== ""): ?><span><strong>Queue ID: </strong><?= esc($queueCode) ?></span><?php endif; ?>
                      <?php if ($service !== ""): ?><span><strong>Service: </strong><?= esc($service) ?></span><?php endif; ?>
                      <?php if ($status !== ""): ?><span><strong>Status: </strong><?= esc($status) ?></span><?php endif; ?>
                      <?php if ($cancelNote !== "" && $category === "cancelled"): ?><span><strong>Reason: </strong><?= esc($cancelNote) ?></span><?php endif; ?>
                    </span>
                  </span>
                  <span class="admin-notification-item__indicator" aria-hidden="true"></span>
                </button>
              </article>
            <?php endforeach; ?>
          </div>
        </main>
      </div>
    </section>
  </div>
</div>

<script>
(function () {
  "use strict";

  var root = document.querySelector(".admin-notification-center");
  if (!root) return;

  var endpoints = {
    markRead: <?= json_encode(admin_url('/pages/admin/_includes/admin_notification_mark_read.php')) ?>,
    deleteBulk: <?= json_encode(admin_url('/pages/admin/_includes/admin_notification_delete_bulk.php')) ?>
  };

  var activeFilter = "all";
  var selectedIds = new Set();
  var list = root.querySelector("[data-admin-notification-list]");
  var emptyState = root.querySelector("[data-admin-notification-empty]");
  var selectAllCheckbox = root.querySelector("[data-admin-notification-select-all]");
  var selectionSummary = root.querySelector("[data-admin-notification-selection-summary]");
  var markSelectedButton = root.querySelector("[data-admin-notification-mark-selected]");
  var markAllButton = root.querySelector("[data-admin-notification-mark-all]");
  var deleteSelectedButton = root.querySelector("[data-admin-notification-delete-selected]");
  var filterButtons = Array.from(root.querySelectorAll("[data-admin-notification-filter]"));

  function csrfToken() {
    return window.servitechCsrfToken ? window.servitechCsrfToken() : "";
  }

  function notificationItems() {
    return Array.from(list.querySelectorAll(".admin-notification-item"));
  }

  function visibleItems() {
    return notificationItems().filter(function (item) {
      return !item.hidden;
    });
  }

  function postForm(url, params) {
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Accept": "application/json",
        "Content-Type": "application/x-www-form-urlencoded",
        "X-CSRF-Token": csrfToken(),
        "X-Requested-With": "XMLHttpRequest"
      },
      body: new URLSearchParams(params).toString()
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data.ok) {
          throw new Error(data.error || "Notification request failed.");
        }
        return data;
      });
    });
  }

  function syncCounts() {
    var counts = {
      all: 0,
      unread: 0,
      "new-orders": 0,
      cancelled: 0,
      "stalled-orders": 0,
      general: 0
    };

    notificationItems().forEach(function (item) {
      var category = item.dataset.adminNotificationCategory || "general";
      counts.all += 1;
      if (item.dataset.adminNotificationRead !== "true") counts.unread += 1;
      if (Object.prototype.hasOwnProperty.call(counts, category)) counts[category] += 1;
    });

    Object.keys(counts).forEach(function (key) {
      var count = root.querySelector('[data-admin-filter-count="' + key + '"]');
      if (count) count.textContent = String(counts[key]);
    });

    var summary = root.querySelector("[data-admin-notification-summary]");
    if (summary) summary.textContent = counts.unread + " unread";

    var badge = document.querySelector(".admin-notification-badge");
    var trigger = document.querySelector(".admin-notification-btn");
    if (trigger) trigger.setAttribute("aria-label", "Admin notifications: " + counts.unread);
    if (badge) {
      if (counts.unread > 0) badge.textContent = String(counts.unread);
      else badge.remove();
    }
  }

  function syncEmptyState() {
    var hasVisible = visibleItems().length > 0;
    emptyState.hidden = hasVisible;
    emptyState.querySelector("strong").textContent = activeFilter === "all"
      ? "No notifications yet."
      : "No notifications in this category.";
    emptyState.querySelector("span").textContent = activeFilter === "all"
      ? "New requests, cancellations, and stalled orders will appear here."
      : "Try another filter to see more admin updates.";
  }

  function syncActions() {
    var visible = visibleItems();
    var selectedVisibleCount = visible.filter(function (item) {
      return selectedIds.has(Number(item.dataset.adminNotificationId || 0));
    }).length;
    var selectedItems = notificationItems().filter(function (item) {
      return selectedIds.has(Number(item.dataset.adminNotificationId || 0));
    });
    var hasUnreadSelection = selectedItems.some(function (item) {
      return item.dataset.adminNotificationRead !== "true";
    });

    selectionSummary.textContent = selectedIds.size + " selected";
    markSelectedButton.disabled = !hasUnreadSelection;
    deleteSelectedButton.disabled = selectedIds.size === 0;
    selectAllCheckbox.disabled = visible.length === 0;
    selectAllCheckbox.checked = visible.length > 0 && selectedVisibleCount === visible.length;
    selectAllCheckbox.indeterminate = selectedVisibleCount > 0 && selectedVisibleCount < visible.length;
    markAllButton.disabled = notificationItems().every(function (item) {
      return item.dataset.adminNotificationRead === "true";
    });
  }

  function applyFilter() {
    notificationItems().forEach(function (item) {
      var isVisible = activeFilter === "all"
        || (activeFilter === "unread" && item.dataset.adminNotificationRead !== "true")
        || item.dataset.adminNotificationCategory === activeFilter;
      item.hidden = !isVisible;
    });

    filterButtons.forEach(function (button) {
      var isActive = button.dataset.adminNotificationFilter === activeFilter;
      button.classList.toggle("is-active", isActive);
      button.setAttribute("aria-pressed", isActive ? "true" : "false");
    });

    syncEmptyState();
    syncActions();
  }

  function markItemRead(item) {
    if (!item) return;
    item.dataset.adminNotificationRead = "true";
    item.classList.remove("is-unread");
    var checkbox = item.querySelector("[data-admin-notification-select]");
    if (checkbox) checkbox.checked = selectedIds.has(Number(item.dataset.adminNotificationId || 0));
  }

  function removeSelectedItems(ids) {
    ids.forEach(function (id) {
      var item = list.querySelector('[data-admin-notification-id="' + String(id) + '"]');
      if (item) item.remove();
      selectedIds.delete(id);
    });
  }

  list.addEventListener("change", function (event) {
    var checkbox = event.target.closest("[data-admin-notification-select]");
    if (!checkbox) return;

    var item = event.target.closest(".admin-notification-item");
    var id = Number(item ? item.dataset.adminNotificationId : 0);
    if (!id) return;

    if (checkbox.checked) selectedIds.add(id);
    else selectedIds.delete(id);
    syncActions();
  });

  list.addEventListener("click", function (event) {
    var openButton = event.target.closest("[data-admin-notification-open]");
    if (!openButton) return;

    var item = event.target.closest(".admin-notification-item");
    var id = Number(item ? item.dataset.adminNotificationId : 0);
    var targetUrl = item ? item.dataset.adminNotificationUrl : "";
    if (!item || !id) {
      if (targetUrl) window.location.href = targetUrl;
      return;
    }

    var go = function () {
      if (targetUrl) window.location.href = targetUrl;
    };

    if (item.dataset.adminNotificationRead === "true") {
      go();
      return;
    }

    postForm(endpoints.markRead, { id: String(id) })
      .then(function () {
        markItemRead(item);
        selectedIds.delete(id);
        syncCounts();
        applyFilter();
      })
      .catch(function (error) {
        console.error(error);
      })
      .finally(go);
  });

  selectAllCheckbox.addEventListener("change", function () {
    visibleItems().forEach(function (item) {
      var id = Number(item.dataset.adminNotificationId || 0);
      var checkbox = item.querySelector("[data-admin-notification-select]");
      if (!id || !checkbox) return;

      checkbox.checked = selectAllCheckbox.checked;
      if (selectAllCheckbox.checked) selectedIds.add(id);
      else selectedIds.delete(id);
    });
    syncActions();
  });

  filterButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      activeFilter = button.dataset.adminNotificationFilter || "all";
      applyFilter();
    });
  });

  markSelectedButton.addEventListener("click", function () {
    var ids = Array.from(selectedIds);
    if (!ids.length) return;

    postForm(endpoints.markRead, { ids: JSON.stringify(ids) })
      .then(function () {
        ids.forEach(function (id) {
          selectedIds.delete(id);
          markItemRead(list.querySelector('[data-admin-notification-id="' + String(id) + '"]'));
        });
        syncCounts();
        applyFilter();
      })
      .catch(function (error) {
        window.servitechAdminToast?.error(error.message || "Failed to mark selected notifications as read.");
      });
  });

  markAllButton.addEventListener("click", function () {
    postForm(endpoints.markRead, { mark_all: "1" })
      .then(function () {
        selectedIds.clear();
        notificationItems().forEach(markItemRead);
        syncCounts();
        applyFilter();
        window.servitechAdminToast?.persist("Notifications marked as read.");
      })
      .catch(function (error) {
        window.servitechAdminToast?.error(error.message || "Failed to mark notifications as read.");
      });
  });

  deleteSelectedButton.addEventListener("click", function () {
    var ids = Array.from(selectedIds);
    if (!ids.length || !window.confirm("Delete " + ids.length + " selected notification(s)?")) return;

    postForm(endpoints.deleteBulk, { ids: JSON.stringify(ids) })
      .then(function () {
        removeSelectedItems(ids);
        syncCounts();
        applyFilter();
        window.servitechAdminToast?.persist("Selected notifications deleted successfully.");
      })
      .catch(function (error) {
        window.servitechAdminToast?.error(error.message || "Failed to delete selected notifications.");
      });
  });

  syncCounts();
  applyFilter();
})();
</script>
<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>
