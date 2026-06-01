<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/db.php";

$userId = (int)($_SESSION["user_id"] ?? 0);
if ($userId <= 0) {
  http_response_code(401);
  exit("Not logged in.");
}

$path = rawurldecode(trim((string)($_GET["path"] ?? "")));
if (!preg_match('#^/uploads/(printing|print_orders)/([a-zA-Z0-9_.-]+)$#', $path, $matches)) {
  http_response_code(404);
  exit("File not found.");
}

$stmt = $pdo->prepare("
  SELECT q.user_id
  FROM queues q
  WHERE EXISTS (
    SELECT 1
    FROM jsonb_array_elements(
      CASE
        WHEN jsonb_typeof(q.details::jsonb->'uploaded_files') = 'array' THEN q.details::jsonb->'uploaded_files'
        ELSE '[]'::jsonb
      END
    ) AS upload
    WHERE upload->>'saved_path' = :saved_path
       OR upload->>'file_path' = :saved_path
  )
  LIMIT 1
");
$stmt->execute([":saved_path" => $path]);
$ownerId = (int)($stmt->fetchColumn() ?: 0);
if ($ownerId <= 0 || (!servitech_is_admin() && $ownerId !== $userId)) {
  http_response_code(404);
  exit("File not found.");
}

$fullPath = dirname(__DIR__) . str_replace("/", DIRECTORY_SEPARATOR, $path);
if (!is_file($fullPath) || !is_readable($fullPath)) {
  http_response_code(404);
  exit("File not found.");
}

$mimeType = "application/octet-stream";
if (class_exists("finfo")) {
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $detected = trim((string)$finfo->file($fullPath));
  if ($detected !== "") $mimeType = $detected;
}
$inlineRequested = strtolower(trim((string)($_GET["disposition"] ?? ""))) === "inline";
$inlineAllowed = in_array($mimeType, ["application/pdf", "image/jpeg", "image/png"], true);
$disposition = $inlineRequested && $inlineAllowed ? "inline" : "attachment";
$fileName = str_replace(['"', "\r", "\n"], "", basename($path));

header("Content-Type: " . $mimeType);
header("Content-Length: " . (string)filesize($fullPath));
header("Content-Disposition: {$disposition}; filename=\"" . $fileName . "\"");
header("Cache-Control: private, no-store, max-age=0");
header("X-Content-Type-Options: nosniff");
readfile($fullPath);
exit();
