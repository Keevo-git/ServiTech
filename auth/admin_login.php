<?php
require_once __DIR__ . "/_password_login.php";

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_handle_password_login("admin");
}

require_once __DIR__ . "/_internal_login_page.php";

servitech_render_internal_login_page([
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
