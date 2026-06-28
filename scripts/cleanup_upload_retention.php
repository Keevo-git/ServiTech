<?php
if (PHP_SAPI !== "cli") {
  http_response_code(404);
  exit();
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/data_lifecycle.php";
require_once __DIR__ . "/../api/upload_helpers.php";

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "servitech-upload-retention.lock";
$lock = @fopen($lockPath, "c");
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
  fwrite(STDERR, "Upload retention cleanup is already running." . PHP_EOL);
  exit(0);
}

try {
  $result = servitech_cleanup_upload_retention(
    $pdo,
    servitech_upload_temporary_retention_hours(),
    servitech_upload_closed_retention_days()
  );

  echo "Temporary upload states deleted: " . (int)$result["request_states_deleted"] . PHP_EOL;
  echo "Temporary uploads deleted: " . (int)$result["temporary_deleted"] . PHP_EOL;
  echo "Closed-request uploads deleted: " . (int)$result["closed_deleted"] . PHP_EOL;

  if (!empty($result["errors"])) {
    fwrite(STDERR, "Failed upload tokens: " . implode(", ", $result["errors"]) . PHP_EOL);
    exit(1);
  }
} finally {
  flock($lock, LOCK_UN);
  fclose($lock);
}
