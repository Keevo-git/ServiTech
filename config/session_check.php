<?php
require_once __DIR__ . "/app.php";
// session_check.php
$lifetimeEnv = getenv("SESSION_LIFETIME_SECONDS");
$sessionLifetime = (is_string($lifetimeEnv) && ctype_digit($lifetimeEnv) && (int)$lifetimeEnv > 0)
    ? (int)$lifetimeEnv
    : 60 * 60 * 24 * 30; // 30 days default

ini_set("session.use_strict_mode", "1");
ini_set("session.use_only_cookies", "1");
ini_set("session.use_cookies", "1");
ini_set("session.gc_maxlifetime", (string)$sessionLifetime);

session_name("SERVITECHSESSID");
$secure = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");

session_set_cookie_params([
    "lifetime" => $sessionLifetime,
    "path" => servitech_cookie_path(),
    "httponly" => true,
    "samesite" => "Lax",
    "secure" => $secure,
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Sliding expiration: refresh cookie expiry on each request while active.
if (session_id() !== "") {
    setcookie(session_name(), session_id(), [
        "expires" => time() + $sessionLifetime,
        "path" => servitech_cookie_path(),
        "httponly" => true,
        "samesite" => "Lax",
        "secure" => $secure,
    ]);
}

require_once __DIR__ . "/csrf.php";
servitech_csrf_token();
