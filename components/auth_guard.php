<?php
// Central auth gate + session settings
require_once __DIR__ . "/../config/session_check.php";

if (!servitech_is_logged_in()) {
    header("Location: " . servitech_url("/auth/log_in.html"));
    exit();
}

if (servitech_is_admin()) {
    header("Location: " . servitech_url("/pages/admin/admin_dashboard.php"));
    exit();
}
