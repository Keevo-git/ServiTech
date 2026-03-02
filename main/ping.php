<?php
require_once __DIR__ . "/db.php";

header("Content-Type: text/plain; charset=utf-8");

echo "PING OK\n";
echo "PHP is running\n";

try {
  $stmt = $pdo->query("SELECT now() AS server_time");
  $row = $stmt->fetch();
  echo "DB Connected ✅\n";
  echo "Supabase time: " . ($row["server_time"] ?? "unknown") . "\n";
} catch (Throwable $e) {
  echo "DB Query failed ❌\n";
}