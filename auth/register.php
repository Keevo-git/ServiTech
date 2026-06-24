<?php
require_once __DIR__ . "/guest_guard.php";
servitech_redirect_authenticated_user();

require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/account.php";
require_once __DIR__ . "/../config/mail.php";
require_once __DIR__ . "/registration_notifications.php";

servitech_enforce_same_origin(false);
servitech_enforce_csrf_token(false);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: " . servitech_url("/auth/regis.php"));
    exit();
}

$fullname = trim($_POST["fullname"] ?? "");
$contact = trim($_POST["contact"] ?? $_POST["contacts"] ?? "");
$email = strtolower(trim($_POST["email"] ?? ""));
$password_raw = (string)($_POST["password"] ?? "");
$confirm_password = (string)($_POST["confirm_password"] ?? "");
$privacy_consent = (string)($_POST["privacy_consent"] ?? "");

if ($fullname === "" || $contact === "" || $email === "" || $password_raw === "" || $confirm_password === "") {
    header("Location: " . servitech_url("/auth/regis.php?error=required"));
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: " . servitech_url("/auth/regis.php?error=invalid_email"));
    exit();
}

if (preg_match('/^09\d{9}$/', $contact)) {
    $contact = "+63" . substr($contact, 1);
}

if (!preg_match('/^\+639\d{9}$/', $contact)) {
    header("Location: " . servitech_url("/auth/regis.php?error=invalid_contact"));
    exit();
}

if ($password_raw !== $confirm_password) {
    header("Location: " . servitech_url("/auth/regis.php?error=mismatch"));
    exit();
}

if ($privacy_consent !== "1") {
    header("Location: " . servitech_url("/auth/regis.php?error=privacy"));
    exit();
}

$passwordError = servitech_password_validation_error($password_raw);
if ($passwordError !== "") {
    header("Location: " . servitech_url("/auth/regis.php?error=password"));
    exit();
}

if (servitech_supabase_auth_enabled()) {
    try {
        if (!servitech_supabase_auth_configured()) {
            throw new RuntimeException("Supabase Auth is enabled but not configured.");
        }

        $privilegedPdo = servitech_db_connect_privileged();
        $existingProfile = $privilegedPdo->prepare("
            SELECT id
            FROM users
            WHERE LOWER(email) = LOWER(:email)
            LIMIT 1
        ");
        $existingProfile->execute([":email" => $email]);
        if ($existingProfile->fetchColumn()) {
            header("Location: " . servitech_url("/auth/log_in.php?registered=exists"));
            exit();
        }

        $authResponse = servitech_supabase_sign_up($email, $password_raw, [
            "fullname" => $fullname,
            "contact" => $contact,
            "privacy_consent" => "1",
            "consent_version" => servitech_account_consent_version(),
        ]);

        $hasSession = trim((string)($authResponse["access_token"] ?? "")) !== ""
            && trim((string)($authResponse["refresh_token"] ?? "")) !== "";

        if (!$hasSession) {
            // With email confirmation enabled, Supabase creates auth.users and the
            // profile trigger runs, but no login session is issued until verification.
            // Do not notify admins yet; unconfirmed signups can be abandoned or
            // automated. Operational reporting should count confirmed accounts.
            header("Location: " . servitech_url("/auth/log_in.php?registered=verify"));
            exit();
        }

        $profile = servitech_supabase_complete_login($privilegedPdo, $authResponse);
        if (($profile["role"] ?? "customer") !== "admin") {
            servitech_notify_admin_new_customer(
                $privilegedPdo,
                (int)($profile["id"] ?? 0),
                $fullname,
                $email
            );
        }
        header("Location: " . servitech_url(
            ($profile["role"] ?? "customer") === "admin"
                ? "/pages/admin/admin_dashboard.php"
                : "/pages/customer/customer_dash.php"
        ));
        exit();
    } catch (DomainException $e) {
        error_log("Supabase registration rejected: " . $e->getMessage());
        $message = strtolower($e->getMessage());
        $code = str_contains($message, "already") || str_contains($message, "registered")
            ? "exists"
            : "error";
        header("Location: " . servitech_url("/auth/regis.php?error=" . $code));
        exit();
    } catch (Throwable $e) {
        error_log("Supabase registration error: " . $e->getMessage());
        servitech_supabase_clear_auth_session();
        header("Location: " . servitech_url("/auth/regis.php?error=error"));
        exit();
    }
}

$password_hash = password_hash($password_raw, PASSWORD_DEFAULT);
if (!is_string($password_hash) || $password_hash === "") {
    header("Location: " . servitech_url("/auth/regis.php?error=error"));
    exit();
}

$verification = servitech_account_email_verification_required()
    ? servitech_email_verification_token()
    : ["token" => null, "token_hash" => null];
$verificationSentAt = $verification["token"] !== null ? date(DATE_ATOM) : null;
$verificationExpires = $verification["token"] !== null
    ? date(DATE_ATOM, time() + (SERVITECH_EMAIL_VERIFICATION_TTL_HOURS * 60 * 60))
    : null;

try {
    $pdo->beginTransaction();

    $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
    $check->execute([":email" => $email]);
    if ($check->fetch()) {
        $pdo->rollBack();
        header("Location: " . servitech_url("/auth/log_in.php?registered=exists"));
        exit();
    }

    $params = [
        ":fullname" => $fullname,
        ":email" => $email,
        ":contact" => ($contact === "" ? null : $contact),
        ":password_hash" => $password_hash,
        ":consent_version" => servitech_account_consent_version(),
        ":email_verification_token" => $verification["token_hash"],
        ":email_verification_expires" => $verificationExpires,
        ":email_verification_sent_at" => $verificationSentAt,
    ];

    try {
        $ins = $pdo->prepare("
            INSERT INTO users (
                fullname, email, contact, password_hash,
                consent_accepted_at, consent_version,
                email_verification_token, email_verification_expires, email_verification_sent_at
            )
            VALUES (
                :fullname, :email, :contact, :password_hash,
                NOW(), :consent_version,
                :email_verification_token, :email_verification_expires, :email_verification_sent_at
            )
            RETURNING id
        ");
        $ins->execute($params);
    } catch (PDOException $e) {
        $ins = $pdo->prepare("
            INSERT INTO users (
                fullname, email, contacts, password_hash,
                consent_accepted_at, consent_version,
                email_verification_token, email_verification_expires, email_verification_sent_at
            )
            VALUES (
                :fullname, :email, :contact, :password_hash,
                NOW(), :consent_version,
                :email_verification_token, :email_verification_expires, :email_verification_sent_at
            )
            RETURNING id
        ");
        $ins->execute($params);
    }
    $customerId = (int)($ins->fetchColumn() ?: 0);
    if ($customerId <= 0) {
        throw new RuntimeException("Registration did not return a customer ID.");
    }

    servitech_notify_admin_new_customer($pdo, $customerId, $fullname, $email);

    if (is_string($verification["token"])) {
        $mailResult = servitech_send_email_verification_mail(
            $email,
            servitech_email_verification_url($verification["token"])
        );
        if (empty($mailResult["ok"])) {
            throw new RuntimeException("Email verification delivery failed: " . (string)($mailResult["error"] ?? "unknown error"));
        }
    }

    $pdo->commit();
    $registeredCode = $verification["token"] !== null ? "verify" : "1";
    header("Location: " . servitech_url("/auth/log_in.php?registered=" . $registeredCode));
    exit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("register error: " . $e->getMessage());
    header("Location: " . servitech_url("/auth/regis.php?error=error"));
    exit();
}
