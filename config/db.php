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

function env_first(array $keys): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && trim((string)$value) !== "") {
            return trim((string)$value);
        }
    }
    return "";
}

$databaseUrl = env_first(["SUPABASE_DB_URL", "DATABASE_URL"]);

$host = env_first(["SUPABASE_DB_HOST", "DB_HOST"]);
$port = env_first(["SUPABASE_DB_PORT", "DB_PORT"]);
$db   = env_first(["SUPABASE_DB_NAME", "DB_NAME"]);
$user = env_first(["SUPABASE_DB_USER", "DB_USER", "DB_USERNAME"]);
$pass = env_first(["SUPABASE_DB_PASS", "DB_PASS", "DB_PASSWORD"]);

if ($port === "") {
    $port = (string)($local["port"] ?? "5432");
}

if ($db === "") {
    $db = (string)($local["dbname"] ?? "postgres");
}

if ($host === "") {
    $host = (string)($local["host"] ?? "");
}

if ($user === "") {
    $user = (string)($local["user"] ?? "");
}

if ($pass === "") {
    $pass = (string)($local["pass"] ?? "");
}

if ($databaseUrl !== "") {
    $urlParts = parse_url($databaseUrl);
    if (is_array($urlParts)) {
        $host = $host !== "" ? $host : (string)($urlParts["host"] ?? "");
        $port = $port !== "" ? $port : (string)($urlParts["port"] ?? "5432");
        $db = $db !== "" ? $db : ltrim((string)($urlParts["path"] ?? "postgres"), "/");
        $user = $user !== "" ? $user : (string)($urlParts["user"] ?? "");
        $pass = $pass !== "" ? $pass : (string)($urlParts["pass"] ?? "");
    }
}

if ($host === "" || $user === "" || $pass === "") {
    error_log("DB CONFIG ERROR: Missing DB credentials. Set SUPABASE_DB_* vars, DATABASE_URL, or config/db.local.php.");
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
