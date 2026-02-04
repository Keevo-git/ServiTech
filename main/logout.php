<?php
// /main/logout.php

session_name("SERVITECHSESSID");
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/ServiTech/main/",
    "domain"   => "",
    "secure"   => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];
session_destroy();

header("Location: /ServiTech/main/log_in.html?logout=1");
exit();
