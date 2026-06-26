<?php
// Admin/_includes/admin_auth.php
require_once __DIR__ . "/../../../config/session_check.php";
require_once __DIR__ . "/url.php";
require_once __DIR__ . "/../../../config/employee_setup.php";

if (!servitech_is_logged_in()) {
    header("Location: " . admin_url_raw("/auth/log_in.php"));
    exit();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . "/../../../config/db.php";
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

$adminAuthRequestPath = (string)(parse_url((string)($_SERVER["REQUEST_URI"] ?? ""), PHP_URL_PATH) ?: "");
$adminAuthAllowsPendingSetup = str_ends_with($adminAuthRequestPath, "/pages/admin/admin_first_time_setup.php")
    || str_ends_with($adminAuthRequestPath, "/pages/admin/logout.php");
if (
    servitech_current_role() === "admin"
    && !$adminAuthAllowsPendingSetup
    && ($pdo ?? null) instanceof PDO
    && servitech_employee_setup_required($pdo)
) {
    try {
        require_once __DIR__ . "/../../../config/activity_log.php";
        servitech_activity_log($pdo, [
            "actor_id" => (int)($_SESSION["user_id"] ?? 0),
            "role" => "admin",
            "action_type" => "employee_pending_setup_access_denied",
            "module" => "employee_setup",
            "target_record_id" => $adminAuthRequestPath,
            "new_value" => ["requested_url" => (string)($_SERVER["REQUEST_URI"] ?? "")],
            "description" => "Employee attempted to access an admin page before completing first-time setup.",
            "status" => "failed",
        ]);
    } catch (Throwable $exception) {
        error_log("employee pending setup access log failed: " . $exception->getMessage());
    }
    servitech_admin_flash_toast("Complete your employee account setup before accessing the Admin Dashboard.", "warning");
    header("Location: " . admin_url_raw(servitech_employee_setup_path()));
    exit();
}

if (!function_exists("servitech_require_super_admin")) {
    function servitech_require_super_admin(): void
    {
        if (servitech_is_super_admin()) {
            return;
        }

        try {
            require_once __DIR__ . "/../../../config/db.php";
            require_once __DIR__ . "/../../../config/activity_log.php";
            $target = (string)($_SERVER["REQUEST_URI"] ?? "");
            servitech_activity_log(servitech_db_connect_privileged(), [
                "actor_id" => (int)($_SESSION["user_id"] ?? 0),
                "role" => servitech_current_role(),
                "action_type" => "unauthorized_access",
                "module" => "super_admin_access",
                "target_record_id" => $target,
                "new_value" => ["requested_url" => $target],
                "description" => "Admin attempted to access a Super Admin-only page and was denied.",
                "status" => "failed",
            ]);
        } catch (Throwable $exception) {
            error_log("super admin access-denied activity log failed: " . $exception->getMessage());
        }

        header("Location: " . admin_url_raw("/pages/admin/access_denied.php"));
        exit();
    }
}

if (!function_exists("servitech_require_admin_role")) {
    function servitech_require_admin_role(array $allowedRoles): void
    {
        $allowedRoles = array_map("servitech_normalize_role", $allowedRoles);
        if (in_array(servitech_current_role(), $allowedRoles, true)) {
            return;
        }

        header("Location: " . admin_url_raw("/pages/admin/access_denied.php"));
        exit();
    }
}
