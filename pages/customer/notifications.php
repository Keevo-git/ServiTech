<?php
require_once __DIR__ . "/../../config/session_check.php";

if (!servitech_is_logged_in()) {
    header("Location: " . servitech_url("/auth/log_in.php"));
    exit();
}

if (servitech_is_admin()) {
    header("Location: " . servitech_url("/pages/admin/admin_notifications.php"));
    exit();
}

// Customer notifications are maintained by the shared header dropdown.
header("Location: " . servitech_url("/pages/customer/customer_dash.php"));
exit();
