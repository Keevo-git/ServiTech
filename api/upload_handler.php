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

$maxBytes = 20 * 1024 * 1024;
$allowed = [
  "pdf" => true,
  "doc" => true,
  "docx" => true,
  "ppt" => true,
  "pptx" => true,
  "jpg" => true,
  "jpeg" => true,
  "png" => true,
];

function upload_json_exit(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit();
}

function normalize_files(?array $files): array {
  if (!$files || !isset($files["name"])) return [];

  if (!is_array($files["name"])) {
    return [[
      "name" => (string)$files["name"],
      "tmp_name" => (string)($files["tmp_name"] ?? ""),
      "error" => (int)($files["error"] ?? UPLOAD_ERR_NO_FILE),
      "size" => (int)($files["size"] ?? 0),
    ]];
  }

  $out = [];
  $count = count($files["name"]);
  for ($i = 0; $i < $count; $i++) {
    $out[] = [
      "name" => (string)($files["name"][$i] ?? ""),
      "tmp_name" => (string)($files["tmp_name"][$i] ?? ""),
      "error" => (int)($files["error"][$i] ?? UPLOAD_ERR_NO_FILE),
      "size" => (int)($files["size"][$i] ?? 0),
    ];
  }
  return $out;
}

function sanitize_base_name(string $name): string {
  $base = pathinfo($name, PATHINFO_FILENAME);
  $base = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $base);
  $base = trim((string)$base, "_");
  if ($base === "") $base = "file";
  return substr($base, 0, 80);
}

function extension_of(string $name): string {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  return preg_replace('/[^a-z0-9]+/', '', $ext);
}

$projectRoot = dirname(__DIR__);
$uploadDir = $projectRoot . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "printing";

if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
  upload_json_exit([
    "success" => false,
    "message" => "Unable to create upload directory.",
  ], 500);
}

$files = normalize_files($_FILES["files"] ?? null);
if (empty($files)) {
  upload_json_exit([
    "success" => false,
    "message" => "No files uploaded.",
  ], 422);
}

$uploaded = [];
$errors = [];
$seen = [];

foreach ($files as $file) {
  $original = trim((string)($file["name"] ?? ""));
  $tmp = (string)($file["tmp_name"] ?? "");
  $error = (int)($file["error"] ?? UPLOAD_ERR_NO_FILE);
  $size = (int)($file["size"] ?? 0);

  if ($original === "" || $error === UPLOAD_ERR_NO_FILE) {
    continue;
  }

  if ($error !== UPLOAD_ERR_OK || $tmp === "" || !is_uploaded_file($tmp)) {
    $errors[] = $original . " failed to upload.";
    continue;
  }

  $ext = extension_of($original);
  if ($ext === "" || !isset($allowed[$ext])) {
    $errors[] = $original . " has invalid file type.";
    continue;
  }

  if ($size > $maxBytes) {
    $errors[] = $original . " exceeds 20MB limit.";
    continue;
  }

  $fingerprint = strtolower($original) . "|" . $size;
  if (isset($seen[$fingerprint])) {
    $errors[] = $original . " is duplicated in upload list.";
    continue;
  }
  $seen[$fingerprint] = true;

  $safeBase = sanitize_base_name($original);
  $token = bin2hex(random_bytes(5));
  $savedName = date("YmdHis") . "_" . $token . "_" . $safeBase . "." . $ext;
  $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $savedName;

  $tries = 0;
  while (file_exists($targetPath) && $tries < 5) {
    $tries++;
    $token = bin2hex(random_bytes(5));
    $savedName = date("YmdHis") . "_" . $token . "_" . $safeBase . "." . $ext;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $savedName;
  }

  if (!move_uploaded_file($tmp, $targetPath)) {
    $errors[] = $original . " could not be saved.";
    continue;
  }

  $uploaded[] = [
    "original_name" => $original,
    "saved_path" => "/uploads/printing/" . $savedName,
    "file_type" => $ext,
  ];
}

if (empty($uploaded)) {
  upload_json_exit([
    "success" => false,
    "message" => "No files were uploaded.",
    "errors" => $errors,
  ], 422);
}

if (!empty($errors)) {
  upload_json_exit([
    "success" => false,
    "message" => "Some files failed validation/upload.",
    "uploaded_files" => $uploaded,
    "errors" => $errors,
  ], 422);
}

upload_json_exit([
  "success" => true,
  "uploaded_files" => $uploaded,
]);
