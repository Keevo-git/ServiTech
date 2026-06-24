<?php
// Admin/_includes/admin_auth.php
require_once __DIR__ . "/../../../config/session_check.php";
require_once __DIR__ . "/url.php";

if (!servitech_is_logged_in()) {
    header("Location: " . admin_url_raw("/auth/log_in.php"));
    exit();
}

if (servitech_supabase_auth_enabled()) {
    require_once __DIR__ . "/../../../config/db.php";
    if (!servitech_supabase_rebind_application_profile(
        $pdo,
        false,
        servitech_supabase_profile_rebind_seconds()
    )) {
        servitech_supabase_clear_auth_session();
        servitech_supabase_clear_application_session();
        header("Location: " . admin_url_raw("/auth/log_in.php?login=session_expired"));
        exit();
    }
}

header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

if (!servitech_is_admin()) {
    header("Location: " . admin_url_raw("/pages/customer/customer_dash.php"));
    exit();
}

if (
    servitech_supabase_auth_enabled()
    && servitech_supabase_admin_mfa_required()
    && servitech_supabase_session_aal() !== "aal2"
) {
    header("Location: " . admin_url_raw("/auth/mfa.php"));
    exit();
}
