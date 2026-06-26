<?php
require_once __DIR__ . "/_password_login.php";

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_handle_password_login("super_admin");
}

require_once __DIR__ . "/_internal_login_page.php";

servitech_render_internal_login_page([
    "title" => "Super Admin Login",
    "subtitle" => "Owner access for ServiTech management.",
    "badge" => "Owner Access",
    "button" => "Log in as Super Admin",
    "path" => "/auth/super_admin_login.php",
    "other_path" => "/auth/admin_login.php",
    "other_label" => "Admin Login",
    "menu_id" => "super-admin-login-header-menu",
]);
