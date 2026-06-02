<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/../../api/queue_payment.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);

try {
  $payment = servitech_update_queue_payment(
    $pdo,
    $id,
    $_POST["price"] ?? "",
    $_POST["paid_amount"] ?? ""
  );
  echo json_encode(["ok" => true] + $payment);
} catch (DomainException $e) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => $e->getMessage()]);
} catch (Throwable $e) {
  error_log("payment_update error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Unable to update payment details."]);
}

