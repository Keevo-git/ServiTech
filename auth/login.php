<?php
require_once __DIR__ . "/guest_guard.php";
servitech_redirect_authenticated_user();

require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/account.php";
require_once __DIR__ . "/../config/remember_me.php";
require_once __DIR__ . "/registration_notifications.php";

servitech_enforce_same_origin(false);
servitech_enforce_csrf_token(false);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . servitech_url("/auth/log_in.php"));
    exit();
}

$email = strtolower(trim($_POST["email"] ?? ""));
$password = (string)($_POST["password"] ?? "");
$rememberMe = isset($_POST["remember_me"]) && (string)$_POST["remember_me"] === "1";

function servitech_login_failure_redirect(string $code, bool $rememberMe): void
{
    $_SESSION["login_remember_retry"] = $rememberMe;
    header("Location: " . servitech_url("/auth/log_in.php?login=" . rawurlencode($code)));
    exit();
}

function servitech_apply_password_login_persistence(PDO $pdo, int $userId, bool $rememberMe): void
{
    unset($_SESSION["login_remember_retry"], $_SESSION["remember_selector"]);
    $_SESSION["remember_me"] = $rememberMe;

    if (servitech_supabase_auth_enabled()) {
        // The Supabase refresh token remains in PHP's server-side session
        // storage; only its random session ID is persisted in the browser.
        servitech_remember_clear_cookie();
        servitech_apply_session_cookie_lifetime($rememberMe);
        return;
    }

    // Local password auth keeps PHPSESSID session-only and uses a separate,
    // revocable selector/validator token for browser-restart persistence.
    try {
        if ($rememberMe) {
            servitech_remember_issue($pdo, $userId);
        } else {
            servitech_remember_revoke_current($pdo);
        }
    } catch (Throwable $exception) {
        error_log("Remember-me setup failed: " . $exception->getMessage());
        $_SESSION["remember_me"] = false;
        servitech_remember_clear_cookie();
    }
    servitech_apply_session_cookie_lifetime(false);
}

if ($email === "" || $password === "") {
    servitech_login_failure_redirect("required", $rememberMe);
}

if (servitech_supabase_auth_enabled()) {
    $privilegedPdo = null;
    try {
        if (!servitech_supabase_auth_configured()) {
            throw new RuntimeException("Supabase Auth is enabled but not configured.");
        }
        $privilegedPdo = servitech_db_connect_privileged();

        if (!servitech_login_throttle_allows($privilegedPdo, $email)) {
            servitech_login_failure_redirect("throttled", $rememberMe);
        }

        // Supabase is the sole password verifier in Auth mode. Legacy public.users
        // credentials are never read, compared, or copied into auth.users here.
        $authResponse = servitech_supabase_sign_in($email, $password);

        servitech_login_throttle_clear($privilegedPdo, $email);
        $profile = servitech_supabase_complete_login($privilegedPdo, $authResponse, "password");
        if (($profile["role"] ?? "customer") !== "admin") {
            servitech_notify_admin_new_customer(
                $privilegedPdo,
                (int)($profile["id"] ?? 0),
                (string)($profile["fullname"] ?? ""),
                (string)($profile["email"] ?? $email)
            );
        }
        servitech_apply_password_login_persistence(
            $privilegedPdo,
            (int)($profile["id"] ?? 0),
            $rememberMe
        );
        header("Location: " . servitech_url(
            ($profile["role"] ?? "customer") === "admin"
                ? (servitech_supabase_admin_mfa_required()
                    && servitech_supabase_session_aal() !== "aal2"
                    ? "/auth/mfa.php"
                    : "/pages/admin/admin_dashboard.php")
                : "/pages/customer/customer_dash.php"
        ));
        exit();
    } catch (DomainException $e) {
        error_log("Supabase login rejected: " . $e->getMessage());
        servitech_supabase_clear_auth_session();
        servitech_supabase_clear_application_session();
        if (servitech_supabase_error_requires_email_verification($e->getMessage())) {
            $_SESSION["verification_email_hint"] = $email;
            if ($privilegedPdo instanceof PDO) {
                try {
                    servitech_login_throttle_clear($privilegedPdo, $email);
                } catch (Throwable $ignored) {
                }
            }
            servitech_login_failure_redirect("verify_email", $rememberMe);
        }
        if ($privilegedPdo instanceof PDO) {
            try {
                servitech_login_throttle_record_failure($privilegedPdo, $email);
            } catch (Throwable $ignored) {
            }
        }
        servitech_login_failure_redirect("fail", $rememberMe);
    } catch (Throwable $e) {
        error_log("Supabase login error: " . $e->getMessage());
        servitech_supabase_clear_auth_session();
        servitech_supabase_clear_application_session();
        if ($privilegedPdo instanceof PDO) {
            try {
                servitech_login_throttle_record_failure($privilegedPdo, $email);
            } catch (Throwable $ignored) {
            }
        }
        servitech_login_failure_redirect("fail", $rememberMe);
    }
}

