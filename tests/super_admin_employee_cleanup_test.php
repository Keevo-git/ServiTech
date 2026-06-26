<?php

function super_admin_cleanup_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function super_admin_cleanup_source(string $path): string
{
    return file_get_contents(__DIR__ . "/../" . $path) ?: "";
}

function super_admin_cleanup_between(string $source, string $start, string $end): string
{
    $startPosition = strpos($source, $start);
    if ($startPosition === false) {
        return "";
    }
    $endPosition = strpos($source, $end, $startPosition);
    if ($endPosition === false) {
        return substr($source, $startPosition);
    }
    return substr($source, $startPosition, $endPosition - $startPosition);
}

$dashboard = super_admin_cleanup_source("pages/super_admin/super_admin_dashboard.php");
$adminDashboard = super_admin_cleanup_source("pages/admin/admin_dashboard.php");
$employeeAccounts = super_admin_cleanup_source("pages/super_admin/super_admin_employee_accounts.php");
$employeeLogs = super_admin_cleanup_source("pages/super_admin/super_admin_employee_activity_logs.php");
$oldStaffStub = super_admin_cleanup_source("pages/super_admin/super_admin_staff_accounts.php");
$oldLogsStub = super_admin_cleanup_source("pages/super_admin/super_admin_activity_logs.php");
$adminStaffStub = super_admin_cleanup_source("pages/admin/staff_accounts.php");
$adminLogsStub = super_admin_cleanup_source("pages/admin/activity_logs.php");
$header = super_admin_cleanup_source("pages/admin/_includes/admin_header.php");
$session = super_admin_cleanup_source("config/session_check.php");
$ownerCss = super_admin_cleanup_source("pages/admin/admin_owner.css");
$createForm = super_admin_cleanup_between($employeeAccounts, 'data-create-employee-form', "</form>");

foreach ([
    "Queue Management",
    "Order Management",
    "Customer Management",
    "Employee Accounts",
    "Employee Activity Logs",
    "System Settings",
] as $label) {
    super_admin_cleanup_assert(str_contains($dashboard, $label), "Super Admin dashboard must keep {$label} quick access.");
}

foreach ([
    "Store Availability",
    "Service Management",
    "Announcement",
    "Payment Management",
    "Reports / Analytics",
] as $removedLabel) {
    super_admin_cleanup_assert(!str_contains($dashboard, "<h4>{$removedLabel}</h4>"), "Super Admin quick access must not show {$removedLabel} as a card.");
}

foreach ([$dashboard, $adminDashboard] as $source) {
    super_admin_cleanup_assert(str_contains($source, "LANDING_QUEUEING.png"), "Queue quick access must use the existing queue image asset.");
    super_admin_cleanup_assert(str_contains($source, "LANDING_PRINT-ORD.png"), "Order quick access must use the existing order image asset.");
    super_admin_cleanup_assert(str_contains($source, "icon-image--queue-management"), "Queue quick access icon must use the shared icon image styling.");
    super_admin_cleanup_assert(str_contains($source, "icon-image--order-management"), "Order quick access icon must use the shared icon image styling.");
}

super_admin_cleanup_assert(str_contains($dashboard, "super_admin_employee_accounts.php"), "Super Admin dashboard must link to Employee Accounts.");
super_admin_cleanup_assert(str_contains($dashboard, "super_admin_employee_activity_logs.php"), "Super Admin dashboard must link to Employee Activity Logs.");
super_admin_cleanup_assert(!str_contains($dashboard, "super_admin_staff_accounts.php"), "Dashboard must not link directly to the old Staff Accounts route.");
super_admin_cleanup_assert(!str_contains($dashboard, "super_admin_activity_logs.php"), "Dashboard must not link directly to the old Activity Logs route.");

