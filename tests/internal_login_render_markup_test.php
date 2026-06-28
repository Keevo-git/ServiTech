<?php

ini_set("session.save_path", __DIR__ . "/../logs");
session_id("servitechrender");
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["DOCUMENT_ROOT"] = dirname(__DIR__);

require_once __DIR__ . "/../auth/_internal_login_page.php";

function internal_login_render_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function internal_login_render_html(array $config): string
{
    ob_start();
    servitech_render_internal_login_page($config);
    return (string)ob_get_clean();
}

$adminHtml = internal_login_render_html([
    "title" => "Admin Login",
    "subtitle" => "Sign in to manage daily store operations.",
    "badge" => "EMPLOYEE ACCESS",
    "button" => "Log in as Admin",
    "path" => "/auth/admin_login.php",
    "helper_text" => "Use only your assigned ServiTech employee account.",
    "panel_title" => "ServiTech Internal Access",
    "panel_text" => "Employee portal for daily service queues, order handling, and store operations.",
    "menu_id" => "admin-login-header-menu",
]);

$superAdminHtml = internal_login_render_html([
    "title" => "Super Admin Login",
    "subtitle" => "Owner access for ServiTech management.",
    "badge" => "OWNER ACCESS",
    "button" => "Log in as Super Admin",
    "path" => "/auth/super_admin_login.php",
    "helper_text" => "Use only your assigned ServiTech owner account.",
    "panel_title" => "ServiTech Owner Access",
    "panel_text" => "Owner portal for management oversight, staff governance, and system controls.",
    "menu_id" => "super-admin-login-header-menu",
]);

foreach ([
    "Admin" => $adminHtml,
    "Super Admin" => $superAdminHtml,
] as $label => $html) {
    internal_login_render_assert(str_contains($html, "auth-page--internal-login"), "{$label} page must use formal internal login styling.");
    internal_login_render_assert(str_contains($html, "Services Home"), "{$label} page must include Services Home.");
    internal_login_render_assert(str_contains($html, "Customer Login"), "{$label} page must include Customer Login.");
    internal_login_render_assert(str_contains($html, "/auth/log_in.php"), "{$label} page must link to customer login.");
    internal_login_render_assert(!str_contains($html, "Owner Login"), "{$label} page must not expose Owner Login text.");
    internal_login_render_assert(!str_contains($html, "Employee Login"), "{$label} page must not expose Employee Login text.");
}

internal_login_render_assert(!str_contains($adminHtml, "Super Admin Login"), "Admin page must not expose Super Admin Login text.");
internal_login_render_assert(!str_contains($adminHtml, "/auth/super_admin_login.php"), "Admin page must not link to Super Admin login.");
internal_login_render_assert(!str_contains($superAdminHtml, "/auth/admin_login.php"), "Super Admin page must not link to Admin login.");

$renderSessionId = session_id();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$renderSessionFile = rtrim((string)session_save_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "sess_" . $renderSessionId;
if ($renderSessionId !== "" && is_file($renderSessionFile)) {
    @unlink($renderSessionFile);
}

echo "Internal login render markup checks passed.\n";
