<?php
// 🔥 FULL DEBUG DB FILE — TEMP USE ONLY

ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);

// ✅ REPLACE ONLY THIS PASSWORD
$host = "db.gxepuopnghgpnldjrjda.supabase.co";
$port = "5432";
$db   = "postgres";
$user = "postgres";
$pass = "REMOVED_DB_PASSWORD"; // 🔴 PUT YOUR NEW PASSWORD HERE

$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "<pre style='background:#111;color:#0f0;padding:15px;font-size:14px;'>";

    echo "✅ CONNECTED TO DATABASE\n\n";

    // 1️⃣ Show current database
    $dbName = $pdo->query("SELECT current_database()")->fetchColumn();
    echo "📦 Database Name: $dbName\n\n";

    // 2️⃣ Show current user
    $dbUser = $pdo->query("SELECT current_user")->fetchColumn();
    echo "👤 Connected User: $dbUser\n\n";

    // 3️⃣ List all tables
    echo "📋 Tables in PUBLIC schema:\n";
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public'
    ")->fetchAll();

    if (!$tables) {
        echo "❌ NO TABLES FOUND\n";
    } else {
        print_r($tables);
    }

    // 4️⃣ Check if queues table exists
    echo "\n🔍 Checking 'queues' table...\n";

    $check = $pdo->query("
        SELECT COUNT(*) 
        FROM information_schema.tables 
        WHERE table_name = 'queues'
    ")->fetchColumn();

    if ($check == 0) {
        echo "❌ 'queues' table DOES NOT EXIST\n";
    } else {
        echo "✅ 'queues' table EXISTS\n";

        // 5️⃣ Count rows
        $count = $pdo->query("SELECT COUNT(*) FROM queues")->fetchColumn();
        echo "\n📊 Queues COUNT: $count\n";

        // 6️⃣ Show actual data
        echo "\n📦 Queues DATA:\n";
        $rows = $pdo->query("SELECT * FROM queues")->fetchAll();

        if (!$rows) {
            echo "❌ NO DATA IN TABLE\n";
        } else {
            print_r($rows);
        }
    }

    echo "\n🎯 DEBUG COMPLETE\n";
    echo "</pre>";

} catch (PDOException $e) {
    echo "<pre style='background:#111;color:red;padding:15px;font-size:14px;'>";
    echo "❌ CONNECTION FAILED\n\n";
    echo $e->getMessage();
    echo "</pre>";
}