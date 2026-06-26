<?php
require_once __DIR__ . "/guest_guard.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/account.php";
require_once __DIR__ . "/../config/remember_me.php";
require_once __DIR__ . "/../config/activity_log.php";
require_once __DIR__ . "/../config/employee_setup.php";
require_once __DIR__ . "/registration_notifications.php";

function servitech_login_context_config(string $context): array
{
    return match ($context) {
        "super_admin" => [
            "context" => "super_admin",
            "login_path" => "/auth/super_admin_login.php",
            "allowed_roles" => ["super_admin"],
            "success_action" => "super_admin_login_success",
            "failed_action" => "super_admin_login_failed",
            "wrong_role_action" => "super_admin_wrong_role_login",
            "success_description" => "Super Admin logged in successfully.",
            "wrong_role_description" => "A non-Super Admin account attempted to use Super Admin login and was blocked.",
            "wrong_role_code" => "wrong_role_super_admin",
        ],
        "admin" => [
            "context" => "admin",
            "login_path" => "/auth/admin_login.php",
            "allowed_roles" => ["admin"],
            "success_action" => "admin_login_success",
            "failed_action" => "admin_login_failed",
            "wrong_role_action" => "admin_wrong_role_login",
            "success_description" => "Admin logged in successfully.",
            "wrong_role_description" => "A non-Admin account attempted to use Admin login and was blocked.",
            "wrong_role_code" => "wrong_role_admin",
        ],
        default => [
            "context" => "customer",
            "login_path" => "/auth/log_in.php",
            "allowed_roles" => ["customer"],
            "success_action" => "customer_login_success",
            "failed_action" => "customer_login_failed",
            "wrong_role_action" => "customer_wrong_role_login",
            "success_description" => "Customer logged in successfully.",
            "wrong_role_description" => "An internal account attempted to use Customer login and was blocked.",
            "wrong_role_code" => "wrong_role_customer",
        ],
    };
}

function servitech_login_failure_redirect(array $config, string $code, bool $rememberMe): void
{
    $_SESSION["login_remember_retry"] = $rememberMe;
    header("Location: " . servitech_url($config["login_path"] . "?login=" . rawurlencode($code)));
    exit();
}

function servitech_apply_password_login_persistence(PDO $pdo, int $userId, bool $rememberMe): void
{
    unset($_SESSION["login_remember_retry"], $_SESSION["remember_selector"]);
    $_SESSION["remember_me"] = $rememberMe;

    if (servitech_supabase_auth_enabled()) {
        // Supabase refresh credentials live in the server-side PHP session; only
        // its random session ID may persist in the browser.
        servitech_remember_clear_cookie();
        servitech_apply_session_cookie_lifetime($rememberMe);
        return;
    }

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

function servitech_password_login_redirect_path(string $role, ?PDO $pdo = null, ?int $userId = null): string
{
    $role = servitech_normalize_role($role);
    if ($role === "admin" && $pdo instanceof PDO && servitech_employee_setup_required($pdo, $userId)) {
        return servitech_employee_setup_path();
    }

    if (in_array($role, ["admin", "super_admin"], true)) {
        return servitech_supabase_auth_enabled()
            && servitech_supabase_admin_mfa_required()
            && servitech_supabase_session_aal() !== "aal2"
                ? "/auth/mfa.php"
                : servitech_internal_dashboard_path($role);
    }

    return "/pages/customer/customer_dash.php";
}

function servitech_password_login_clear_session(PDO $pdo): void
{
    try {
        servitech_remember_revoke_current($pdo);
    } catch (Throwable $exception) {
        error_log("Remember-me revoke during login cleanup failed: " . $exception->getMessage());
    }
    servitech_remember_clear_cookie();
    servitech_supabase_clear_auth_session();
    servitech_supabase_clear_application_session();
}

function servitech_password_login_log(PDO $pdo, array $config, string $status, string $email, ?array $profile = null, array $extra = []): void
{
    $profile = is_array($profile) ? $profile : [];
    $role = servitech_normalize_role($profile["role"] ?? $extra["role"] ?? "customer");
    $actorId = isset($profile["id"]) ? (int)$profile["id"] : null;
    $fullname = trim((string)($profile["fullname"] ?? ""));
    $label = $fullname !== "" ? $fullname : $email;

    $description = match ($status) {
        "success" => (string)$config["success_description"],
        "wrong_role" => servitech_role_label($role) . " account {$label} attempted to use the wrong login page and was blocked.",
        default => "Failed login attempt for {$email}.",
    };

    servitech_activity_log($pdo, [
        "actor_id" => $actorId,
        "role" => $role,
        "action_type" => match ($status) {
            "success" => (string)$config["success_action"],
            "wrong_role" => (string)$config["wrong_role_action"],
            default => (string)$config["failed_action"],
        },
        "module" => "authentication",
        "target_record_id" => $actorId !== null ? (string)$actorId : null,
        "new_value" => [
            "email" => $email,
            "login_context" => $config["context"],
            "resolved_role" => $role,
        ] + $extra,
        "description" => $description,
        "status" => $status === "success" ? "success" : "failed",
    ]);
}

function servitech_password_login_role_allowed(array $config, string $role): bool
{
    return in_array(servitech_normalize_role($role), $config["allowed_roles"], true);
}

function servitech_log_employee_unverified_login_attempt(PDO $pdo, string $email): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT id, fullname, email, role
            FROM users
            WHERE LOWER(email) = LOWER(:email)
              AND LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
            LIMIT 1
        ");
        $stmt->execute([":email" => $email]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($profile)) {
            return;
        }
        $label = trim((string)($profile["fullname"] ?? "")) !== ""
            ? (string)$profile["fullname"]
            : $email;
        servitech_activity_log($pdo, [
            "actor_id" => (int)$profile["id"],
            "role" => "admin",
            "action_type" => "employee_login_before_email_verification",
            "module" => "authentication",
            "target_record_id" => (string)$profile["id"],
            "new_value" => ["email" => $email],
            "description" => "Employee {$label} attempted to log in before verifying email.",
            "status" => "failed",
        ]);
    } catch (Throwable $exception) {
        error_log("employee unverified login activity log failed: " . $exception->getMessage());
    }
}

