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
        throw new DomainException("Temporary password and confirmation are required.");
    }
    if (!hash_equals($password, $confirmation)) {
        throw new DomainException("Temporary password confirmation does not match.");
    }
    if (strlen($password) < 8) {
        throw new DomainException("Temporary password must be at least 8 characters.");
    }
    if (!preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        throw new DomainException("Temporary password must include uppercase, lowercase, number, and special character.");
    }
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

function employee_account_assert_no_unlinked_email(PDO $pdo, string $email): void
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE LOWER(email) = LOWER(:email)
        LIMIT 1
    ");
    $stmt->execute([":email" => $email]);
    if ($stmt->fetchColumn()) {
        throw new DomainException("That email already belongs to a ServiTech profile. Link or review that profile before creating an employee account.");
    }
}

function employee_account_redirect_self(): void
{
    header("Location: " . admin_url_raw("/pages/super_admin/super_admin_employee_accounts.php"), true, 303);
    exit();
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
            if ($role === "super_admin") {
                throw new DomainException("Owner Super Admin accounts cannot be managed as employee accounts.");
            }
            if ($role === "customer") {
                throw new DomainException("This email already belongs to a customer account and cannot be converted into an employee account here.");
            }
        }
        employee_account_assert_email_not_taken($pdo, $email, $authUserId, (int)($existingProfile["id"] ?? 0));

        servitech_supabase_admin_update_user($authUserId, [
            "password" => $password,
            "email_confirm" => true,
            "user_metadata" => [
                "fullname" => $fullname,
                "role" => "admin",
                "servitech_internal_role" => "admin",
            ],
        ]);
        return [
            "auth_user_id" => $authUserId,
            "email_confirmed_at" => trim((string)($existingAuth["email_confirmed_at"] ?? "")) !== "" ? $existingAuth["email_confirmed_at"] : date("c"),
            "existing_profile" => $existingProfile,
        ];
    }

    employee_account_assert_no_unlinked_email($pdo, $email);
    $created = servitech_supabase_admin_create_user($email, $password, [
        "fullname" => $fullname,
        "role" => "admin",
        "servitech_internal_role" => "admin",
    ]);
    $authUserId = strtolower(trim((string)($created["id"] ?? $created["user"]["id"] ?? "")));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        throw new RuntimeException("Supabase did not return a valid employee Auth user ID.");
    }

    return [
        "auth_user_id" => $authUserId,
        "email_confirmed_at" => trim((string)($created["email_confirmed_at"] ?? $created["user"]["email_confirmed_at"] ?? "")) ?: date("c"),
        "existing_profile" => null,
    ];
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
            $contact = trim((string)($_POST["contact"] ?? ""));
            $positionTitle = trim((string)($_POST["position_title"] ?? ""));
            $notes = trim((string)($_POST["employee_notes"] ?? ""));
            $password = (string)($_POST["temporary_password"] ?? "");
            $passwordConfirm = (string)($_POST["temporary_password_confirm"] ?? "");

            if ($fullname === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException("Enter a valid employee name and email.");
            }
            employee_account_validate_password($password, $passwordConfirm);

            $authResult = employee_account_resolve_auth_user($pdo, $email, $password, $fullname);
            $authUserId = strtolower((string)$authResult["auth_user_id"]);
            employee_account_assert_email_not_taken($pdo, $email, $authUserId);
            $existingProfile = is_array($authResult["existing_profile"] ?? null) ? $authResult["existing_profile"] : null;

            if ($existingProfile) {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET fullname = :fullname,
                        email = :email,
                        contact = :contact,
                        role = 'admin',
                        account_status = 'active',
                        force_password_change = TRUE,
                        profile_completed = FALSE,
                        first_login_completed_at = NULL,
                        position_title = :position_title,
                        employee_notes = :employee_notes,
                        email_verified_at = COALESCE(email_verified_at, :confirmed_at),
                        password_hash = NULL,
                        updated_at = NOW()
                    WHERE id = :id
                    RETURNING id
                ");
                $stmt->execute([
                    ":fullname" => $fullname,
                    ":email" => $email,
                    ":contact" => $contact !== "" ? $contact : null,
                    ":position_title" => $positionTitle,
                    ":employee_notes" => $notes,
                    ":confirmed_at" => $authResult["email_confirmed_at"],
                    ":id" => (int)$existingProfile["id"],
                ]);
                $employeeId = (int)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        auth_user_id, fullname, email, contact, password_hash, role,
                        account_status, force_password_change, profile_completed,
                        first_login_completed_at, email_verified_at, position_title,
                        employee_notes, created_by, created_at, updated_at
                    ) VALUES (
                        CAST(:auth_user_id AS uuid), :fullname, :email, :contact, NULL, 'admin',
                        'active', TRUE, FALSE,
                        NULL, :confirmed_at, :position_title,
                        :employee_notes, :created_by, NOW(), NOW()
                    )
                    RETURNING id
                ");
                $stmt->execute([
                    ":auth_user_id" => $authUserId,
                    ":fullname" => $fullname,
                    ":email" => $email,
                    ":contact" => $contact !== "" ? $contact : null,
                    ":confirmed_at" => $authResult["email_confirmed_at"],
                    ":position_title" => $positionTitle,
                    ":employee_notes" => $notes,
                    ":created_by" => $currentAdminId > 0 ? $currentAdminId : null,
                ]);
                $employeeId = (int)$stmt->fetchColumn();
            }

            servitech_activity_log($pdo, [
                "action_type" => "employee_account_create",
                "module" => "employee_accounts",
                "target_record_id" => (string)$employeeId,
                "new_value" => ["email" => $email, "role" => "admin", "auth_user_id" => $authUserId, "profile_completed" => false],
                "description" => "Super Admin created an employee account for {$fullname}.",
            ]);
            servitech_admin_flash_toast("Employee account created. Give the temporary password to the employee securely; it will not be shown again.", "success");
        } elseif ($action === "update") {
            $employeeId = (int)($_POST["employee_id"] ?? 0);
            $account = employee_account_load_by_id($pdo, $employeeId);
            if (!$account) {
                throw new DomainException("Employee account not found.");
            }

            $fullname = trim((string)($_POST["fullname"] ?? ""));
            $email = strtolower(trim((string)($_POST["email"] ?? "")));
            $contact = trim((string)($_POST["contact"] ?? ""));
            $positionTitle = trim((string)($_POST["position_title"] ?? ""));
            $notes = trim((string)($_POST["employee_notes"] ?? ""));

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
                ":position_title" => $positionTitle,
                ":employee_notes" => $notes,
                ":confirmed_at" => trim((string)($authUser["email_confirmed_at"] ?? "")) ?: date("c"),
                ":id" => $employeeId,
            ]);
            servitech_activity_log($pdo, [
                "action_type" => "employee_account_update",
                "module" => "employee_accounts",
                "target_record_id" => (string)$employeeId,
                "old_value" => $account,
                "new_value" => ["fullname" => $fullname, "email" => $email, "contact" => $contact],
                "description" => "Super Admin updated the employee account for {$fullname}.",
            ]);
            servitech_admin_flash_toast("Employee account updated.", "success");
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
        $message = str_contains(strtolower($exception->getMessage()), "unique")
            ? "That employee account is already linked."
            : "Unable to save the employee account.";
        error_log("employee account save error: " . $exception->getMessage());
        servitech_admin_flash_toast($message, "error");
    } catch (Throwable $exception) {
        servitech_admin_flash_toast($exception->getMessage(), "error");
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
               u.position_title, u.employee_notes, u.first_login_completed_at
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Employee Accounts | ServiTech Admin</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260626-roles') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260626-roles') ?>">
</head>
<body>
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
  <div class="admin-owner-alert">Temporary passwords are never stored in ServiTech tables and are only sent to Supabase Auth. Give them to employees securely.</div>

  <section class="admin-owner-grid">
    <aside class="admin-owner-panel">
      <h2>Create Employee Account</h2>
      <form class="admin-owner-form employee-account-password-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
        <input type="hidden" name="action" value="create">
        <div class="admin-owner-field">
          <label for="fullname">Full Name</label>
          <input id="fullname" name="fullname" autocomplete="name" required>
        </div>
        <div class="admin-owner-field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" autocomplete="email" required>
        </div>
        <div class="admin-owner-field">
          <label for="contact">Contact</label>
          <input id="contact" name="contact" autocomplete="tel">
        </div>
        <div class="admin-owner-field">
          <label for="position_title">Position / Job Title</label>
          <input id="position_title" name="position_title" maxlength="120">
        </div>
        <div class="admin-owner-field">
          <label>Role</label>
          <input value="Admin / Employee" readonly>
        </div>
        <div class="admin-owner-field">
          <label for="temporary_password">Temporary Password</label>
          <input id="temporary_password" name="temporary_password" type="password" autocomplete="new-password" required>
        </div>
        <div class="admin-owner-field">
          <label for="temporary_password_confirm">Confirm Temporary Password</label>
          <input id="temporary_password_confirm" name="temporary_password_confirm" type="password" autocomplete="new-password" required>
        </div>
        <div class="admin-owner-actions">
          <button class="admin-owner-button-secondary" type="button" data-generate-temp-password>Generate Temporary Password</button>
          <button class="admin-owner-button-secondary" type="button" data-copy-temp-password>Copy Temporary Password</button>
        </div>
        <div class="admin-owner-field">
          <label for="employee_notes">Notes</label>
          <textarea id="employee_notes" name="employee_notes" rows="4"></textarea>
        </div>
        <button class="admin-owner-button" type="submit"<?= $pageReady && $supabaseAdminReady ? "" : " disabled" ?>>Create Employee Account</button>
      </form>
    </aside>

    <section class="admin-owner-panel">
      <h2>Employee Admin Accounts</h2>
      <div class="admin-owner-table-wrap">
        <table class="admin-owner-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Role</th>
              <th>Status</th>
              <th>Profile</th>
              <th>Dates</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$employeeAccounts): ?>
            <tr><td colspan="6">No linked employee admin accounts found.</td></tr>
          <?php endif; ?>
          <?php foreach ($employeeAccounts as $account): ?>
            <?php
              $employeeId = (int)$account["id"];
              $status = strtolower(trim((string)($account["account_status"] ?? "active")));
              $isActive = $status === "active";
              $profileCompleted = filter_var($account["profile_completed"] ?? false, FILTER_VALIDATE_BOOLEAN);
              $forcePasswordChange = filter_var($account["force_password_change"] ?? false, FILTER_VALIDATE_BOOLEAN);
            ?>
            <tr>
              <td>
                <strong>#<?= $employeeId ?> - <?= employee_account_h($account["fullname"] ?? "") ?></strong>
                <small><?= employee_account_h($account["email"] ?? "") ?></small><br>
                <small><?= employee_account_h($account["contact"] ?? "-") ?></small>
              </td>
              <td><span class="admin-owner-pill">Admin / Employee</span></td>
              <td>
                <span class="admin-owner-pill<?= $isActive ? "" : " admin-owner-pill--danger" ?>"><?= employee_account_h(ucfirst($status)) ?></span>
                <?php if ($forcePasswordChange): ?>
                  <br><small>Password change required</small>
                <?php endif; ?>
              </td>
              <td>
                <span class="admin-owner-pill<?= $profileCompleted && !$forcePasswordChange ? "" : " admin-owner-pill--danger" ?>">
                  <?= $profileCompleted && !$forcePasswordChange ? "Completed" : "Pending Setup" ?>
                </span>
              </td>
              <td>
                <small>Created: <?= employee_account_h(employee_account_format_datetime($account["created_at"] ?? "")) ?></small><br>
                <small>Last login: <?= employee_account_h(employee_account_format_datetime($account["last_login_at"] ?? "")) ?></small><br>
                <small>Created by: <?= employee_account_h($account["created_by_name"] ?: "-") ?></small>
              </td>
              <td>
                <details>
                  <summary class="admin-owner-button-secondary">View / Edit Details</summary>
                  <form class="admin-owner-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                    <div class="admin-owner-field">
                      <label>Name</label>
                      <input name="fullname" value="<?= employee_account_h($account["fullname"] ?? "") ?>" required>
                    </div>
                    <div class="admin-owner-field">
                      <label>Email</label>
                      <input name="email" type="email" value="<?= employee_account_h($account["email"] ?? "") ?>" required>
                    </div>
                    <div class="admin-owner-field">
                      <label>Contact</label>
                      <input name="contact" value="<?= employee_account_h($account["contact"] ?? "") ?>">
                    </div>
                    <div class="admin-owner-field">
                      <label>Position / Job Title</label>
                      <input name="position_title" value="<?= employee_account_h($account["position_title"] ?? "") ?>">
                    </div>
                    <div class="admin-owner-field">
                      <label>Notes</label>
                      <textarea name="employee_notes" rows="3"><?= employee_account_h($account["employee_notes"] ?? "") ?></textarea>
                    </div>
                    <div class="admin-owner-actions">
                      <button class="admin-owner-button-secondary" type="submit">Save</button>
                    </div>
                  </form>
                  <div class="admin-owner-muted">
                    <strong>Profile Details</strong><br>
                    Address: <?= employee_account_h($account["address"] ?: "-") ?><br>
                    Emergency Contact: <?= employee_account_h($account["emergency_contact_name"] ?: "-") ?><br>
                    Relationship: <?= employee_account_h($account["emergency_contact_relationship"] ?: "-") ?><br>
                    Emergency Address: <?= employee_account_h($account["emergency_contact_address"] ?: "-") ?><br>
                    Emergency Number: <?= employee_account_h($account["emergency_contact_number"] ?: "-") ?><br>
                    Setup Completed: <?= employee_account_h(employee_account_format_datetime($account["first_login_completed_at"] ?? "")) ?><br>
                    Last Updated: <?= employee_account_h(employee_account_format_datetime($account["updated_at"] ?? "")) ?>
                  </div>
                </details>

                <form method="post" class="admin-owner-actions" style="margin-top:8px">
                  <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                  <input type="hidden" name="status" value="<?= $isActive ? "deactivated" : "active" ?>">
                  <button class="<?= $isActive ? "admin-owner-button-danger" : "admin-owner-button" ?>" type="submit">
                    <?= $isActive ? "Deactivate" : "Reactivate" ?>
                  </button>
                </form>

                <details style="margin-top:8px">
                  <summary class="admin-owner-button-secondary">Reset Temporary Password</summary>
                  <form class="admin-owner-form employee-account-password-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                    <div class="admin-owner-field">
                      <label>New Temporary Password</label>
                      <input name="temporary_password" type="password" autocomplete="new-password" required>
                    </div>
                    <div class="admin-owner-field">
                      <label>Confirm Temporary Password</label>
                      <input name="temporary_password_confirm" type="password" autocomplete="new-password" required>
                    </div>
                    <div class="admin-owner-actions">
                      <button class="admin-owner-button-secondary" type="button" data-generate-temp-password>Generate</button>
                      <button class="admin-owner-button-secondary" type="button" data-copy-temp-password>Copy</button>
                      <button class="admin-owner-button-secondary" type="submit"<?= $supabaseAdminReady ? "" : " disabled" ?>>Reset</button>
                    </div>
                  </form>
                </details>

                <form method="post" class="admin-owner-actions" style="margin-top:8px">
                  <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="force_password_change">
                  <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                  <button class="admin-owner-button-secondary" type="submit">Force Password Change</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</main>
<script>
  (function () {
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
  })();
</script>
</body>
</html>
