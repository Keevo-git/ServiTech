<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/queue_notifications.php";

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
  $pdo->beginTransaction();

  $select = $pdo->prepare("
    SELECT id, queue_code, category, user_id, status
    FROM queues
    WHERE id = :id
    FOR UPDATE
  ");
  $select->execute([":id" => $id]);
  $queue = $select->fetch(PDO::FETCH_ASSOC);

  if (!$queue) {
    $pdo->rollBack();
    echo json_encode(["ok" => false, "error" => "Queue not found"]);
    exit();
  }

  $oldStatus = strtoupper(trim((string)($queue["status"] ?? "")));
  $stmt = $pdo->prepare("UPDATE queues SET status = :status WHERE id = :id");
  $stmt->execute([":status" => $status, ":id" => $id]);

  if ($oldStatus !== $status) {
    servitech_insert_queue_status_notification($pdo, $queue, $status);
  }

  $pdo->commit();

  echo json_encode(["ok" => true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("queue_update_status error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
}
