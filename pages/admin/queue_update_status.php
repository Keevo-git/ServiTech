<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/_includes/admin_db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);
$status = strtoupper(trim($_POST["status"] ?? ""));

$allowed = ["PENDING", "ONGOING", "FOR PICK-UP", "DONE", "CANCELLED"];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  echo json_encode(["ok" => false, "error" => "Invalid request"]);
  exit();
}

try {
  $stmt = $pdo->prepare("
    UPDATE queues
    SET
      status = :status,
      completed_at = CASE
        WHEN :status_done = 'DONE' THEN COALESCE(completed_at, NOW())
        ELSE NULL
      END
    WHERE id = :id
  ");
  $stmt->execute([":status" => $status, ":status_done" => $status, ":id" => $id]);

  echo json_encode(["ok" => true]);
} catch (PDOException $e) {
  error_log("queue_update_status error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
}
