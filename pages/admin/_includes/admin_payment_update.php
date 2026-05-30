<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/admin_db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);
$status = strtoupper(trim((string)($_POST["status"] ?? "")));

if ($id <= 0) {
  echo json_encode(["ok" => false, "error" => "Invalid queue ID"]);
  exit();
}

if (!in_array($status, ["PAID", "VERIFIED", "PENDING", "FAILED", "REJECTED"], true)) {
  echo json_encode(["ok" => false, "error" => "Invalid status"]);
  exit();
}

try {
  $pdo->beginTransaction();

  // Get the queue
  $queueStmt = $pdo->prepare("SELECT id, user_id, queue_code FROM queues WHERE id = ?");
  $queueStmt->execute([$id]);
  $queue = $queueStmt->fetch(PDO::FETCH_ASSOC);

  if (!$queue) {
    echo json_encode(["ok" => false, "error" => "Queue not found"]);
    exit();
  }

  $user_id = (int)$queue["user_id"];
  $queue_code = (string)$queue["queue_code"];

  // Update payment status
  $paymentStmt = $pdo->prepare("
    UPDATE payments
    SET status = ?
    WHERE queue_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $paymentStmt->execute([$status, $id]);

  // Also update in queue details if it exists
  $detailsStmt = $pdo->prepare("
    UPDATE queues
    SET details = jsonb_set(
      details,
      '{payment_status}',
      to_jsonb(?::text)
    )
    WHERE id = ?
  ");
  $detailsStmt->execute([$status === "PAID" ? "Paid" : ($status === "VERIFIED" ? "Verified" : $status), $id]);

  // Send notification to customer
  $statusLabel = $status === "PAID" ? "Paid" : ($status === "VERIFIED" ? "Verified" : $status);
  $notificationMessage = "Queue {$queue_code}: Payment status updated to {$statusLabel}.";
  
  require_once __DIR__ . "/../../../api/queue_helpers.php";
  servitech_add_notification($pdo, $user_id, "payment_update", $id, $notificationMessage);

  // Log the action
  error_log("Admin updated payment status for queue {$queue_code} to {$status}");

  $pdo->commit();

  echo json_encode(["ok" => true, "message" => "Payment status updated successfully"]);
  exit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("admin_payment_update error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "Database error"]);
  exit();
}
?>
