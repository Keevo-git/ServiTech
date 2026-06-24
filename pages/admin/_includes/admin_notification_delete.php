<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/admin_db.php";
require_once __DIR__ . "/queue_files.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);

if ($id <= 0) {
  echo json_encode(["ok" => false, "error" => "Invalid notification ID"]);
  exit();
}

try {
  $stmt = $pdo->prepare("
    UPDATE notifications
    SET deleted_at = NOW()
    WHERE id = ?
      AND deleted_at IS NULL
      AND (user_id = ? OR user_id IN (SELECT id FROM users WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'))
  ");
  $stmt->execute([$id, $_SESSION["user_id"] ?? 0]);

  if ($stmt->rowCount() === 0) {
    echo json_encode(["ok" => false, "error" => "Notification not found"]);
    exit();
  }

  echo json_encode([
    "ok" => true,
    "unread_count" => admin_notification_unread_count($pdo),
  ]);
  exit();
} catch (Throwable $e) {
  error_log("admin_notification_delete error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "Database error"]);
  exit();
}
?>
