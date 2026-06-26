<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/activity_log.php";
require_once __DIR__ . "/../../config/employee_setup.php";

if (servitech_current_role() !== "admin") {
    header("Location: " . admin_url_raw(servitech_internal_dashboard_path()));
    exit();
}

if (!servitech_employee_setup_required($pdo)) {
    header("Location: " . admin_url_raw("/pages/admin/admin_dashboard.php"));
    exit();
}

function employee_setup_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function employee_setup_validate_password(string $password, string $confirmation): void
{
    if ($password === "" || $confirmation === "") {
        throw new DomainException("New password and confirmation are required.");
    }
    if (!hash_equals($password, $confirmation)) {
        throw new DomainException("Password confirmation does not match.");
    }
    if (strlen($password) < 8) {
        throw new DomainException("Password must be at least 8 characters.");
    }
    if (!preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        throw new DomainException("Password must include uppercase, lowercase, number, and special character.");
    }
}

function employee_setup_validate_phone(string $value, string $label): void
{
    $value = trim($value);
    if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $value)) {
        throw new DomainException("{$label} must use 09XXXXXXXXX or +639XXXXXXXXX format.");
    }
}

function employee_setup_validate_name(string $value, string $label): void
{
    $value = trim($value);
    if ($value === "" || strlen($value) > 160 || !preg_match('/^[\pL\s.\'-]+$/u', $value)) {
        throw new DomainException("Enter a valid {$label}.");
    }
}

