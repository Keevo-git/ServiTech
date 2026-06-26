<?php
require_once __DIR__ . "/_password_login.php";

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_handle_password_login("admin");
}

require_once __DIR__ . "/_internal_login_page.php";

servitech_render_internal_login_page([
    "title" => "Admin Login",
    "subtitle" => "Employee access for daily store operations.",
    "badge" => "Employee Access",
    "button" => "Log in as Admin",
    "path" => "/auth/admin_login.php",
    "other_path" => "/auth/super_admin_login.php",
    "other_label" => "Super Admin Login",
    "menu_id" => "admin-login-header-menu",
]);
