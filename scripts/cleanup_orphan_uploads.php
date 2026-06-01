<?php
if (PHP_SAPI !== "cli") {
  http_response_code(404);
  exit();
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../api/upload_helpers.php";

$ageHours = isset($argv[1]) ? max(1, (int)$argv[1]) : 24;
$result = servitech_cleanup_orphan_uploads($pdo, $ageHours);
echo "Deleted orphan uploads: " . (int)$result["deleted"] . PHP_EOL;
if (!empty($result["errors"])) {
  fwrite(STDERR, "Failed upload tokens: " . implode(", ", $result["errors"]) . PHP_EOL);
  exit(1);
}
