<?php
require_once __DIR__ . "/../admin/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/../admin/_includes/admin_db.php";
require_once __DIR__ . "/../admin/_includes/url.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/activity_log.php";

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

function employee_account_load_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT u.id, u.auth_user_id::text AS auth_user_id, u.fullname, u.email, u.contact,
               u.role, u.account_status, u.force_password_change, u.last_login_at,
               u.created_at, u.created_by
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

function employee_account_require_confirmed_auth_user(?array $authUser, string $email): array
{
    if (!$authUser) {
        throw new DomainException("No Supabase Auth user exists for {$email}. Create or invite the employee in Supabase Auth first, then link the profile here.");
    }

    $authUserId = strtolower(trim((string)($authUser["auth_user_id"] ?? "")));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        throw new DomainException("The matching Supabase Auth user is invalid.");
    }

    if (trim((string)($authUser["email_confirmed_at"] ?? "")) === "") {
        throw new DomainException("The Supabase Auth user must verify their email before it can be linked as an employee account.");
    }

    return $authUser;
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

function employee_account_redirect_self(): void
{
    header("Location: " . admin_url_raw("/pages/super_admin/super_admin_employee_accounts.php"), true, 303);
    exit();
}

$schemaReady = admin_table_has_columns($pdo, "users", [
    "auth_user_id",
    "account_status",
    "force_password_change",
    "last_login_at",
    "created_by",
    "deactivated_at",
    "deactivated_by",
    "password_hash",
    "email_verified_at",
]);
$authUsersReady = employee_account_auth_users_available($pdo);
$pageReady = $schemaReady && $authUsersReady;

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_enforce_same_origin(true);
    servitech_enforce_csrf_token(true);

    $action = trim((string)($_POST["action"] ?? ""));
    $currentAdminId = (int)($_SESSION["user_id"] ?? 0);

    try {
        if (!$pageReady) {
            throw new DomainException("Employee Accounts needs the Supabase Auth role migration and auth.users access before account changes can be made.");
        }

        if ($action === "create") {
            $fullname = trim((string)($_POST["fullname"] ?? ""));
            $email = strtolower(trim((string)($_POST["email"] ?? "")));
            $contact = trim((string)($_POST["contact"] ?? ""));

            if ($fullname === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException("Enter a valid employee name and email.");
            }

            $authUser = employee_account_require_confirmed_auth_user(
                employee_account_load_auth_user_by_email($pdo, $email),
                $email
            );
            $authUserId = strtolower((string)$authUser["auth_user_id"]);
            employee_account_assert_email_not_taken($pdo, $email, $authUserId);

            $existing = $pdo->prepare("
                SELECT id, fullname, email, contact, role, account_status
                FROM users
                WHERE auth_user_id = CAST(:auth_user_id AS uuid)
                LIMIT 1
            ");
            $existing->execute([":auth_user_id" => $authUserId]);
            $existingAccount = $existing->fetch(PDO::FETCH_ASSOC);

            if (is_array($existingAccount)) {
                if (servitech_normalize_role($existingAccount["role"] ?? "customer") === "super_admin") {
                    throw new DomainException("Owner Super Admin accounts cannot be managed as employee accounts.");
                }

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET fullname = :fullname,
                        email = :email,
                        contact = :contact,
                        role = 'admin',
                        account_status = 'active',
                        force_password_change = FALSE,
                        email_verified_at = COALESCE(email_verified_at, :confirmed_at),
                        updated_at = NOW()
                    WHERE id = :id
                    RETURNING id
                ");
                $stmt->execute([
                    ":fullname" => $fullname,
                    ":email" => $email,
                    ":contact" => $contact !== "" ? $contact : null,
                    ":confirmed_at" => $authUser["email_confirmed_at"],
                    ":id" => (int)$existingAccount["id"],
                ]);
                $employeeId = (int)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        auth_user_id, fullname, email, contact, password_hash, role,
                        account_status, force_password_change, email_verified_at,
                        created_by, created_at, updated_at
                    ) VALUES (
                        CAST(:auth_user_id AS uuid), :fullname, :email, :contact, NULL, 'admin',
                        'active', FALSE, :confirmed_at,
                        :created_by, NOW(), NOW()
                    )
                    RETURNING id
                ");
                $stmt->execute([
                    ":auth_user_id" => $authUserId,
                    ":fullname" => $fullname,
                    ":email" => $email,
                    ":contact" => $contact !== "" ? $contact : null,
                    ":confirmed_at" => $authUser["email_confirmed_at"],
                    ":created_by" => $currentAdminId > 0 ? $currentAdminId : null,
                ]);
                $employeeId = (int)$stmt->fetchColumn();
            }

            servitech_activity_log($pdo, [
                "action_type" => "employee_account_create",
                "module" => "employee_accounts",
                "target_record_id" => (string)$employeeId,
                "new_value" => ["email" => $email, "role" => "admin", "auth_user_id" => $authUserId],
                "description" => "Super Admin created or linked an Employee Admin account for {$fullname}.",
            ]);
            servitech_admin_flash_toast("Employee account linked successfully.", "success");
        } elseif ($action === "update") {
            $employeeId = (int)($_POST["employee_id"] ?? 0);
            $account = employee_account_load_by_id($pdo, $employeeId);
            if (!$account) {
                throw new DomainException("Employee account not found.");
            }

            $fullname = trim((string)($_POST["fullname"] ?? ""));
            $email = strtolower(trim((string)($_POST["email"] ?? "")));
            $contact = trim((string)($_POST["contact"] ?? ""));

            if ($fullname === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException("Enter a valid employee name and email.");
            }

            $authUser = employee_account_require_confirmed_auth_user(
                employee_account_load_auth_user_by_email($pdo, $email),
                $email
            );
            $authUserId = strtolower((string)$authUser["auth_user_id"]);
            if ($authUserId !== strtolower((string)$account["auth_user_id"])) {
                throw new DomainException("To change an employee login email, update the existing Supabase Auth user first. This page will only keep the current Auth link.");
            }
            employee_account_assert_email_not_taken($pdo, $email, $authUserId, $employeeId);

            $stmt = $pdo->prepare("
                UPDATE users
                SET fullname = :fullname,
                    email = :email,
                    contact = :contact,
                    role = 'admin',
                    email_verified_at = COALESCE(email_verified_at, :confirmed_at),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ":fullname" => $fullname,
                ":email" => $email,
                ":contact" => $contact !== "" ? $contact : null,
                ":confirmed_at" => $authUser["email_confirmed_at"],
                ":id" => $employeeId,
            ]);
            servitech_activity_log($pdo, [
                "action_type" => "employee_account_update",
                "module" => "employee_accounts",
                "target_record_id" => (string)$employeeId,
                "old_value" => $account,
                "new_value" => ["fullname" => $fullname, "email" => $email, "contact" => $contact, "role" => "admin"],
                "description" => "Super Admin updated the Employee Admin account for {$fullname}.",
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
                "description" => "Super Admin " . ($status === "active" ? "activated" : "deactivated") . " the Employee Admin account for " . (string)($account["fullname"] ?? $account["email"] ?? "employee") . ".",
            ]);
            servitech_admin_flash_toast($status === "active" ? "Employee account activated." : "Employee account deactivated.", "success");
        } elseif ($action === "reset_password") {
            $employeeId = (int)($_POST["employee_id"] ?? 0);
            $account = employee_account_load_by_id($pdo, $employeeId);
            if (!$account) {
                throw new DomainException("Employee account not found.");
            }
            if (!servitech_supabase_auth_configured()) {
                throw new DomainException("Supabase Auth recovery email is not configured.");
            }

            servitech_supabase_send_recovery((string)$account["email"], servitech_supabase_recovery_redirect_url());
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
                "description" => "Super Admin sent a secure password reset email for " . (string)($account["fullname"] ?? $account["email"] ?? "employee") . ".",
            ]);
            servitech_admin_flash_toast("Password reset email sent to the employee.", "success");
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
               u.role, u.account_status, u.force_password_change, u.last_login_at,
               u.created_at, creator.fullname AS created_by_name,
               auth_account.email_confirmed_at
        FROM users u
        INNER JOIN auth.users auth_account
          ON auth_account.id = u.auth_user_id
         AND auth_account.deleted_at IS NULL
        LEFT JOIN users creator ON creator.id = u.created_by
        WHERE u.auth_user_id IS NOT NULL
          AND LOWER(TRIM(COALESCE(u.role, 'customer'))) = 'admin'
        ORDER BY
          LOWER(TRIM(COALESCE(u.account_status, 'active'))) ASC,
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
    <p>Link Supabase Auth users to employee Admin profiles, update basic details, deactivate access, and send secure password reset emails without exposing credentials.</p>
  </section>

  <?php if (!$schemaReady): ?>
    <div class="admin-owner-alert admin-owner-alert--error">Employee Accounts needs the 20260626 role migration and the Supabase Auth linkage column before this page can be used.</div>
  <?php endif; ?>
  <?php if ($schemaReady && !$authUsersReady): ?>
    <div class="admin-owner-alert admin-owner-alert--error">Employee Accounts requires readable Supabase Auth users. The main list is hidden until Auth linkage can be verified.</div>
  <?php endif; ?>
  <div class="admin-owner-alert">Create or invite the employee in Supabase Auth first. This page only links verified Auth users to ServiTech employee profiles and never displays passwords.</div>

  <section class="admin-owner-grid">
    <aside class="admin-owner-panel">
      <h2>Link Employee Admin Account</h2>
      <form class="admin-owner-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
        <input type="hidden" name="action" value="create">
        <div class="admin-owner-field">
          <label for="fullname">Full Name</label>
          <input id="fullname" name="fullname" autocomplete="name" required>
        </div>
        <div class="admin-owner-field">
          <label for="email">Supabase Auth Email</label>
          <input id="email" name="email" type="email" autocomplete="email" required>
        </div>
        <div class="admin-owner-field">
          <label for="contact">Contact</label>
          <input id="contact" name="contact" autocomplete="tel">
        </div>
        <div class="admin-owner-field">
          <label>Role</label>
          <input value="Admin / Employee" readonly>
        </div>
        <button class="admin-owner-button" type="submit"<?= $pageReady ? "" : " disabled" ?>>Link Employee Account</button>
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
              <th>Dates</th>
              <th>Update Details</th>
              <th>Password Reset</th>
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
            ?>
            <tr>
              <td>
                <strong><?= employee_account_h($account["fullname"] ?? "") ?></strong>
                <small><?= employee_account_h($account["email"] ?? "") ?></small><br>
                <small><?= employee_account_h($account["contact"] ?? "-") ?></small><br>
                <small>Auth ID: <?= employee_account_h($account["auth_user_id"] ?? "-") ?></small>
              </td>
              <td><span class="admin-owner-pill">Admin / Employee</span></td>
              <td>
                <span class="admin-owner-pill<?= $isActive ? "" : " admin-owner-pill--danger" ?>"><?= employee_account_h(ucfirst($status)) ?></span>
                <?php if (!empty($account["force_password_change"])): ?>
                  <br><small>Password reset requested</small>
                <?php endif; ?>
              </td>
              <td>
                <small>Created: <?= employee_account_h(employee_account_format_datetime($account["created_at"] ?? "")) ?></small><br>
                <small>Last login: <?= employee_account_h(employee_account_format_datetime($account["last_login_at"] ?? "")) ?></small><br>
                <small>Created by: <?= employee_account_h($account["created_by_name"] ?: "-") ?></small>
              </td>
              <td>
                <form class="admin-owner-form" method="post">
                  <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                  <div class="admin-owner-field">
                    <label>Name</label>
                    <input name="fullname" value="<?= employee_account_h($account["fullname"] ?? "") ?>" required>
                  </div>
                  <div class="admin-owner-field">
                    <label>Supabase Auth Email</label>
                    <input name="email" type="email" value="<?= employee_account_h($account["email"] ?? "") ?>" required>
                  </div>
                  <div class="admin-owner-field">
                    <label>Contact</label>
                    <input name="contact" value="<?= employee_account_h($account["contact"] ?? "") ?>">
                  </div>
                  <div class="admin-owner-actions">
                    <button class="admin-owner-button-secondary" type="submit">Save</button>
                  </div>
                </form>
                <form method="post" class="admin-owner-actions" style="margin-top:8px">
                  <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                  <input type="hidden" name="status" value="<?= $isActive ? "deactivated" : "active" ?>">
                  <button class="<?= $isActive ? "admin-owner-button-danger" : "admin-owner-button" ?>" type="submit">
                    <?= $isActive ? "Deactivate" : "Activate" ?>
                  </button>
                </form>
              </td>
              <td>
                <form class="admin-owner-form" method="post">
                  <input type="hidden" name="csrf_token" value="<?= employee_account_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="reset_password">
                  <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                  <p class="admin-owner-muted">Sends the employee a Supabase password reset email. No password is shown or stored here.</p>
                  <button class="admin-owner-button-secondary" type="submit">Send Reset Email</button>
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
</body>
</html>
