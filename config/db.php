<?php

ini_set("display_errors", 1);
error_reporting(E_ALL);

$host = "db.gxepuopnghgpnldjrjda.supabase.co";
$port = "5432";
$db   = "postgres";
$user = "postgres";
$pass = "YOUR_NEW_PASSWORD"; // ← your new password

$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "<pre style='color:lime;font-size:16px;'>";

    echo "✅ CONNECTED\n\n";

    // 1. Show database
    $dbName = $pdo->query("SELECT current_database()")->fetchColumn();
    echo "Database: $dbName\n\n";

    // 2. Show all tables
    echo "Tables:\n";
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public'
    ")->fetchAll();
    print_r($tables);

    // 3. Check queues table directly
    echo "\nQueues COUNT:\n";
    $count = $pdo->query("SELECT COUNT(*) FROM queues")->fetchColumn();
    echo $count . "\n\n";

    // 4. Dump actual rows
    echo "Queues DATA:\n";
    $rows = $pdo->query("SELECT * FROM queues")->fetchAll();
    print_r($rows);

    echo "</pre>";

} catch (PDOException $e) {
    echo "<pre style='color:red'>";
    echo "❌ ERROR:\n";
    echo $e->getMessage();
    echo "</pre>";
}