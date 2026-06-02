<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/admin_db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

try {
  if (empty($_POST["mark_all"])) {
    echo json_encode(["ok" => false, "error" => "Invalid request"]);
    exit();
  }

  $stmt = $pdo->prepare("
    UPDATE notifications
    SET is_read = TRUE
    WHERE user_id IN (
      SELECT id
      FROM users
      WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
    )
      AND is_read = FALSE
  ");
  $stmt->execute();

  echo json_encode(["ok" => true, "marked" => $stmt->rowCount()]);
  exit();
} catch (Throwable $e) {
  error_log("admin_notification_mark_read error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "Database error"]);
  exit();
}
?>
