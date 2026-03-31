<?php
// db.php — DIRECT Supabase connection (no env issues)

ini_set("display_errors", 1);
error_reporting(E_ALL);

// 🔴 REPLACE THESE WITH YOUR ACTUAL SUPABASE VALUES
$host = "db.gxepuopnghgpqnldrjda.supabase.co"; // ← from Supabase
$port = "5432"; // ← IMPORTANT: must be 5432
$db   = "postgres"; // default for Supabase
$user = "postgres"; // default user
$pass = "QPyoGoOS4VIfOfbk"; // ← from Supabase

$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // ✅ TEMP DEBUG (remove later)
    echo "<pre style='color:lime'>";
    echo "✅ Connected to Supabase\n";

    $test = $pdo->query("SELECT COUNT(*) FROM queues")->fetchColumn();
    echo "Queues count: " . $test . "\n";
    echo "</pre>";

} catch (PDOException $e) {
    echo "<pre style='color:red'>";
    echo "❌ Connection failed:\n";
    echo $e->getMessage();
    echo "</pre>";
    exit;
}