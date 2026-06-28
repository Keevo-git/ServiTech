<?php
if (PHP_SAPI !== "cli") {
  http_response_code(404);
  exit();
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/data_lifecycle.php";
require_once __DIR__ . "/../api/upload_helpers.php";

$ageHours = isset($argv[1]) ? max(1, (int)$argv[1]) : 24;
$closedDays = isset($argv[2]) ? max(1, (int)$argv[2]) : 30;
$result = servitech_cleanup_upload_retention($pdo, $ageHours, $closedDays);
echo "Deleted temporary upload states: " . (int)$result["request_states_deleted"] . PHP_EOL;
echo "Deleted temporary uploads: " . (int)$result["temporary_deleted"] . PHP_EOL;
echo "Deleted closed-request uploads: " . (int)$result["closed_deleted"] . PHP_EOL;
if (!empty($result["errors"])) {
  fwrite(STDERR, "Failed upload tokens: " . implode(", ", $result["errors"]) . PHP_EOL);
  exit(1);
}