try {
    if (!servitech_login_throttle_allows($pdo, $email)) {
        servitech_login_failure_redirect("throttled", $rememberMe);
    }

    $stmt = $pdo->prepare("
        SELECT id, email,
               COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer') AS role,
               COALESCE(NULLIF(to_jsonb(users)->>'google_id', ''), '') AS google_id,
               COALESCE(NULLIF(to_jsonb(users)->>'email_verified_at', ''), '') AS email_verified_at,
               NULLIF(to_jsonb(users)->>'password_hash', '') AS auth_hash
        FROM users
        WHERE LOWER(email) = LOWER(:email)
        LIMIT 1
    ");
    $stmt->execute([":email" => $email]);
    $user = $stmt->fetch();

    $storedHash = (string)($user["auth_hash"] ?? "");
    $is_valid = false;
    $rehashNeeded = false;
    if ($user && $storedHash !== "") {
        $hashInfo = password_get_info($storedHash);
        $isRealHash = (int)($hashInfo["algo"] ?? 0) !== 0;

        if ($isRealHash) {
            $is_valid = password_verify($password, $storedHash);
            $rehashNeeded = $is_valid && password_needs_rehash($storedHash, PASSWORD_DEFAULT);
        }
    }

    if ($user && $is_valid) {
        servitech_login_throttle_clear($pdo, $email);

        if (
            servitech_account_email_verification_required()
            && trim((string)($user["email_verified_at"] ?? "")) === ""
        ) {
            servitech_login_failure_redirect("verify_email", $rememberMe);
        }
    }

    if ($is_valid && $rehashNeeded && isset($user["id"])) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $rehash = $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
        $rehash->execute([
            ":hash" => $newHash,
            ":id" => (int)$user["id"],
        ]);
    }

    if ($user && $is_valid) {
        session_regenerate_id(true);
        $_SESSION["user_id"] = (int)$user["id"];
        $_SESSION["role"] = strtolower((string)($user["role"] ?? "customer"));
        servitech_apply_password_login_persistence(
            $pdo,
            (int)$user["id"],
            $rememberMe
        );

        if ($_SESSION["role"] === "admin") {
            $_SESSION["admin_logged_in"] = true;
            $_SESSION["admin_email"] = (string)($user["email"] ?? $email);
            header("Location: " . servitech_url("/pages/admin/admin_dashboard.php"));
            exit();
        }

        unset($_SESSION["admin_logged_in"], $_SESSION["admin_email"]);
        header("Location: " . servitech_url("/pages/customer/customer_dash.php"));
        exit();
    }

    servitech_login_throttle_record_failure($pdo, $email);

    $googleId = trim((string)($user["google_id"] ?? ""));
    if ($user && $googleId !== "" && $storedHash === "") {
        servitech_login_failure_redirect("google_required", $rememberMe);
    }

    servitech_login_failure_redirect("fail", $rememberMe);
} catch (Throwable $e) {
    error_log("login error: " . $e->getMessage());
    servitech_login_failure_redirect("fail", $rememberMe);
}
