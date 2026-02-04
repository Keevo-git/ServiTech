<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "STEP 1: PHP is running ✅<br>";

require __DIR__ . "/db.php";

echo "STEP 2: db.php loaded ✅<br>";

try {
    $stmt = $pdo->query("SELECT 1");
    echo "STEP 3: Database connected ✅";
} catch (Throwable $e) {
    echo "STEP 3: Database failed ❌<br>";
    echo "ERROR: " . $e->getMessage();
}
