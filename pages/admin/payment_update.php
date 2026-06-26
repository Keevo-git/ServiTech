<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/../../api/queue_payment.php";
require_once __DIR__ . "/../../config/activity_log.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);

try {
  $beforeStmt = $pdo->prepare("SELECT queue_code, price, paid_amount FROM queues WHERE id = :id LIMIT 1");
  $beforeStmt->execute([":id" => $id]);
  $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $payment = servitech_update_queue_payment(
    $pdo,
    $id,
    $_POST["price"] ?? "",
    $_POST["paid_amount"] ?? ""
  );
  $queueCode = trim((string)($before["queue_code"] ?? ("#" . $id)));
  servitech_activity_log($pdo, [
    "action_type" => "payment_update",
    "module" => "payment_management",
    "target_record_id" => $queueCode,
    "old_value" => [
      "price" => $before["price"] ?? null,
      "paid_amount" => $before["paid_amount"] ?? null,
    ],
    "new_value" => [
      "price" => $payment["price"] ?? null,
      "paid_amount" => $payment["paid_amount"] ?? null,
    ],
    "description" => "Admin updated payment details for Queue {$queueCode}.",
  ]);
  echo json_encode(["ok" => true] + $payment);
} catch (DomainException $e) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => $e->getMessage()]);
} catch (Throwable $e) {
  error_log("payment_update error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Unable to update payment details."]);
}

