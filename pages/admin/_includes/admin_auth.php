<?php
// Admin/_includes/admin_auth.php
require_once __DIR__ . "/../../../config/session_check.php";
require_once __DIR__ . "/url.php";

if (empty($_SESSION["user_id"]) || strtolower((string)($_SESSION["role"] ?? "")) !== "admin") {
    header("Location: " . admin_url_raw("/auth/log_in.html"));
    exit();
}