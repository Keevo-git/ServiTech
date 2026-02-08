<?php
// /main/includes/session.php
// One single place to control sessions for the whole app.

ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.use_cookies', 1);

// IMPORTANT: avoid collisions with other PHP projects by using a custom session name
session_name("SERVITECHSESSID");

// IMPORTANT: cookie path must match your app folder
// so the browser consistently sends the cookie to /ServiTech/main/*
session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/ServiTech/main/",
    "httponly" => true,
    "samesite" => "Lax"
    // "secure" => true, // enable only if you're using HTTPS
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
