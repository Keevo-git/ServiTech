<?php
// Admin/_includes/admin_auth.php
// Protect admin pages and keep admin session separated from customer session.

session_name("SERVITECH_ADMIN");
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/ServiTech/",
    "domain"   => "",
    "secure"   => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION["admin_logged_in"])) {
    header("Location: /ServiTech/Admin/main.php");
    exit();
}
