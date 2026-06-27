<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/url.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/activity_log.php";
require_once __DIR__ . "/../../config/employee_setup.php";
require_once __DIR__ . "/../../config/input_limits.php";
require_once __DIR__ . "/../../config/account.php";

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

const EMPLOYEE_SETUP_PASSWORD_MIN_LENGTH = 8;

function employee_setup_relationship_options(): array
{
    return ["Mother", "Father", "Sibling", "Spouse", "Guardian"];
}

function employee_setup_has_errors(array $errors): bool
{
    foreach ($errors as $message) {
        if ((string)$message !== "") {
            return true;
        }
    }

    return false;
}

function employee_setup_mobile_digits(string $value): string
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

function employee_setup_phone_storage_value(string $rawValue, string $mobileValue = ""): string
{
    $source = trim($mobileValue) !== "" ? $mobileValue : $rawValue;
    $mobileDigits = employee_setup_mobile_digits($source);
    if (preg_match('/^9\d{9}$/', $mobileDigits)) {
        return "+63" . $mobileDigits;
    }

    return trim($rawValue !== "" ? $rawValue : $mobileValue);
}

function employee_setup_password_errors(string $password, string $confirmation): array
{
    $errors = [
        "new_password" => "",
        "confirm_password" => "",
    ];

    if ($password === "") {
        $errors["new_password"] = "New password is required.";
    } elseif (strlen($password) < EMPLOYEE_SETUP_PASSWORD_MIN_LENGTH) {
        $errors["new_password"] = "Password must be at least " . EMPLOYEE_SETUP_PASSWORD_MIN_LENGTH . " characters.";
    } elseif (strlen($password) > SERVITECH_PASSWORD_MAX_BYTES) {
        $errors["new_password"] = "Password must not exceed " . SERVITECH_PASSWORD_MAX_BYTES . " bytes.";
    } elseif (!preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        $errors["new_password"] = "Use uppercase, lowercase, number, and special character.";
    }

    if ($confirmation === "") {
        $errors["confirm_password"] = "Please confirm your new password.";
    } elseif ($password !== "" && !hash_equals($password, $confirmation)) {
        $errors["confirm_password"] = "Passwords do not match.";
    }

    return $errors;
}

function employee_setup_phone_error(string $value, string $label): string
{
    $value = trim($value);
    return preg_match('/^(09\d{9}|\+639\d{9})$/', $value)
        ? ""
        : "Enter a valid {$label}.";
}

function employee_setup_name_error(string $value, string $label): string
{
    $value = trim($value);
    return $value !== "" && servitech_text_length($value) <= SERVITECH_LIMIT_FULLNAME && preg_match('/^[\pL\s.\'-]+$/u', $value)
        ? ""
        : "Enter a valid {$label}.";
}

function employee_setup_textarea_error(string $value, string $label): string
{
    $value = trim($value);
    return $value !== "" && servitech_text_length($value) <= SERVITECH_LIMIT_ADDRESS
        ? ""
        : "{$label} is required and must be " . SERVITECH_LIMIT_ADDRESS . " characters or fewer.";
}

