<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/admin_db.php";
require_once __DIR__ . "/../../../api/queue_helpers.php";

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
  "approved" => "APPROVED",
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

$queueStmt = $pdo->prepare("
  SELECT id, user_id, queue_code, category, details
  FROM queues
  WHERE id = ?
  LIMIT 1
");
$queueStmt->execute([$id]);
$queue = $queueStmt->fetch(PDO::FETCH_ASSOC);

if (!$queue) {
  echo json_encode(["ok" => false, "error" => "Queue/order not found"]);
  exit();
}

$details = [];
if (is_string($queue["details"] ?? null) && trim((string)$queue["details"]) !== "") {
  $decoded = json_decode((string)$queue["details"], true);
  if (is_array($decoded)) {
    $details = $decoded;
  }
}

$category = strtolower(trim((string)($queue["category"] ?? "")));
$queueCode = strtoupper(trim((string)($queue["queue_code"] ?? "")));
$orderType = strtolower(trim((string)($details["order_type"] ?? "")));
$isOnlinePrintOrder = in_array($category, ["online_printorder", "printing_online"], true)
  || $orderType === "online"
  || strpos($queueCode, "OP") === 0;

if ($newStatus === "APPROVED" && !$isOnlinePrintOrder) {
  echo json_encode(["ok" => false, "error" => "Approved status is only available for online print orders."]);
  exit();
}

$stmt = $pdo->prepare("
  UPDATE queues
  SET
    status = ?,
    completed_at = CASE
      WHEN ? = 'DONE' THEN COALESCE(completed_at, NOW())
      ELSE NULL
    END
  WHERE id = ?
");
$stmt->execute([$newStatus, $newStatus, $id]);

if ($newStatus === "APPROVED") {
  $queueCodeLabel = trim((string)($queue["queue_code"] ?? ""));
  servitech_add_notification(
    $pdo,
    (int)$queue["user_id"],
    "payment_update",
    $id,
    "Queue {$queueCodeLabel}: Your payment has been approved. Your order is now waiting to be processed."
  );
}

echo json_encode(["ok" => true, "status" => $newStatus]);
