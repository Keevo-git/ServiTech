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

$data = json_decode(file_get_contents("php://input"), true);
$uploadId = is_array($data) ? (string)($data["upload_id"] ?? "") : "";

try {
  $uploadId = servitech_upload_request_id($uploadId);
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
  }

  $handle = servitech_upload_request_open($uploadId);
  try {
    $state = servitech_upload_request_read($handle);
    $ownerId = (int)($state["user_id"] ?? 0);
    if ($ownerId > 0 && $ownerId !== $userId) {
      throw new DomainException("Upload request not found.");
    }

    $uploadedFiles = isset($state["uploaded_files"]) && is_array($state["uploaded_files"])
      ? $state["uploaded_files"]
      : [];
    $cleanup = servitech_upload_cancel_owned_orphans($pdo, $userId, $uploadedFiles);

    servitech_upload_request_write($handle, [
      "user_id" => $userId,
      "status" => "cancelled",
      "uploaded_files" => [],
      "deleted_tokens" => $cleanup["deleted_tokens"],
      "cleanup_errors" => $cleanup["errors"],
    ]);
  } finally {
    servitech_upload_request_close($handle);
  }

  echo json_encode(["success" => true, "message" => "File upload was cancelled."]);
} catch (DomainException $e) {
  http_response_code(404);
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
} catch (Throwable $e) {
  error_log("upload_cancel error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "Unable to cancel the upload right now."]);
}
