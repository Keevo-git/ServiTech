<?php
// Admin/logout.php
session_name("SERVITECH_ADMIN");
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/ServiTech/",
    "domain"   => "",
    "secure"   => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

if (session_status() === PHP_SESSION_NONE) session_start();

$_SESSION = [];
session_destroy();

header("Location: /ServiTech/Admin/main.php");
exit();