function employee_setup_relationship_error(string $value): string
{
    return in_array($value, employee_setup_relationship_options(), true)
        ? ""
        : "Select an emergency contact relationship.";
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

$relationshipOptions = employee_setup_relationship_options();
$formErrors = [
    "new_password" => "",
    "confirm_password" => "",
    "contact" => "",
    "address" => "",
    "emergency_contact_name" => "",
    "emergency_contact_relationship" => "",
    "emergency_contact_address" => "",
    "emergency_contact_number" => "",
];
$formMessage = "";
$formValues = [
    "contact" => employee_setup_phone_storage_value((string)($profile["contact"] ?? "")),
    "contact_mobile" => employee_setup_mobile_digits((string)($profile["contact"] ?? "")),
    "address" => (string)($profile["address"] ?? ""),
    "emergency_contact_name" => (string)($profile["emergency_contact_name"] ?? ""),
    "emergency_contact_relationship" => in_array((string)($profile["emergency_contact_relationship"] ?? ""), $relationshipOptions, true)
        ? (string)$profile["emergency_contact_relationship"]
        : "",
    "emergency_contact_address" => (string)($profile["emergency_contact_address"] ?? ""),
    "emergency_contact_number" => employee_setup_phone_storage_value((string)($profile["emergency_contact_number"] ?? "")),
    "emergency_contact_mobile" => employee_setup_mobile_digits((string)($profile["emergency_contact_number"] ?? "")),
];

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    servitech_enforce_same_origin(true);
    servitech_enforce_csrf_token(true);

    $newPassword = (string)($_POST["new_password"] ?? "");
    $confirmPassword = (string)($_POST["confirm_password"] ?? "");
    $contact = employee_setup_phone_storage_value((string)($_POST["contact"] ?? ""), (string)($_POST["contact_mobile"] ?? ""));
    $address = trim((string)($_POST["address"] ?? ""));
    $emergencyName = trim((string)($_POST["emergency_contact_name"] ?? ""));
    $emergencyRelationship = trim((string)($_POST["emergency_contact_relationship"] ?? ""));
    $emergencyAddress = trim((string)($_POST["emergency_contact_address"] ?? ""));
    $emergencyNumber = employee_setup_phone_storage_value((string)($_POST["emergency_contact_number"] ?? ""), (string)($_POST["emergency_contact_mobile"] ?? ""));

    $formValues = [
        "contact" => $contact,
        "contact_mobile" => employee_setup_mobile_digits((string)($_POST["contact_mobile"] ?? $_POST["contact"] ?? "")),
        "address" => $address,
        "emergency_contact_name" => $emergencyName,
        "emergency_contact_relationship" => $emergencyRelationship,
        "emergency_contact_address" => $emergencyAddress,
        "emergency_contact_number" => $emergencyNumber,
        "emergency_contact_mobile" => employee_setup_mobile_digits((string)($_POST["emergency_contact_mobile"] ?? $_POST["emergency_contact_number"] ?? "")),
    ];

    $passwordErrors = employee_setup_password_errors($newPassword, $confirmPassword);
    $formErrors["new_password"] = $passwordErrors["new_password"];
    $formErrors["confirm_password"] = $passwordErrors["confirm_password"];
    $formErrors["contact"] = employee_setup_phone_error($contact, "contact number");
    $formErrors["address"] = employee_setup_textarea_error($address, "Address");
    $formErrors["emergency_contact_name"] = employee_setup_name_error($emergencyName, "emergency contact name");
    $formErrors["emergency_contact_relationship"] = employee_setup_relationship_error($emergencyRelationship);
    $formErrors["emergency_contact_address"] = employee_setup_textarea_error($emergencyAddress, "Emergency contact address");
    $formErrors["emergency_contact_number"] = employee_setup_phone_error($emergencyNumber, "emergency contact number");

    if (employee_setup_has_errors($formErrors)) {
        $formMessage = "Please correct the highlighted fields before completing setup.";
        servitech_activity_log($pdo, [
            "actor_id" => (int)$profile["id"],
            "role" => "admin",
            "action_type" => "employee_first_time_setup_failed",
            "module" => "employee_setup",
            "target_record_id" => (string)$profile["id"],
            "description" => "Employee first-time setup submission failed: validation errors.",
            "status" => "failed",
        ]);
    } else {
        try {
            if (!servitech_supabase_auth_enabled()) {
                throw new DomainException("Employee setup requires Supabase Auth.");
            }
            $accessToken = trim((string)($_SESSION["supabase_access_token"] ?? ""));
            if ($accessToken === "") {
                throw new DomainException("Your secure Auth session expired. Please log in again.");
            }

            $matchesTemporaryPassword = false;
            try {
                servitech_supabase_sign_in((string)$profile["email"], $newPassword);
                $matchesTemporaryPassword = true;
            } catch (DomainException $samePasswordCheck) {
                $matchesTemporaryPassword = false;
            }
            if ($matchesTemporaryPassword) {
                throw new DomainException("New password must be different from your temporary password.");
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
            $formMessage = $exception->getMessage();
            if ($formMessage === "New password must be different from your temporary password.") {
                $formErrors["new_password"] = $formMessage;
            }
            servitech_activity_log($pdo, [
                "actor_id" => (int)$profile["id"],
                "role" => "admin",
                "action_type" => "employee_first_time_setup_failed",
                "module" => "employee_setup",
                "target_record_id" => (string)$profile["id"],
                "description" => "Employee first-time setup submission failed: " . $exception->getMessage(),
                "status" => "failed",
            ]);
        }
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
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260627-employee-setup-polish') ?>">
</head>
<body class="admin-first-time-setup">
<?php require __DIR__ . "/_includes/admin_header.php"; ?>

<main class="admin-owner-shell employee-setup-shell">
  <section class="admin-owner-hero employee-setup-hero">
    <span class="admin-owner-kicker">Employee Access</span>
    <h1>Complete Your Employee Account</h1>
    <p>Please update your password and complete your contact details before accessing the Admin Dashboard.</p>
  </section>

  <div class="admin-owner-alert">Your temporary password must be changed before you can access the Admin Dashboard.</div>
  <?php if ($formMessage !== ""): ?>
    <div class="admin-owner-alert admin-owner-alert--error" role="alert"><?= employee_setup_h($formMessage) ?></div>
  <?php endif; ?>

  <section class="admin-owner-panel employee-setup-panel">
    <form id="employeeSetupForm" class="admin-owner-form employee-setup-form" method="post" autocomplete="on" data-accepted-phone-formats="09XXXXXXXXX +639XXXXXXXXX" novalidate>
      <input type="hidden" name="csrf_token" value="<?= employee_setup_h($csrfToken) ?>">

      <section class="employee-setup-section employee-setup-section--password" aria-labelledby="employee-password-heading">
        <div class="employee-setup-section__header">
          <h2 id="employee-password-heading">Change Password</h2>
          <p>Create a permanent password before using employee admin tools.</p>
        </div>

        <div class="employee-setup-password-layout">
          <div class="employee-setup-password-fields">
            <div class="admin-owner-field employee-setup-field">
              <label for="new_password">New Password</label>
              <div class="password-input-wrap">
                <input id="new_password" class="<?= $formErrors["new_password"] !== "" ? "is-invalid" : "" ?>" name="new_password" type="password" placeholder="Create a secure password" autocomplete="new-password" minlength="<?= EMPLOYEE_SETUP_PASSWORD_MIN_LENGTH ?>" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" aria-describedby="passwordRequirements newPasswordError" aria-invalid="<?= $formErrors["new_password"] !== "" ? "true" : "false" ?>" required>
                <button type="button" class="password-toggle" data-password-toggle="new_password" aria-label="Show new password" aria-pressed="false" aria-hidden="true" tabindex="-1"></button>
              </div>
              <p class="field-error" id="newPasswordError" aria-live="polite"><?= employee_setup_h($formErrors["new_password"]) ?></p>
            </div>

            <div class="admin-owner-field employee-setup-field">
              <label for="confirm_password">Confirm New Password</label>
              <div class="password-input-wrap">
                <input id="confirm_password" class="<?= $formErrors["confirm_password"] !== "" ? "is-invalid" : "" ?>" name="confirm_password" type="password" placeholder="Re-enter your password" autocomplete="new-password" minlength="<?= EMPLOYEE_SETUP_PASSWORD_MIN_LENGTH ?>" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" aria-describedby="confirmPasswordError" aria-invalid="<?= $formErrors["confirm_password"] !== "" ? "true" : "false" ?>" required>
                <button type="button" class="password-toggle" data-password-toggle="confirm_password" aria-label="Show confirm password" aria-pressed="false" aria-hidden="true" tabindex="-1"></button>
              </div>
              <p class="field-error" id="confirmPasswordError" aria-live="polite"><?= employee_setup_h($formErrors["confirm_password"]) ?></p>
            </div>
          </div>

          <div class="employee-setup-requirements" id="passwordRequirements" aria-label="Password requirements">
            <span>Password requirements</span>
            <ul>
              <li data-password-rule="length"><?= EMPLOYEE_SETUP_PASSWORD_MIN_LENGTH ?> to <?= SERVITECH_PASSWORD_MAX_BYTES ?> characters</li>
              <li data-password-rule="uppercase">One uppercase letter</li>
              <li data-password-rule="lowercase">One lowercase letter</li>
              <li data-password-rule="number">One number</li>
              <li data-password-rule="special">One special character</li>
            </ul>
          </div>
        </div>
      </section>

      <section class="employee-setup-section" aria-labelledby="employee-contact-heading">
        <div class="employee-setup-section__header">
          <h2 id="employee-contact-heading">Contact Details</h2>
          <p>Provide your current mobile number and address for internal account records.</p>
        </div>

        <div class="employee-setup-field-grid">
          <div class="admin-owner-field employee-setup-field">
            <label for="contact_mobile">Contact Number</label>
            <div id="contactControl" class="contact-number-control<?= $formErrors["contact"] !== "" ? " is-invalid" : "" ?>">
              <span class="contact-number-prefix" aria-label="Philippine country code">+63</span>
              <input id="contact_mobile" name="contact_mobile" type="tel" inputmode="numeric" value="<?= employee_setup_h($formValues["contact_mobile"]) ?>" placeholder="9XXXXXXXXX" autocomplete="tel-national" maxlength="10" pattern="9[0-9]{9}" title="Enter the 10-digit mobile number after +63." aria-describedby="contactError" aria-invalid="<?= $formErrors["contact"] !== "" ? "true" : "false" ?>" required>
            </div>
            <input id="contact" name="contact" type="hidden" value="<?= employee_setup_h($formValues["contact"]) ?>">
            <p class="field-error" id="contactError" aria-live="polite"><?= employee_setup_h($formErrors["contact"]) ?></p>
          </div>

          <div class="admin-owner-field employee-setup-field employee-setup-field--wide">
            <label for="address">Address</label>
            <textarea id="address" class="<?= $formErrors["address"] !== "" ? "is-invalid" : "" ?>" name="address" rows="4" placeholder="Enter your complete address" maxlength="<?= SERVITECH_LIMIT_ADDRESS ?>" aria-describedby="addressError" aria-invalid="<?= $formErrors["address"] !== "" ? "true" : "false" ?>" required><?= employee_setup_h($formValues["address"]) ?></textarea>
            <p class="field-error" id="addressError" aria-live="polite"><?= employee_setup_h($formErrors["address"]) ?></p>
          </div>
        </div>
      </section>

      <section class="employee-setup-section" aria-labelledby="employee-emergency-heading">
        <div class="employee-setup-section__header">
          <h2 id="employee-emergency-heading">Emergency Contact</h2>
          <p>Add one trusted contact who can be reached if urgent employee coordination is needed.</p>
        </div>

        <div class="employee-setup-field-grid">
          <div class="admin-owner-field employee-setup-field">
            <label for="emergency_contact_name">Full Name</label>
            <input id="emergency_contact_name" class="<?= $formErrors["emergency_contact_name"] !== "" ? "is-invalid" : "" ?>" name="emergency_contact_name" value="<?= employee_setup_h($formValues["emergency_contact_name"]) ?>" placeholder="Enter full name" maxlength="<?= SERVITECH_LIMIT_FULLNAME ?>" aria-describedby="emergencyNameError" aria-invalid="<?= $formErrors["emergency_contact_name"] !== "" ? "true" : "false" ?>" required>
            <p class="field-error" id="emergencyNameError" aria-live="polite"><?= employee_setup_h($formErrors["emergency_contact_name"]) ?></p>
          </div>

          <div class="admin-owner-field employee-setup-field">
            <label for="emergency_contact_relationship">Relationship</label>
            <select id="emergency_contact_relationship" class="<?= $formErrors["emergency_contact_relationship"] !== "" ? "is-invalid" : "" ?>" name="emergency_contact_relationship" aria-describedby="relationshipError" aria-invalid="<?= $formErrors["emergency_contact_relationship"] !== "" ? "true" : "false" ?>" required>
              <option value="">Select relationship</option>
              <?php foreach ($relationshipOptions as $relationshipOption): ?>
                <option value="<?= employee_setup_h($relationshipOption) ?>" <?= $formValues["emergency_contact_relationship"] === $relationshipOption ? "selected" : "" ?>><?= employee_setup_h($relationshipOption) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="field-error" id="relationshipError" aria-live="polite"><?= employee_setup_h($formErrors["emergency_contact_relationship"]) ?></p>
          </div>

          <div class="admin-owner-field employee-setup-field employee-setup-field--wide">
            <label for="emergency_contact_address">Address</label>
            <textarea id="emergency_contact_address" class="<?= $formErrors["emergency_contact_address"] !== "" ? "is-invalid" : "" ?>" name="emergency_contact_address" rows="4" placeholder="Enter emergency contact address" maxlength="<?= SERVITECH_LIMIT_ADDRESS ?>" aria-describedby="emergencyAddressError" aria-invalid="<?= $formErrors["emergency_contact_address"] !== "" ? "true" : "false" ?>" required><?= employee_setup_h($formValues["emergency_contact_address"]) ?></textarea>
            <p class="field-error" id="emergencyAddressError" aria-live="polite"><?= employee_setup_h($formErrors["emergency_contact_address"]) ?></p>
          </div>

          <div class="admin-owner-field employee-setup-field">
            <label for="emergency_contact_mobile">Contact Number</label>
            <div id="emergencyContactControl" class="contact-number-control<?= $formErrors["emergency_contact_number"] !== "" ? " is-invalid" : "" ?>">
              <span class="contact-number-prefix" aria-label="Philippine country code">+63</span>
              <input id="emergency_contact_mobile" name="emergency_contact_mobile" type="tel" inputmode="numeric" value="<?= employee_setup_h($formValues["emergency_contact_mobile"]) ?>" placeholder="9XXXXXXXXX" autocomplete="tel-national" maxlength="10" pattern="9[0-9]{9}" title="Enter the 10-digit mobile number after +63." aria-describedby="emergencyContactError" aria-invalid="<?= $formErrors["emergency_contact_number"] !== "" ? "true" : "false" ?>" required>
            </div>
            <input id="emergency_contact_number" name="emergency_contact_number" type="hidden" value="<?= employee_setup_h($formValues["emergency_contact_number"]) ?>">
            <p class="field-error" id="emergencyContactError" aria-live="polite"><?= employee_setup_h($formErrors["emergency_contact_number"]) ?></p>
          </div>
        </div>
      </section>

      <div class="employee-setup-actions">
        <button id="employeeSetupSubmit" class="admin-owner-button" type="submit">Complete Account Setup</button>
      </div>
    </form>
  </section>
</main>
<script>
  (function () {
    const form = document.getElementById("employeeSetupForm");
    if (!form) return;

    const submitButton = document.getElementById("employeeSetupSubmit");
    const passwordMinLength = <?= EMPLOYEE_SETUP_PASSWORD_MIN_LENGTH ?>;
    const passwordMaxLength = <?= SERVITECH_PASSWORD_MAX_BYTES ?>;

    const fields = {
      new_password: {
        input: document.getElementById("new_password"),
        error: document.getElementById("newPasswordError"),
        validate(value) {
          if (!value) return "New password is required.";
          if (value.length < passwordMinLength) return `Password must be at least ${passwordMinLength} characters.`;
          if (value.length > passwordMaxLength) return `Password must not exceed ${passwordMaxLength} characters.`;
          if (!/[A-Z]/.test(value) || !/[a-z]/.test(value) || !/\d/.test(value) || !/[^A-Za-z0-9]/.test(value)) {
            return "Use uppercase, lowercase, number, and special character.";
          }
          return "";
        }
      },
      confirm_password: {
        input: document.getElementById("confirm_password"),
        error: document.getElementById("confirmPasswordError"),
        validate(value) {
          if (!value) return "Please confirm your new password.";
          return value === fields.new_password.input.value ? "" : "Passwords do not match.";
        }
      },
      contact: {
        input: document.getElementById("contact_mobile"),
        hidden: document.getElementById("contact"),
        error: document.getElementById("contactError"),
        control: document.getElementById("contactControl"),
        validate(value) {
          return /^9\d{9}$/.test(value) ? "" : "Enter a valid contact number.";
        }
      },
      address: {
        input: document.getElementById("address"),
        error: document.getElementById("addressError"),
        validate(value) {
          const trimmed = value.trim();
          if (!trimmed) return "Address is required.";
          return trimmed.length <= <?= SERVITECH_LIMIT_ADDRESS ?> ? "" : "Address must be <?= SERVITECH_LIMIT_ADDRESS ?> characters or fewer.";
        }
      },
      emergency_contact_name: {
        input: document.getElementById("emergency_contact_name"),
        error: document.getElementById("emergencyNameError"),
        validate(value) {
          const trimmed = value.trim();
          if (!trimmed) return "Emergency contact full name is required.";
          return /^[\p{L}\s.'-]+$/u.test(trimmed) && trimmed.length <= <?= SERVITECH_LIMIT_FULLNAME ?> ? "" : "Enter a valid emergency contact name.";
        }
      },
      emergency_contact_relationship: {
        input: document.getElementById("emergency_contact_relationship"),
        error: document.getElementById("relationshipError"),
        validate(value) {
          return value ? "" : "Select an emergency contact relationship.";
        }
      },
      emergency_contact_address: {
        input: document.getElementById("emergency_contact_address"),
        error: document.getElementById("emergencyAddressError"),
        validate(value) {
          const trimmed = value.trim();
          if (!trimmed) return "Emergency contact address is required.";
          return trimmed.length <= <?= SERVITECH_LIMIT_ADDRESS ?> ? "" : "Emergency contact address must be <?= SERVITECH_LIMIT_ADDRESS ?> characters or fewer.";
        }
      },
      emergency_contact_number: {
        input: document.getElementById("emergency_contact_mobile"),
        hidden: document.getElementById("emergency_contact_number"),
        error: document.getElementById("emergencyContactError"),
        control: document.getElementById("emergencyContactControl"),
        validate(value) {
          return /^9\d{9}$/.test(value) ? "" : "Enter a valid emergency contact number.";
        }
      }
    };

    const passwordRules = {
      length: (value) => value.length >= passwordMinLength,
      uppercase: (value) => /[A-Z]/.test(value),
      lowercase: (value) => /[a-z]/.test(value),
      number: (value) => /\d/.test(value),
      special: (value) => /[^A-Za-z0-9]/.test(value)
    };

    function sanitizeMobile(value) {
      let digits = value.replace(/\D/g, "");
      if (digits.startsWith("63")) digits = digits.slice(2);
      if (digits.startsWith("0")) digits = digits.slice(1);
      return digits.slice(0, 10);
    }

    function syncMobile(field) {
      field.input.value = sanitizeMobile(field.input.value);
      field.hidden.value = field.input.value ? `+63${field.input.value}` : "";
    }

    function setFieldState(field, message) {
      const target = field.control || field.input;
      field.error.textContent = message;
      target.classList.toggle("is-invalid", Boolean(message));
      field.input.setAttribute("aria-invalid", message ? "true" : "false");
    }

    function updatePasswordRequirements() {
      const value = fields.new_password.input.value;
      Object.keys(passwordRules).forEach((rule) => {
        const item = document.querySelector(`[data-password-rule="${rule}"]`);
        if (item) item.classList.toggle("is-met", passwordRules[rule](value));
      });
    }

    function updatePasswordToggle(toggle) {
      const input = document.getElementById(toggle.dataset.passwordToggle || "");
      if (!input) return;
      const hasValue = Boolean(input.value);
      toggle.classList.toggle("has-value", hasValue);
      toggle.tabIndex = hasValue ? 0 : -1;
      toggle.setAttribute("aria-hidden", hasValue ? "false" : "true");
      if (!hasValue) {
        input.type = "password";
        toggle.classList.remove("is-visible");
        toggle.setAttribute("aria-pressed", "false");
      }
    }

    function validateField(name, showMessage = true) {
      const field = fields[name];
      if (field.hidden) syncMobile(field);
      const message = field.validate(field.input.value);
      if (showMessage) setFieldState(field, message);
      return !message;
    }

    function validateForm(showMessages = true) {
      const isValid = Object.keys(fields).map((name) => validateField(name, showMessages)).every(Boolean);
      submitButton.disabled = !isValid;
      return isValid;
    }

    Object.keys(fields).forEach((name) => {
      const field = fields[name];
      const eventName = field.input.tagName === "SELECT" ? "change" : "input";
      field.input.addEventListener(eventName, () => {
        if (name === "new_password") {
          updatePasswordRequirements();
          if (fields.confirm_password.input.value) validateField("confirm_password");
        }
        if (name === "new_password" || name === "confirm_password") {
          document.querySelectorAll("[data-password-toggle]").forEach(updatePasswordToggle);
        }
        validateField(name);
        validateForm(false);
      });
      field.input.addEventListener("blur", () => {
        validateField(name);
        validateForm(false);
      });
    });

    document.querySelectorAll("[data-password-toggle]").forEach((toggle) => {
      const input = document.getElementById(toggle.dataset.passwordToggle || "");
      if (!input) return;
      toggle.addEventListener("click", () => {
        const shouldShow = input.type === "password";
        input.type = shouldShow ? "text" : "password";
        toggle.classList.toggle("is-visible", shouldShow);
        toggle.setAttribute("aria-pressed", shouldShow ? "true" : "false");
        toggle.setAttribute("aria-label", `${shouldShow ? "Hide" : "Show"} ${input.id === "confirm_password" ? "confirm password" : "new password"}`);
      });
      updatePasswordToggle(toggle);
    });

    form.addEventListener("submit", (event) => {
      if (!validateForm(true)) {
        event.preventDefault();
        const invalidField = form.querySelector("[aria-invalid='true']");
        if (invalidField instanceof HTMLElement) invalidField.focus();
      }
    });

    window.addEventListener("pageshow", () => {
      updatePasswordRequirements();
      document.querySelectorAll("[data-password-toggle]").forEach(updatePasswordToggle);
      validateForm(false);
    });
    updatePasswordRequirements();
    validateForm(false);
  })();
</script>
</body>
</html>
