<?php
require_once __DIR__ . "/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/account.php";
require_once __DIR__ . "/../../config/activity_log.php";

function staff_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function staff_role_options(): array
{
    return [
        "admin" => "Admin",
        "super_admin" => "Super Admin",
    ];
}

function staff_format_datetime($value): string
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

function staff_active_super_admin_count(PDO $pdo): int
{
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'super_admin'
          AND LOWER(TRIM(COALESCE(account_status, 'active'))) = 'active'
    ");
    return (int)$stmt->fetchColumn();
}

function staff_load_account(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, fullname, email, contact, role, account_status, force_password_change
        FROM users
        WHERE id = :id
          AND LOWER(TRIM(COALESCE(role, 'customer'))) IN ('admin', 'super_admin')
        LIMIT 1
    ");
    $stmt->execute([":id" => $id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($account) ? $account : null;
}

function staff_validate_password(string $password): void
{
    if (strlen($password) < SERVITECH_PASSWORD_MIN_LENGTH) {
        throw new DomainException("Password must be at least " . SERVITECH_PASSWORD_MIN_LENGTH . " characters.");
    }
    if (strlen($password) > SERVITECH_PASSWORD_MAX_BYTES) {
        throw new DomainException("Password is too long.");
    }
}

$schemaReady = admin_table_has_columns($pdo, "users", [
    "account_status",
    "force_password_change",
    "last_login_at",
    "created_by",
    "deactivated_at",
    "deactivated_by",
]);
$notice = "";
$error = "";

if ($schemaReady && ($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_enforce_same_origin(true);
    servitech_enforce_csrf_token(true);

    $action = trim((string)($_POST["action"] ?? ""));
    $currentAdminId = (int)($_SESSION["user_id"] ?? 0);

    try {
        if ($action === "create") {
            $fullname = trim((string)($_POST["fullname"] ?? ""));
            $email = strtolower(trim((string)($_POST["email"] ?? "")));
            $contact = trim((string)($_POST["contact"] ?? ""));
            $role = servitech_normalize_role($_POST["role"] ?? "admin");
            $password = (string)($_POST["password"] ?? "");
            $forcePasswordChange = !empty($_POST["force_password_change"]);

            if ($fullname === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException("Enter a valid staff name and email.");
            }
            if (!array_key_exists($role, staff_role_options())) {
                throw new DomainException("Choose a valid staff role.");
            }
            staff_validate_password($password);

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    fullname, email, contact, password_hash, role, account_status,
                    force_password_change, email_verified_at, created_by, created_at, updated_at
                ) VALUES (
                    :fullname, :email, :contact, :password_hash, :role, 'active',
                    :force_password_change, NOW(), :created_by, NOW(), NOW()
                )
                RETURNING id
            ");
            $stmt->execute([
                ":fullname" => $fullname,
                ":email" => $email,
                ":contact" => $contact !== "" ? $contact : null,
                ":password_hash" => $hash,
                ":role" => $role,
                ":force_password_change" => $forcePasswordChange ? "true" : "false",
                ":created_by" => $currentAdminId > 0 ? $currentAdminId : null,
            ]);
            $createdId = (int)$stmt->fetchColumn();
            servitech_activity_log($pdo, [
                "action_type" => "staff_create",
                "module" => "staff_accounts",
                "target_record_id" => (string)$createdId,
                "new_value" => ["email" => $email, "role" => $role],
                "description" => "Super Admin created a new " . servitech_role_label($role) . " account for {$fullname}.",
            ]);
            $notice = "Staff account created. Share the temporary password privately and require a reset after first use.";
        } elseif ($action === "update") {
            $staffId = (int)($_POST["staff_id"] ?? 0);
            $account = staff_load_account($pdo, $staffId);
            if (!$account) {
                throw new DomainException("Staff account not found.");
            }

            $fullname = trim((string)($_POST["fullname"] ?? ""));
            $email = strtolower(trim((string)($_POST["email"] ?? "")));
            $contact = trim((string)($_POST["contact"] ?? ""));
            $role = servitech_normalize_role($_POST["role"] ?? "admin");
            $forcePasswordChange = !empty($_POST["force_password_change"]);

            if ($fullname === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException("Enter a valid staff name and email.");
            }
            if (!array_key_exists($role, staff_role_options())) {
                throw new DomainException("Choose a valid staff role.");
            }
            if ((int)$account["id"] === $currentAdminId && $role !== "super_admin") {
                throw new DomainException("You cannot remove your own Super Admin role.");
            }
            if (
                servitech_normalize_role($account["role"] ?? "") === "super_admin"
                && $role !== "super_admin"
                && strtolower((string)($account["account_status"] ?? "active")) === "active"
                && staff_active_super_admin_count($pdo) <= 1
            ) {
                throw new DomainException("At least one active Super Admin must remain.");
            }

            $stmt = $pdo->prepare("
                UPDATE users
                SET fullname = :fullname,
                    email = :email,
                    contact = :contact,
                    role = :role,
                    force_password_change = :force_password_change,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ":fullname" => $fullname,
                ":email" => $email,
                ":contact" => $contact !== "" ? $contact : null,
                ":role" => $role,
                ":force_password_change" => $forcePasswordChange ? "true" : "false",
                ":id" => $staffId,
            ]);
            servitech_activity_log($pdo, [
                "action_type" => "staff_update",
                "module" => "staff_accounts",
                "target_record_id" => (string)$staffId,
                "old_value" => $account,
                "new_value" => ["fullname" => $fullname, "email" => $email, "contact" => $contact, "role" => $role],
                "description" => "Super Admin updated the staff account for {$fullname}.",
            ]);
            $notice = "Staff account updated.";
        } elseif ($action === "set_status") {
            $staffId = (int)($_POST["staff_id"] ?? 0);
            $status = strtolower(trim((string)($_POST["status"] ?? "")));
            $account = staff_load_account($pdo, $staffId);
            if (!$account || !in_array($status, ["active", "deactivated"], true)) {
                throw new DomainException("Invalid staff status request.");
            }
            if ($staffId === $currentAdminId && $status === "deactivated") {
                throw new DomainException("You cannot deactivate your own account.");
            }
            if (
                $status === "deactivated"
                && servitech_normalize_role($account["role"] ?? "") === "super_admin"
                && strtolower((string)($account["account_status"] ?? "active")) === "active"
                && staff_active_super_admin_count($pdo) <= 1
            ) {
                throw new DomainException("At least one active Super Admin must remain.");
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
                ":id" => $staffId,
            ]);
            servitech_activity_log($pdo, [
                "action_type" => $status === "active" ? "staff_activate" : "staff_deactivate",
                "module" => "staff_accounts",
                "target_record_id" => (string)$staffId,
                "old_value" => ["account_status" => $account["account_status"] ?? ""],
                "new_value" => ["account_status" => $status],
                "description" => "Super Admin " . ($status === "active" ? "activated" : "deactivated") . " the staff account for " . (string)($account["fullname"] ?? $account["email"] ?? "staff") . ".",
            ]);
            $notice = $status === "active" ? "Staff account activated." : "Staff account deactivated.";
        } elseif ($action === "reset_password") {
            $staffId = (int)($_POST["staff_id"] ?? 0);
            $password = (string)($_POST["new_password"] ?? "");
            $account = staff_load_account($pdo, $staffId);
            if (!$account) {
                throw new DomainException("Staff account not found.");
            }
            staff_validate_password($password);

            $stmt = $pdo->prepare("
                UPDATE users
                SET password_hash = :password_hash,
                    force_password_change = TRUE,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ":password_hash" => password_hash($password, PASSWORD_DEFAULT),
                ":id" => $staffId,
            ]);
            servitech_activity_log($pdo, [
                "action_type" => "staff_password_reset",
                "module" => "staff_accounts",
                "target_record_id" => (string)$staffId,
                "description" => "Super Admin reset the staff password for " . (string)($account["fullname"] ?? $account["email"] ?? "staff") . ".",
            ]);
            $notice = "Password reset. Share the new temporary password privately.";
        }
    } catch (PDOException $exception) {
        $error = str_contains(strtolower($exception->getMessage()), "unique")
            ? "That email is already used by another account."
            : "Unable to save the staff account.";
        error_log("staff account save error: " . $exception->getMessage());
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$staffAccounts = [];
if ($schemaReady) {
    $stmt = $pdo->query("
        SELECT u.id, u.fullname, u.email, u.contact, u.role, u.account_status,
               u.force_password_change, u.last_login_at, u.created_at,
               creator.fullname AS created_by_name
        FROM users u
        LEFT JOIN users creator ON creator.id = u.created_by
        WHERE LOWER(TRIM(COALESCE(u.role, 'customer'))) IN ('admin', 'super_admin')
        ORDER BY
          CASE WHEN LOWER(TRIM(COALESCE(u.role, 'customer'))) = 'super_admin' THEN 0 ELSE 1 END,
          LOWER(TRIM(COALESCE(u.account_status, 'active'))) ASC,
          u.created_at DESC,
          u.id DESC
    ");
    $staffAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$csrfToken = servitech_csrf_token();
$supabaseMode = servitech_supabase_auth_enabled();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Staff Account Management | ServiTech Admin</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260626-roles') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260626-roles') ?>">
</head>
<body>
<?php require __DIR__ . "/_includes/admin_header.php"; ?>

<main class="admin-owner-shell">
  <section class="admin-owner-hero">
    <span class="admin-owner-kicker">Super Admin</span>
    <h1>Staff Account Management</h1>
    <p>Create employee Admin accounts, update staff details, deactivate access, and reset passwords without exposing stored credentials.</p>
  </section>

  <?php if (!$schemaReady): ?>
    <div class="admin-owner-alert admin-owner-alert--error">Staff management needs the 20260626 role migration before this page can be used.</div>
  <?php endif; ?>
  <?php if ($supabaseMode): ?>
    <div class="admin-owner-alert">Supabase Auth is enabled. This page manages ServiTech staff profile metadata; password sign-in must also exist in Supabase Auth for those staff accounts.</div>
  <?php endif; ?>
  <?php if ($notice !== ""): ?>
    <div class="admin-owner-alert"><?= staff_h($notice) ?></div>
  <?php endif; ?>
  <?php if ($error !== ""): ?>
    <div class="admin-owner-alert admin-owner-alert--error"><?= staff_h($error) ?></div>
  <?php endif; ?>

  <section class="admin-owner-grid">
    <aside class="admin-owner-panel">
      <h2>Create Admin Account</h2>
      <form class="admin-owner-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= staff_h($csrfToken) ?>">
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
          <label for="role">Role</label>
          <select id="role" name="role">
            <?php foreach (staff_role_options() as $role => $label): ?>
              <option value="<?= staff_h($role) ?>"><?= staff_h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-owner-field">
          <label for="password">Temporary Password</label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="<?= SERVITECH_PASSWORD_MIN_LENGTH ?>" required>
        </div>
        <label class="admin-owner-check">
          <input type="checkbox" name="force_password_change" value="1" checked>
          <span>Flag for password change</span>
        </label>
        <button class="admin-owner-button" type="submit"<?= $schemaReady ? "" : " disabled" ?>>Create Account</button>
      </form>
    </aside>

    <section class="admin-owner-panel">
      <h2>Employee/Admin Accounts</h2>
      <div class="admin-owner-table-wrap">
        <table class="admin-owner-table">
          <thead>
            <tr>
              <th>Staff</th>
              <th>Role</th>
              <th>Status</th>
              <th>Dates</th>
              <th>Update Details</th>
              <th>Reset Password</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$staffAccounts): ?>
            <tr><td colspan="6">No staff accounts found.</td></tr>
          <?php endif; ?>
          <?php foreach ($staffAccounts as $account): ?>
            <?php
              $staffId = (int)$account["id"];
              $role = servitech_normalize_role($account["role"] ?? "admin");
              $status = strtolower(trim((string)($account["account_status"] ?? "active")));
              $isActive = $status === "active";
            ?>
            <tr>
              <td>
                <strong><?= staff_h($account["fullname"] ?? "") ?></strong>
                <small><?= staff_h($account["email"] ?? "") ?></small><br>
                <small><?= staff_h($account["contact"] ?? "-") ?></small>
              </td>
              <td><span class="admin-owner-pill"><?= staff_h(servitech_role_label($role)) ?></span></td>
              <td>
                <span class="admin-owner-pill<?= $isActive ? "" : " admin-owner-pill--danger" ?>"><?= staff_h(ucfirst($status)) ?></span>
                <?php if (!empty($account["force_password_change"])): ?>
                  <br><small>Password change flagged</small>
                <?php endif; ?>
              </td>
              <td>
                <small>Created: <?= staff_h(staff_format_datetime($account["created_at"] ?? "")) ?></small><br>
                <small>Last login: <?= staff_h(staff_format_datetime($account["last_login_at"] ?? "")) ?></small><br>
                <small>Created by: <?= staff_h($account["created_by_name"] ?: "-") ?></small>
              </td>
              <td>
                <form class="admin-owner-form" method="post">
                  <input type="hidden" name="csrf_token" value="<?= staff_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="staff_id" value="<?= $staffId ?>">
                  <div class="admin-owner-field">
                    <label>Name</label>
                    <input name="fullname" value="<?= staff_h($account["fullname"] ?? "") ?>" required>
                  </div>
                  <div class="admin-owner-field">
                    <label>Email</label>
                    <input name="email" type="email" value="<?= staff_h($account["email"] ?? "") ?>" required>
                  </div>
                  <div class="admin-owner-field">
                    <label>Contact</label>
                    <input name="contact" value="<?= staff_h($account["contact"] ?? "") ?>">
                  </div>
                  <div class="admin-owner-field">
                    <label>Role</label>
                    <select name="role">
                      <?php foreach (staff_role_options() as $optionRole => $label): ?>
                        <option value="<?= staff_h($optionRole) ?>"<?= $role === $optionRole ? " selected" : "" ?>><?= staff_h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <label class="admin-owner-check">
                    <input type="checkbox" name="force_password_change" value="1"<?= !empty($account["force_password_change"]) ? " checked" : "" ?>>
                    <span>Flag password change</span>
                  </label>
                  <div class="admin-owner-actions">
                    <button class="admin-owner-button-secondary" type="submit">Save</button>
                  </div>
                </form>
                <form method="post" class="admin-owner-actions" style="margin-top:8px">
                  <input type="hidden" name="csrf_token" value="<?= staff_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="staff_id" value="<?= $staffId ?>">
                  <input type="hidden" name="status" value="<?= $isActive ? "deactivated" : "active" ?>">
                  <button class="<?= $isActive ? "admin-owner-button-danger" : "admin-owner-button" ?>" type="submit">
                    <?= $isActive ? "Deactivate" : "Activate" ?>
                  </button>
                </form>
              </td>
              <td>
                <form class="admin-owner-form" method="post">
                  <input type="hidden" name="csrf_token" value="<?= staff_h($csrfToken) ?>">
                  <input type="hidden" name="action" value="reset_password">
                  <input type="hidden" name="staff_id" value="<?= $staffId ?>">
                  <div class="admin-owner-field">
                    <label>New Temporary Password</label>
                    <input name="new_password" type="password" autocomplete="new-password" minlength="<?= SERVITECH_PASSWORD_MIN_LENGTH ?>" required>
                  </div>
                  <button class="admin-owner-button-secondary" type="submit">Reset Password</button>
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
