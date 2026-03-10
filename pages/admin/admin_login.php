<?php
// Admin login route is centralized in /auth/log_in.html.
// Keep this file as a compatibility redirect.
require_once __DIR__ . "/../../config/session_check.php";
require_once __DIR__ . "/_includes/url.php";

if (!empty($_SESSION["user_id"]) && strtolower((string)($_SESSION["role"] ?? "")) === "admin") {
    header("Location: " . admin_url_raw("/pages/admin/admin_dashboard.php"));
    exit();
}

header("Location: " . admin_url_raw("/auth/log_in.html"));
exit();