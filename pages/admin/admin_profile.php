<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/account.php";
require_once __DIR__ . "/../../config/input_limits.php";
require_once __DIR__ . "/../../config/activity_log.php";

const ADMIN_PROFILE_PASSWORD_MIN_LENGTH = 8;

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

function admin_profile_blank($value): string
{
    $value = trim((string)$value);
    return $value !== "" ? $value : "-";
}

function admin_profile_mobile_digits(string $value): string
{
    $digits = preg_replace('/\D+/', '', trim($value)) ?? "";
    if (str_starts_with($digits, "63")) {
        $digits = substr($digits, 2);
    }
    if (str_starts_with($digits, "0")) {
        $digits = substr($digits, 1);
    }

    return substr($digits, 0, 10);
}

function admin_profile_phone_storage_value(string $rawValue, string $mobileValue = ""): string
{
    $source = trim($mobileValue) !== "" ? $mobileValue : $rawValue;
    $mobileDigits = admin_profile_mobile_digits($source);
    if (preg_match('/^9\d{9}$/', $mobileDigits)) {
        return "+63" . $mobileDigits;
    }

    return trim($rawValue !== "" ? $rawValue : $mobileValue);
}

function admin_profile_password_strength_error(string $password): string
{
    if ($password === "") {
        return "New password is required.";
    }
    if (strlen($password) < ADMIN_PROFILE_PASSWORD_MIN_LENGTH) {
        return "New password does not meet the password requirements.";
    }
    if (
        !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        return "New password does not meet the password requirements.";
    }
    if (strlen($password) > SERVITECH_PASSWORD_MAX_BYTES) {
        return "New password does not meet the password requirements.";
    }

    return "";
}

function admin_profile_name_error(string $value, string $label): string
{
    $value = trim($value);
    if ($value === "" || servitech_text_length($value) > SERVITECH_LIMIT_FULLNAME || !preg_match('/^[\pL\s.\'-]+$/u', $value)) {
        return "{$label} is required.";
    }

    return "";
}

function admin_profile_text_error(string $value, string $label, int $maxLength = 500): string
{
    $value = trim($value);
    if ($value === "") {
        return "{$label} is required.";
    }
    if (servitech_text_length($value) > $maxLength) {
        return "{$label} must not exceed {$maxLength} characters.";
    }

    return "";
}

function admin_profile_phone_error(string $value, string $label): string
{
    return preg_match('/^(09\d{9}|\+639\d{9})$/', trim($value))
        ? ""
        : "Enter a valid {$label}.";
}

function admin_profile_relationship_options(): array
{
    return ["Mother", "Father", "Sibling", "Spouse", "Guardian"];
}

function admin_profile_relationship_error(string $value): string
{
    return in_array($value, admin_profile_relationship_options(), true)
        ? ""
        : "Select relationship.";
}

function admin_profile_has_errors(array $errors): bool
{
    foreach ($errors as $message) {
        if ((string)$message !== "") {
            return true;
        }
    }

    return false;
}

function admin_profile_verify_current_password(array $profile, string $currentPassword): array
{
    if ($currentPassword === "") {
        throw new DomainException("Incorrect password. Please try again.");
    }

    if (servitech_supabase_auth_enabled()) {
        try {
            $authResponse = servitech_supabase_sign_in((string)$profile["email"], $currentPassword);
        } catch (DomainException $exception) {
            throw new DomainException("Incorrect password. Please try again.", 0, $exception);
        }
        $authUser = is_array($authResponse["user"] ?? null) ? $authResponse["user"] : [];
        $authUserId = strtolower(trim((string)($authUser["id"] ?? "")));
        $expectedAuthUserId = strtolower(trim((string)($profile["auth_user_id"] ?? $_SESSION["auth_user_id"] ?? "")));

        if ($expectedAuthUserId !== "" && !hash_equals($expectedAuthUserId, $authUserId)) {
            throw new DomainException("Incorrect password. Please try again.");
        }

        return $authResponse;
    }

    $storedHash = (string)($profile["password_hash"] ?? "");
    if ($storedHash === "" || !password_verify($currentPassword, $storedHash)) {
        throw new DomainException("Incorrect password. Please try again.");
    }

    return [];
}

