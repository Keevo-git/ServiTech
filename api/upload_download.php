<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/upload_helpers.php";

$userId = (int)($_SESSION["user_id"] ?? 0);
if ($userId <= 0) {
  http_response_code(401);
  exit("Not logged in.");
}

$token = strtolower(trim((string)($_GET["token"] ?? "")));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
  http_response_code(404);
  exit("File not found.");
}

$stmt = $pdo->prepare("
  SELECT upload_token, user_id, original_name, storage_key, mime_type,
         COALESCE(NULLIF(to_jsonb(uploads)->>'visibility', ''), 'private') AS visibility,
         COALESCE(NULLIF(to_jsonb(uploads)->>'upload_status', ''), 'active') AS upload_status
  FROM uploads
  WHERE upload_token = :upload_token
    AND deleted_at IS NULL
    AND COALESCE(NULLIF(to_jsonb(uploads)->>'upload_status', ''), 'active') = 'active'
    AND COALESCE(NULLIF(to_jsonb(uploads)->>'visibility', ''), 'private') = 'private'
  LIMIT 1
");
$stmt->execute([":upload_token" => $token]);
$upload = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($upload) || (!servitech_is_admin() && (int)$upload["user_id"] !== $userId)) {
  http_response_code(404);
  exit("File not found.");
}

try {
  $path = servitech_upload_storage_path((string)$upload["storage_key"]);
} catch (Throwable $e) {
  http_response_code(404);
  exit("File not found.");
}
if (!is_file($path) || !is_readable($path)) {
  http_response_code(404);
  exit("File not found.");
}

$originalName = trim((string)$upload["original_name"]);
$originalName = str_replace(["\r", "\n", '"'], "", basename($originalName));
$mimeType = trim((string)$upload["mime_type"]);
$inlineRequested = strtolower(trim((string)($_GET["disposition"] ?? ""))) === "inline";
$inlineAllowed = in_array($mimeType, ["application/pdf", "image/jpeg", "image/png"], true);
$disposition = $inlineRequested && $inlineAllowed ? "inline" : "attachment";

header("Content-Type: " . ($mimeType !== "" ? $mimeType : "application/octet-stream"));
header("Content-Length: " . (string)filesize($path));
header("Content-Disposition: {$disposition}; filename=\"" . ($originalName !== "" ? $originalName : "download") . "\"");
header("Cache-Control: private, no-store, max-age=0");
header("X-Content-Type-Options: nosniff");
readfile($path);
exit();