super_admin_cleanup_assert(str_contains($employeeAccounts, "<title>Employee Accounts | ServiTech Admin</title>"), "Employee Accounts page title must use employee wording.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "<h1>Employee Accounts</h1>"), "Employee Accounts heading must use employee wording.");
super_admin_cleanup_assert(!str_contains($employeeAccounts, "Staff Account"), "Employee Accounts page must not expose Staff Account wording.");
super_admin_cleanup_assert(!str_contains($employeeAccounts, "Staff Accounts"), "Employee Accounts page must not expose Staff Accounts wording.");
super_admin_cleanup_assert(str_contains($employeeLogs, "<title>Employee Activity Logs | ServiTech Admin</title>"), "Employee Activity Logs page title must be explicit.");
super_admin_cleanup_assert(str_contains($employeeLogs, "<h1>Employee Activity Logs</h1>"), "Employee Activity Logs heading must be explicit.");

super_admin_cleanup_assert(str_contains($employeeAccounts, "INNER JOIN auth.users auth_account"), "Employee Accounts must join Supabase Auth users.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "auth_account.id = u.auth_user_id"), "Employee Accounts must join by auth_user_id.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "u.auth_user_id IS NOT NULL"), "Employee Accounts must exclude unlinked profiles.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "LOWER(TRIM(COALESCE(u.role, 'customer'))) = 'admin'"), "Employee Accounts must include only employee admin role rows.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "auth_account.deleted_at IS NULL"), "Employee Accounts must exclude deleted Supabase Auth users.");
super_admin_cleanup_assert(!str_contains($employeeAccounts, "IN ('admin', 'super_admin')"), "Employee Accounts must not list owner accounts beside employees.");
super_admin_cleanup_assert(!str_contains($employeeAccounts, 'value="super_admin"'), "Employee Accounts must not provide a Super Admin role option.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "Temporary Password"), "Employee Accounts must support one-time temporary password input.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "temporary_password_confirm"), "Employee Accounts must require temporary password confirmation.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "servitech_supabase_admin_create_user"), "Employee creation must use Supabase Admin Auth.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "servitech_supabase_admin_update_user"), "Employee password reset must update Supabase Auth securely.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "force_password_change = TRUE"), "Employee creation/reset must force password change.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "profile_completed") && str_contains($employeeAccounts, "'active', TRUE, FALSE"), "New employees must start with pending profile setup.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "servitech_admin_flash_toast"), "Employee Accounts form feedback must use shared toast flash.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "data-open-create-employee-modal"), "Employee creation must be opened from a modal trigger.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "data-create-employee-modal"), "Employee creation modal must exist.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "data-create-employee-form"), "Employee creation form must be inside the modal.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "admin_owner.css?v=20260626-employee-accounts-modal"), "Employee Accounts page must load the cache-busted owner modal CSS.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "admin-owner-panel admin-owner-panel--full"), "Employee Accounts table card must use the full-width owner panel.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "Create an employee login account with a temporary password. The employee will complete their profile during first login."), "Create modal must explain first-login profile setup.");
super_admin_cleanup_assert(!str_contains($employeeAccounts, "<aside class=\"admin-owner-panel\">\n      <h2>Create Employee Account</h2>"), "Create Employee Account form must not render as an always-visible side panel.");
super_admin_cleanup_assert(!str_contains($createForm, 'name="contact"'), "Create Employee Account form must not ask for contact.");
super_admin_cleanup_assert(!str_contains($createForm, 'name="position_title"'), "Create Employee Account form must not ask for position/job title.");
super_admin_cleanup_assert(!str_contains($createForm, 'name="employee_notes"'), "Create Employee Account form must not ask for notes.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "employee_account_assert_create_email_available"), "Employee creation must use role-aware email conflict checks.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "This employee account already exists."), "Duplicate employee toast must be explicit.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "This email is already used by a customer account."), "Customer email conflict toast must be explicit.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "This email is already used by a Super Admin account."), "Super Admin email conflict toast must be explicit.");
super_admin_cleanup_assert(str_contains($employeeAccounts, "An employee profile exists but is not linked to an auth account. Please review or link it manually."), "Unlinked employee profile toast must be explicit.");
super_admin_cleanup_assert(!str_contains($employeeAccounts, "That employee account is already linked."), "Old misleading duplicate toast must be removed.");
super_admin_cleanup_assert(str_contains($ownerCss, ".admin-owner-modal-overlay"), "Owner CSS must include modal overlay styling.");
super_admin_cleanup_assert(str_contains($ownerCss, ".admin-owner-modal"), "Owner CSS must include modal panel styling.");
super_admin_cleanup_assert(str_contains($ownerCss, ".admin-owner-grid--single > *"), "Owner CSS must keep single-column panels full width.");
super_admin_cleanup_assert(str_contains($ownerCss, ".admin-owner-panel--full"), "Owner CSS must include a full-width panel helper.");
super_admin_cleanup_assert(str_contains($ownerCss, ".admin-owner-modal-open"), "Owner CSS must prevent background scroll while modal is open.");

foreach ([
    $oldStaffStub,
    $adminStaffStub,
] as $stub) {
    super_admin_cleanup_assert(str_contains($stub, "super_admin_employee_accounts.php"), "Old employee account route stubs must redirect to the new route.");
}

foreach ([
    $oldLogsStub,
    $adminLogsStub,
] as $stub) {
    super_admin_cleanup_assert(str_contains($stub, "super_admin_employee_activity_logs.php"), "Old activity log route stubs must redirect to the new route.");
}

super_admin_cleanup_assert(str_contains($session, "function servitech_admin_flash_toast"), "Session helper must support admin flash toasts.");
super_admin_cleanup_assert(str_contains($session, "function servitech_consume_admin_flash_toast"), "Session helper must consume admin flash toasts once.");
super_admin_cleanup_assert(str_contains($header, "servitech_consume_admin_flash_toast"), "Shared admin header must render session flash toasts.");
super_admin_cleanup_assert(str_contains($header, "window.servitechAdminToast?.show"), "Shared admin header must use the canonical toast component.");

echo "Super Admin employee cleanup checks passed.\n";
