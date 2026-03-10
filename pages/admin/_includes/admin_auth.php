<?php
// Admin/_includes/admin_auth.php
require_once __DIR__ . "/../../../config/session_check.php";

if (empty($_SESSION["user_id"]) || strtolower((string)($_SESSION["role"] ?? "")) !== "admin") {
    header("Location: /auth/log_in.html");
    exit();
}

