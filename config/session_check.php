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

// Normalize role once per request for consistent access checks.
if (!empty($_SESSION["user_id"]) && (int)$_SESSION["user_id"] > 0) {
    $role = strtolower(trim((string)($_SESSION["role"] ?? "customer")));
    if ($role !== "admin" && $role !== "customer") {
        $role = "customer";
    }
    $_SESSION["role"] = $role;
} else {
    unset($_SESSION["role"], $_SESSION["admin_logged_in"], $_SESSION["admin_email"]);
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

if (!function_exists("servitech_is_logged_in")) {
    function servitech_is_logged_in(): bool
    {
        return !empty($_SESSION["user_id"]) && (int)$_SESSION["user_id"] > 0;
    }
}

if (!function_exists("servitech_current_role")) {
    function servitech_current_role(): string
    {
        if (!servitech_is_logged_in()) {
            return "guest";
        }
        $role = strtolower(trim((string)($_SESSION["role"] ?? "customer")));
        return ($role === "admin") ? "admin" : "customer";
    }
}

if (!function_exists("servitech_is_admin")) {
    function servitech_is_admin(): bool
    {
        return servitech_current_role() === "admin";
    }
}

if (!function_exists("servitech_is_customer")) {
    function servitech_is_customer(): bool
    {
        return servitech_current_role() === "customer";
    }
}

if (!function_exists("servitech_brand_home_path")) {
    function servitech_brand_home_path(): string
    {
        if (!servitech_is_logged_in()) {
            return "/index.php";
        }

        return servitech_is_admin()
            ? "/pages/admin/admin_dashboard.php"
            : "/pages/customer/customer_dash.php";
    }
}

if (!function_exists("servitech_brand_home_url")) {
    function servitech_brand_home_url(): string
    {
        return servitech_url(servitech_brand_home_path());
    }
}
