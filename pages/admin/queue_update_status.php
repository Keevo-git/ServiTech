<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/_includes/admin_db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);
$status = strtoupper(trim($_POST["status"] ?? ""));
$notes = trim((string)($_POST["notes"] ?? ""));

if ($id <= 0) {
  echo json_encode(["ok" => false, "error" => "Invalid request"]);
  exit();
}

try {
  $result = servitech_transition_queue_status($pdo, $id, $status, (int)($_SESSION["user_id"] ?? 0), $notes);
  echo json_encode(["ok" => true] + $result);
} catch (DomainException $e) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => $e->getMessage()]);
} catch (Throwable $e) {
  error_log("queue_update_status error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
}