function admin_profile_fetch(PDO $pdo, int $adminId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, auth_user_id, fullname, email, contact, address,
               emergency_contact_name, emergency_contact_relationship,
               emergency_contact_address, emergency_contact_number,
               COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer') AS role,
               COALESCE(NULLIF(to_jsonb(users)->>'account_status', ''), 'active') AS account_status,
               COALESCE(NULLIF(to_jsonb(users)->>'last_login_at', ''), '') AS last_login_at,
               created_at,
               NULLIF(to_jsonb(users)->>'password_hash', '') AS password_hash
        FROM users
        WHERE id = :id
          AND LOWER(TRIM(COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer'))) = 'admin'
        LIMIT 1
    ");
    $stmt->execute([":id" => $adminId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($profile) ? $profile : null;
}

if (servitech_current_role() !== "admin") {
    header("Location: " . admin_url_raw(servitech_internal_dashboard_path()));
    exit();
}

$adminId = (int)($_SESSION["user_id"] ?? 0);
$profile = admin_profile_fetch($pdo, $adminId);

if (!$profile) {
    header("Location: " . admin_url_raw("/auth/log_in.php?login=session_expired"));
    exit();
}

$role = servitech_normalize_role($profile["role"] ?? "admin");
$roleLabel = $role === "admin" ? "Admin / Employee" : servitech_role_label($role);
$profileTitle = servitech_admin_employee_banner_title($pdo, "My Admin Profile");
$csrfToken = servitech_csrf_token();
$toastMessage = "";
$toastType = "info";
$openEditMode = false;
$activeTab = "profile";
$passwordFormValues = ["current_password" => "", "new_password" => "", "confirm_password" => ""];
$profileFormValues = [
    "fullname" => (string)($profile["fullname"] ?? ""),
    "contact" => admin_profile_phone_storage_value((string)($profile["contact"] ?? "")),
    "contact_mobile" => admin_profile_mobile_digits((string)($profile["contact"] ?? "")),
    "address" => (string)($profile["address"] ?? ""),
    "emergency_contact_name" => (string)($profile["emergency_contact_name"] ?? ""),
    "emergency_contact_relationship" => (string)($profile["emergency_contact_relationship"] ?? ""),
    "emergency_contact_address" => (string)($profile["emergency_contact_address"] ?? ""),
    "emergency_contact_number" => admin_profile_phone_storage_value((string)($profile["emergency_contact_number"] ?? "")),
    "emergency_contact_mobile" => admin_profile_mobile_digits((string)($profile["emergency_contact_number"] ?? "")),
];
$relationshipOptions = admin_profile_relationship_options();
$savedRelationship = trim((string)($profileFormValues["emergency_contact_relationship"] ?? ""));
$hasLegacyRelationship = $savedRelationship !== "" && !in_array($savedRelationship, $relationshipOptions, true);

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_enforce_same_origin(true);
    servitech_enforce_csrf_token(true);

    $action = (string)($_POST["profile_action"] ?? "");

    if ($action === "profile_update") {
        $openEditMode = true;
        $activeTab = in_array((string)($_POST["active_profile_tab"] ?? "profile"), ["profile", "emergency"], true)
            ? (string)$_POST["active_profile_tab"]
            : "profile";
        $profileFormValues = [
            "fullname" => trim((string)($_POST["fullname"] ?? "")),
            "contact" => admin_profile_phone_storage_value((string)($_POST["contact"] ?? ""), (string)($_POST["contact_mobile"] ?? "")),
            "contact_mobile" => admin_profile_mobile_digits((string)($_POST["contact_mobile"] ?? $_POST["contact"] ?? "")),
            "address" => trim((string)($_POST["address"] ?? "")),
            "emergency_contact_name" => trim((string)($_POST["emergency_contact_name"] ?? "")),
            "emergency_contact_relationship" => trim((string)($_POST["emergency_contact_relationship"] ?? "")),
            "emergency_contact_address" => trim((string)($_POST["emergency_contact_address"] ?? "")),
            "emergency_contact_number" => admin_profile_phone_storage_value((string)($_POST["emergency_contact_number"] ?? ""), (string)($_POST["emergency_contact_mobile"] ?? "")),
            "emergency_contact_mobile" => admin_profile_mobile_digits((string)($_POST["emergency_contact_mobile"] ?? $_POST["emergency_contact_number"] ?? "")),
        ];

        $errors = $activeTab === "emergency"
            ? [
                admin_profile_name_error($profileFormValues["emergency_contact_name"], "Emergency contact name"),
                admin_profile_relationship_error($profileFormValues["emergency_contact_relationship"]),
                admin_profile_text_error($profileFormValues["emergency_contact_address"], "Emergency contact address"),
                admin_profile_phone_error($profileFormValues["emergency_contact_number"], "emergency contact number"),
            ]
            : [
                admin_profile_name_error($profileFormValues["fullname"], "Full name"),
                admin_profile_phone_error($profileFormValues["contact"], "contact number"),
                admin_profile_text_error($profileFormValues["address"], "Address"),
            ];

        if (admin_profile_has_errors($errors)) {
            $toastMessage = "Please complete all required fields.";
            $toastType = "error";
        } else {
            try {
                admin_profile_verify_current_password($profile, (string)($_POST["confirm_current_password"] ?? ""));

                if ($activeTab === "emergency") {
                    $update = $pdo->prepare("
                        UPDATE users
                        SET emergency_contact_name = :emergency_contact_name,
                            emergency_contact_relationship = :emergency_contact_relationship,
                            emergency_contact_address = :emergency_contact_address,
                            emergency_contact_number = :emergency_contact_number,
                            updated_at = NOW()
                        WHERE id = :id
                          AND LOWER(TRIM(COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer'))) = 'admin'
                    ");
                    $update->execute([
                        ":emergency_contact_name" => $profileFormValues["emergency_contact_name"],
                        ":emergency_contact_relationship" => $profileFormValues["emergency_contact_relationship"],
                        ":emergency_contact_address" => $profileFormValues["emergency_contact_address"],
                        ":emergency_contact_number" => $profileFormValues["emergency_contact_number"],
                        ":id" => $adminId,
                    ]);
                } else {
                    $update = $pdo->prepare("
                        UPDATE users
                        SET fullname = :fullname,
                            contact = :contact,
                            address = :address,
                            updated_at = NOW()
                        WHERE id = :id
                          AND LOWER(TRIM(COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer'))) = 'admin'
                    ");
                    $update->execute([
                        ":fullname" => $profileFormValues["fullname"],
                        ":contact" => $profileFormValues["contact"],
                        ":address" => $profileFormValues["address"],
                        ":id" => $adminId,
                    ]);
                }

                servitech_activity_log($pdo, [
                    "actor_id" => $adminId,
                    "role" => $role,
                    "action_type" => "admin_profile_update",
                    "module" => "admin_profile",
                    "target_record_id" => (string)$adminId,
                    "description" => $roleLabel . " " . $profileFormValues["fullname"] . " updated their profile information.",
                ]);

                servitech_admin_flash_toast(
                    $activeTab === "emergency" ? "Emergency contact updated successfully." : "Profile updated successfully.",
                    "success"
                );
                header("Location: " . admin_url_raw("/pages/admin/admin_profile.php"), true, 303);
                exit();
            } catch (DomainException $exception) {
                $toastMessage = $exception->getMessage() === "Incorrect password. Please try again."
                    ? "Incorrect password. Please try again."
                    : "Unable to update profile. Please try again.";
                $toastType = "error";
            } catch (Throwable $exception) {
                error_log("admin profile update failed: " . $exception->getMessage());
                $toastMessage = "Unable to update profile. Please try again.";
                $toastType = "error";
            }
        }
    } elseif ($action === "password_change") {
        $activeTab = "password";
        $currentPassword = (string)($_POST["current_password"] ?? "");
        $newPassword = (string)($_POST["new_password"] ?? "");
        $confirmPassword = (string)($_POST["confirm_password"] ?? "");

        try {
            if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
                throw new DomainException("Please complete all required fields.");
            }
            if (!hash_equals($newPassword, $confirmPassword)) {
                throw new DomainException("New passwords do not match.");
            }
            $passwordError = admin_profile_password_strength_error($newPassword);
            if ($passwordError !== "") {
                throw new DomainException($passwordError);
            }

            $authResponse = admin_profile_verify_current_password($profile, $currentPassword);
            if (hash_equals($currentPassword, $newPassword)) {
                throw new DomainException("New password does not meet the password requirements.");
            }

            if (servitech_supabase_auth_enabled()) {
                $accessToken = trim((string)($authResponse["access_token"] ?? $_SESSION["supabase_access_token"] ?? ""));
                if ($accessToken === "") {
                    throw new RuntimeException("Supabase Auth session is unavailable.");
                }
                servitech_supabase_update_user($accessToken, ["password" => $newPassword]);
                if (isset($authResponse["access_token"], $authResponse["refresh_token"], $authResponse["user"])) {
                    servitech_supabase_store_auth_session($authResponse);
                    servitech_supabase_rebind_application_profile($pdo, true, 30);
                }
            } else {
                if (password_verify($newPassword, (string)($profile["password_hash"] ?? ""))) {
                    throw new DomainException("New password does not meet the password requirements.");
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
            }

            servitech_activity_log($pdo, [
                "actor_id" => $adminId,
                "role" => $role,
                "action_type" => "admin_password_change",
                "module" => "admin_profile",
                "target_record_id" => (string)$adminId,
                "description" => $roleLabel . " " . (string)($profile["fullname"] ?? $profile["email"]) . " changed their account password.",
            ]);

            servitech_admin_flash_toast("Password changed successfully.", "success");
            header("Location: " . admin_url_raw("/pages/admin/admin_profile.php"), true, 303);
            exit();
        } catch (DomainException $exception) {
            $toastMessage = in_array($exception->getMessage(), [
                "Please complete all required fields.",
                "New passwords do not match.",
                "New password does not meet the password requirements.",
                "Incorrect password. Please try again.",
            ], true)
                ? $exception->getMessage()
                : "Unable to change password. Please try again.";
            $toastType = "error";
        } catch (Throwable $exception) {
            error_log("admin password change failed: " . $exception->getMessage());
            $toastMessage = "Unable to change password. Please try again.";
            $toastType = "error";
        }
    }

    $profile = admin_profile_fetch($pdo, $adminId) ?: $profile;
}

$savedRelationship = trim((string)($profileFormValues["emergency_contact_relationship"] ?? ""));
$hasLegacyRelationship = $savedRelationship !== "" && !in_array($savedRelationship, $relationshipOptions, true);
$accountStatus = ucfirst(strtolower((string)($profile["account_status"] ?? "active")));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Admin Profile | ServiTech</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260626-roles') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260627-admin-profile-layout') ?>">
</head>
<body class="admin-profile-page<?= $openEditMode ? ' is-editing-profile' : '' ?>">
<?php require __DIR__ . "/_includes/admin_header.php"; ?>

<main class="admin-owner-shell admin-profile-shell">
  <section class="admin-owner-hero">
    <span class="admin-owner-kicker"><?= admin_profile_h($roleLabel) ?></span>
    <h1><?= admin_profile_h($profileTitle) ?></h1>
    <p>Manage your profile information, emergency contact, and account password.</p>
  </section>

  <section class="admin-owner-grid admin-profile-grid">
    <article class="admin-owner-panel admin-profile-summary">
      <div class="admin-profile-panel-header">
        <h2>Account Summary</h2>
        <button class="admin-owner-button-secondary" type="button" data-edit-profile>Edit Profile</button>
      </div>
      <section class="admin-profile-summary-section" aria-labelledby="account-summary-heading">
        <h3 id="account-summary-heading">Basic Information</h3>
        <dl class="admin-profile-summary-list">
          <div><dt>Full Name</dt><dd><?= admin_profile_h($profile["fullname"] ?? "") ?></dd></div>
          <div><dt>Email</dt><dd><?= admin_profile_h($profile["email"] ?? "") ?></dd></div>
          <div><dt>Contact Number</dt><dd><?= admin_profile_h(admin_profile_blank($profile["contact"] ?? "")) ?></dd></div>
          <div><dt>Address</dt><dd><?= admin_profile_h(admin_profile_blank($profile["address"] ?? "")) ?></dd></div>
        </dl>
      </section>

      <section class="admin-profile-summary-section" aria-labelledby="account-status-heading">
        <h3 id="account-status-heading">Account Status</h3>
        <dl class="admin-profile-summary-list">
          <div><dt>Role</dt><dd><?= admin_profile_h($roleLabel) ?></dd></div>
          <div><dt>Status</dt><dd><span class="admin-owner-pill"><?= admin_profile_h($accountStatus) ?></span></dd></div>
          <div><dt>Last Login</dt><dd><?= admin_profile_h(admin_profile_datetime($profile["last_login_at"] ?? "")) ?></dd></div>
          <div><dt>Created Date</dt><dd><?= admin_profile_h(admin_profile_datetime($profile["created_at"] ?? "")) ?></dd></div>
        </dl>
      </section>

      <section class="admin-profile-summary-section" aria-labelledby="emergency-summary-heading">
        <h3 id="emergency-summary-heading">Emergency Contact</h3>
        <dl class="admin-profile-summary-list">
          <div><dt>Name</dt><dd><?= admin_profile_h(admin_profile_blank($profile["emergency_contact_name"] ?? "")) ?></dd></div>
          <div><dt>Relationship</dt><dd><?= admin_profile_h(admin_profile_blank($profile["emergency_contact_relationship"] ?? "")) ?></dd></div>
          <div><dt>Contact Number</dt><dd><?= admin_profile_h(admin_profile_blank($profile["emergency_contact_number"] ?? "")) ?></dd></div>
        </dl>
      </section>
    </article>

    <article class="admin-owner-panel admin-profile-workspace">
      <div class="admin-profile-tabs" role="tablist" aria-label="Profile management">
        <button class="admin-profile-tab" type="button" data-profile-tab="profile" role="tab" aria-selected="false">Profile Information</button>
        <button class="admin-profile-tab" type="button" data-profile-tab="emergency" role="tab" aria-selected="false">Emergency Contact</button>
        <button class="admin-profile-tab" type="button" data-profile-tab="password" role="tab" aria-selected="false">Change Password</button>
      </div>

      <form id="adminProfileForm" class="admin-owner-form admin-profile-form" method="post" autocomplete="on" novalidate>
        <input type="hidden" name="csrf_token" value="<?= admin_profile_h($csrfToken) ?>">
        <input type="hidden" name="profile_action" value="profile_update">
        <input type="hidden" id="active_profile_tab" name="active_profile_tab" value="<?= admin_profile_h($activeTab === "emergency" ? "emergency" : "profile") ?>">
        <input type="hidden" id="confirm_current_password" name="confirm_current_password" value="">

        <section class="admin-profile-tab-panel" data-profile-panel="profile">
          <div class="admin-profile-section-header">
            <h2>Profile Information</h2>
            <p>Email changes must be handled through account verification.</p>
          </div>

          <div class="admin-profile-form-grid admin-profile-form-grid--profile-info">
            <div class="admin-owner-field admin-profile-field--full-name">
              <label for="fullname">Full Name</label>
              <input id="fullname" name="fullname" value="<?= admin_profile_h($profileFormValues["fullname"]) ?>" maxlength="<?= SERVITECH_LIMIT_FULLNAME ?>" required>
            </div>
            <div class="admin-owner-field">
              <label for="email">Email</label>
              <input id="email" value="<?= admin_profile_h($profile["email"] ?? "") ?>" readonly>
              <p class="admin-owner-muted">Email changes must be handled through account verification.</p>
            </div>
            <div class="admin-owner-field">
              <label for="contact_mobile">Contact Number</label>
              <div class="contact-number-control">
                <span class="contact-number-prefix" aria-label="Philippine country code">+63</span>
                <input id="contact_mobile" name="contact_mobile" type="tel" inputmode="numeric" value="<?= admin_profile_h($profileFormValues["contact_mobile"]) ?>" placeholder="9XXXXXXXXX" maxlength="10" pattern="9[0-9]{9}" required>
              </div>
              <input id="contact" name="contact" type="hidden" value="<?= admin_profile_h($profileFormValues["contact"]) ?>">
            </div>
            <div class="admin-owner-field admin-profile-form-grid__wide">
              <label for="address">Address</label>
              <textarea id="address" name="address" rows="4" maxlength="<?= SERVITECH_LIMIT_ADDRESS ?>" required><?= admin_profile_h($profileFormValues["address"]) ?></textarea>
            </div>
          </div>
          <div class="admin-profile-actions">
            <button class="admin-owner-button" type="submit">Save Changes</button>
            <button class="admin-owner-button-secondary" type="button" data-cancel-edit>Cancel</button>
          </div>
        </section>

        <section class="admin-profile-tab-panel" data-profile-panel="emergency" hidden>
          <div class="admin-profile-section-header">
            <h2>Emergency Contact</h2>
            <p>Keep one trusted contact available for urgent employee coordination.</p>
          </div>

          <div class="admin-profile-form-grid">
            <div class="admin-owner-field">
              <label for="emergency_contact_name">Emergency Contact Name</label>
              <input id="emergency_contact_name" name="emergency_contact_name" value="<?= admin_profile_h($profileFormValues["emergency_contact_name"]) ?>" maxlength="<?= SERVITECH_LIMIT_FULLNAME ?>" required>
            </div>
            <div class="admin-owner-field">
              <label for="emergency_contact_relationship">Relationship</label>
              <select id="emergency_contact_relationship" name="emergency_contact_relationship" required>
                <option value="">Select relationship</option>
                <?php if ($hasLegacyRelationship): ?>
                  <option value="<?= admin_profile_h($savedRelationship) ?>" selected><?= admin_profile_h($savedRelationship) ?> (saved)</option>
                <?php endif; ?>
                <?php foreach ($relationshipOptions as $relationshipOption): ?>
                  <option value="<?= admin_profile_h($relationshipOption) ?>" <?= $profileFormValues["emergency_contact_relationship"] === $relationshipOption ? "selected" : "" ?>><?= admin_profile_h($relationshipOption) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="admin-owner-field admin-profile-form-grid__wide">
              <label for="emergency_contact_address">Emergency Contact Address</label>
              <textarea id="emergency_contact_address" name="emergency_contact_address" rows="4" maxlength="<?= SERVITECH_LIMIT_ADDRESS ?>" required><?= admin_profile_h($profileFormValues["emergency_contact_address"]) ?></textarea>
            </div>
            <div class="admin-owner-field">
              <label for="emergency_contact_mobile">Emergency Contact Number</label>
              <div class="contact-number-control">
                <span class="contact-number-prefix" aria-label="Philippine country code">+63</span>
                <input id="emergency_contact_mobile" name="emergency_contact_mobile" type="tel" inputmode="numeric" value="<?= admin_profile_h($profileFormValues["emergency_contact_mobile"]) ?>" placeholder="9XXXXXXXXX" maxlength="10" pattern="9[0-9]{9}" required>
              </div>
              <input id="emergency_contact_number" name="emergency_contact_number" type="hidden" value="<?= admin_profile_h($profileFormValues["emergency_contact_number"]) ?>">
            </div>
          </div>
          <div class="admin-profile-actions">
            <button class="admin-owner-button" type="submit">Save Changes</button>
            <button class="admin-owner-button-secondary" type="button" data-cancel-edit>Cancel</button>
          </div>
        </section>
      </form>

      <form id="adminPasswordForm" class="admin-owner-form admin-profile-password-form" method="post" autocomplete="on" novalidate>
        <input type="hidden" name="csrf_token" value="<?= admin_profile_h($csrfToken) ?>">
        <input type="hidden" name="profile_action" value="password_change">
        <section class="admin-profile-tab-panel" data-profile-panel="password" hidden>
          <div class="admin-profile-section-header">
            <h2>Change Password</h2>
            <p>Password changes require your current password before the new password is applied securely.</p>
          </div>
          <div class="admin-profile-form-grid">
            <div class="admin-owner-field">
              <label for="current_password">Current Password</label>
              <input id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" required>
            </div>
            <div class="admin-owner-field">
              <label for="new_password">New Password</label>
              <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="<?= ADMIN_PROFILE_PASSWORD_MIN_LENGTH ?>" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" required>
            </div>
            <div class="admin-owner-field">
              <label for="confirm_password">Confirm New Password</label>
              <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="<?= ADMIN_PROFILE_PASSWORD_MIN_LENGTH ?>" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" required>
            </div>
          </div>
          <ul class="admin-profile-password-rules" aria-label="Password requirements">
            <li>8 to <?= SERVITECH_PASSWORD_MAX_BYTES ?> characters</li>
            <li>At least one uppercase letter</li>
            <li>At least one lowercase letter</li>
            <li>At least one number</li>
            <li>At least one special character</li>
          </ul>
          <div class="admin-profile-actions admin-profile-actions--password">
            <button class="admin-owner-button" type="submit">Change Password</button>
          </div>
        </section>
      </form>
    </article>
  </section>
</main>

<div id="adminProfileConfirmModal" class="admin-owner-modal-overlay admin-profile-confirm-overlay" hidden>
  <div class="admin-owner-modal admin-profile-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmProfileUpdateTitle">
    <div class="admin-owner-modal__header">
      <div>
        <h2 id="confirmProfileUpdateTitle">Confirm Profile Update</h2>
        <p>Please enter your current password to save these changes.</p>
      </div>
      <button class="admin-owner-modal__close" type="button" data-close-profile-confirm aria-label="Close confirmation">&times;</button>
    </div>
    <div class="admin-owner-form">
      <div class="admin-owner-field">
        <label for="profileConfirmPassword">Current Password</label>
        <input id="profileConfirmPassword" type="password" autocomplete="current-password" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" required>
      </div>
      <div class="admin-profile-modal-actions">
        <button class="admin-owner-button-secondary" type="button" data-close-profile-confirm>Cancel</button>
        <button class="admin-owner-button" type="button" data-confirm-profile-save>Confirm and Save</button>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const toastMessage = <?= json_encode($toastMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const toastType = <?= json_encode($toastType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const body = document.body;
    const profileForm = document.getElementById("adminProfileForm");
    const passwordForm = document.getElementById("adminPasswordForm");
    const modal = document.getElementById("adminProfileConfirmModal");
    const modalPassword = document.getElementById("profileConfirmPassword");
    const hiddenPassword = document.getElementById("confirm_current_password");
    const activeProfileTab = document.getElementById("active_profile_tab");
    const initialTab = <?= json_encode($activeTab, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const relationshipOptions = new Set(<?= json_encode($relationshipOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
    const editButtons = document.querySelectorAll("[data-edit-profile]");
    const cancelButtons = document.querySelectorAll("[data-cancel-edit]");
    const tabs = document.querySelectorAll("[data-profile-tab]");
    const panels = document.querySelectorAll("[data-profile-panel]");
    const restoreFieldIds = [
      "fullname",
      "contact_mobile",
      "contact",
      "address",
      "emergency_contact_name",
      "emergency_contact_relationship",
      "emergency_contact_address",
      "emergency_contact_mobile",
      "emergency_contact_number"
    ];
    const initialProfileValues = {};
    restoreFieldIds.forEach((id) => {
      const field = document.getElementById(id);
      if (field) initialProfileValues[id] = field.value;
    });

    function showToast(message, type) {
      if (message) window.servitechAdminToast?.show(message, type || "info");
    }

    function setEditing(isEditing) {
      const active = Boolean(isEditing);
      body.classList.toggle("is-editing-profile", active);
      profileForm?.querySelectorAll("input:not([type='hidden']):not(#email), textarea, select").forEach((field) => {
        if (field.tagName === "SELECT") {
          field.disabled = !active;
        } else {
          field.readOnly = !active;
        }
      });
    }

    function setTab(name) {
      const isPassword = name === "password";
      if (profileForm) profileForm.hidden = isPassword;
      if (passwordForm) passwordForm.hidden = !isPassword;
      if (activeProfileTab && !isPassword) activeProfileTab.value = name === "emergency" ? "emergency" : "profile";
      tabs.forEach((tab) => {
        const active = tab.dataset.profileTab === name;
        tab.classList.toggle("is-active", active);
        tab.setAttribute("aria-selected", active ? "true" : "false");
      });
      panels.forEach((panel) => {
        panel.hidden = panel.dataset.profilePanel !== name;
      });
    }

    function sanitizeMobile(value) {
      let digits = String(value || "").replace(/\D/g, "");
      if (digits.startsWith("63")) digits = digits.slice(2);
      if (digits.startsWith("0")) digits = digits.slice(1);
      return digits.slice(0, 10);
    }

    function syncMobile(inputId, hiddenId) {
      const input = document.getElementById(inputId);
      const hidden = document.getElementById(hiddenId);
      if (!input || !hidden) return;
      input.value = sanitizeMobile(input.value);
      hidden.value = input.value ? `+63${input.value}` : "";
    }

    function restoreProfileValues() {
      Object.keys(initialProfileValues).forEach((id) => {
        const field = document.getElementById(id);
        if (field) field.value = initialProfileValues[id];
      });
      syncMobile("contact_mobile", "contact");
      syncMobile("emergency_contact_mobile", "emergency_contact_number");
    }

    function profileFieldsComplete() {
      syncMobile("contact_mobile", "contact");
      syncMobile("emergency_contact_mobile", "emergency_contact_number");
      const panelName = activeProfileTab?.value === "emergency" ? "emergency" : "profile";
      const panel = profileForm.querySelector(`[data-profile-panel="${panelName}"]`);
      const required = panel ? panel.querySelectorAll("input[required], textarea[required], select[required]") : [];
      return Array.from(required).every((field) => {
        const value = String(field.value || "").trim();
        if (!value) return false;
        if (field.id === "emergency_contact_relationship" && !relationshipOptions.has(value)) return false;
        return !field.pattern || new RegExp(`^${field.pattern}$`).test(value);
      });
    }

    function openModal() {
      modal.hidden = false;
      body.classList.add("admin-owner-modal-open");
      modalPassword.value = "";
      window.setTimeout(() => modalPassword.focus(), 0);
    }

    function closeModal() {
      modal.hidden = true;
      body.classList.remove("admin-owner-modal-open");
      modalPassword.value = "";
    }

    editButtons.forEach((button) => button.addEventListener("click", () => {
      const selectedTab = document.querySelector("[data-profile-tab].is-active")?.dataset.profileTab || "profile";
      setEditing(true);
      setTab(selectedTab === "password" ? "profile" : selectedTab);
      const focusTarget = selectedTab === "emergency" ? "emergency_contact_name" : "fullname";
      document.getElementById(focusTarget)?.focus();
    }));

    cancelButtons.forEach((button) => button.addEventListener("click", () => {
      restoreProfileValues();
      setEditing(false);
    }));

    tabs.forEach((tab) => tab.addEventListener("click", () => {
      const nextTab = tab.dataset.profileTab || "profile";
      setTab(nextTab);
    }));

    ["contact_mobile", "emergency_contact_mobile"].forEach((id) => {
      document.getElementById(id)?.addEventListener("input", () => {
        syncMobile("contact_mobile", "contact");
        syncMobile("emergency_contact_mobile", "emergency_contact_number");
      });
    });

    profileForm?.addEventListener("submit", (event) => {
      event.preventDefault();
      if (!profileFieldsComplete()) {
        showToast("Please complete all required fields.", "error");
        return;
      }
      openModal();
    });

    passwordForm?.addEventListener("submit", (event) => {
      const current = document.getElementById("current_password")?.value || "";
      const next = document.getElementById("new_password")?.value || "";
      const confirm = document.getElementById("confirm_password")?.value || "";
      if (!current || !next || !confirm) {
        event.preventDefault();
        showToast("Please complete all required fields.", "error");
        return;
      }
      if (next !== confirm) {
        event.preventDefault();
        showToast("New passwords do not match.", "error");
        return;
      }
      if (next.length < <?= ADMIN_PROFILE_PASSWORD_MIN_LENGTH ?> || !/[A-Z]/.test(next) || !/[a-z]/.test(next) || !/\d/.test(next) || !/[^A-Za-z0-9]/.test(next)) {
        event.preventDefault();
        showToast("New password does not meet the password requirements.", "error");
      }
    });

    document.querySelectorAll("[data-close-profile-confirm]").forEach((button) => {
      button.addEventListener("click", closeModal);
    });

    document.querySelector("[data-confirm-profile-save]")?.addEventListener("click", () => {
      if (!modalPassword.value) {
        showToast("Please complete all required fields.", "error");
        modalPassword.focus();
        return;
      }
      hiddenPassword.value = modalPassword.value;
      profileForm.submit();
    });

    modal?.addEventListener("click", (event) => {
      if (event.target === modal) closeModal();
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !modal.hidden) closeModal();
    });

    window.addEventListener("DOMContentLoaded", () => showToast(toastMessage, toastType), { once: true });
    setEditing(body.classList.contains("is-editing-profile"));
    setTab(initialTab || "profile");
    syncMobile("contact_mobile", "contact");
    syncMobile("emergency_contact_mobile", "emergency_contact_number");
  })();
</script>
</body>
</html>
