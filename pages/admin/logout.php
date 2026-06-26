<?php
// Admin/logout.php
require_once __DIR__ . "/../../config/session_check.php";
require_once __DIR__ . "/_includes/url.php";
require_once __DIR__ . "/../../config/remember_me.php";

$logoutUserId = (int)($_SESSION["user_id"] ?? 0);
if ($logoutUserId > 0) {
    try {
        require_once __DIR__ . "/../../config/db.php";
        require_once __DIR__ . "/../../config/activity_log.php";
        servitech_activity_log(servitech_db_connect_privileged(), [
            "actor_id" => $logoutUserId,
            "action_type" => "logout",
            "module" => "authentication",
            "target_record_id" => (string)$logoutUserId,
            "description" => servitech_role_label() . " logged out from the admin area.",
        ]);
    } catch (Throwable $exception) {
        error_log("Admin logout activity log failed: " . $exception->getMessage());
    }
}

if (!empty($_COOKIE[servitech_remember_cookie_name()])) {
    try {
        require_once __DIR__ . "/../../config/db.php";
        servitech_remember_revoke_current(servitech_db_connect_privileged());
    } catch (Throwable $exception) {
        error_log("Remember-me admin logout cleanup failed: " . $exception->getMessage());
        servitech_remember_clear_cookie();
    }
} else {
    servitech_remember_clear_cookie();
}

$supabaseAccessToken = trim((string)($_SESSION["supabase_access_token"] ?? ""));
if ($supabaseAccessToken !== "") {
    servitech_supabase_logout_token($supabaseAccessToken);
}
servitech_supabase_clear_auth_session();
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

setcookie(session_name(), "", servitech_session_cookie_options(time() - 3600));

header("Location: " . admin_url_raw("/auth/log_in.php?logout=1"));
exit();
