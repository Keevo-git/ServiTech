<?php
require_once __DIR__ . "/guest_guard.php";
servitech_redirect_authenticated_user();

require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/account.php";

servitech_enforce_same_origin(false);
servitech_enforce_csrf_token(false);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . servitech_url("/auth/log_in.php"));
    exit();
}

$email = strtolower(trim($_POST["email"] ?? ""));
$password = (string)($_POST["password"] ?? "");
$rememberMe = isset($_POST["remember_me"]) && (string)$_POST["remember_me"] === "1";

if ($email === "" || $password === "") {
    header("Location: " . servitech_url("/auth/log_in.php?login=required"));
    exit();
}

if (servitech_supabase_auth_enabled()) {
    $privilegedPdo = null;
    try {
        if (!servitech_supabase_auth_configured(true)) {
            throw new RuntimeException("Supabase Auth or its server-only bridge key is not configured.");
        }
        $privilegedPdo = servitech_db_connect_privileged();

        if (!servitech_login_throttle_allows($privilegedPdo, $email)) {
            header("Location: " . servitech_url("/auth/log_in.php?login=throttled"));
            exit();
        }

        try {
            $authResponse = servitech_supabase_sign_in($email, $password);
        } catch (DomainException $signInError) {
            $legacy = $privilegedPdo->prepare("
                SELECT id, email, fullname,
                       COALESCE(
                         NULLIF(to_jsonb(users)->>'contact', ''),
                         NULLIF(to_jsonb(users)->>'contacts', '')
                       ) AS contact,
                       COALESCE(
                         NULLIF(to_jsonb(users)->>'password_hash', ''),
                         NULLIF(to_jsonb(users)->>'password', '')
                       ) AS password_hash,
                       role
                FROM users
                WHERE LOWER(email) = LOWER(:email)
                  AND auth_user_id IS NULL
                LIMIT 1
            ");
            $legacy->execute([":email" => $email]);
            $legacyUser = $legacy->fetch(PDO::FETCH_ASSOC);
            $legacyHash = (string)($legacyUser["password_hash"] ?? "");
            $legacyPasswordValid = false;
            if (is_array($legacyUser) && $legacyHash !== "") {
                $hashInfo = password_get_info($legacyHash);
                $legacyPasswordValid = (int)($hashInfo["algo"] ?? 0) !== 0
                    ? password_verify($password, $legacyHash)
                    : hash_equals($legacyHash, $password);
            }
            if (!$legacyPasswordValid) {
                servitech_login_throttle_record_failure($privilegedPdo, $email);
                throw $signInError;
            }

            $created = servitech_supabase_admin_create_user($email, $password, [
                "fullname" => (string)($legacyUser["fullname"] ?? ""),
                "contact" => (string)($legacyUser["contact"] ?? ""),
                "servitech_legacy_user_id" => (int)$legacyUser["id"],
            ]);
            $createdUser = is_array($created["user"] ?? null) ? $created["user"] : $created;
            $authUserId = trim((string)($createdUser["id"] ?? ""));
            if (!preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
                throw new RuntimeException("Supabase did not return the migrated Auth user ID.");
            }

            $link = $privilegedPdo->prepare("
                UPDATE users
                SET auth_user_id = :auth_user_id,
                    password_hash = NULL,
                    email_verified_at = COALESCE(email_verified_at, NOW()),
                    updated_at = NOW()
                WHERE id = :id
                  AND auth_user_id IS NULL
            ");
            $link->execute([
                ":auth_user_id" => $authUserId,
                ":id" => (int)$legacyUser["id"],
            ]);
            if ($link->rowCount() !== 1) {
                throw new RuntimeException("The legacy profile could not be linked safely.");
            }
            $authResponse = servitech_supabase_sign_in($email, $password);
        }

        servitech_login_throttle_clear($privilegedPdo, $email);
        $profile = servitech_supabase_complete_login($privilegedPdo, $authResponse);
        $_SESSION["remember_me"] = $rememberMe;
        servitech_apply_session_cookie_lifetime($rememberMe);
        header("Location: " . servitech_url(
            ($profile["role"] ?? "customer") === "admin"
                ? "/pages/admin/admin_dashboard.php"
                : "/pages/customer/customer_dash.php"
        ));
        exit();
    } catch (DomainException $e) {
        error_log("Supabase login rejected: " . $e->getMessage());
        header("Location: " . servitech_url("/auth/log_in.php?login=fail"));
        exit();
    } catch (Throwable $e) {
        error_log("Supabase login error: " . $e->getMessage());
        if ($privilegedPdo instanceof PDO) {
            try {
                servitech_login_throttle_record_failure($privilegedPdo, $email);
            } catch (Throwable $ignored) {
            }
        }
        header("Location: " . servitech_url("/auth/log_in.php?login=fail"));
        exit();
    }
}

try {
    if (!servitech_login_throttle_allows($pdo, $email)) {
        header("Location: " . servitech_url("/auth/log_in.php?login=throttled"));
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT id, email,
               COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer') AS role,
               COALESCE(NULLIF(to_jsonb(users)->>'google_id', ''), '') AS google_id,
               COALESCE(NULLIF(to_jsonb(users)->>'email_verified_at', ''), '') AS email_verified_at,
               COALESCE(
                   NULLIF(to_jsonb(users)->>'password_hash', ''),
                   NULLIF(to_jsonb(users)->>'password', '')
               ) AS auth_hash
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
        } else {
            $is_valid = hash_equals($storedHash, $password);
            $rehashNeeded = $is_valid;
        }
    }

    if ($user && $is_valid) {
        servitech_login_throttle_clear($pdo, $email);

        if (
            servitech_account_email_verification_required()
            && trim((string)($user["email_verified_at"] ?? "")) === ""
        ) {
            header("Location: " . servitech_url("/auth/log_in.php?login=verify_email"));
            exit();
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
        $_SESSION["remember_me"] = $rememberMe;
        servitech_apply_session_cookie_lifetime($rememberMe);

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
        header("Location: " . servitech_url("/auth/log_in.php?login=google_required"));
        exit();
    }

    header("Location: " . servitech_url("/auth/log_in.php?login=fail"));
    exit();
} catch (Throwable $e) {
    error_log("login error: " . $e->getMessage());
    header("Location: " . servitech_url("/auth/log_in.php?login=fail"));
    exit();
}
