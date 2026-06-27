<?php
require_once __DIR__ . "/../admin/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/../admin/_includes/admin_db.php";
require_once __DIR__ . "/../admin/_includes/url.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/activity_log.php";
require_once __DIR__ . "/../../config/employee_setup.php";

function employee_account_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function employee_account_format_datetime($value): string
{
    $value = trim((string)$value);
    if ($value === "") {
        return "-";
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone("Asia/Manila"))
            ->format("M d, Y h:i A");
    } catch (Throwable $exception) {
        return "-";
    }
}

function employee_account_format_date($value): string
{
    $value = trim((string)$value);
    if ($value === "") {
        return "-";
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone("Asia/Manila"))
            ->format("M d, Y");
    } catch (Throwable $exception) {
        return "-";
    }
}

function employee_account_format_time($value): string
{
    $value = trim((string)$value);
    if ($value === "") {
        return "-";
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone("Asia/Manila"))
            ->format("h:i A");
    } catch (Throwable $exception) {
        return "-";
    }
}

function employee_account_auth_users_available(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("
            SELECT EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = 'auth'
                  AND table_name = 'users'
            )
        ");
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $exception) {
        error_log("employee accounts auth.users availability check failed: " . $exception->getMessage());
        return false;
    }
}

function employee_account_load_auth_user_by_email(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare("
        SELECT id::text AS auth_user_id, email, email_confirmed_at
        FROM auth.users
        WHERE LOWER(email) = LOWER(:email)
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([":email" => $email]);
    $authUser = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($authUser) ? $authUser : null;
}

function employee_account_confirmed_at($value): ?string
{
    $value = trim((string)$value);
    return $value !== "" ? $value : null;
}

function employee_account_auth_user_exists(PDO $pdo, string $authUserId): bool
{
    $authUserId = strtolower(trim($authUserId));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT EXISTS (
            SELECT 1
            FROM auth.users
            WHERE id = CAST(:auth_user_id AS uuid)
              AND deleted_at IS NULL
        )
    ");
    $stmt->execute([":auth_user_id" => $authUserId]);
    return (bool)$stmt->fetchColumn();
}

function employee_account_load_profile_by_auth(PDO $pdo, string $authUserId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, fullname, email, contact, role, account_status
        FROM users
        WHERE auth_user_id = CAST(:auth_user_id AS uuid)
        LIMIT 1
    ");
    $stmt->execute([":auth_user_id" => $authUserId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($profile) ? $profile : null;
}

function employee_account_load_profiles_by_email(PDO $pdo, string $email): array
{
    $stmt = $pdo->prepare("
        SELECT id, fullname, email, role, auth_user_id::text AS auth_user_id
        FROM users
        WHERE LOWER(email) = LOWER(:email)
        ORDER BY
          CASE LOWER(TRIM(COALESCE(role, 'customer')))
            WHEN 'admin' THEN 1
            WHEN 'super_admin' THEN 2
            WHEN 'customer' THEN 3
            ELSE 4
          END,
          id ASC
    ");
    $stmt->execute([":email" => $email]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function employee_account_load_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT u.id, u.auth_user_id::text AS auth_user_id, u.fullname, u.email, u.contact,
               u.role, u.account_status, u.force_password_change, u.profile_completed,
               u.last_login_at, u.created_at, u.updated_at, u.created_by,
               u.address, u.emergency_contact_name, u.emergency_contact_relationship,
               u.emergency_contact_address, u.emergency_contact_number,
               u.position_title, u.employee_notes, u.first_login_completed_at
        FROM users u
        INNER JOIN auth.users auth_account
          ON auth_account.id = u.auth_user_id
         AND auth_account.deleted_at IS NULL
        WHERE u.id = :id
          AND u.auth_user_id IS NOT NULL
          AND LOWER(TRIM(COALESCE(u.role, 'customer'))) = 'admin'
        LIMIT 1
    ");
    $stmt->execute([":id" => $id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($account) ? $account : null;
}

function employee_account_validate_password(string $password, string $confirmation): void
{
    if ($password === "" || $confirmation === "") {
        throw new DomainException("Please complete all required fields.");
    }
    if (!hash_equals($password, $confirmation)) {
        throw new DomainException("Temporary passwords do not match.");
    }
    if (strlen($password) < 8) {
        throw new DomainException("Temporary password does not meet the password requirements.");
    }
    if (!preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        throw new DomainException("Temporary password does not meet the password requirements.");
    }
}

function employee_account_safe_create_error(Throwable $exception): string
{
    $message = trim($exception->getMessage());
    $publicMessages = [
        "Please complete all required fields.",
        "Temporary passwords do not match.",
        "Temporary password does not meet the password requirements.",
        "This employee account already exists.",
        "This email is already used by a customer account.",
        "This email is already used by a Super Admin account.",
        "An employee profile exists but is not linked to an auth account. Please review or link it manually.",
        "This email is already used by another ServiTech account.",
        "Unable to create Supabase Auth account. Please check the email and password requirements.",
        "Employee account was created, but the verification email could not be sent. Please check SMTP/Resend settings.",
        "Auth account was created, but employee profile linking failed. Please review the account.",
        "Supabase Admin service role is not configured. Set SUPABASE_SERVICE_ROLE_KEY on the server before creating employee accounts.",
        "Employee Accounts needs the employee setup migration and Supabase Auth linkage before account changes can be made.",
    ];

    if (in_array($message, $publicMessages, true)) {
        return $message;
    }

    return "Unable to create employee account. Please check the details and try again.";
}

function employee_account_assert_email_not_taken(PDO $pdo, string $email, string $authUserId, int $ignoreUserId = 0): void
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE LOWER(email) = LOWER(:email)
          AND (:ignore_user_id = 0 OR id <> :ignore_user_id)
          AND (
            auth_user_id IS NULL
            OR auth_user_id IS DISTINCT FROM CAST(:auth_user_id AS uuid)
          )
        LIMIT 1
    ");
    $stmt->execute([
        ":email" => $email,
        ":auth_user_id" => $authUserId,
        ":ignore_user_id" => $ignoreUserId,
    ]);

    if ($stmt->fetchColumn()) {
        throw new DomainException("That email is already linked to a different ServiTech profile.");
    }
}

function employee_account_assert_create_email_available(PDO $pdo, string $email): void
{
    foreach (employee_account_load_profiles_by_email($pdo, $email) as $profile) {
        $role = servitech_normalize_role($profile["role"] ?? "customer");
        $authUserId = trim((string)($profile["auth_user_id"] ?? ""));

        if ($role === "admin") {
            if ($authUserId !== "" && employee_account_auth_user_exists($pdo, $authUserId)) {
                throw new DomainException("This employee account already exists.");
            }
            throw new DomainException("An employee profile exists but is not linked to an auth account. Please review or link it manually.");
        }

        if ($role === "super_admin") {
            throw new DomainException("This email is already used by a Super Admin account.");
        }

        if ($role === "customer") {
            throw new DomainException("This email is already used by a customer account.");
        }

        throw new DomainException("This email is already used by another ServiTech account.");
    }
}

function employee_account_redirect_self(): void
{
    header("Location: " . admin_url_raw("/pages/super_admin/super_admin_employee_accounts.php"), true, 303);
    exit();
}

function employee_account_send_verification_email(string $email): void
{
    try {
        servitech_supabase_resend_signup($email, servitech_supabase_admin_confirmation_redirect_url());
    } catch (DomainException $exception) {
        error_log("employee verification email rejected: " . $exception->getMessage());
        throw new DomainException("Employee account was created, but the verification email could not be sent. Please check SMTP/Resend settings.");
    } catch (Throwable $exception) {
        error_log("employee verification email failed: " . $exception->getMessage());
        throw new DomainException("Employee account was created, but the verification email could not be sent. Please check SMTP/Resend settings.");
    }
}

function employee_account_resolve_auth_user(PDO $pdo, string $email, string $password, string $fullname): array
{
    if (!servitech_supabase_auth_enabled() || !servitech_supabase_admin_configured()) {
        throw new DomainException("Supabase Admin service role is not configured. Set SUPABASE_SERVICE_ROLE_KEY on the server before creating employee accounts.");
    }

    $existingAuth = employee_account_load_auth_user_by_email($pdo, $email);
    if ($existingAuth) {
        $authUserId = strtolower((string)$existingAuth["auth_user_id"]);
        $existingProfile = employee_account_load_profile_by_auth($pdo, $authUserId);
        if (is_array($existingProfile)) {
            $role = servitech_normalize_role($existingProfile["role"] ?? "customer");
            if ($role === "admin") {
                throw new DomainException("This employee account already exists.");
            }
            if ($role === "super_admin") {
                throw new DomainException("This email is already used by a Super Admin account.");
            }
            if ($role === "customer") {
                throw new DomainException("This email is already used by a customer account.");
            }
            throw new DomainException("This email is already used by another ServiTech account.");
        }

        servitech_supabase_admin_update_user($authUserId, [
            "password" => $password,
            "user_metadata" => [
                "fullname" => $fullname,
                "role" => "admin",
                "servitech_internal_role" => "admin",
            ],
        ]);
        $confirmedAt = employee_account_confirmed_at($existingAuth["email_confirmed_at"] ?? null);
        return [
            "auth_user_id" => $authUserId,
            "email_confirmed_at" => $confirmedAt,
            "verification_required" => $confirmedAt === null,
            "existing_profile" => $existingProfile,
        ];
    }

    try {
        $created = servitech_supabase_admin_create_user($email, $password, [
            "fullname" => $fullname,
            "role" => "admin",
            "servitech_internal_role" => "admin",
        ], false);
    } catch (DomainException $exception) {
        error_log("employee Supabase Auth create rejected: " . $exception->getMessage());
        throw new DomainException("Unable to create Supabase Auth account. Please check the email and password requirements.");
    } catch (Throwable $exception) {
        error_log("employee Supabase Auth create failed: " . $exception->getMessage());
        throw new DomainException("Unable to create Supabase Auth account. Please check the email and password requirements.");
    }
    $authUserId = strtolower(trim((string)($created["id"] ?? $created["user"]["id"] ?? "")));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        throw new RuntimeException("Supabase did not return a valid employee Auth user ID.");
    }
    $confirmedAt = employee_account_confirmed_at($created["email_confirmed_at"] ?? $created["user"]["email_confirmed_at"] ?? null);

    return [
        "auth_user_id" => $authUserId,
        "email_confirmed_at" => $confirmedAt,
        "verification_required" => $confirmedAt === null,
        "existing_profile" => null,
    ];
}

function employee_account_link_profile(PDO $pdo, string $authUserId, string $fullname, string $email, ?string $confirmedAt, ?int $createdBy): int
{
    $existingProfile = employee_account_load_profile_by_auth($pdo, $authUserId);
    try {
        if ($existingProfile) {
            $stmt = $pdo->prepare("
                UPDATE users
                SET fullname = :fullname,
                    email = :email,
                    role = 'admin',
                    account_status = 'active',
                    force_password_change = TRUE,
                    profile_completed = FALSE,
                    first_login_completed_at = NULL,
                    email_verified_at = :confirmed_at,
                    password_hash = NULL,
                    created_by = COALESCE(created_by, :created_by),
                    updated_at = NOW()
                WHERE id = :id
                RETURNING id
            ");
            $stmt->execute([
                ":fullname" => $fullname,
                ":email" => $email,
                ":confirmed_at" => $confirmedAt,
                ":created_by" => $createdBy,
                ":id" => (int)$existingProfile["id"],
            ]);
            return (int)$stmt->fetchColumn();
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (
                auth_user_id, fullname, email, password_hash, role,
                account_status, force_password_change, profile_completed,
                first_login_completed_at, email_verified_at, created_by,
                created_at, updated_at
            ) VALUES (
                CAST(:auth_user_id AS uuid), :fullname, :email, NULL, 'admin',
                'active', TRUE, FALSE,
                NULL, :confirmed_at, :created_by,
                NOW(), NOW()
            )
            RETURNING id
        ");
        $stmt->execute([
            ":auth_user_id" => $authUserId,
            ":fullname" => $fullname,
            ":email" => $email,
            ":confirmed_at" => $confirmedAt,
            ":created_by" => $createdBy,
        ]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $exception) {
        error_log("employee profile link failed after Auth create for {$email}: " . $exception->getMessage());
        throw new DomainException("Auth account was created, but employee profile linking failed. Please review the account.");
    }
}

$schemaReady = admin_table_has_columns($pdo, "users", [
    "auth_user_id",
    "account_status",
    "force_password_change",
    "profile_completed",
    "first_login_completed_at",
    "last_login_at",
    "created_by",
    "deactivated_at",
    "deactivated_by",
    "password_hash",
    "email_verified_at",
    "address",
    "emergency_contact_name",
    "emergency_contact_relationship",
    "emergency_contact_address",
    "emergency_contact_number",
    "position_title",
    "employee_notes",
]);
$authUsersReady = employee_account_auth_users_available($pdo);
$supabaseAdminReady = servitech_supabase_auth_enabled() && servitech_supabase_admin_configured();
$pageReady = $schemaReady && $authUsersReady;

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_enforce_same_origin(true);
    servitech_enforce_csrf_token(true);

    $action = trim((string)($_POST["action"] ?? ""));
    $currentAdminId = (int)($_SESSION["user_id"] ?? 0);

    try {
        if (!$pageReady) {
            throw new DomainException("Employee Accounts needs the employee setup migration and Supabase Auth linkage before account changes can be made.");
        }

        if ($action === "create") {
            $fullname = trim((string)($_POST["fullname"] ?? ""));
            $email = strtolower(trim((string)($_POST["email"] ?? "")));
            $password = (string)($_POST["temporary_password"] ?? "");
            $passwordConfirm = (string)($_POST["temporary_password_confirm"] ?? "");

            if ($fullname === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException("Please complete all required fields.");
            }
            employee_account_assert_create_email_available($pdo, $email);
            employee_account_validate_password($password, $passwordConfirm);

            $authResult = employee_account_resolve_auth_user($pdo, $email, $password, $fullname);
            $authUserId = strtolower((string)$authResult["auth_user_id"]);
            $confirmedAt = employee_account_confirmed_at($authResult["email_confirmed_at"] ?? null);
            $employeeId = employee_account_link_profile(
                $pdo,
                $authUserId,
                $fullname,
                $email,
                $confirmedAt,
                $currentAdminId > 0 ? $currentAdminId : null
            );

            $verificationEmailSent = false;
            $verificationEmailFailed = false;
            if (!empty($authResult["verification_required"])) {
                try {
                    employee_account_send_verification_email($email);
                    $verificationEmailSent = true;
                    servitech_activity_log($pdo, [
                        "action_type" => "employee_verification_email_sent",
                        "module" => "employee_accounts",
                        "target_record_id" => (string)$employeeId,
                        "new_value" => [
                            "email" => $email,
                            "redirect_url" => servitech_supabase_admin_confirmation_redirect_url(),
                        ],
                        "description" => "Verification email was sent for employee {$fullname}.",
                    ]);
                } catch (DomainException $emailException) {
                    $verificationEmailFailed = true;
                    servitech_activity_log($pdo, [
                        "action_type" => "employee_verification_email_failed",
                        "module" => "employee_accounts",
                        "target_record_id" => (string)$employeeId,
                        "new_value" => ["email" => $email],
                        "description" => "Verification email could not be sent for employee {$fullname}.",
                        "status" => "failed",
                    ]);
                    error_log("employee account verification email failed for {$email}: " . $emailException->getMessage());
                }
            }

            servitech_activity_log($pdo, [
                "action_type" => "employee_account_create",
                "module" => "employee_accounts",
                "target_record_id" => (string)$employeeId,
                "new_value" => [
                    "email" => $email,
                    "role" => "admin",
                    "auth_user_id" => $authUserId,
                    "profile_completed" => false,
                    "email_verified" => $confirmedAt !== null,
                    "verification_email_sent" => $verificationEmailSent,
                ],
                "description" => $verificationEmailSent
                    ? "Super Admin created an employee account for {$fullname} and sent a verification email."
                    : "Super Admin created an employee account for {$fullname}.",
            ]);
            if ($verificationEmailFailed) {
                servitech_admin_flash_toast("Employee account was created, but the verification email could not be sent. Please check SMTP/Resend settings.", "warning");
            } elseif ($verificationEmailSent) {
                servitech_admin_flash_toast("Employee account created. A verification email has been sent.", "success");
            } else {
                servitech_admin_flash_toast("Employee account created successfully.", "success");
            }
        } elseif ($action === "update") {
            $employeeId = (int)($_POST["employee_id"] ?? 0);
            $account = employee_account_load_by_id($pdo, $employeeId);
            if (!$account) {
                throw new DomainException("Employee account not found.");
            }

            $fullname = trim((string)($_POST["fullname"] ?? ""));
            $email = strtolower(trim((string)($_POST["email"] ?? "")));
            $contact = trim((string)($_POST["contact"] ?? ""));
            $address = trim((string)($_POST["address"] ?? ""));
            $emergencyName = trim((string)($_POST["emergency_contact_name"] ?? ""));
            $emergencyRelationship = trim((string)($_POST["emergency_contact_relationship"] ?? ""));
            $emergencyAddress = trim((string)($_POST["emergency_contact_address"] ?? ""));
            $emergencyNumber = trim((string)($_POST["emergency_contact_number"] ?? ""));
            $positionTitle = array_key_exists("position_title", $_POST)
                ? trim((string)$_POST["position_title"])
                : (string)($account["position_title"] ?? "");
            $notes = array_key_exists("employee_notes", $_POST)
                ? trim((string)$_POST["employee_notes"])
                : (string)($account["employee_notes"] ?? "");

            if ($fullname === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException("Enter a valid employee name and email.");
            }

            $authUser = employee_account_load_auth_user_by_email($pdo, $email);
            if (!$authUser || strtolower((string)$authUser["auth_user_id"]) !== strtolower((string)$account["auth_user_id"])) {
                throw new DomainException("To change an employee login email, update the existing Supabase Auth user first. This page will only keep the current Auth link.");
            }
            employee_account_assert_email_not_taken($pdo, $email, (string)$account["auth_user_id"], $employeeId);

            $stmt = $pdo->prepare("
                UPDATE users
                SET fullname = :fullname,
                    email = :email,
                    contact = :contact,
                    address = :address,
                    emergency_contact_name = :emergency_contact_name,
                    emergency_contact_relationship = :emergency_contact_relationship,
                    emergency_contact_address = :emergency_contact_address,
                    emergency_contact_number = :emergency_contact_number,
                    position_title = :position_title,
                    employee_notes = :employee_notes,
                    role = 'admin',
                    email_verified_at = COALESCE(email_verified_at, :confirmed_at),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ":fullname" => $fullname,
                ":email" => $email,
                ":contact" => $contact !== "" ? $contact : null,
                ":address" => $address !== "" ? $address : null,
                ":emergency_contact_name" => $emergencyName !== "" ? $emergencyName : null,
                ":emergency_contact_relationship" => $emergencyRelationship !== "" ? $emergencyRelationship : null,
                ":emergency_contact_address" => $emergencyAddress !== "" ? $emergencyAddress : null,
                ":emergency_contact_number" => $emergencyNumber !== "" ? $emergencyNumber : null,
                ":position_title" => $positionTitle,
                ":employee_notes" => $notes,
                ":confirmed_at" => employee_account_confirmed_at($authUser["email_confirmed_at"] ?? null),
                ":id" => $employeeId,
            ]);
            servitech_activity_log($pdo, [
                "action_type" => "employee_account_update",
                "module" => "employee_accounts",
                "target_record_id" => (string)$employeeId,
                "old_value" => $account,
                "new_value" => [
                    "fullname" => $fullname,
                    "email" => $email,
                    "contact" => $contact,
                    "address" => $address,
                    "emergency_contact_name" => $emergencyName,
                    "emergency_contact_relationship" => $emergencyRelationship,
                    "emergency_contact_number" => $emergencyNumber,
                ],
                "description" => "Super Admin updated the employee account for {$fullname}.",
            ]);
            servitech_admin_flash_toast("Employee account updated.", "success");
            $_SESSION["employee_account_detail_modal_open"] = $employeeId;
            unset($_SESSION["employee_account_detail_modal_edit"]);
        } elseif ($action === "set_status") {
            $employeeId = (int)($_POST["employee_id"] ?? 0);
            $status = strtolower(trim((string)($_POST["status"] ?? "")));
            $account = employee_account_load_by_id($pdo, $employeeId);
            if (!$account || !in_array($status, ["active", "deactivated"], true)) {
                throw new DomainException("Invalid employee status request.");
            }

            $stmt = $pdo->prepare("
                UPDATE users
                SET account_status = :status,
                    deactivated_at = CASE WHEN :status_for_deactivated = 'deactivated' THEN NOW() ELSE NULL END,
                    deactivated_by = CASE WHEN :status_for_deactivated = 'deactivated' THEN :admin_id ELSE NULL END,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ":status" => $status,
                ":status_for_deactivated" => $status,
                ":admin_id" => $currentAdminId > 0 ? $currentAdminId : null,
                ":id" => $employeeId,
            ]);
            servitech_activity_log($pdo, [
                "action_type" => $status === "active" ? "employee_account_activate" : "employee_account_deactivate",
                "module" => "employee_accounts",
                "target_record_id" => (string)$employeeId,
                "old_value" => ["account_status" => $account["account_status"] ?? ""],
                "new_value" => ["account_status" => $status],
                "description" => "Super Admin " . ($status === "active" ? "reactivated" : "deactivated") . " the employee account for " . (string)($account["fullname"] ?? $account["email"] ?? "employee") . ".",
            ]);
            servitech_admin_flash_toast($status === "active" ? "Employee account reactivated." : "Employee account deactivated.", "success");
        } elseif ($action === "reset_password") {
            $employeeId = (int)($_POST["employee_id"] ?? 0);
            $password = (string)($_POST["temporary_password"] ?? "");
            $passwordConfirm = (string)($_POST["temporary_password_confirm"] ?? "");
            $account = employee_account_load_by_id($pdo, $employeeId);
            if (!$account) {
                throw new DomainException("Employee account not found.");
            }
            if (!$supabaseAdminReady) {
                throw new DomainException("Supabase Admin service role is not configured. Set SUPABASE_SERVICE_ROLE_KEY before resetting employee passwords.");
            }
            employee_account_validate_password($password, $passwordConfirm);

            servitech_supabase_admin_update_user((string)$account["auth_user_id"], [
                "password" => $password,
                "user_metadata" => [
                    "fullname" => (string)($account["fullname"] ?? ""),
                    "role" => "admin",
                    "servitech_internal_role" => "admin",
                ],
            ]);
            $stmt = $pdo->prepare("
                UPDATE users
                SET force_password_change = TRUE,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([":id" => $employeeId]);
            servitech_activity_log($pdo, [
                "action_type" => "employee_password_reset",
                "module" => "employee_accounts",
                "target_record_id" => (string)$employeeId,
                "description" => "Super Admin reset the temporary password for " . (string)($account["fullname"] ?? $account["email"] ?? "employee") . ".",
            ]);
            servitech_admin_flash_toast("Employee temporary password reset. Give the new temporary password securely; it will not be shown again.", "success");
        } elseif ($action === "force_password_change") {
            $employeeId = (int)($_POST["employee_id"] ?? 0);
            $account = employee_account_load_by_id($pdo, $employeeId);
            if (!$account) {
                throw new DomainException("Employee account not found.");
            }
            $stmt = $pdo->prepare("UPDATE users SET force_password_change = TRUE, updated_at = NOW() WHERE id = :id");
            $stmt->execute([":id" => $employeeId]);
            servitech_activity_log($pdo, [
                "action_type" => "employee_force_password_change",
                "module" => "employee_accounts",
                "target_record_id" => (string)$employeeId,
                "description" => "Super Admin forced password change for " . (string)($account["fullname"] ?? $account["email"] ?? "employee") . ".",
            ]);
            servitech_admin_flash_toast("Employee will be required to change password on next login.", "success");
        }
    } catch (PDOException $exception) {
        $message = $action === "create"
            ? "Unable to create employee account. Please check the details and try again."
            : "Unable to save the employee account.";
        error_log("employee account save error: " . $exception->getMessage());
        servitech_admin_flash_toast($message, "error");
        if ($action === "create") {
            $_SESSION["employee_account_create_modal_open"] = true;
        } elseif ($action === "update") {
            $_SESSION["employee_account_detail_modal_open"] = (int)($_POST["employee_id"] ?? 0);
            $_SESSION["employee_account_detail_modal_edit"] = true;
        }
    } catch (Throwable $exception) {
        servitech_admin_flash_toast(
            $action === "create" ? employee_account_safe_create_error($exception) : $exception->getMessage(),
            "error"
        );
        if ($action === "create") {
            $_SESSION["employee_account_create_modal_open"] = true;
        } elseif ($action === "update") {
            $_SESSION["employee_account_detail_modal_open"] = (int)($_POST["employee_id"] ?? 0);
            $_SESSION["employee_account_detail_modal_edit"] = true;
        }
    }

    employee_account_redirect_self();
}

$employeeAccounts = [];
if ($pageReady) {
    $stmt = $pdo->query("
        SELECT u.id, u.auth_user_id::text AS auth_user_id, u.fullname, u.email, u.contact,
               u.role, u.account_status, u.force_password_change, u.profile_completed,
               u.last_login_at, u.created_at, u.updated_at, creator.fullname AS created_by_name,
               u.address, u.emergency_contact_name, u.emergency_contact_relationship,
               u.emergency_contact_address, u.emergency_contact_number,
               u.position_title, u.employee_notes, u.first_login_completed_at,
               auth_account.email_confirmed_at AS auth_email_confirmed_at
        FROM users u
        INNER JOIN auth.users auth_account
          ON auth_account.id = u.auth_user_id
         AND auth_account.deleted_at IS NULL
        LEFT JOIN users creator ON creator.id = u.created_by
        WHERE u.auth_user_id IS NOT NULL
          AND LOWER(TRIM(COALESCE(u.role, 'customer'))) = 'admin'
        ORDER BY
          LOWER(TRIM(COALESCE(u.account_status, 'active'))) ASC,
          COALESCE(u.profile_completed, FALSE) ASC,
          u.created_at DESC,
          u.id DESC
    ");
    $employeeAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$csrfToken = servitech_csrf_token();
$openCreateModal = !empty($_SESSION["employee_account_create_modal_open"]);
unset($_SESSION["employee_account_create_modal_open"]);
$openEmployeeDetailsModalId = (int)($_SESSION["employee_account_detail_modal_open"] ?? 0);
unset($_SESSION["employee_account_detail_modal_open"]);
$openEmployeeDetailsEdit = !empty($_SESSION["employee_account_detail_modal_edit"]);
unset($_SESSION["employee_account_detail_modal_edit"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Employee Accounts | ServiTech Admin</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260626-roles') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260627-employee-responsive-modal') ?>">
</head>
<body class="admin-employee-accounts<?= $openCreateModal || $openEmployeeDetailsModalId > 0 ? " admin-owner-modal-open" : "" ?>">
<?php require __DIR__ . "/../admin/_includes/admin_header.php"; ?>

<main class="admin-owner-shell">
  <section class="admin-owner-hero">
    <span class="admin-owner-kicker">Super Admin</span>
    <h1>Employee Accounts</h1>
    <p>Create employee Admin accounts with temporary passwords, reset access, and review profile setup status without exposing credentials.</p>
  </section>

  <?php if (!$schemaReady): ?>
    <div class="admin-owner-alert admin-owner-alert--error">Employee Accounts needs the first-time setup migration before this page can be used.</div>
  <?php endif; ?>
  <?php if ($schemaReady && !$authUsersReady): ?>
    <div class="admin-owner-alert admin-owner-alert--error">Employee Accounts requires readable Supabase Auth users. The main list is hidden until Auth linkage can be verified.</div>
  <?php endif; ?>
  <?php if (!$supabaseAdminReady): ?>
    <div class="admin-owner-alert admin-owner-alert--error">Set SUPABASE_SERVICE_ROLE_KEY on the server to create employees or reset temporary passwords.</div>
  <?php endif; ?>
  <div class="admin-owner-alert employee-account-security-note">Temporary passwords are never stored in ServiTech tables and are only sent to Supabase Auth. Give them to employees securely.</div>

  <section class="admin-owner-grid admin-owner-grid--single">
    <section class="admin-owner-panel admin-owner-panel--full employee-accounts-panel">
      <div class="employee-accounts-panel__header">
        <div>
          <h2>Employee Admin Accounts</h2>
          <p>Manage employee admin access, verification status, and required setup steps.</p>
        </div>
        <label class="employee-account-search" for="employee_account_search">
          <span class="employee-account-search__icon" aria-hidden="true"></span>
          <input id="employee_account_search" type="search" placeholder="Search by name or email" autocomplete="off" data-employee-account-search>
        </label>
      </div>
      <div class="admin-owner-table-wrap">
        <table class="admin-owner-table employee-accounts-table">
          <colgroup>
            <col class="employee-accounts-table__col-employee">
            <col class="employee-accounts-table__col-email">
            <col class="employee-accounts-table__col-status">
            <col class="employee-accounts-table__col-status">
            <col class="employee-accounts-table__col-status">
            <col class="employee-accounts-table__col-created">
            <col class="employee-accounts-table__col-actions">
          </colgroup>
          <thead>
            <tr>
              <th>Employee</th>
              <th>Email</th>
              <th>Auth Status</th>
              <th>Profile Status</th>
              <th>Account Status</th>
              <th>Created Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$employeeAccounts): ?>
            <tr data-employee-empty-state="no-records">
              <td colspan="7" class="admin-owner-empty-state employee-accounts-empty-state">
                <strong>No linked employee admin accounts found.</strong>
                <span>No linked employee login accounts are available yet.</span>
              </td>
            </tr>
          <?php endif; ?>
            <tr data-employee-empty-state="search" hidden>
              <td colspan="7" class="admin-owner-empty-state employee-accounts-empty-state">
                <strong>No employee accounts match your search.</strong>
                <span>Try searching with a different name or email.</span>
              </td>
            </tr>
          <?php foreach ($employeeAccounts as $account): ?>
            <?php
              $employeeId = (int)$account["id"];
              $status = strtolower(trim((string)($account["account_status"] ?? "active")));
              $isActive = $status === "active";
              $profileCompleted = filter_var($account["profile_completed"] ?? false, FILTER_VALIDATE_BOOLEAN);
              $forcePasswordChange = filter_var($account["force_password_change"] ?? false, FILTER_VALIDATE_BOOLEAN);
              $authVerified = trim((string)($account["auth_email_confirmed_at"] ?? "")) !== "";
            ?>
            <tr class="employee-account-row" data-employee-search-text="<?= employee_account_h(trim((string)($account["fullname"] ?? "")) . " " . trim((string)($account["email"] ?? ""))) ?>">
              <td class="employee-account-cell employee-account-cell--employee">
                <strong><?= employee_account_h($account["fullname"] ?? "") ?></strong>
                <small>
                  Employee ID #<?= $employeeId ?>
                  <?php if (trim((string)($account["contact"] ?? "")) !== ""): ?>
                    &middot; <?= employee_account_h($account["contact"]) ?>
                  <?php endif; ?>
                </small>
              </td>
              <td class="employee-account-cell employee-account-cell--email"><?= employee_account_h($account["email"] ?? "") ?></td>
              <td>
                <span class="admin-owner-pill<?= $authVerified ? "" : " admin-owner-pill--danger" ?>">
                  <?= $authVerified ? "Verified" : "Pending Verification" ?>
                </span>
              </td>
              <td>
                <span class="admin-owner-pill<?= $profileCompleted && !$forcePasswordChange ? "" : " admin-owner-pill--danger" ?>">
                  <?= $profileCompleted && !$forcePasswordChange ? "Completed" : "Pending Setup" ?>
                </span>
              </td>
              <td>
                <span class="admin-owner-pill<?= $isActive ? "" : " admin-owner-pill--danger" ?>"><?= $isActive ? "Active" : "Inactive" ?></span>
                <?php if ($forcePasswordChange): ?>
                  <small class="employee-account-cell__note">Password change required</small>
                <?php endif; ?>
              </td>
              <td class="employee-account-cell employee-account-cell--created">
                <strong><?= employee_account_h(employee_account_format_date($account["created_at"] ?? "")) ?></strong>
                <small><?= employee_account_h(employee_account_format_time($account["created_at"] ?? "")) ?></small>
                <small>Last login: <?= employee_account_h(employee_account_format_datetime($account["last_login_at"] ?? "")) ?></small>
              </td>
              <td class="employee-account-actions-cell">
                <div class="employee-account-actions">
                  <button class="admin-owner-button-secondary employee-account-action-button" type="button" data-open-employee-modal="employee-details-<?= $employeeId ?>">View Details</button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </section>

  <?php foreach ($employeeAccounts as $account): ?>
    <?php
      $employeeId = (int)$account["id"];
      $status = strtolower(trim((string)($account["account_status"] ?? "active")));
      $isActive = $status === "active";
      $profileCompleted = filter_var($account["profile_completed"] ?? false, FILTER_VALIDATE_BOOLEAN);
      $forcePasswordChange = filter_var($account["force_password_change"] ?? false, FILTER_VALIDATE_BOOLEAN);
      $authVerified = trim((string)($account["auth_email_confirmed_at"] ?? "")) !== "";
      $authStatusLabel = $authVerified ? "Verified" : "Pending Verification";
      $profileStatusLabel = $profileCompleted && !$forcePasswordChange ? "Completed" : "Pending Setup";
      $accountStatusLabel = $isActive ? "Active" : "Inactive";
    ?>
    <div class="admin-owner-modal-overlay employee-details-modal-overlay" id="employee-details-<?= $employeeId ?>" data-employee-details-modal data-employee-details-layout-version="two-column-20260627" aria-hidden="<?= $openEmployeeDetailsModalId === $employeeId ? "false" : "true" ?>"<?= $openEmployeeDetailsModalId === $employeeId ? "" : " hidden" ?>>
      <section class="admin-owner-modal employee-details-modal" role="dialog" aria-modal="true" aria-labelledby="employee-details-title-<?= $employeeId ?>">
        <div class="admin-owner-modal__header employee-details-modal__header">
          <div>
            <span class="employee-details-modal__eyebrow">Employee Admin Account</span>
            <h2 id="employee-details-title-<?= $employeeId ?>">Employee Details</h2>
            <p><?= employee_account_h($account["fullname"] ?? "") ?></p>
          </div>
          <button class="admin-owner-modal__close" type="button" aria-label="Close employee details modal" data-close-employee-details-modal>&times;</button>
        </div>

        <div class="employee-details-modal__content">
          <div class="employee-details-layout">
            <div class="employee-details-main">
              <div class="employee-details-modal__body" data-employee-modal-view<?= $openEmployeeDetailsModalId === $employeeId && $openEmployeeDetailsEdit ? " hidden" : "" ?>>
                <section class="employee-details-section">
                  <div class="employee-details-section__header">
                    <h3>Basic Information</h3>
                  </div>
                  <dl class="employee-details-list">
                    <div>
                      <dt>Employee Name</dt>
                      <dd><?= employee_account_h($account["fullname"] ?: "-") ?></dd>
                    </div>
                    <div>
                      <dt>Email</dt>
                      <dd><?= employee_account_h($account["email"] ?: "-") ?></dd>
                    </div>
                    <div>
                      <dt>Role</dt>
                      <dd>Employee Admin</dd>
                    </div>
                    <div>
                      <dt>Created By</dt>
                      <dd><?= employee_account_h($account["created_by_name"] ?: "-") ?></dd>
                    </div>
                    <div>
                      <dt>Created Date</dt>
                      <dd><?= employee_account_h(employee_account_format_datetime($account["created_at"] ?? "")) ?></dd>
                    </div>
                    <div>
                      <dt>Last Login</dt>
                      <dd><?= employee_account_h(employee_account_format_datetime($account["last_login_at"] ?? "")) ?></dd>
                    </div>
                  </dl>
                </section>

                <section class="employee-details-section">
                  <div class="employee-details-section__header">
                    <h3>Contact Details</h3>
                  </div>
                  <dl class="employee-details-list">
                    <div>
                      <dt>Contact Number</dt>
                      <dd><?= employee_account_h($account["contact"] ?: "-") ?></dd>
                    </div>
                    <div class="employee-details-list__wide">
                      <dt>Address</dt>
                      <dd><?= employee_account_h($account["address"] ?: "-") ?></dd>
                    </div>
                  </dl>
                </section>

                <section class="employee-details-section">
                  <div class="employee-details-section__header">
                    <h3>Emergency Contact</h3>
                  </div>
                  <dl class="employee-details-list">
                    <div>
                      <dt>Name</dt>
                      <dd><?= employee_account_h($account["emergency_contact_name"] ?: "-") ?></dd>
                    </div>
                    <div>
                      <dt>Relationship</dt>
                      <dd><?= employee_account_h($account["emergency_contact_relationship"] ?: "-") ?></dd>
                    </div>
                    <div class="employee-details-list__wide">
                      <dt>Address</dt>
                      <dd><?= employee_account_h($account["emergency_contact_address"] ?: "-") ?></dd>
                    </div>
                    <div>
                      <dt>Number</dt>
                      <dd><?= employee_account_h($account["emergency_contact_number"] ?: "-") ?></dd>
                    </div>
                  </dl>
                </section>
              </div>

              <form id="employee-details-edit-form-<?= $employeeId ?>" class="admin-owner-form employee-details-edit-form" method="post" data-employee-modal-edit<?= $openEmployeeDetailsModalId === $employeeId && $openEmployeeDetailsEdit ? "" : " hidden" ?>>
                <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                <section class="employee-details-section">
                  <div class="employee-details-section__header">
                    <h3>Edit Details</h3>
                    <p>Email changes must be completed through the linked Supabase Auth account.</p>
                  </div>
                  <div class="employee-account-form-grid">
                    <div class="admin-owner-field">
                      <label for="employee_fullname_<?= $employeeId ?>">Name</label>
                      <input id="employee_fullname_<?= $employeeId ?>" name="fullname" value="<?= employee_account_h($account["fullname"] ?? "") ?>" required>
                    </div>
                    <div class="admin-owner-field">
                      <label for="employee_email_<?= $employeeId ?>">Email</label>
                      <input id="employee_email_<?= $employeeId ?>" name="email" type="email" value="<?= employee_account_h($account["email"] ?? "") ?>" readonly required>
                    </div>
                    <div class="admin-owner-field">
                      <label for="employee_contact_<?= $employeeId ?>">Contact Number</label>
                      <input id="employee_contact_<?= $employeeId ?>" name="contact" value="<?= employee_account_h($account["contact"] ?? "") ?>">
                    </div>
                    <div class="admin-owner-field employee-account-form-grid__wide">
                      <label for="employee_address_<?= $employeeId ?>">Address</label>
                      <textarea id="employee_address_<?= $employeeId ?>" name="address" rows="2"><?= employee_account_h($account["address"] ?? "") ?></textarea>
                    </div>
                    <div class="admin-owner-field">
                      <label for="employee_emergency_name_<?= $employeeId ?>">Emergency Contact Name</label>
                      <input id="employee_emergency_name_<?= $employeeId ?>" name="emergency_contact_name" value="<?= employee_account_h($account["emergency_contact_name"] ?? "") ?>">
                    </div>
                    <div class="admin-owner-field">
                      <label for="employee_emergency_relationship_<?= $employeeId ?>">Emergency Contact Relationship</label>
                      <input id="employee_emergency_relationship_<?= $employeeId ?>" name="emergency_contact_relationship" value="<?= employee_account_h($account["emergency_contact_relationship"] ?? "") ?>">
                    </div>
                    <div class="admin-owner-field employee-account-form-grid__wide">
                      <label for="employee_emergency_address_<?= $employeeId ?>">Emergency Contact Address</label>
                      <textarea id="employee_emergency_address_<?= $employeeId ?>" name="emergency_contact_address" rows="2"><?= employee_account_h($account["emergency_contact_address"] ?? "") ?></textarea>
                    </div>
                    <div class="admin-owner-field">
                      <label for="employee_emergency_number_<?= $employeeId ?>">Emergency Contact Number</label>
                      <input id="employee_emergency_number_<?= $employeeId ?>" name="emergency_contact_number" value="<?= employee_account_h($account["emergency_contact_number"] ?? "") ?>">
                    </div>
                  </div>
                </section>
              </form>
            </div>

            <aside class="employee-details-side">
              <section class="employee-details-section employee-details-status">
                <div class="employee-details-section__header">
                  <h3>Account Status</h3>
                </div>
                <dl class="employee-details-status-list">
                  <div>
                    <dt>Auth Status</dt>
                    <dd><span class="admin-owner-pill<?= $authVerified ? "" : " admin-owner-pill--danger" ?>"><?= employee_account_h($authStatusLabel) ?></span></dd>
                  </div>
                  <div>
                    <dt>Profile Status</dt>
                    <dd><span class="admin-owner-pill<?= $profileCompleted && !$forcePasswordChange ? "" : " admin-owner-pill--danger" ?>"><?= employee_account_h($profileStatusLabel) ?></span></dd>
                  </div>
                  <div>
                    <dt>Account Status</dt>
                    <dd><span class="admin-owner-pill<?= $isActive ? "" : " admin-owner-pill--danger" ?>"><?= employee_account_h($accountStatusLabel) ?></span></dd>
                  </div>
                  <div>
                    <dt>Setup Completed</dt>
                    <dd><?= employee_account_h(employee_account_format_datetime($account["first_login_completed_at"] ?? "")) ?></dd>
                  </div>
                </dl>
              </section>

              <section class="employee-details-section employee-details-actions" data-employee-modal-actions>
                <div class="employee-details-section__header">
                  <h3>Actions</h3>
                </div>
                <div class="employee-details-action-grid" data-employee-view-actions<?= $openEmployeeDetailsModalId === $employeeId && $openEmployeeDetailsEdit ? " hidden" : "" ?>>
                  <button class="admin-owner-button-secondary" type="button" data-edit-employee-details>Edit Details</button>
                  <details class="employee-details-password-reset">
                    <summary class="admin-owner-button-secondary">Reset Temporary Password</summary>
                    <form class="admin-owner-form employee-account-password-form" method="post">
                      <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
                      <input type="hidden" name="action" value="reset_password">
                      <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                      <div class="employee-account-form-grid">
                        <div class="admin-owner-field">
                          <label>New Temporary Password</label>
                          <input name="temporary_password" type="password" autocomplete="new-password" required>
                        </div>
                        <div class="admin-owner-field">
                          <label>Confirm Temporary Password</label>
                          <input name="temporary_password_confirm" type="password" autocomplete="new-password" required>
                        </div>
                      </div>
                      <div class="admin-owner-actions employee-account-menu-actions">
                        <button class="admin-owner-button-secondary" type="button" data-generate-temp-password>Generate</button>
                        <button class="admin-owner-button-secondary" type="button" data-copy-temp-password>Copy</button>
                        <button class="admin-owner-button-secondary" type="submit"<?= $supabaseAdminReady ? "" : " disabled" ?>>Reset Password</button>
                      </div>
                    </form>
                  </details>
                  <form method="post" class="employee-account-menu-form">
                    <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
                    <input type="hidden" name="action" value="force_password_change">
                    <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                    <button class="admin-owner-button-secondary" type="submit">Force Password Change</button>
                  </form>
                  <form method="post" class="employee-account-menu-form" data-employee-status-form data-employee-name="<?= employee_account_h($account["fullname"] ?? $account["email"] ?? "this employee") ?>" data-employee-status-action="<?= $isActive ? "deactivate" : "reactivate" ?>">
                    <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                    <input type="hidden" name="status" value="<?= $isActive ? "deactivated" : "active" ?>">
                    <button class="<?= $isActive ? "admin-owner-button-danger" : "admin-owner-button" ?>" type="submit">
                      <?= $isActive ? "Deactivate" : "Reactivate" ?>
                    </button>
                  </form>
                </div>
                <div class="employee-details-edit-actions" data-employee-edit-actions<?= $openEmployeeDetailsModalId === $employeeId && $openEmployeeDetailsEdit ? "" : " hidden" ?>>
                  <button class="admin-owner-button" type="submit" form="employee-details-edit-form-<?= $employeeId ?>">Save Changes</button>
                  <button class="admin-owner-button-secondary" type="button" data-cancel-employee-edit>Cancel Edit</button>
                </div>
              </section>
            </aside>
          </div>
        </div>
      </section>
    </div>
  <?php endforeach; ?>

  <div class="admin-owner-modal-overlay employee-account-confirm-overlay" data-employee-confirm-modal aria-hidden="true" hidden>
    <section class="admin-owner-modal employee-account-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="employee-confirm-title" aria-describedby="employee-confirm-message">
      <div class="admin-owner-modal__header">
        <div>
          <h2 id="employee-confirm-title">Confirm Account Status Change</h2>
          <p id="employee-confirm-message">Confirm this employee account status change.</p>
        </div>
        <button class="admin-owner-modal__close" type="button" aria-label="Close confirmation modal" data-close-employee-confirm-modal>&times;</button>
      </div>
      <div class="admin-owner-modal__actions">
        <button class="admin-owner-button-secondary" type="button" data-close-employee-confirm-modal>Cancel</button>
        <button class="admin-owner-button-danger" type="button" data-confirm-employee-status>Confirm</button>
      </div>
    </section>
  </div>

  <div class="admin-owner-modal-overlay" data-create-employee-modal aria-hidden="<?= $openCreateModal ? "false" : "true" ?>"<?= $openCreateModal ? "" : " hidden" ?>>
    <section class="admin-owner-modal" role="dialog" aria-modal="true" aria-labelledby="create-employee-title" aria-describedby="create-employee-help">
      <div class="admin-owner-modal__header">
        <div>
          <h2 id="create-employee-title">Create Employee Account</h2>
          <p id="create-employee-help">Create an employee login account with a temporary password. The employee will complete their profile during first login.</p>
        </div>
        <button class="admin-owner-modal__close" type="button" aria-label="Close create employee modal" data-close-create-employee-modal>&times;</button>
      </div>
      <form class="admin-owner-form employee-account-password-form" method="post" data-create-employee-form>
        <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
        <input type="hidden" name="action" value="create">
        <div class="admin-owner-field">
          <label for="create_employee_fullname">Full Name</label>
          <input id="create_employee_fullname" name="fullname" autocomplete="name" required>
        </div>
        <div class="admin-owner-field">
          <label for="create_employee_email">Email Address</label>
          <input id="create_employee_email" name="email" type="email" autocomplete="email" required>
        </div>
        <div class="admin-owner-modal__meta">
          <span><strong>Role</strong> Admin</span>
          <span><strong>Status</strong> Active</span>
        </div>
        <div class="admin-owner-field">
          <label for="create_employee_temporary_password">Temporary Password</label>
          <input id="create_employee_temporary_password" name="temporary_password" type="password" autocomplete="new-password" required>
        </div>
        <div class="admin-owner-field">
          <label for="create_employee_temporary_password_confirm">Confirm Temporary Password</label>
          <input id="create_employee_temporary_password_confirm" name="temporary_password_confirm" type="password" autocomplete="new-password" required>
        </div>
        <div class="admin-owner-actions">
          <button class="admin-owner-button-secondary" type="button" data-generate-temp-password>Generate Temporary Password</button>
          <button class="admin-owner-button-secondary" type="button" data-copy-temp-password>Copy Temporary Password</button>
        </div>
        <div class="admin-owner-modal__actions">
          <button class="admin-owner-button-secondary" type="button" data-close-create-employee-modal>Cancel</button>
          <button class="admin-owner-button" type="submit" data-create-employee-submit<?= $pageReady && $supabaseAdminReady ? "" : " disabled" ?>>Create Account</button>
        </div>
      </form>
    </section>
  </div>
</main>
<script>
  (function () {
    var modal = document.querySelector("[data-create-employee-modal]");
    var createForm = document.querySelector("[data-create-employee-form]");
    var submitButton = document.querySelector("[data-create-employee-submit]");
    var canSubmitCreate = <?= $pageReady && $supabaseAdminReady ? "true" : "false" ?>;
    var lastFocusedElement = null;
    var confirmModal = document.querySelector("[data-employee-confirm-modal]");
    var confirmMessage = document.querySelector("#employee-confirm-message");
    var confirmButton = document.querySelector("[data-confirm-employee-status]");
    var searchInput = document.querySelector("[data-employee-account-search]");
    var employeeRows = Array.prototype.slice.call(document.querySelectorAll(".employee-account-row"));
    var detailModals = Array.prototype.slice.call(document.querySelectorAll("[data-employee-details-modal]"));
    var searchEmptyState = document.querySelector("[data-employee-empty-state='search']");
    var pendingStatusForm = null;

    function firstModalField() {
      return modal ? modal.querySelector("input[name='fullname']") : null;
    }

    function setBodyModalLock() {
      var createOpen = modal && !modal.hidden;
      var confirmOpen = confirmModal && !confirmModal.hidden;
      var detailsOpen = detailModals.some(function (detailModal) {
        return !detailModal.hidden;
      });
      document.body.classList.toggle("admin-owner-modal-open", !!(createOpen || confirmOpen || detailsOpen));
    }

    function isTypingTarget(target) {
      if (!target) return false;
      if (target.isContentEditable) return true;
      if (typeof target.closest !== "function") return false;
      return !!target.closest("input, textarea, select, [contenteditable='true']");
    }

    function openCreateModal() {
      if (!modal) return;
      lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      modal.hidden = false;
      modal.setAttribute("aria-hidden", "false");
      setBodyModalLock();
      if (!canSubmitCreate) {
        window.servitechAdminToast?.warning?.("Employee account creation is unavailable until Supabase Admin setup is ready.");
      }
      window.setTimeout(function () {
        var field = firstModalField();
        if (field) field.focus();
      }, 0);
    }

    function closeCreateModal(resetForm) {
      if (!modal) return;
      modal.hidden = true;
      modal.setAttribute("aria-hidden", "true");
      setBodyModalLock();
      if (resetForm && createForm) {
        createForm.reset();
        createForm.querySelectorAll("input[type='text'][name^='temporary_password']").forEach(function (input) {
          input.type = "password";
        });
      }
      if (submitButton) {
        submitButton.disabled = !canSubmitCreate;
        submitButton.textContent = "Create Account";
      }
      if (lastFocusedElement && document.contains(lastFocusedElement)) {
        lastFocusedElement.focus();
      }
    }

    function openConfirmModal(form) {
      if (!confirmModal || !form) return;
      pendingStatusForm = form;
      var employeeName = form.getAttribute("data-employee-name") || "this employee";
      var action = form.getAttribute("data-employee-status-action") || "update";
      var label = action === "reactivate" ? "reactivate" : "deactivate";
      if (confirmMessage) {
        confirmMessage.textContent = "Are you sure you want to " + label + " " + employeeName + "?";
      }
      if (confirmButton) {
        confirmButton.textContent = action === "reactivate" ? "Reactivate Account" : "Deactivate Account";
      }
      lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      confirmModal.hidden = false;
      confirmModal.setAttribute("aria-hidden", "false");
      setBodyModalLock();
      window.setTimeout(function () {
        if (confirmButton) confirmButton.focus();
      }, 0);
    }

    function closeConfirmModal() {
      if (!confirmModal) return;
      confirmModal.hidden = true;
      confirmModal.setAttribute("aria-hidden", "true");
      pendingStatusForm = null;
      setBodyModalLock();
      if (lastFocusedElement && document.contains(lastFocusedElement)) {
        lastFocusedElement.focus();
      }
    }

    function resetEmployeeModal(detailModal) {
      if (!detailModal) return;
      var view = detailModal.querySelector("[data-employee-modal-view]");
      var edit = detailModal.querySelector("[data-employee-modal-edit]");
      var viewActions = detailModal.querySelector("[data-employee-view-actions]");
      var editActions = detailModal.querySelector("[data-employee-edit-actions]");
      if (view) view.hidden = false;
      if (edit) {
        edit.hidden = true;
        edit.reset();
      }
      if (viewActions) viewActions.hidden = false;
      if (editActions) editActions.hidden = true;
      detailModal.querySelectorAll("details[open]").forEach(function (details) {
        details.open = false;
      });
    }

    function openEmployeeModal(modalId) {
      var detailModal = document.getElementById(modalId);
      if (!detailModal) {
        window.servitechAdminToast?.error?.("Unable to load employee details.");
        return;
      }
      lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      resetEmployeeModal(detailModal);
      detailModal.hidden = false;
      detailModal.setAttribute("aria-hidden", "false");
      setBodyModalLock();
      window.setTimeout(function () {
        var closeButton = detailModal.querySelector("[data-close-employee-details-modal]");
        if (closeButton) closeButton.focus();
      }, 0);
    }

    function closeEmployeeModal(detailModal) {
      if (!detailModal) return;
      resetEmployeeModal(detailModal);
      detailModal.hidden = true;
      detailModal.setAttribute("aria-hidden", "true");
      setBodyModalLock();
      if (lastFocusedElement && document.contains(lastFocusedElement)) {
        lastFocusedElement.focus();
      }
    }

    function setEmployeeEditMode(detailModal, editMode) {
      if (!detailModal) return;
      var view = detailModal.querySelector("[data-employee-modal-view]");
      var edit = detailModal.querySelector("[data-employee-modal-edit]");
      var viewActions = detailModal.querySelector("[data-employee-view-actions]");
      var editActions = detailModal.querySelector("[data-employee-edit-actions]");
      if (view) view.hidden = editMode;
      if (edit) edit.hidden = !editMode;
      if (viewActions) viewActions.hidden = editMode;
      if (editActions) editActions.hidden = !editMode;
      if (editMode && edit) {
        window.setTimeout(function () {
          var firstField = edit.querySelector("input:not([type='hidden']):not([readonly]), textarea");
          if (firstField) firstField.focus();
        }, 0);
      }
    }

    if (modal) {
      modal.addEventListener("click", function (event) {
        if (event.target === modal || event.target.closest("[data-close-create-employee-modal]")) {
          closeCreateModal(true);
        }
      });
    }

    if (confirmModal) {
      confirmModal.addEventListener("click", function (event) {
        if (event.target === confirmModal || event.target.closest("[data-close-employee-confirm-modal]")) {
          closeConfirmModal();
        }
      });
    }

    if (confirmButton) {
      confirmButton.addEventListener("click", function () {
        if (!pendingStatusForm) return;
        pendingStatusForm.setAttribute("data-status-confirmed", "true");
        pendingStatusForm.submit();
      });
    }

    detailModals.forEach(function (detailModal) {
      detailModal.addEventListener("click", function (event) {
        if (event.target === detailModal || event.target.closest("[data-close-employee-details-modal]")) {
          closeEmployeeModal(detailModal);
          return;
        }
        if (event.target.closest("[data-edit-employee-details]")) {
          setEmployeeEditMode(detailModal, true);
          return;
        }
        if (event.target.closest("[data-cancel-employee-edit]")) {
          var editForm = detailModal.querySelector("[data-employee-modal-edit]");
          if (editForm) editForm.reset();
          setEmployeeEditMode(detailModal, false);
        }
      });
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && modal && !modal.hidden) {
        closeCreateModal(true);
        return;
      }
      if (event.key === "Escape" && confirmModal && !confirmModal.hidden) {
        closeConfirmModal();
        return;
      }
      var openDetailModal = detailModals.find(function (detailModal) {
        return !detailModal.hidden;
      });
      if (event.key === "Escape" && openDetailModal) {
        closeEmployeeModal(openDetailModal);
        return;
      }
      if (event.ctrlKey && event.altKey && !event.shiftKey && String(event.key).toLowerCase() === "e") {
        if (isTypingTarget(event.target)) return;
        event.preventDefault();
        openCreateModal();
      }
    });

    if (createForm) {
      createForm.addEventListener("submit", function () {
        if (!submitButton) return;
        submitButton.disabled = true;
        submitButton.textContent = "Creating...";
      });
    }

    function closeRowMenus(row) {
      row.querySelectorAll("details[open]").forEach(function (details) {
        details.open = false;
      });
    }

    function filterEmployeeRows() {
      if (!searchInput) return;
      var query = searchInput.value.trim().toLowerCase();
      var visibleCount = 0;

      employeeRows.forEach(function (row) {
        var haystack = (row.getAttribute("data-employee-search-text") || "").toLowerCase();
        var visible = query === "" || haystack.indexOf(query) !== -1;
        row.hidden = !visible;
        if (visible) {
          visibleCount += 1;
        } else {
          closeRowMenus(row);
        }
      });

      if (searchEmptyState) {
        searchEmptyState.hidden = query === "" || visibleCount > 0 || employeeRows.length === 0;
      }
    }

    if (searchInput) {
      searchInput.addEventListener("input", filterEmployeeRows);
      filterEmployeeRows();
    }

    function makePassword() {
      var upper = "ABCDEFGHJKLMNPQRSTUVWXYZ";
      var lower = "abcdefghijkmnopqrstuvwxyz";
      var number = "23456789";
      var special = "!@#$%&*?";
      var all = upper + lower + number + special;
      var chars = [
        upper[Math.floor(Math.random() * upper.length)],
        lower[Math.floor(Math.random() * lower.length)],
        number[Math.floor(Math.random() * number.length)],
        special[Math.floor(Math.random() * special.length)]
      ];
      while (chars.length < 14) {
        chars.push(all[Math.floor(Math.random() * all.length)]);
      }
      return chars.sort(function () { return Math.random() - 0.5; }).join("");
    }

    document.addEventListener("click", function (event) {
      var modalButton = event.target.closest("[data-open-employee-modal]");
      if (modalButton) {
        openEmployeeModal(modalButton.getAttribute("data-open-employee-modal"));
        return;
      }

      var generate = event.target.closest("[data-generate-temp-password]");
      var copy = event.target.closest("[data-copy-temp-password]");
      if (!generate && !copy) return;

      var form = event.target.closest(".employee-account-password-form");
      if (!form) return;
      var password = form.querySelector('input[name="temporary_password"]');
      var confirm = form.querySelector('input[name="temporary_password_confirm"]');
      if (!password || !confirm) return;

      if (generate) {
        var generated = makePassword();
        password.value = generated;
        confirm.value = generated;
        password.type = "text";
        confirm.type = "text";
        window.servitechAdminToast?.success?.("Temporary password generated. Copy it now; it will not be shown after saving.");
        return;
      }

      if (copy) {
        var value = password.value || "";
        if (!value) {
          window.servitechAdminToast?.warning?.("Generate or enter a temporary password first.");
          return;
        }
        navigator.clipboard?.writeText(value)
          .then(function () {
            window.servitechAdminToast?.success?.("Temporary password copied.");
          })
          .catch(function () {
            window.servitechAdminToast?.error?.("Unable to copy temporary password.");
          });
      }
    });

    document.addEventListener("submit", function (event) {
      var statusForm = event.target.closest("[data-employee-status-form]");
      if (!statusForm || statusForm.getAttribute("data-status-confirmed") === "true") return;
      event.preventDefault();
      openConfirmModal(statusForm);
    });

  })();
</script>
</body>
</html>
