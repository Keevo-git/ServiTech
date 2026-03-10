<?php
require_once __DIR__ . "/../config/session_check.php";

$debug = (getenv("APP_DEBUG") === "1");
$isAdmin = !empty($_SESSION["user_id"]) && strtolower((string)($_SESSION["role"] ?? "")) === "admin";

if (!$debug || !$isAdmin) {
  http_response_code(404);
  echo "Not Found\n";
  exit();
}

require_once __DIR__ . "/../config/db.php";

header("Content-Type: text/plain; charset=utf-8");
echo "PING OK\n";
echo "PHP is running\n";

try {
  $stmt = $pdo->query("SELECT now() AS server_time");
  $row = $stmt->fetch();
  echo "DB connected\n";
  echo "Supabase time: " . ($row["server_time"] ?? "unknown") . "\n";
} catch (Throwable $e) {
  echo "DB query failed\n";
}
