<?php
// Admin login route is centralized in /auth/log_in.html.
// Keep this file as a compatibility redirect.
require_once __DIR__ . "/../../config/session_check.php";

if (!empty($_SESSION["user_id"]) && strtolower((string)($_SESSION["role"] ?? "")) === "admin") {
    header("Location: /pages/admin/admin_dashboard.php");
    exit();
}

header("Location: /auth/log_in.html");
exit();

