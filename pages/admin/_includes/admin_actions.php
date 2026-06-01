<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/admin_db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);
$action = strtolower(trim((string)($_POST["action"] ?? "")));
$notes = trim((string)($_POST["notes"] ?? ""));

if ($id <= 0) {
  echo json_encode(["ok" => false, "error" => "Invalid ID"]);
  exit();
}

if ($action === "delete") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Queue records cannot be permanently deleted. Cancel the order instead."]);
  exit();
}

$statusMap = [
  "approved" => "APPROVED",
  "ongoing" => "ONGOING",
  "pickup" => "FOR PICK-UP",
  "done" => "DONE",
  "cancel" => "CANCELLED",
];

if (!isset($statusMap[$action])) {
  echo json_encode(["ok" => false, "error" => "Invalid action"]);
  exit();
}

try {
  $result = servitech_transition_queue_status(
    $pdo,
    $id,
    $statusMap[$action],
    (int)($_SESSION["user_id"] ?? 0),
    $notes
  );
  echo json_encode(["ok" => true] + $result);
} catch (DomainException $e) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => $e->getMessage()]);
} catch (Throwable $e) {
  error_log("admin_actions transition error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Unable to update order status."]);
}
