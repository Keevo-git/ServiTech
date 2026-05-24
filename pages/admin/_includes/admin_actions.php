<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/admin_db.php";
require_once __DIR__ . "/queue_notifications.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);
$action = (string)($_POST["action"] ?? "");

if ($id <= 0) {
  echo json_encode(["ok" => false, "error" => "Invalid ID"]);
  exit();
}

if ($action === "delete") {
  $stmt = $pdo->prepare("DELETE FROM queues WHERE id = ?");
  $stmt->execute([$id]);
  echo json_encode(["ok" => true]);
  exit();
}

$statusMap = [
  "pending" => "PENDING",
  "ongoing" => "ONGOING",
  "pickup"  => "FOR PICK-UP",
  "done"    => "DONE",
  "cancel"  => "CANCELLED",
];

if (!isset($statusMap[$action])) {
  echo json_encode(["ok" => false, "error" => "Invalid action"]);
  exit();
}

$newStatus = $statusMap[$action];

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
  $stmt->execute([":status" => $newStatus, ":id" => $id]);

  if ($oldStatus !== $newStatus) {
    servitech_insert_queue_status_notification($pdo, $queue, $newStatus);
  }

  $pdo->commit();
} catch (Throwable $exception) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  error_log("admin_actions status error: " . $exception->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
  exit();
}

echo json_encode(["ok" => true, "status" => $newStatus]);
