<?php
// db.php (Supabase Postgres via PDO)

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ✅ Supabase connection (Pooler/Session)
$host = "aws-1-ap-southeast-1.pooler.supabase.com";
$port = "5432";
$db   = "postgres";

// user looks like: postgres.<project-ref>
$user = "postgres.gxepuopnghgpqnldrjda";
$pass = "cb9D9tvfZaJFygPZ";

// If you want to avoid hardcoding, you can use getenv() later.
$dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    // Don't expose full credentials in browser. Log actual error instead.
    error_log("DB CONNECTION ERROR: " . $e->getMessage());
    die("DB ERROR: could not connect.");
}