function servitech_employee_pending_verification_profile(PDO $pdo, string $email): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT id, fullname, email, role, email_verified_at
            FROM users
            WHERE LOWER(email) = LOWER(:email)
              AND LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
            LIMIT 1
        ");
        $stmt->execute([":email" => $email]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($profile) || trim((string)($profile["email_verified_at"] ?? "")) !== "") {
            return null;
        }
        return $profile;
    } catch (Throwable $exception) {
        error_log("employee pending verification lookup failed: " . $exception->getMessage());
        return null;
    }
}

function servitech_handle_password_login(string $context): void
{
    servitech_redirect_authenticated_user();
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);

    $config = servitech_login_context_config($context);
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        header("Location: " . servitech_url($config["login_path"]));
        exit();
    }

    $email = strtolower(trim((string)($_POST["email"] ?? "")));
    $password = (string)($_POST["password"] ?? "");
    $rememberMe = isset($_POST["remember_me"]) && (string)$_POST["remember_me"] === "1";

    if ($email === "" || $password === "") {
        servitech_login_failure_redirect($config, "required", $rememberMe);
    }

    if (servitech_supabase_auth_enabled()) {
        $privilegedPdo = null;
        try {
            if (!servitech_supabase_auth_configured()) {
                throw new RuntimeException("Supabase Auth is enabled but not configured.");
            }
            $privilegedPdo = servitech_db_connect_privileged();

            if (!servitech_login_throttle_allows($privilegedPdo, $email)) {
                servitech_login_failure_redirect($config, "throttled", $rememberMe);
            }

            $authResponse = servitech_supabase_sign_in($email, $password);
            $pendingEmployeeVerification = ($config["context"] ?? "") === "admin"
                ? servitech_employee_pending_verification_profile($privilegedPdo, $email)
                : null;
            servitech_login_throttle_clear($privilegedPdo, $email);
            $profile = servitech_supabase_complete_login($privilegedPdo, $authResponse, "password");
            $profileRole = servitech_normalize_role($profile["role"] ?? "customer");

            if (!servitech_password_login_role_allowed($config, $profileRole)) {
                servitech_password_login_log($privilegedPdo, $config, "wrong_role", $email, $profile);
                servitech_password_login_clear_session($privilegedPdo);
                servitech_login_failure_redirect($config, (string)$config["wrong_role_code"], false);
            }

            if ($profileRole === "customer") {
                servitech_notify_admin_new_customer(
                    $privilegedPdo,
                    (int)($profile["id"] ?? 0),
                    (string)($profile["fullname"] ?? ""),
                    (string)($profile["email"] ?? $email)
                );
            }
            if ($profileRole === "admin" && is_array($pendingEmployeeVerification)) {
                servitech_activity_log($privilegedPdo, [
                    "actor_id" => (int)($profile["id"] ?? $pendingEmployeeVerification["id"] ?? 0),
                    "role" => "admin",
                    "action_type" => "employee_email_verified",
                    "module" => "authentication",
                    "target_record_id" => (string)($profile["id"] ?? $pendingEmployeeVerification["id"] ?? ""),
                    "new_value" => ["email" => $email],
                    "description" => "Employee " . (string)($profile["fullname"] ?? $pendingEmployeeVerification["fullname"] ?? $email) . " verified email and logged in.",
                ]);
            }
            servitech_password_login_log($privilegedPdo, $config, "success", $email, $profile);
            if ($profileRole === "admin" && servitech_employee_setup_required($privilegedPdo, (int)($profile["id"] ?? 0))) {
                servitech_activity_log($privilegedPdo, [
                    "actor_id" => (int)($profile["id"] ?? 0),
                    "role" => "admin",
                    "action_type" => "employee_first_login",
                    "module" => "employee_setup",
                    "target_record_id" => (string)($profile["id"] ?? ""),
                    "description" => "Employee " . (string)($profile["fullname"] ?? $email) . " logged in and was sent to first-time setup.",
                ]);
            }
            servitech_apply_password_login_persistence($privilegedPdo, (int)($profile["id"] ?? 0), $rememberMe);
            header("Location: " . servitech_url(servitech_password_login_redirect_path($profileRole, $privilegedPdo, (int)($profile["id"] ?? 0))));
            exit();
        } catch (DomainException $exception) {
            error_log("Supabase login rejected: " . $exception->getMessage());
            if ($privilegedPdo instanceof PDO) {
                servitech_password_login_clear_session($privilegedPdo);
                if (servitech_supabase_error_requires_email_verification($exception->getMessage())) {
                    $_SESSION["verification_email_hint"] = $email;
                    if (($config["context"] ?? "") === "admin") {
                        servitech_log_employee_unverified_login_attempt($privilegedPdo, $email);
                    }
                    try {
                        servitech_login_throttle_clear($privilegedPdo, $email);
                    } catch (Throwable $ignored) {
                    }
                    servitech_login_failure_redirect($config, "verify_email", $rememberMe);
                }
                try {
                    servitech_login_throttle_record_failure($privilegedPdo, $email);
                } catch (Throwable $ignored) {
                }
                servitech_password_login_log($privilegedPdo, $config, "failed", $email);
            } else {
                servitech_supabase_clear_auth_session();
                servitech_supabase_clear_application_session();
            }
            servitech_login_failure_redirect($config, "fail", $rememberMe);
        } catch (Throwable $exception) {
            error_log("Supabase login error: " . $exception->getMessage());
            if ($privilegedPdo instanceof PDO) {
                servitech_password_login_clear_session($privilegedPdo);
                try {
                    servitech_login_throttle_record_failure($privilegedPdo, $email);
                } catch (Throwable $ignored) {
                }
                servitech_password_login_log($privilegedPdo, $config, "failed", $email);
            } else {
                servitech_supabase_clear_auth_session();
                servitech_supabase_clear_application_session();
            }
            servitech_login_failure_redirect($config, "fail", $rememberMe);
        }
    }

    global $pdo;
    try {
        if (!servitech_login_throttle_allows($pdo, $email)) {
            servitech_login_failure_redirect($config, "throttled", $rememberMe);
        }

        $stmt = $pdo->prepare("
            SELECT id, email, fullname,
                   COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer') AS role,
                   COALESCE(NULLIF(to_jsonb(users)->>'account_status', ''), 'active') AS account_status,
                   COALESCE(NULLIF(to_jsonb(users)->>'google_id', ''), '') AS google_id,
                   COALESCE(NULLIF(to_jsonb(users)->>'email_verified_at', ''), '') AS email_verified_at,
                   NULLIF(to_jsonb(users)->>'password_hash', '') AS auth_hash
            FROM users
            WHERE LOWER(email) = LOWER(:email)
            LIMIT 1
        ");
        $stmt->execute([":email" => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $storedHash = (string)($user["auth_hash"] ?? "");
        $isValid = false;
        $rehashNeeded = false;
        if ($user && $storedHash !== "") {
            $hashInfo = password_get_info($storedHash);
            if ((int)($hashInfo["algo"] ?? 0) !== 0) {
                $isValid = password_verify($password, $storedHash);
                $rehashNeeded = $isValid && password_needs_rehash($storedHash, PASSWORD_DEFAULT);
            }
        }

        if ($user && $isValid) {
            servitech_login_throttle_clear($pdo, $email);

            if (strtolower(trim((string)($user["account_status"] ?? "active"))) !== "active") {
                servitech_password_login_log($pdo, $config, "failed", $email, $user, ["reason" => "inactive"]);
                servitech_login_failure_redirect($config, "inactive", $rememberMe);
            }

            if (
                servitech_account_email_verification_required()
                && trim((string)($user["email_verified_at"] ?? "")) === ""
            ) {
                servitech_login_failure_redirect($config, "verify_email", $rememberMe);
            }

            $profileRole = servitech_normalize_role($user["role"] ?? "customer");
            if (!servitech_password_login_role_allowed($config, $profileRole)) {
                servitech_password_login_log($pdo, $config, "wrong_role", $email, $user);
                servitech_password_login_clear_session($pdo);
                servitech_login_failure_redirect($config, (string)$config["wrong_role_code"], false);
            }
        }

        if ($isValid && $rehashNeeded && isset($user["id"])) {
            $rehash = $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
            $rehash->execute([
                ":hash" => password_hash($password, PASSWORD_DEFAULT),
                ":id" => (int)$user["id"],
            ]);
        }

        if ($user && $isValid) {
            session_regenerate_id(true);
            unset($_SESSION["verification_registration_state"], $_SESSION["verification_email_hint"]);
            $_SESSION["user_id"] = (int)$user["id"];
            $_SESSION["role"] = servitech_normalize_role($user["role"] ?? "customer");
            servitech_apply_password_login_persistence($pdo, (int)$user["id"], $rememberMe);

            try {
                $updateLogin = $pdo->prepare("UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id");
                $updateLogin->execute([":id" => (int)$user["id"]]);
            } catch (Throwable $metadataException) {
                error_log("login metadata update failed: " . $metadataException->getMessage());
            }

            if (servitech_is_admin()) {
                $_SESSION["admin_logged_in"] = true;
                $_SESSION["admin_email"] = (string)($user["email"] ?? $email);
            } else {
                unset($_SESSION["admin_logged_in"], $_SESSION["admin_email"]);
            }

            servitech_password_login_log($pdo, $config, "success", $email, $user);
            if ($_SESSION["role"] === "admin" && servitech_employee_setup_required($pdo, (int)$user["id"])) {
                servitech_activity_log($pdo, [
                    "actor_id" => (int)$user["id"],
                    "role" => "admin",
                    "action_type" => "employee_first_login",
                    "module" => "employee_setup",
                    "target_record_id" => (string)$user["id"],
                    "description" => "Employee " . (string)($user["fullname"] ?? $email) . " logged in and was sent to first-time setup.",
                ]);
            }
            header("Location: " . servitech_url(servitech_password_login_redirect_path($_SESSION["role"], $pdo, (int)$user["id"])));
            exit();
        }

        servitech_login_throttle_record_failure($pdo, $email);
        servitech_password_login_log($pdo, $config, "failed", $email, $user ?: null);

        $googleId = trim((string)($user["google_id"] ?? ""));
        if ($user && $googleId !== "" && $storedHash === "") {
            servitech_login_failure_redirect($config, "google_required", $rememberMe);
        }

        servitech_login_failure_redirect($config, "fail", $rememberMe);
    } catch (Throwable $exception) {
        error_log("login error: " . $exception->getMessage());
        servitech_login_failure_redirect($config, "fail", $rememberMe);
    }
}
