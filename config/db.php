<?php
require_once __DIR__ . "/supabase_auth.php";

function servitech_db_raw_env_value(string $key): string
{
    $candidates = [
        getenv($key),
        $_ENV[$key] ?? null,
        $_SERVER[$key] ?? null,
    ];

    if (function_exists("apache_getenv")) {
        $candidates[] = apache_getenv($key, true);
        $candidates[] = apache_getenv($key);
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== "") {
            return trim($candidate);
        }
    }

    return "";
}

function servitech_db_parse_dotenv_value(string $value): string
{
    $value = trim($value);
    if ($value === "") {
        return "";
    }

    $quote = $value[0];
    if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
        $value = substr($value, 1, -1);
        return $quote === '"' ? stripcslashes($value) : $value;
    }

    return trim((string)preg_replace('/\s+#.*$/', '', $value));
}

function servitech_db_load_dotenv(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    foreach ([dirname(__DIR__) . "/.env", __DIR__ . "/.env"] as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === "" || $line[0] === "#" || $line[0] === ";") {
                continue;
            }

            if (strpos($line, "export ") === 0) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, "=");
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            if (servitech_db_raw_env_value($key) !== "") {
                continue;
            }

            $value = servitech_db_parse_dotenv_value(substr($line, $separator + 1));
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            @putenv($key . "=" . $value);
        }
    }
}

function servitech_db_local_config(): array
{
    $path = __DIR__ . "/db.local.php";
    if (!is_file($path)) {
        return [];
    }

    $config = require $path;
    return is_array($config) ? $config : [];
}

function servitech_db_config_value(string $envKey, string $localKey, string $default = ""): string
{
    $envValue = servitech_db_raw_env_value($envKey);
    if ($envValue !== "") {
        return $envValue;
    }

    $localConfig = servitech_db_local_config();
    $localValue = trim((string)($localConfig[$localKey] ?? ""));
    return $localValue !== "" ? $localValue : $default;
}

servitech_db_load_dotenv();

$debug = servitech_db_raw_env_value("APP_DEBUG") === "1";
ini_set("display_errors", $debug ? "1" : "0");
ini_set("display_startup_errors", $debug ? "1" : "0");
error_reporting(E_ALL);

$host = servitech_db_config_value("SUPABASE_DB_HOST", "host");
$port = servitech_db_config_value("SUPABASE_DB_PORT", "port", "5432");
$db = servitech_db_config_value("SUPABASE_DB_NAME", "dbname", "postgres");
$user = servitech_db_config_value("SUPABASE_DB_USER", "user");
$pass = servitech_db_config_value("SUPABASE_DB_PASS", "pass");
$sslmode = servitech_db_config_value("SUPABASE_DB_SSLMODE", "sslmode", "require");

if ($host === "" || $user === "" || $pass === "") {
    throw new RuntimeException("Database configuration is incomplete. Set the SUPABASE_DB_* environment variables.");
}

function servitech_db_connect(bool $applyRlsContext = true): PDO
{
    global $host, $port, $db, $user, $pass, $sslmode;

    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=$sslmode";
    try {
        $connection = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        error_log("DB connection failed: " . $e->getMessage());
        throw new RuntimeException("Database connection failed.");
    }

    $enforceRls = servitech_supabase_env_bool("SERVITECH_DB_ENFORCE_RLS", false);
    if (!$applyRlsContext || !$enforceRls || PHP_SAPI === "cli") {
        return $connection;
    }

    $claims = is_array($_SESSION["supabase_claims"] ?? null)
        ? $_SESSION["supabase_claims"]
        : [];
    $accessToken = trim((string)($_SESSION["supabase_access_token"] ?? ""));
    $authenticated = $accessToken !== ""
        && preg_match('/^[0-9a-f-]{36}$/i', (string)($claims["sub"] ?? ""));
    $databaseRole = $authenticated ? "authenticated" : "anon";
    $claims = $authenticated ? $claims : ["role" => "anon"];
    $claims["role"] = $databaseRole;
    // This claim is added only by the server-side PDO connection. Browser calls
    // made directly with the Supabase anon key do not receive it, allowing RLS
    // to distinguish validated application writes from client-crafted writes.
    $claims["servitech_backend"] = $authenticated;

    $connection->exec("SET ROLE " . $databaseRole);
    $setClaims = $connection->prepare("
        SELECT
          set_config('request.jwt.claims', :claims, false),
          set_config('request.jwt.claim.sub', :subject, false),
          set_config('request.jwt.claim.role', :role, false)
    ");
    $setClaims->execute([
        ":claims" => json_encode($claims, JSON_UNESCAPED_SLASHES),
        ":subject" => (string)($claims["sub"] ?? ""),
        ":role" => $databaseRole,
    ]);
    return $connection;
}

function servitech_db_connect_privileged(): PDO
{
    return servitech_db_connect(false);
}

$pdo = servitech_db_connect(true);
