<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/queue_state_machine.php";
require_once __DIR__ . "/queue_payment.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

function customer_cancel_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit();
}

$userId = (int)($_SESSION["user_id"] ?? 0);
if ($userId <= 0) {
  customer_cancel_json(["ok" => false, "error" => "Not logged in"], 401);
}
if (!servitech_is_customer()) {
  customer_cancel_json(["ok" => false, "error" => "Customer access required"], 403);
}
if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  customer_cancel_json(["ok" => false, "error" => "Method not allowed"], 405);
}

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
  $data = $_POST;
}

$queueId = (int)($data["queue_id"] ?? $data["id"] ?? 0);
if ($queueId <= 0) {
  customer_cancel_json(["ok" => false, "error" => "Invalid queue ID."], 422);
}

try {
  $pdo->beginTransaction();
  servitech_ensure_queue_lifecycle_schema($pdo);

  $stmt = $pdo->prepare("
    SELECT id, user_id, queue_code, category, status, price, paid_amount, details
    FROM queues
    WHERE id = :id
      AND user_id = :user_id
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([":id" => $queueId, ":user_id" => $userId]);
  $queue = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!is_array($queue)) {
    throw new DomainException("Queue not found.");
  }

  $currentStatus = servitech_queue_normalize_status((string)($queue["status"] ?? "PENDING"));
  if ($currentStatus !== "PENDING") {
    throw new DomainException("Only pending requests can be cancelled by the customer.");
  }

  $update = $pdo->prepare("
    UPDATE queues
    SET status = 'CANCELLED',
        lifecycle_stage = 'ORDER',
        paid_amount = 0,
        updated_at = NOW()
    WHERE id = :id
      AND user_id = :user_id
  ");
  $update->execute([":id" => $queueId, ":user_id" => $userId]);

  $queueCode = trim((string)($queue["queue_code"] ?? ""));
  $historyNote = "Cancelled by customer while request was pending.";
  servitech_record_queue_status_history(
    $pdo,
    $queueId,
    (string)($queue["category"] ?? ""),
    $currentStatus,
    "CANCELLED",
    null,
    $historyNote
  );
  servitech_add_notification(
    $pdo,
    $userId,
    "queue_cancelled",
    $queueId,
    "Queue {$queueCode}: Your pending request has been cancelled."
  );
  servitech_notify_admins(
    $pdo,
    "admin_cancelled",
    $queueId,
    "Queue {$queueCode}: Customer cancelled this pending request.",
    "admin_cancelled:customer:{$queueId}:{$currentStatus}",
    true
  );

  $pdo->commit();

  customer_cancel_json([
    "ok" => true,
    "status" => "CANCELLED",
    "lifecycle_stage" => "ORDER",
    "payment" => servitech_queue_payment_values([
      "status" => "CANCELLED",
      "price" => $queue["price"] ?? null,
      "paid_amount" => 0,
      "details" => $queue["details"] ?? null,
    ]),
  ]);
} catch (DomainException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  customer_cancel_json(["ok" => false, "error" => $e->getMessage()], 422);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("queue_cancel_request error: " . $e->getMessage());
  customer_cancel_json(["ok" => false, "error" => "Unable to cancel this request right now."], 500);
}
