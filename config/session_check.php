<?php
require_once __DIR__ . "/app.php";
require_once __DIR__ . "/supabase_auth.php";
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
$secure = servitech_request_is_https();

session_set_cookie_params([
    "lifetime" => 0,
    "path" => servitech_cookie_path(),
    "httponly" => true,
    "samesite" => "Lax",
    "secure" => $secure,
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (servitech_supabase_auth_enabled()) {
    $hadApplicationSession = !empty($_SESSION["user_id"]);
    if (
        $hadApplicationSession
        && (
            !servitech_supabase_refresh_session_if_needed()
            || empty($_SESSION["supabase_identity_verified"])
        )
    ) {
        servitech_supabase_clear_auth_session();
        servitech_supabase_clear_application_session();
    }

    $idleSetting = servitech_supabase_env("SESSION_IDLE_TIMEOUT_SECONDS", "1800");
    $idleTimeout = ctype_digit($idleSetting) ? (int)$idleSetting : 1800;
    $lastActivityAt = (int)($_SESSION["supabase_last_activity_at"] ?? 0);
    if (
        $idleTimeout > 0
        && !empty($_SESSION["user_id"])
        && $lastActivityAt > 0
        && $lastActivityAt < time() - $idleTimeout
    ) {
        $accessToken = trim((string)($_SESSION["supabase_access_token"] ?? ""));
        if ($accessToken !== "") {
            servitech_supabase_logout_token($accessToken);
        }
        servitech_supabase_clear_auth_session();
        servitech_supabase_clear_application_session();
    } elseif (!empty($_SESSION["user_id"])) {
        $_SESSION["supabase_last_activity_at"] = time();
    }
}

// Password-login remember tokens can rebuild a fresh, short-lived PHP session
// after the browser has discarded its normal session cookie.
if (
    !servitech_supabase_auth_enabled()
    && empty($_SESSION["user_id"])
    && !empty($_COOKIE["SERVITECHREMEMBER"])
) {
    require_once __DIR__ . "/remember_me.php";
    try {
        require_once __DIR__ . "/db.php";
        $rememberPdo = servitech_db_connect_privileged();
        servitech_remember_restore($rememberPdo);
    } catch (Throwable $exception) {
        error_log("Remember-me restore failed: " . $exception->getMessage());
        servitech_remember_clear_cookie();
    }
}

if (!function_exists("servitech_normalize_role")) {
    function servitech_normalize_role($role): string
    {
        $role = strtolower(trim((string)$role));
        return match ($role) {
            "super_admin", "owner" => "super_admin",
            "admin", "employee", "staff" => "admin",
            default => "customer",
        };
    }
}

// Normalize role once per request for consistent access checks.
if (!empty($_SESSION["user_id"]) && (int)$_SESSION["user_id"] > 0) {
    $_SESSION["role"] = servitech_normalize_role($_SESSION["role"] ?? "customer");
} else {
    unset($_SESSION["role"], $_SESSION["admin_logged_in"], $_SESSION["admin_email"]);
}

if (!function_exists("servitech_session_cookie_options")) {
    function servitech_session_cookie_options(int $expires): array
    {
        return [
            "expires" => $expires,
            "path" => servitech_cookie_path(),
            "httponly" => true,
            "samesite" => "Lax",
            "secure" => servitech_request_is_https(),
        ];
    }
}

if (!function_exists("servitech_apply_session_cookie_lifetime")) {
    function servitech_apply_session_cookie_lifetime(?bool $remember = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === "") {
            return;
        }

        $lifetimeEnv = getenv("SESSION_LIFETIME_SECONDS");
        $sessionLifetime = (is_string($lifetimeEnv) && ctype_digit($lifetimeEnv) && (int)$lifetimeEnv > 0)
            ? (int)$lifetimeEnv
            : 60 * 60 * 24 * 30;
        // Supabase refresh credentials live in the server-side PHP session, so
        // that integration retains the persistent session-cookie strategy.
        // Local password auth uses the separate hashed token cookie instead.
        $shouldRemember = servitech_supabase_auth_enabled()
            && ($remember ?? !empty($_SESSION["remember_me"]));
        $expires = $shouldRemember ? (time() + $sessionLifetime) : 0;
        setcookie(session_name(), session_id(), servitech_session_cookie_options($expires));
    }
}

// Supabase-only sliding expiration. Other PHP session cookies stay session-only.
if (session_id() !== "") {
    servitech_apply_session_cookie_lifetime();
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
        return servitech_normalize_role($_SESSION["role"] ?? "customer");
    }
}

if (!function_exists("servitech_is_admin")) {
    function servitech_is_admin(): bool
    {
        return in_array(servitech_current_role(), ["admin", "super_admin"], true);
    }
}

if (!function_exists("servitech_is_super_admin")) {
    function servitech_is_super_admin(): bool
    {
        return servitech_current_role() === "super_admin";
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

if (!function_exists("servitech_role_label")) {
    function servitech_role_label(?string $role = null): string
    {
        return match (servitech_normalize_role($role ?? servitech_current_role())) {
            "super_admin" => "Super Admin",
            "admin" => "Admin",
            "customer" => "Customer",
            default => "Guest",
        };
    }
}

if (!function_exists("servitech_brand_home_url")) {
    function servitech_brand_home_url(): string
    {
        return servitech_url(servitech_brand_home_path());
    }
}
