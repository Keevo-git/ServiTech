<?php
// Admin/_includes/admin_auth.php

if (session_status() === PHP_SESSION_NONE) {
    session_name("SERVITECHADMINSESSID");
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/ServiTech/Admin/",
        "domain"   => "",
        "secure"   => false,
        "httponly" => true,
        "samesite" => "Lax"
    ]);
    session_start();
}

if (empty($_SESSION["admin_logged_in"])) {
    header("Location: /ServiTech/Admin/admin_login.php");
    exit();
}
