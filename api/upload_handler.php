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

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  http_response_code(405);
  echo json_encode(["success" => false, "message" => "Method not allowed"]);
  exit();
}

$maxBytes = 20 * 1024 * 1024;

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

$uploadDir = servitech_upload_private_dir();
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
  upload_json_exit(["success" => false, "message" => "Unable to create private upload directory."], 500);
}

$files = normalize_files($_FILES["files"] ?? null);
if (empty($files)) {
  upload_json_exit(["success" => false, "message" => "No files uploaded."], 422);
}

$insert = $pdo->prepare("
  INSERT INTO uploads (
    upload_token, user_id, original_name, storage_key, file_extension, mime_type, byte_size, checksum_sha256
  )
  VALUES (
    :upload_token, :user_id, :original_name, :storage_key, :file_extension, :mime_type, :byte_size, :checksum_sha256
  )
  RETURNING upload_token, original_name, file_extension, mime_type, byte_size, checksum_sha256
");
$uploaded = [];
$errors = [];
$seenChecksums = [];

foreach ($files as $file) {
  $original = basename(str_replace("\\", "/", trim((string)($file["name"] ?? ""))));
  $tmp = (string)($file["tmp_name"] ?? "");
  $error = (int)($file["error"] ?? UPLOAD_ERR_NO_FILE);
  $size = (int)($file["size"] ?? 0);

  if ($original === "" || $error === UPLOAD_ERR_NO_FILE) continue;
  if ($error !== UPLOAD_ERR_OK || $tmp === "" || !is_uploaded_file($tmp)) {
    $errors[] = $original . " failed to upload.";
    continue;
  }
  if ($size <= 0 || $size > $maxBytes) {
    $errors[] = $original . " must be between 1 byte and 20MB.";
    continue;
  }

  try {
    $type = servitech_upload_validate_type($tmp, $original);
    $checksum = hash_file("sha256", $tmp);
    if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
      throw new RuntimeException("Unable to calculate file checksum.");
    }
    if (isset($seenChecksums[$checksum])) {
      throw new DomainException("is duplicated in the upload list.");
    }
    $seenChecksums[$checksum] = true;

    $token = bin2hex(random_bytes(32));
    $storageKey = $token . "." . $type["extension"];
    $targetPath = servitech_upload_storage_path($storageKey);
    if (!move_uploaded_file($tmp, $targetPath)) {
      throw new RuntimeException("Unable to save private upload.");
    }
    @chmod($targetPath, 0600);

    try {
      $insert->execute([
        ":upload_token" => $token,
        ":user_id" => $userId,
        ":original_name" => substr($original, 0, 255),
        ":storage_key" => $storageKey,
        ":file_extension" => $type["extension"],
        ":mime_type" => $type["mime_type"],
        ":byte_size" => $size,
        ":checksum_sha256" => $checksum,
      ]);
    } catch (Throwable $e) {
      @unlink($targetPath);
      throw $e;
    }

    $uploaded[] = servitech_upload_public_metadata((array)$insert->fetch(PDO::FETCH_ASSOC));
  } catch (DomainException $e) {
    $errors[] = $original . " " . $e->getMessage();
  } catch (Throwable $e) {
    error_log("upload_handler error: " . $e->getMessage());
    $errors[] = $original . " could not be saved.";
  }
}

if (!empty($errors)) {
  servitech_upload_delete_owned_orphans($pdo, $userId, $uploaded);
  upload_json_exit([
    "success" => false,
    "message" => "Some files failed validation/upload.",
    "errors" => $errors,
  ], 422);
}

if (empty($uploaded)) {
  upload_json_exit(["success" => false, "message" => "No files were uploaded."], 422);
}

upload_json_exit(["success" => true, "uploaded_files" => $uploaded]);
