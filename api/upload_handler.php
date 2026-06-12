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

$uploadContext = strtolower(trim((string)($_POST["upload_context"] ?? "")));
$allowedUploadContexts = ["service_request", "document_printing", "rush_id", "payment"];
if ($uploadContext === "") {
  $uploadContext = "service_request";
}
if (!in_array($uploadContext, $allowedUploadContexts, true)) {
  http_response_code(422);
  echo json_encode(["success" => false, "message" => "Invalid upload purpose."]);
  exit();
}
$isRushIdUpload = $uploadContext === "rush_id";
$uploadId = trim((string)($_POST["upload_id"] ?? ""));
$uploadRequestHandle = null;

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
try {
  servitech_upload_assert_limits($files, "size");
} catch (DomainException $e) {
  upload_json_exit(["success" => false, "message" => $e->getMessage()], 422);
}

if ($uploadId !== "") {
  try {
    $uploadId = servitech_upload_request_id($uploadId);
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }
    $uploadRequestHandle = servitech_upload_request_open($uploadId);
    $requestState = servitech_upload_request_read($uploadRequestHandle);
    $ownerId = (int)($requestState["user_id"] ?? 0);
    if ($ownerId > 0 && $ownerId !== $userId) {
      throw new DomainException("Upload request not found.");
    }
    if (($requestState["status"] ?? "") === "cancelled") {
      servitech_upload_request_close($uploadRequestHandle);
      $uploadRequestHandle = null;
      upload_json_exit(["success" => false, "cancelled" => true, "message" => "File upload was cancelled."], 409);
    }
    servitech_upload_request_write($uploadRequestHandle, [
      "user_id" => $userId,
      "status" => "processing",
      "uploaded_files" => [],
    ]);
  } catch (DomainException $e) {
    if (is_resource($uploadRequestHandle)) servitech_upload_request_close($uploadRequestHandle);
    upload_json_exit(["success" => false, "message" => $e->getMessage()], 422);
  } catch (Throwable $e) {
    if (is_resource($uploadRequestHandle)) servitech_upload_request_close($uploadRequestHandle);
    error_log("upload_handler request state error: " . $e->getMessage());
    upload_json_exit(["success" => false, "message" => "Unable to initialize upload tracking."], 500);
  }
}

$supabaseUploadMetadataEnabled = function_exists("servitech_supabase_auth_enabled")
  && servitech_supabase_auth_enabled();
$insert = $supabaseUploadMetadataEnabled
  ? $pdo->prepare("
      INSERT INTO uploads (
        upload_token, user_id, uploaded_by, original_name, storage_key, file_extension,
        mime_type, byte_size, checksum_sha256, upload_purpose, visibility, upload_status
      )
      VALUES (
        :upload_token, :user_id, :uploaded_by, :original_name, :storage_key, :file_extension,
        :mime_type, :byte_size, :checksum_sha256, :upload_purpose, 'private', 'active'
      )
      RETURNING upload_token, original_name, file_extension, mime_type, byte_size, checksum_sha256,
                upload_purpose, visibility, upload_status
    ")
  : $pdo->prepare("
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
  if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    $errors[] = "Maximum file size is 25 MB per file.";
    continue;
  }
  if ($error !== UPLOAD_ERR_OK || $tmp === "" || !is_uploaded_file($tmp)) {
    $errors[] = $original . " failed to upload.";
    continue;
  }
  if ($size <= 0) {
    $errors[] = $original . " is empty.";
    continue;
  }
  if ($size > servitech_upload_max_file_bytes()) {
    $errors[] = "Maximum file size is 25 MB per file.";
    continue;
  }

  try {
    if ($isRushIdUpload) {
      servitech_upload_assert_rush_id_photo_extension(servitech_upload_extension($original));
    }

    $type = servitech_upload_validate_type($tmp, $original);
    if ($isRushIdUpload) {
      servitech_upload_assert_rush_id_photo_extension($type["extension"]);
    }

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
      $insertParams = [
        ":upload_token" => $token,
        ":user_id" => $userId,
        ":original_name" => substr($original, 0, 255),
        ":storage_key" => $storageKey,
        ":file_extension" => $type["extension"],
        ":mime_type" => $type["mime_type"],
        ":byte_size" => $size,
        ":checksum_sha256" => $checksum,
      ];
      if ($supabaseUploadMetadataEnabled) {
        $insertParams[":uploaded_by"] = $userId;
        $insertParams[":upload_purpose"] = $uploadContext;
      }
      $insert->execute($insertParams);
    } catch (Throwable $e) {
      @unlink($targetPath);
      throw $e;
    }

    $uploaded[] = servitech_upload_public_metadata((array)$insert->fetch(PDO::FETCH_ASSOC));
  } catch (DomainException $e) {
    $message = $e->getMessage();
    if ($isRushIdUpload && $message === "has invalid file content.") {
      $message = "is not a valid JPG, JPEG, or PNG photo.";
    }
    $errors[] = $original . " " . $message;
  } catch (Throwable $e) {
    error_log("upload_handler error: " . $e->getMessage());
    $errors[] = $original . " could not be saved.";
  }
}

if (!empty($errors)) {
  servitech_upload_delete_owned_orphans($pdo, $userId, $uploaded);
  if (is_resource($uploadRequestHandle)) {
    servitech_upload_request_write($uploadRequestHandle, [
      "user_id" => $userId,
      "status" => "failed",
      "uploaded_files" => [],
      "errors" => $errors,
    ]);
    servitech_upload_request_close($uploadRequestHandle);
  }
  upload_json_exit([
    "success" => false,
    "message" => "Some files failed validation/upload.",
    "errors" => $errors,
  ], 422);
}

if (empty($uploaded)) {
  if (is_resource($uploadRequestHandle)) {
    servitech_upload_request_write($uploadRequestHandle, [
      "user_id" => $userId,
      "status" => "failed",
      "uploaded_files" => [],
    ]);
    servitech_upload_request_close($uploadRequestHandle);
  }
  upload_json_exit(["success" => false, "message" => "No files were uploaded."], 422);
}

if (is_resource($uploadRequestHandle)) {
  try {
    servitech_upload_request_write($uploadRequestHandle, [
      "user_id" => $userId,
      "status" => "completed",
      "uploaded_files" => $uploaded,
    ]);
  } catch (Throwable $e) {
    servitech_upload_request_close($uploadRequestHandle);
    $uploadRequestHandle = null;
    servitech_upload_delete_owned_orphans($pdo, $userId, $uploaded);
    error_log("upload_handler completion state error: " . $e->getMessage());
    upload_json_exit(["success" => false, "message" => "Unable to finalize upload tracking."], 500);
  }
  servitech_upload_request_close($uploadRequestHandle);
}

upload_json_exit(["success" => true, "uploaded_files" => $uploaded]);
