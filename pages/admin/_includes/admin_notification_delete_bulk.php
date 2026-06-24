<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/admin_db.php";
require_once __DIR__ . "/queue_files.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$ids = json_decode((string)($_POST["ids"] ?? "[]"), true);

if (!is_array($ids) || empty($ids)) {
  echo json_encode(["ok" => false, "error" => "No IDs provided"]);
  exit();
}

// Sanitize IDs
$ids = array_filter($ids, fn($id) => is_numeric($id) && (int)$id > 0);
$ids = array_map(fn($id) => (int)$id, $ids);

if (empty($ids)) {
  echo json_encode(["ok" => false, "error" => "Invalid IDs"]);
  exit();
}

try {
  $placeholders = implode(",", array_fill(0, count($ids), "?"));
  $stmt = $pdo->prepare("
    UPDATE notifications
    SET deleted_at = NOW()
    WHERE id IN ({$placeholders})
      AND deleted_at IS NULL
      AND (user_id = ? OR user_id IN (SELECT id FROM users WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'))
  ");
  $stmt->execute(array_merge($ids, [$_SESSION["user_id"] ?? 0]));

  echo json_encode([
    "ok" => true,
    "deleted" => $stmt->rowCount(),
    "unread_count" => admin_notification_unread_count($pdo),
  ]);
  exit();
} catch (Throwable $e) {
  error_log("admin_notification_delete_bulk error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "Database error"]);
  exit();
}
?>
