<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/admin_db.php";
require_once __DIR__ . "/queue_files.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

try {
  echo json_encode(["ok" => true, "count" => admin_queue_notification_count($pdo)]);
} catch (Throwable $e) {
  error_log("admin_notification_count error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["ok" => false, "count" => 0, "error" => "Error fetching count"]);
}
?>
