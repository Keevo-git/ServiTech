<?php

function internal_login_split_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function internal_login_split_source(string $path): string
{
    return file_get_contents(__DIR__ . "/../" . $path) ?: "";
}

$passwordHelper = internal_login_split_source("auth/_password_login.php");
$internalPage = internal_login_split_source("auth/_internal_login_page.php");
$customerLoginPost = internal_login_split_source("auth/login.php");
$customerLoginPage = internal_login_split_source("auth/log_in.php");
$superAdminLogin = internal_login_split_source("auth/super_admin_login.php");
$adminLogin = internal_login_split_source("auth/admin_login.php");
$adminShim = internal_login_split_source("pages/admin/admin_login.php");
$shortcuts = internal_login_split_source("assets/js/internal-login-shortcuts.js");
$landing = internal_login_split_source("index.php");
$authShared = internal_login_split_source("auth/_shared.php");
$googleLogin = internal_login_split_source("auth/google_login.php");

internal_login_split_assert(str_contains($superAdminLogin, 'servitech_handle_password_login("super_admin")'), "Super Admin login route must hardcode the super_admin context server-side.");
internal_login_split_assert(str_contains($adminLogin, 'servitech_handle_password_login("admin")'), "Admin login route must hardcode the admin context server-side.");
internal_login_split_assert(str_contains($customerLoginPost, 'servitech_handle_password_login("customer")'), "Customer login POST must be customer-scoped.");

foreach ([
    "Super Admin Login" => $superAdminLogin,
    "Owner access for ServiTech management." => $superAdminLogin,
    "Owner Access" => $superAdminLogin,
    "Log in as Super Admin" => $superAdminLogin,
    "Admin Login" => $adminLogin,
    "Employee access for daily store operations." => $adminLogin,
    "Employee Access" => $adminLogin,
    "Log in as Admin" => $adminLogin,
] as $label => $source) {
    internal_login_split_assert(str_contains($source, $label), "Internal login page must include label: {$label}");
}

foreach ([
    '"allowed_roles" => ["super_admin"]',
    '"allowed_roles" => ["admin"]',
    '"allowed_roles" => ["customer"]',
    '"wrong_role_code" => "wrong_role_super_admin"',
    '"wrong_role_code" => "wrong_role_admin"',
    '"wrong_role_code" => "wrong_role_customer"',
    "servitech_password_login_clear_session",
    "servitech_supabase_clear_auth_session",
    "servitech_supabase_clear_application_session",
    "servitech_supabase_admin_mfa_required()",
    "servitech_internal_dashboard_path(\$role)",
    "super_admin_login_success",
    "admin_login_success",
    "customer_login_success",
    "super_admin_wrong_role_login",
    "admin_wrong_role_login",
    "customer_wrong_role_login",
    "employee_login_before_email_verification",
    "employee_email_verified",
] as $requiredHelperText) {
    internal_login_split_assert(str_contains($passwordHelper, $requiredHelperText), "Shared password login helper must include {$requiredHelperText}.");
}

internal_login_split_assert(str_contains($adminShim, "/auth/admin_login.php"), "Legacy pages/admin/admin_login.php must redirect guests to the new Admin login page.");
internal_login_split_assert(str_contains($internalPage, "window.servitechToast"), "Internal login pages must use toast feedback.");
internal_login_split_assert(str_contains($internalPage, "Please verify your email before logging in."), "Internal login pages must show the employee verification message.");
internal_login_split_assert(str_contains($customerLoginPage, "window.servitechToast"), "Customer login feedback must use toast notifications.");
internal_login_split_assert(str_contains($authShared, "render_auth_toast_assets"), "Auth shared helpers must expose toast assets.");
internal_login_split_assert(str_contains($authShared, "internal-login-shortcuts.js"), "Auth shared footer must load internal login shortcuts.");

foreach ([
    '"/auth/super_admin_login.php"',
    '"/auth/admin_login.php"',
    "event.ctrlKey",
    "event.altKey",
    'key === "s"',
    'key === "a"',
    'input, textarea, select, button',
    "contenteditable",
] as $requiredShortcutText) {
    internal_login_split_assert(str_contains($shortcuts, $requiredShortcutText), "Shortcut script must include {$requiredShortcutText}.");
}

internal_login_split_assert(str_contains($landing, "internal-login-shortcuts.js"), "Landing page must load internal login shortcuts.");
internal_login_split_assert(str_contains($googleLogin, "customer_wrong_role_login"), "Customer Google login must block internal account roles.");
internal_login_split_assert(str_contains($googleLogin, "Internal accounts must use the correct Super Admin or Admin login page."), "Customer Google wrong-role response must be explicit.");

echo "Internal login split checks passed.\n";
