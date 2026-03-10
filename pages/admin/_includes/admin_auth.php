<?php
// Admin/_includes/admin_auth.php
require_once __DIR__ . "/../../../config/session_check.php";
require_once __DIR__ . "/url.php";

if (!servitech_is_logged_in()) {
    header("Location: " . admin_url_raw("/auth/log_in.html"));
    exit();
}

if (!servitech_is_admin()) {
    header("Location: " . admin_url_raw("/pages/customer/customer_dash.php"));
    exit();
}
