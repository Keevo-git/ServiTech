<?php

function internal_shortcuts_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function internal_shortcuts_source(string $path): string
{
    return file_get_contents(__DIR__ . "/../" . $path) ?: "";
}

$shortcutScript = internal_shortcuts_source("assets/js/internal-login-shortcuts.js");
$landing = internal_shortcuts_source("index.php");
$customerLogin = internal_shortcuts_source("auth/log_in.php");
$register = internal_shortcuts_source("auth/regis.php");
$internalLoginTemplate = internal_shortcuts_source("auth/_internal_login_page.php");
$publicFooter = internal_shortcuts_source("components/footer.php");
$authShared = internal_shortcuts_source("auth/_shared.php");
$adminLogin = internal_shortcuts_source("auth/admin_login.php");
$superAdminLogin = internal_shortcuts_source("auth/super_admin_login.php");
$adminDashboard = internal_shortcuts_source("pages/admin/admin_dashboard.php");
$superAdminDashboard = internal_shortcuts_source("pages/super_admin/super_admin_dashboard.php");
$customerDashboard = internal_shortcuts_source("pages/customer/customer_dash.php");

internal_shortcuts_assert(!str_contains($shortcutScript, "keypress"), "Internal login shortcuts must not use deprecated keypress.");
internal_shortcuts_assert(str_contains($shortcutScript, 'getAttribute("data-page")'), "Internal login shortcuts must inspect the body page marker.");
internal_shortcuts_assert(str_contains($shortcutScript, 'page !== "public-login"'), "Internal login shortcuts must no-op outside approved public login pages.");
internal_shortcuts_assert(str_contains($shortcutScript, "/auth/admin_login.php"), "Admin shortcut must route only to Admin Login.");
internal_shortcuts_assert(str_contains($shortcutScript, "/auth/super_admin_login.php"), "Super Admin shortcut must route only to Super Admin Login.");
internal_shortcuts_assert(str_contains($shortcutScript, 'key === "a"'), "Admin shortcut key must remain wired.");
internal_shortcuts_assert(str_contains($shortcutScript, 'key === "s"'), "Super Admin shortcut key must remain wired.");
internal_shortcuts_assert(str_contains($shortcutScript, "event.repeat"), "Internal login shortcuts must ignore held-key repeats.");
internal_shortcuts_assert(str_contains($shortcutScript, "isEditableTarget"), "Internal login shortcuts must ignore editable targets.");
internal_shortcuts_assert(str_contains($shortcutScript, "[contenteditable]"), "Internal login shortcuts must ignore contenteditable targets.");
internal_shortcuts_assert(str_contains($shortcutScript, "AltGraph"), "Internal login shortcuts must avoid AltGraph conflicts.");

internal_shortcuts_assert(str_contains($landing, 'data-page="public-login"') && str_contains($landing, '!$is_logged_in'), "Landing page shortcut marker must be guest-only.");
internal_shortcuts_assert(str_contains($customerLogin, 'data-page="public-login"'), "Customer login page must opt into public login shortcuts.");
internal_shortcuts_assert(!str_contains($register, 'data-page="public-login"'), "Registration page must not opt into internal login shortcuts.");
internal_shortcuts_assert(!str_contains($internalLoginTemplate, 'data-page="public-login"'), "Internal login pages must not opt into cross-login shortcuts.");
internal_shortcuts_assert(!str_contains($adminDashboard, "internal-login-shortcuts.js"), "Admin dashboard must not load public internal-login shortcuts directly.");
internal_shortcuts_assert(!str_contains($superAdminDashboard, "internal-login-shortcuts.js"), "Super Admin dashboard must not load public internal-login shortcuts directly.");
internal_shortcuts_assert(!str_contains($customerDashboard, "internal-login-shortcuts.js"), "Customer dashboard must not load public internal-login shortcuts directly.");

foreach ([$publicFooter, $authShared] as $footerSource) {
    internal_shortcuts_assert(str_contains($footerSource, "Staff Access"), "Footer must include discreet Staff Access fallback.");
    internal_shortcuts_assert(str_contains($footerSource, "/auth/admin_login.php"), "Staff Access footer link must route to Admin Login.");
    internal_shortcuts_assert(str_contains($footerSource, "Owner Access"), "Footer must include discreet Owner Access fallback.");
    internal_shortcuts_assert(str_contains($footerSource, "/auth/super_admin_login.php"), "Owner Access footer link must route to Super Admin Login.");
    internal_shortcuts_assert(!str_contains($footerSource, "Ctrl + Alt"), "Footer must not expose shortcut combinations.");
}

internal_shortcuts_assert(str_contains($adminLogin, 'servitech_handle_password_login("admin")'), "Admin Login must continue validating admin accounts only.");
internal_shortcuts_assert(str_contains($superAdminLogin, 'servitech_handle_password_login("super_admin")'), "Super Admin Login must continue validating super_admin accounts only.");

echo "PASS: internal shortcuts and footer access checks passed.\n";
