<?php
require_once __DIR__ . "/../config/session_check.php";
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

if ($email === "" || $password === "") {
    header("Location: " . servitech_url("/auth/log_in.php?login=required"));
    exit();
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
