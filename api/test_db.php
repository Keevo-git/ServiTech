<?php
require_once __DIR__ . "/../config/session_check.php";

$debug = (getenv("APP_DEBUG") === "1");
$isAdmin = !empty($_SESSION["user_id"]) && strtolower((string)($_SESSION["role"] ?? "")) === "admin";

if (!$debug || !$isAdmin) {
    http_response_code(404);
    echo "Not Found";
    exit();
}

require_once __DIR__ . "/../config/db.php";
echo "Connected successfully to Supabase.";
