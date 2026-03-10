<?php
// Admin auth is handled by /auth/login.php.
require_once __DIR__ . "/../../config/session_check.php";

if (!empty($_SESSION["user_id"]) && strtolower((string)($_SESSION["role"] ?? "")) === "admin") {
    header("Location: " . servitech_url("/pages/admin/admin_dashboard.php"));
    exit();
}

header("Location: " . servitech_url("/auth/log_in.html"));
exit();