$profileStmt = $pdo->prepare("
    SELECT id, fullname, email, contact, address, emergency_contact_name,
           emergency_contact_relationship, emergency_contact_address,
           emergency_contact_number
    FROM users
    WHERE id = :id
      AND LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
    LIMIT 1
");
$profileStmt->execute([":id" => (int)($_SESSION["user_id"] ?? 0)]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($profile)) {
    header("Location: " . admin_url_raw("/pages/admin/access_denied.php"));
    exit();
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_enforce_same_origin(true);
    servitech_enforce_csrf_token(true);

    $newPassword = (string)($_POST["new_password"] ?? "");
    $confirmPassword = (string)($_POST["confirm_password"] ?? "");
    $contact = trim((string)($_POST["contact"] ?? ""));
    $address = trim((string)($_POST["address"] ?? ""));
    $emergencyName = trim((string)($_POST["emergency_contact_name"] ?? ""));
    $emergencyRelationship = trim((string)($_POST["emergency_contact_relationship"] ?? ""));
    $emergencyAddress = trim((string)($_POST["emergency_contact_address"] ?? ""));
    $emergencyNumber = trim((string)($_POST["emergency_contact_number"] ?? ""));

    try {
        employee_setup_validate_password($newPassword, $confirmPassword);
        employee_setup_validate_phone($contact, "Contact number");
        employee_setup_validate_phone($emergencyNumber, "Emergency contact number");
        if ($address === "" || strlen($address) > 500) {
            throw new DomainException("Address is required and must be 500 characters or fewer.");
        }
        employee_setup_validate_name($emergencyName, "emergency contact name");
        if ($emergencyRelationship === "" || strlen($emergencyRelationship) > 80) {
            throw new DomainException("Emergency contact relationship is required.");
        }
        if ($emergencyAddress === "" || strlen($emergencyAddress) > 500) {
            throw new DomainException("Emergency contact address is required and must be 500 characters or fewer.");
        }

        if (!servitech_supabase_auth_enabled()) {
            throw new DomainException("Employee setup requires Supabase Auth.");
        }
        $accessToken = trim((string)($_SESSION["supabase_access_token"] ?? ""));
        if ($accessToken === "") {
            throw new DomainException("Your secure Auth session expired. Please log in again.");
        }

        try {
            servitech_supabase_sign_in((string)$profile["email"], $newPassword);
            throw new DomainException("New password must be different from your temporary password.");
        } catch (DomainException $samePasswordCheck) {
            if ($samePasswordCheck->getMessage() === "New password must be different from your temporary password.") {
                throw $samePasswordCheck;
            }
        }

        servitech_supabase_update_user($accessToken, ["password" => $newPassword]);

        $update = $pdo->prepare("
            UPDATE users
            SET contact = :contact,
                address = :address,
                emergency_contact_name = :emergency_contact_name,
                emergency_contact_relationship = :emergency_contact_relationship,
                emergency_contact_address = :emergency_contact_address,
                emergency_contact_number = :emergency_contact_number,
                force_password_change = FALSE,
                profile_completed = TRUE,
                first_login_completed_at = COALESCE(first_login_completed_at, NOW()),
                updated_at = NOW()
            WHERE id = :id
        ");
        $update->execute([
            ":contact" => $contact,
            ":address" => $address,
            ":emergency_contact_name" => $emergencyName,
            ":emergency_contact_relationship" => $emergencyRelationship,
            ":emergency_contact_address" => $emergencyAddress,
            ":emergency_contact_number" => $emergencyNumber,
            ":id" => (int)$profile["id"],
        ]);

        servitech_activity_log($pdo, [
            "actor_id" => (int)$profile["id"],
            "role" => "admin",
            "action_type" => "employee_first_time_setup_complete",
            "module" => "employee_setup",
            "target_record_id" => (string)$profile["id"],
            "description" => "Employee " . (string)($profile["fullname"] ?? $profile["email"] ?? "employee") . " completed first-time account setup and changed temporary password.",
        ]);
        servitech_admin_flash_toast("Account setup completed successfully.", "success");
        header("Location: " . admin_url_raw("/pages/admin/admin_dashboard.php"), true, 303);
        exit();
    } catch (Throwable $exception) {
        servitech_activity_log($pdo, [
            "actor_id" => (int)$profile["id"],
            "role" => "admin",
            "action_type" => "employee_first_time_setup_failed",
            "module" => "employee_setup",
            "target_record_id" => (string)$profile["id"],
            "description" => "Employee first-time setup submission failed: " . $exception->getMessage(),
            "status" => "failed",
        ]);
        servitech_admin_flash_toast($exception->getMessage(), "error");
        header("Location: " . admin_url_raw(servitech_employee_setup_path()), true, 303);
        exit();
    }
}

$csrfToken = servitech_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Complete Your Employee Account | ServiTech Admin</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260626-roles') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260626-roles') ?>">
</head>
<body>
<?php require __DIR__ . "/_includes/admin_header.php"; ?>

<main class="admin-owner-shell">
  <section class="admin-owner-hero">
    <span class="admin-owner-kicker">Employee Access</span>
    <h1>Complete Your Employee Account</h1>
    <p>Please update your password and complete your contact details before accessing the Admin Dashboard.</p>
  </section>

  <div class="admin-owner-alert">Your temporary password must be changed before you can access the Admin Dashboard.</div>

  <section class="admin-owner-panel">
    <form class="admin-owner-form" method="post" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?= employee_setup_h($csrfToken) ?>">

      <h2>Change Password</h2>
      <div class="admin-owner-field">
        <label for="new_password">New Password</label>
        <input id="new_password" name="new_password" type="password" autocomplete="new-password" required>
      </div>
      <div class="admin-owner-field">
        <label for="confirm_password">Confirm New Password</label>
        <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
      </div>

      <h2>Contact Details</h2>
      <div class="admin-owner-field">
        <label for="contact">Contact Number</label>
        <input id="contact" name="contact" value="<?= employee_setup_h($profile["contact"] ?? "") ?>" placeholder="09XXXXXXXXX" autocomplete="tel" required>
      </div>
      <div class="admin-owner-field">
        <label for="address">Address</label>
        <textarea id="address" name="address" rows="4" required><?= employee_setup_h($profile["address"] ?? "") ?></textarea>
      </div>

      <h2>Emergency Contact</h2>
      <div class="admin-owner-field">
        <label for="emergency_contact_name">Full Name</label>
        <input id="emergency_contact_name" name="emergency_contact_name" value="<?= employee_setup_h($profile["emergency_contact_name"] ?? "") ?>" required>
      </div>
      <div class="admin-owner-field">
        <label for="emergency_contact_relationship">Relationship</label>
        <input id="emergency_contact_relationship" name="emergency_contact_relationship" value="<?= employee_setup_h($profile["emergency_contact_relationship"] ?? "") ?>" placeholder="Parent, Sibling, Spouse, Guardian" required>
      </div>
      <div class="admin-owner-field">
        <label for="emergency_contact_address">Address</label>
        <textarea id="emergency_contact_address" name="emergency_contact_address" rows="4" required><?= employee_setup_h($profile["emergency_contact_address"] ?? "") ?></textarea>
      </div>
      <div class="admin-owner-field">
        <label for="emergency_contact_number">Contact Number</label>
        <input id="emergency_contact_number" name="emergency_contact_number" value="<?= employee_setup_h($profile["emergency_contact_number"] ?? "") ?>" placeholder="09XXXXXXXXX" autocomplete="tel" required>
      </div>

      <button class="admin-owner-button" type="submit">Complete Account Setup</button>
    </form>
  </section>
</main>
</body>
</html>
