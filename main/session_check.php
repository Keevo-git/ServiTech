<?php
// session_check.php
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.use_cookies', 1);

session_name("SERVITECHSESSID");

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/ServiTech/main/",
    "httponly" => true,
    "samesite" => "Lax",
    // "secure" => true, // enable only if HTTPS
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}