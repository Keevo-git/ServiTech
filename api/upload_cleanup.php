<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/upload_helpers.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$userId = (int)($_SESSION["user_id"] ?? 0);
if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(["success" => false, "message" => "Not logged in"]);
  exit();
}
if (!servitech_is_customer()) {
  http_response_code(403);
  echo json_encode(["success" => false, "message" => "Customer access required"]);
  exit();
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  http_response_code(405);
  echo json_encode(["success" => false, "message" => "Method not allowed"]);
  exit();
}

function cleanup_json_exit(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit();
}

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data) || !is_array($data["uploaded_files"] ?? null)) {
  cleanup_json_exit(["success" => false, "message" => "Invalid upload cleanup payload."], 422);
}

$result = servitech_upload_delete_owned_orphans($pdo, $userId, $data["uploaded_files"]);
cleanup_json_exit([
  "success" => empty($result["errors"]),
  "deleted_tokens" => $result["deleted_tokens"],
  "errors" => $result["errors"],
]);
