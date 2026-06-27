<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/account.php";
require_once __DIR__ . "/../../config/activity_log.php";

function admin_profile_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function admin_profile_datetime($value): string
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

$adminId = (int)($_SESSION["user_id"] ?? 0);
$notice = "";
$error = "";

$stmt = $pdo->prepare("
    SELECT id, fullname, email, contact,
           COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer') AS role,
           COALESCE(NULLIF(to_jsonb(users)->>'account_status', ''), 'active') AS account_status,
           COALESCE(NULLIF(to_jsonb(users)->>'last_login_at', ''), '') AS last_login_at,
           created_at,
           NULLIF(to_jsonb(users)->>'password_hash', '') AS password_hash
    FROM users
    WHERE id = :id
      AND LOWER(TRIM(COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer'))) IN ('admin', 'super_admin')
    LIMIT 1
");
$stmt->execute([":id" => $adminId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    header("Location: " . admin_url_raw("/auth/log_in.php?login=session_expired"));
    exit();
}

$canChangeLocalPassword = !servitech_supabase_auth_enabled();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    servitech_enforce_same_origin(true);
    servitech_enforce_csrf_token(true);

    if (!$canChangeLocalPassword) {
        $error = "Password changes are handled by Supabase Auth for this deployment.";
    } else {
        $currentPassword = (string)($_POST["current_password"] ?? "");
        $newPassword = (string)($_POST["new_password"] ?? "");
        $confirmPassword = (string)($_POST["confirm_password"] ?? "");
        $storedHash = (string)($profile["password_hash"] ?? "");

        try {
            if ($storedHash === "" || !password_verify($currentPassword, $storedHash)) {
                throw new DomainException("Current password is incorrect.");
            }
            if ($newPassword !== $confirmPassword) {
                throw new DomainException("New passwords do not match.");
            }
            $passwordError = servitech_password_validation_error($newPassword);
            if ($passwordError !== "") {
                throw new DomainException($passwordError);
            }

            $update = $pdo->prepare("
                UPDATE users
                SET password_hash = :password_hash,
                    force_password_change = FALSE,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $update->execute([
                ":password_hash" => password_hash($newPassword, PASSWORD_DEFAULT),
                ":id" => $adminId,
            ]);
            $activityRoleLabel = servitech_normalize_role($profile["role"] ?? "admin") === "admin"
                ? "Admin / Employee"
                : servitech_role_label($profile["role"] ?? "admin");
            servitech_activity_log($pdo, [
                "action_type" => "admin_password_change",
                "module" => "admin_profile",
                "target_record_id" => (string)$adminId,
                "description" => $activityRoleLabel . " changed their own password.",
            ]);
            $notice = "Password updated successfully.";
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            servitech_activity_log($pdo, [
                "action_type" => "admin_password_change",
                "module" => "admin_profile",
                "target_record_id" => (string)$adminId,
                "description" => "Admin / Employee password change failed for user #{$adminId}.",
                "status" => "failed",
            ]);
        }
    }
}

$role = servitech_normalize_role($profile["role"] ?? "admin");
$roleLabel = $role === "admin" ? "Admin / Employee" : servitech_role_label($role);
$profileTitle = servitech_admin_employee_banner_title($pdo, "My Admin Profile");
$csrfToken = servitech_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Admin Profile | ServiTech</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260626-roles') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260626-profile') ?>">
</head>
<body>
<?php require __DIR__ . "/_includes/admin_header.php"; ?>

<main class="admin-owner-shell">
  <section class="admin-owner-hero">
    <span class="admin-owner-kicker"><?= admin_profile_h($roleLabel) ?></span>
    <h1><?= admin_profile_h($profileTitle) ?></h1>
    <p>View your staff account details and manage your own password when local password auth is enabled.</p>
  </section>

  <?php if ($notice !== ""): ?>
    <div class="admin-owner-alert"><?= admin_profile_h($notice) ?></div>
  <?php endif; ?>
  <?php if ($error !== ""): ?>
    <div class="admin-owner-alert admin-owner-alert--error"><?= admin_profile_h($error) ?></div>
  <?php endif; ?>

  <section class="admin-owner-grid">
    <article class="admin-owner-panel">
      <h2>Account Details</h2>
      <table class="admin-profile-table">
        <tr><th>Name</th><td><?= admin_profile_h($profile["fullname"] ?? "") ?></td></tr>
        <tr><th>Email</th><td><?= admin_profile_h($profile["email"] ?? "") ?></td></tr>
        <tr><th>Contact</th><td><?= admin_profile_h($profile["contact"] ?: "-") ?></td></tr>
        <tr><th>Role</th><td><?= admin_profile_h($roleLabel) ?></td></tr>
        <tr><th>Status</th><td><?= admin_profile_h(ucfirst((string)($profile["account_status"] ?? "active"))) ?></td></tr>
        <tr><th>Last Login</th><td><?= admin_profile_h(admin_profile_datetime($profile["last_login_at"] ?? "")) ?></td></tr>
        <tr><th>Created</th><td><?= admin_profile_h(admin_profile_datetime($profile["created_at"] ?? "")) ?></td></tr>
      </table>
    </article>

    <article class="admin-owner-panel">
      <h2>Change Password</h2>
      <?php if (!$canChangeLocalPassword): ?>
        <p class="admin-owner-muted">This deployment uses Supabase Auth. Use the secure password reset flow if you need to change your password.</p>
        <a class="admin-owner-button-secondary" href="<?= admin_url('/auth/forgot_password.php') ?>">Open Password Reset</a>
      <?php else: ?>
        <form class="admin-owner-form" method="post">
          <input type="hidden" name="csrf_token" value="<?= admin_profile_h($csrfToken) ?>">
          <div class="admin-owner-field">
            <label for="current_password">Current Password</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
          </div>
          <div class="admin-owner-field">
            <label for="new_password">New Password</label>
            <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="<?= SERVITECH_PASSWORD_MIN_LENGTH ?>" required>
          </div>
          <div class="admin-owner-field">
            <label for="confirm_password">Confirm New Password</label>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="<?= SERVITECH_PASSWORD_MIN_LENGTH ?>" required>
          </div>
          <button class="admin-owner-button" type="submit">Update Password</button>
        </form>
      <?php endif; ?>
    </article>
  </section>
</main>
</body>
</html>
