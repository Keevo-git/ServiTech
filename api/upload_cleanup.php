<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  http_response_code(401);
  echo json_encode(["success" => false, "message" => "Not logged in"]);
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

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!is_array($data)) {
  cleanup_json_exit([
    "success" => false,
    "message" => "Invalid JSON payload.",
  ], 422);
}

$uploadedFiles = $data["uploaded_files"] ?? null;
if (!is_array($uploadedFiles) || empty($uploadedFiles)) {
  cleanup_json_exit([
    "success" => false,
    "message" => "No uploaded files provided.",
  ], 422);
}

$projectRoot = dirname(__DIR__);
$uploadDir = $projectRoot . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "printing";
$uploadDirReal = realpath($uploadDir);
if ($uploadDirReal === false) {
  cleanup_json_exit([
    "success" => false,
    "message" => "Upload directory not found.",
  ], 500);
}

cleanup_json_exit([
  "success" => true,
  "deleted_paths" => [],
  "errors" => [],
  "message" => "Uploaded order files are kept permanently for admin access.",
]);
