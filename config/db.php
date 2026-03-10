<?php
// db.php (Supabase Postgres via PDO)

$debug = getenv("APP_DEBUG") === "1";
ini_set("display_errors", $debug ? "1" : "0");
ini_set("display_startup_errors", $debug ? "1" : "0");
error_reporting($debug ? E_ALL : 0);

$local = [];
$localFile = __DIR__ . "/db.local.php";
if (is_file($localFile)) {
    $tmp = require $localFile;
    if (is_array($tmp)) {
        $local = $tmp;
    }
}

$host = getenv("SUPABASE_DB_HOST") ?: ($local["host"] ?? "");
$port = getenv("SUPABASE_DB_PORT") ?: ($local["port"] ?? "5432");
$db   = getenv("SUPABASE_DB_NAME") ?: ($local["dbname"] ?? "postgres");
$user = getenv("SUPABASE_DB_USER") ?: ($local["user"] ?? "");
$pass = getenv("SUPABASE_DB_PASS") ?: ($local["pass"] ?? "");

if ($host === "" || $user === "" || $pass === "") {
    error_log("DB CONFIG ERROR: Missing SUPABASE_DB_HOST / SUPABASE_DB_USER / SUPABASE_DB_PASS");
    http_response_code(500);
    die("DB ERROR: configuration missing.");
}

$dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    error_log("DB CONNECTION ERROR: " . $e->getMessage());
    http_response_code(500);
    die("DB ERROR: could not connect.");
}
