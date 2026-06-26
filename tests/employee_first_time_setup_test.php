<?php

function employee_setup_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function employee_setup_source(string $path): string
{
    return file_get_contents(__DIR__ . "/../" . $path) ?: "";
}

$migration = employee_setup_source("database/migrations/20260626_add_employee_first_time_setup.sql");
$accounts = employee_setup_source("pages/super_admin/super_admin_employee_accounts.php");
$setupPage = employee_setup_source("pages/admin/admin_first_time_setup.php");
$adminAuth = employee_setup_source("pages/admin/_includes/admin_auth.php");
$passwordLogin = employee_setup_source("auth/_password_login.php");
$supabaseAuth = employee_setup_source("config/supabase_auth.php");
$setupHelper = employee_setup_source("config/employee_setup.php");

foreach ([
    "profile_completed",
    "first_login_completed_at",
    "address",
    "emergency_contact_name",
    "emergency_contact_relationship",
    "emergency_contact_address",
    "emergency_contact_number",
    "idx_users_employee_setup_status",
] as $migrationText) {
    employee_setup_assert(str_contains($migration, $migrationText), "Migration must include {$migrationText}.");
}

foreach ([
    "servitech_supabase_admin_configured",
    "servitech_supabase_admin_auth_request",
    "servitech_supabase_admin_create_user",
    "servitech_supabase_admin_update_user",
    "servitech_supabase_admin_confirmation_redirect_url",
    "SUPABASE_SERVICE_ROLE_KEY",
] as $helperText) {
    employee_setup_assert(str_contains($supabaseAuth, $helperText), "Supabase helper must include {$helperText}.");
}

foreach ([
    "Create Employee Account",
    "temporary_password",
    "temporary_password_confirm",
    "Generate Temporary Password",
    "Copy Temporary Password",
    "servitech_supabase_admin_create_user",
    "servitech_supabase_admin_update_user",
    "servitech_supabase_resend_signup",
    "servitech_supabase_admin_confirmation_redirect_url",
    "force_password_change = TRUE",
    "Pending Email Verification",
    "Pending First-Time Setup",
    "employee_password_reset",
    "employee_force_password_change",
    "employee_verification_email_sent",
    "employee_verification_email_failed",
    "SUPABASE_SERVICE_ROLE_KEY",
] as $accountsText) {
    employee_setup_assert(str_contains($accounts, $accountsText), "Employee Accounts page must include {$accountsText}.");
}
employee_setup_assert(str_contains($accounts, "profile_completed") && str_contains($accounts, "'active', TRUE, FALSE"), "Employee Accounts page must create employees with pending profile setup.");
employee_setup_assert(str_contains($accounts, "email_confirm\" => false") || str_contains($accounts, "], false)"), "Employee Accounts must create Supabase Auth employees as unconfirmed pending verification.");
employee_setup_assert(str_contains($accounts, "Employee account created. A verification email has been sent."), "Employee creation must show verification-email success toast.");
employee_setup_assert(str_contains($accounts, "Employee account was created, but the verification email could not be sent. Please check SMTP/Resend settings."), "Employee creation must show specific verification delivery failure toast.");

foreach ([
    "Complete Your Employee Account",
    "Change Password",
    "Contact Details",
    "Emergency Contact",
    "Complete Account Setup",
    "servitech_supabase_update_user",
    "force_password_change = FALSE",
    "profile_completed = TRUE",
    "first_login_completed_at",
    "employee_first_time_setup_complete",
    "employee_first_time_setup_failed",
    "09XXXXXXXXX",
    "+639XXXXXXXXX",
] as $setupText) {
    employee_setup_assert(str_contains($setupPage, $setupText), "First-time setup page must include {$setupText}.");
}

employee_setup_assert(str_contains($setupHelper, "function servitech_employee_setup_required"), "Shared setup helper must expose setup-required check.");
employee_setup_assert(str_contains($setupHelper, "function servitech_employee_setup_path"), "Shared setup helper must expose setup path.");
employee_setup_assert(str_contains($adminAuth, "servitech_employee_setup_required"), "Admin guard must block pending setup employees.");
employee_setup_assert(str_contains($adminAuth, "employee_pending_setup_access_denied"), "Admin guard must log pending setup direct URL attempts.");
employee_setup_assert(str_contains($adminAuth, "admin_first_time_setup.php"), "Admin guard must allow the first-time setup page.");
employee_setup_assert(str_contains($passwordLogin, "servitech_employee_setup_required"), "Password login must route pending employees to first-time setup.");
employee_setup_assert(str_contains($passwordLogin, "employee_first_login"), "Password login must log first-time employee login.");
employee_setup_assert(str_contains($passwordLogin, "employee_login_before_email_verification"), "Password login must log employee attempts before email verification.");
employee_setup_assert(str_contains($passwordLogin, "employee_email_verified"), "Password login must log employee verification detection.");

echo "Employee first-time setup checks passed.\n";
