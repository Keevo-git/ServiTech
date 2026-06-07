<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/_includes/admin_db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);
$message = trim((string)($_POST["message"] ?? ""));

try {
  $result = servitech_send_queue_back_to_customer(
    $pdo,
    $id,
    (int)($_SESSION["user_id"] ?? 0),
    $message
  );
  echo json_encode(["ok" => true] + $result);
} catch (DomainException $e) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => $e->getMessage()]);
} catch (Throwable $e) {
  error_log("queue_send_back error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Unable to send this record back to the customer."]);
}
