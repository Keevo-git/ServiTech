<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/google_auth.php";
require_once __DIR__ . "/../config/account.php";
require_once __DIR__ . "/../config/google_account_completion.php";
require_once __DIR__ . "/../config/remember_me.php";
require_once __DIR__ . "/registration_notifications.php";

servitech_enforce_same_origin(true);
servitech_enforce_csrf_token(true);

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["ok" => false, "error" => "Method not allowed."]);
    exit();
}

$rawInput = file_get_contents("php://input");
$decodedInput = json_decode($rawInput ?: "{}", true);
$credential = trim((string)($decodedInput["credential"] ?? $_POST["credential"] ?? ""));
$privacyConsent = (string)($decodedInput["privacy_consent"] ?? $_POST["privacy_consent"] ?? "");

if ($credential === "") {
    http_response_code(422);
    echo json_encode(["ok" => false, "error" => "Google credential is required."]);
    exit();
}

if (servitech_supabase_auth_enabled()) {
    try {
        if (!servitech_supabase_auth_configured()) {
            throw new RuntimeException("Supabase Auth is enabled but not configured.");
        }
        $authResponse = servitech_supabase_sign_in_with_google_token($credential);
        $authUser = is_array($authResponse["user"] ?? null) ? $authResponse["user"] : [];
        $authUserId = trim((string)($authUser["id"] ?? ""));
        $authEmail = strtolower(trim((string)($authUser["email"] ?? "")));
        $googleClaims = servitech_supabase_jwt_claims($credential);
        $supabaseGoogleId = trim((string)($googleClaims["sub"] ?? ""));
        if (!preg_match('/^[0-9a-f-]{36}$/i', $authUserId) || $authEmail === "" || $supabaseGoogleId === "") {
            throw new RuntimeException("Google sign-in did not return a usable Supabase identity.");
        }

        $privilegedPdo = servitech_db_connect_privileged();
        $profile = $privilegedPdo->prepare("
            SELECT id, auth_user_id,
                   COALESCE(NULLIF(to_jsonb(users)->>'google_id', ''), '') AS google_id
            FROM users
            WHERE auth_user_id = :auth_user_id OR LOWER(email) = LOWER(:email)
            ORDER BY CASE WHEN auth_user_id = :auth_user_id THEN 0 ELSE 1 END
            LIMIT 1
        ");
        $profile->execute([
            ":auth_user_id" => $authUserId,
            ":email" => $authEmail,
        ]);
        $existing = $profile->fetch(PDO::FETCH_ASSOC);
        if (
            is_array($existing)
            && trim((string)($existing["google_id"] ?? "")) !== ""
            && trim((string)$existing["google_id"]) !== $supabaseGoogleId
        ) {
            throw new DomainException("This email is already linked to a different Google account.");
        }
        if (is_array($existing) && trim((string)($existing["auth_user_id"] ?? "")) === "") {
            $link = $privilegedPdo->prepare("
                UPDATE users
                SET auth_user_id = :auth_user_id,
                    email_verified_at = COALESCE(email_verified_at, NOW()),
                    updated_at = NOW()
                WHERE id = :id AND auth_user_id IS NULL
            ");
            $link->execute([
                ":auth_user_id" => $authUserId,
                ":id" => (int)$existing["id"],
            ]);
        }

        $applicationProfile = servitech_supabase_complete_login($privilegedPdo, $authResponse);
        $_SESSION["remember_me"] = false;
        unset($_SESSION["remember_selector"]);
        servitech_apply_session_cookie_lifetime(false);
        servitech_remember_clear_cookie();
        $syncGoogleProfile = $privilegedPdo->prepare("
            UPDATE users
            SET google_id = :google_id,
                local_password_set_at = CASE
                    WHEN :has_email_identity = '1' THEN COALESCE(local_password_set_at, NOW())
                    ELSE local_password_set_at
                END,
                email_verified_at = COALESCE(email_verified_at, NOW()),
                updated_at = NOW()
            WHERE id = :id
              AND (google_id IS NULL OR google_id = :google_id)
        ");
        $syncGoogleProfile->execute([
            ":google_id" => $supabaseGoogleId,
            ":has_email_identity" => servitech_supabase_user_has_email_identity($authUser) ? "1" : "0",
            ":id" => (int)($applicationProfile["id"] ?? 0),
        ]);
        if ($syncGoogleProfile->rowCount() !== 1) {
            throw new RuntimeException("The Google account could not be linked to its ServiTech profile.");
        }
        $completionStatus = servitech_refresh_google_account_completion_state(
            $privilegedPdo,
            (int)($applicationProfile["id"] ?? 0)
        );
        if (!is_array($existing) && ($applicationProfile["role"] ?? "customer") !== "admin") {
            $profileFullname = trim((string)($authUser["user_metadata"]["full_name"] ?? $authUser["user_metadata"]["name"] ?? ""));
            servitech_notify_admin_new_customer(
                $privilegedPdo,
                (int)($applicationProfile["id"] ?? 0),
                $profileFullname,
                $authEmail
            );
        }
        echo json_encode([
            "ok" => true,
            "redirect" => ($applicationProfile["role"] ?? "customer") === "admin"
                ? "/pages/admin/admin_dashboard.php"
                : ($completionStatus["required"]
                    ? servitech_google_account_completion_path()
                    : "/pages/customer/customer_dash.php"),
        ]);
        exit();
    } catch (DomainException $e) {
        http_response_code(401);
        echo json_encode(["ok" => false, "error" => "Google authentication failed."]);
        exit();
    } catch (Throwable $e) {
        error_log("Supabase Google login error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => "Google sign-in could not be completed right now."]);
        exit();
    }
}

$verification = servitech_google_verify_id_token($credential);
if (empty($verification["ok"])) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => (string)($verification["error"] ?? "Google authentication failed.")]);
    exit();
}

$payload = (array)($verification["payload"] ?? []);
$googleId = trim((string)($payload["sub"] ?? ""));
$email = strtolower(trim((string)($payload["email"] ?? "")));
$fullName = trim((string)($payload["name"] ?? ""));
$emailVerified = filter_var($payload["email_verified"] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($googleId === "" || $email === "" || !$emailVerified) {
    http_response_code(422);
    echo json_encode(["ok" => false, "error" => "Google account details are incomplete or the email is not verified."]);
    exit();
}

if ($fullName === "") {
    $fullName = trim((string)($payload["given_name"] ?? "Google User"));
}

try {
    $createdCustomer = false;
    $findUser = $pdo->prepare("
        SELECT id, email,
               COALESCE(NULLIF(to_jsonb(users)->>'fullname', ''), :full_name) AS fullname,
               COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer') AS role,
               COALESCE(NULLIF(to_jsonb(users)->>'google_id', ''), '') AS google_id
        FROM users
        WHERE COALESCE(NULLIF(to_jsonb(users)->>'google_id', ''), '') = :google_id
           OR LOWER(email) = LOWER(:email)
        ORDER BY CASE
            WHEN COALESCE(NULLIF(to_jsonb(users)->>'google_id', ''), '') = :google_id THEN 0
            ELSE 1
        END
        LIMIT 1
    ");
    $findUser->execute([
        ":full_name" => $fullName,
        ":google_id" => $googleId,
        ":email" => $email,
    ]);
    $user = $findUser->fetch();

    if ($user) {
        $existingGoogleId = trim((string)($user["google_id"] ?? ""));
        if ($existingGoogleId !== "" && $existingGoogleId !== $googleId) {
            http_response_code(409);
            echo json_encode(["ok" => false, "error" => "This email is already linked to a different Google account."]);
            exit();
        }

        $updateUser = $pdo->prepare("
            UPDATE users
            SET fullname = :fullname,
                email = :email,
                google_id = :google_id,
                email_verified_at = COALESCE(email_verified_at, NOW()),
                consent_accepted_at = CASE
                    WHEN consent_accepted_at IS NULL AND :privacy_consent = '1' THEN NOW()
                    ELSE consent_accepted_at
                END,
                consent_version = CASE
                    WHEN consent_version IS NULL AND :privacy_consent = '1' THEN :consent_version
                    ELSE consent_version
                END,
                updated_at = NOW()
            WHERE id = :id
        ");
        $updateUser->execute([
            ":fullname" => $fullName,
            ":email" => $email,
            ":google_id" => $googleId,
            ":privacy_consent" => $privacyConsent,
            ":consent_version" => servitech_account_consent_version(),
            ":id" => (int)$user["id"],
        ]);

        $userId = (int)$user["id"];
        $role = strtolower((string)($user["role"] ?? "customer"));
    } else {
        if ($privacyConsent !== "1") {
            http_response_code(422);
            echo json_encode(["ok" => false, "error" => "You must agree to the Data Privacy Policy before creating an account."]);
            exit();
        }

        try {
            $insertUser = $pdo->prepare("
                INSERT INTO users (
                    fullname, email, contact, password_hash, google_id,
                    consent_accepted_at, consent_version, email_verified_at
                )
                VALUES (:fullname, :email, NULL, NULL, :google_id, NOW(), :consent_version, NOW())
                RETURNING id, COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer') AS role
            ");
            $insertUser->execute([
                ":fullname" => $fullName,
                ":email" => $email,
                ":google_id" => $googleId,
                ":consent_version" => servitech_account_consent_version(),
            ]);
        } catch (PDOException $e) {
            $insertUser = $pdo->prepare("
                INSERT INTO users (
                    fullname, email, contacts, password_hash, google_id,
                    consent_accepted_at, consent_version, email_verified_at
                )
                VALUES (:fullname, :email, NULL, NULL, :google_id, NOW(), :consent_version, NOW())
                RETURNING id, COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer') AS role
            ");
            $insertUser->execute([
                ":fullname" => $fullName,
                ":email" => $email,
                ":google_id" => $googleId,
                ":consent_version" => servitech_account_consent_version(),
            ]);
        }

        $inserted = $insertUser->fetch();
        $userId = (int)($inserted["id"] ?? 0);
        $role = strtolower((string)($inserted["role"] ?? "customer"));
        $createdCustomer = $role !== "admin";
    }

    if ($userId <= 0) {
        throw new RuntimeException("Could not resolve authenticated user.");
    }
    if ($createdCustomer) {
        servitech_notify_admin_new_customer($pdo, $userId, $fullName, $email);
    }

    session_regenerate_id(true);
    $_SESSION["user_id"] = $userId;
    $_SESSION["role"] = ($role === "admin") ? "admin" : "customer";
    $_SESSION["remember_me"] = false;
    unset($_SESSION["remember_selector"]);
    servitech_apply_session_cookie_lifetime(false);
    servitech_remember_clear_cookie();

    $completionStatus = servitech_refresh_google_account_completion_state($pdo, $userId);

    $redirect = ($_SESSION["role"] === "admin")
        ? "/pages/admin/admin_dashboard.php"
        : ($completionStatus["required"]
            ? servitech_google_account_completion_path()
            : "/pages/customer/customer_dash.php");

    if ($_SESSION["role"] === "admin") {
        $_SESSION["admin_logged_in"] = true;
        $_SESSION["admin_email"] = $email;
    } else {
        unset($_SESSION["admin_logged_in"], $_SESSION["admin_email"]);
    }

    echo json_encode([
        "ok" => true,
        "redirect" => $redirect,
    ]);
    exit();

} catch (PDOException $e) {
    error_log("google login error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Google sign-in could not be completed. Please confirm the users table includes a nullable google_id column.",
    ]);
    exit();
} catch (Throwable $e) {
    error_log("google login error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Google sign-in could not be completed right now. Please try again.",
    ]);
    exit();
}
