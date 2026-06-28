<?php
require_once __DIR__ . "/_password_login.php";

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_handle_password_login("super_admin");
}

require_once __DIR__ . "/_internal_login_page.php";

servitech_render_internal_login_page([
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
