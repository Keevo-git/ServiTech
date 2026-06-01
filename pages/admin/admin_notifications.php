<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";

function esc($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$page = max(1, (int)($_GET["page"] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Get total count
$countStmt = $pdo->query("SELECT COUNT(*) FROM notifications");
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

// Get notifications
$stmt = $pdo->prepare("
  SELECT id, type, reference_id, message, is_read, created_at
  FROM notifications
  WHERE user_id IN (SELECT id FROM users WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin')
  ORDER BY is_read ASC, created_at DESC
  LIMIT ? OFFSET ?
");
$stmt->execute([$perPage, $offset]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Notifications - Admin</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260601-customer-style-notification') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_dashboard.css?v=20260530admin-ui') ?>">
  <style>
    .notif-container {
      max-width: 800px;
      margin: 0 auto;
      padding: 20px;
    }

    .notif-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 1px solid #e5ebf3;
    }

    .notif-header h1 {
      margin: 0;
      color: var(--maroon);
    }

    .notif-actions {
      display: flex;
      gap: 12px;
    }

    .notif-actions button {
      padding: 8px 16px;
      border-radius: 8px;
      border: 1px solid #ccd8e8;
      background: #fff;
      color: #1d457c;
      cursor: pointer;
      font-weight: 700;
      font-size: 13px;
    }

    .notif-actions button:hover {
      background: #f5f9ff;
    }

    .notif-list {
      display: grid;
      gap: 12px;
    }

    .notif-item {
      display: grid;
      grid-template-columns: 24px 1fr 100px;
      gap: 12px;
      padding: 14px;
      border: 1px solid #e5ebf3;
      border-radius: 10px;
      background: #fff;
      align-items: start;
    }

    .notif-item.unread {
      background: #f0f4ff;
      border-color: #d3deea;
    }

    .notif-checkbox {
      cursor: pointer;
      margin-top: 3px;
    }

    .notif-content {
      display: grid;
      gap: 6px;
    }

    .notif-message {
      color: #102b4d;
      font-size: 14px;
      font-weight: 500;
      line-height: 1.4;
    }

    .notif-meta {
      display: flex;
      gap: 12px;
      font-size: 12px;
      color: #5d728c;
    }

    .notif-type {
      background: #edf3fb;
      padding: 2px 8px;
      border-radius: 4px;
      font-weight: 700;
      letter-spacing: 0.5px;
      text-transform: uppercase;
    }

    .notif-time {
      color: #8896a8;
    }

    .notif-actions-col {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
    }

    .notif-btn-delete {
      padding: 6px 10px;
      border: 1px solid #e9bcc2;
      background: #fff;
      color: #c12940;
      border-radius: 6px;
      cursor: pointer;
      font-size: 12px;
      font-weight: 700;
    }

    .notif-btn-delete:hover {
      background: #fde7ea;
    }

    .notif-empty {
      text-align: center;
      padding: 60px 20px;
      color: #5d728c;
    }

    .notif-empty svg {
      width: 80px;
      height: 80px;
      margin-bottom: 16px;
      opacity: 0.3;
    }

    .pagination {
      display: flex;
      justify-content: center;
      gap: 8px;
      margin-top: 24px;
      padding-top: 16px;
      border-top: 1px solid #e5ebf3;
    }

    .pagination a,
    .pagination span {
      padding: 8px 12px;
      border-radius: 6px;
      border: 1px solid #d3deea;
      background: #fff;
      color: #1d457c;
      text-decoration: none;
      font-weight: 700;
      font-size: 13px;
    }

    .pagination a:hover {
      background: #f5f9ff;
    }

    .pagination .active {
      background: #214f91;
      border-color: #214f91;
      color: #fff;
    }
  </style>
</head>
<body class="admin-dashboard">

<?php require __DIR__ . "/_includes/admin_header.php"; ?>

<div class="admin-wrapper">
  <div class="notif-container">
    <div class="notif-header">
      <h1>Notifications</h1>
      <div class="notif-actions">
        <button onclick="markAllAsRead()">Mark All Read</button>
        <button onclick="deleteSelected()">Delete Selected</button>
      </div>
    </div>

    <?php if (empty($notifications)): ?>
      <div class="notif-empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <p>No notifications yet</p>
      </div>
    <?php else: ?>
      <div class="notif-list">
        <div style="display: grid; grid-template-columns: 24px 1fr 100px; gap: 12px; padding: 14px; font-weight: 700; color: #5d728c; border-bottom: 1px solid #e5ebf3;">
          <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
          <span>Message</span>
          <span>Actions</span>
        </div>
        <?php foreach ($notifications as $notif): ?>
          <div class="notif-item <?= $notif['is_read'] ? '' : 'unread' ?>">
            <input type="checkbox" class="notif-checkbox notif-select" data-id="<?= (int)$notif['id'] ?>">
            <div class="notif-content">
              <div class="notif-message"><?= esc($notif['message']) ?></div>
              <div class="notif-meta">
                <span class="notif-type"><?= esc($notif['type']) ?></span>
                <span class="notif-time"><?= date('M d, Y H:i', strtotime($notif['created_at'])) ?></span>
              </div>
            </div>
            <div class="notif-actions-col">
              <button class="notif-btn-delete" onclick="deleteNotification(<?= (int)$notif['id'] ?>)">Delete</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?page=1">First</a>
            <a href="?page=<?= $page - 1 ?>">← Prev</a>
          <?php endif; ?>

          <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
            <?php if ($p === $page): ?>
              <span class="active"><?= $p ?></span>
            <?php else: ?>
              <a href="?page=<?= $p ?>"><?= $p ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>">Next →</a>
            <a href="?page=<?= $totalPages ?>">Last</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleSelectAll(checkbox) {
  document.querySelectorAll('.notif-select').forEach(cb => {
    cb.checked = checkbox.checked;
  });
}

function deleteNotification(id) {
  if (confirm('Delete this notification?')) {
    fetch('<?= admin_url('/pages/admin/_includes/admin_notification_delete.php') ?>', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': window.servitechCsrfToken ? window.servitechCsrfToken() : ''
      },
      body: 'id=' + encodeURIComponent(id)
    }).then(r => r.json()).then(data => {
      if (data.ok) location.reload();
      else alert(data.error || 'Failed to delete');
    });
  }
}

function deleteSelected() {
  const selected = Array.from(document.querySelectorAll('.notif-select:checked')).map(cb => cb.dataset.id);
  if (selected.length === 0) {
    alert('Please select notifications to delete');
    return;
  }
  if (confirm('Delete ' + selected.length + ' notification(s)?')) {
    fetch('<?= admin_url('/pages/admin/_includes/admin_notification_delete_bulk.php') ?>', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': window.servitechCsrfToken ? window.servitechCsrfToken() : ''
      },
      body: 'ids=' + encodeURIComponent(JSON.stringify(selected))
    }).then(r => r.json()).then(data => {
      if (data.ok) location.reload();
      else alert(data.error || 'Failed to delete');
    });
  }
}

function markAllAsRead() {
  fetch('<?= admin_url('/pages/admin/_includes/admin_notification_mark_read.php') ?>', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-CSRF-Token': window.servitechCsrfToken ? window.servitechCsrfToken() : ''
    },
    body: 'mark_all=1'
  }).then(r => r.json()).then(data => {
    if (data.ok) location.reload();
    else alert(data.error || 'Failed to mark as read');
  });
}
</script>
<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>

</body>
</html>
