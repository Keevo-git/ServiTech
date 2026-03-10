<?php
// Admin login route is centralized in /auth/log_in.html.
// Keep this file as a compatibility redirect.
require_once __DIR__ . "/../../config/session_check.php";
require_once __DIR__ . "/_includes/url.php";

if (servitech_is_admin()) {
    header("Location: " . admin_url_raw("/pages/admin/admin_dashboard.php"));
    exit();
}

if (servitech_is_customer()) {
    header("Location: " . admin_url_raw("/pages/customer/customer_dash.php"));
    exit();
}

header("Location: " . admin_url_raw("/auth/log_in.html"));
exit();
