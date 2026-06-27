<?php
require_once __DIR__ . "/../../config/session_check.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/../../config/google_account_completion.php";
require_once __DIR__ . "/../../auth/_shared.php";

header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!servitech_is_logged_in()) {
    header("Location: " . servitech_url("/auth/log_in.php"));
    exit();
}
if (servitech_is_admin()) {
    header("Location: " . servitech_url(servitech_internal_dashboard_path()));
    exit();
}

$userId = (int)($_SESSION["user_id"] ?? 0);
$accountPdo = servitech_supabase_auth_enabled() ? servitech_db_connect_privileged() : $pdo;
$status = servitech_refresh_google_account_completion_state($accountPdo, $userId);

if (!$status["is_google"] || !$status["required"]) {
    header("Location: " . servitech_url("/pages/customer/customer_dash.php"));
    exit();
}

$errors = ["contact" => "", "password" => "", "confirm_password" => "", "general" => ""];
$contactInput = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);

    $password = (string)($_POST["password"] ?? "");
    $confirmPassword = (string)($_POST["confirm_password"] ?? "");

    if ($status["missing_contact"]) {
        $contactInput = preg_replace('/\D+/', '', (string)($_POST["contact_mobile"] ?? $_POST["contact"] ?? "")) ?? "";
        if (str_starts_with($contactInput, "63")) {
            $contactInput = substr($contactInput, 2);
        }
        if (str_starts_with($contactInput, "0")) {
            $contactInput = substr($contactInput, 1);
        }
        $contactInput = substr($contactInput, 0, 10);
        if (!preg_match('/^9\d{9}$/', $contactInput)) {
            $errors["contact"] = "Enter a valid 10-digit Philippine mobile number after +63, starting with 9.";
        }
    }

    if ($status["missing_password"]) {
        $errors["password"] = servitech_password_validation_error($password);
        if ($confirmPassword === "") {
            $errors["confirm_password"] = "Confirm your password.";
        } elseif ($password !== $confirmPassword) {
            $errors["confirm_password"] = "Password and confirmation do not match.";
        }
    }

    if (!(bool)array_filter($errors)) {
        try {
            $columns = servitech_google_account_profile_columns($accountPdo);
            $assignments = [];
            $parameters = [":user_id" => $userId];

            if ($status["missing_contact"]) {
                $assignments[] = servitech_google_account_quote_identifier($columns["contact"]) . " = :contact";
                $parameters[":contact"] = servitech_google_account_normalize_contact($contactInput);
            }

            if ($status["missing_password"]) {
                if (servitech_supabase_auth_enabled()) {
                    if ($columns["completion"] === null) {
                        throw new RuntimeException("The Google account completion migration has not been applied.");
                    }
                    $accessToken = trim((string)($_SESSION["supabase_access_token"] ?? ""));
                    if ($accessToken === "") {
                        throw new RuntimeException("Your authentication session has expired.");
                    }
                    servitech_supabase_update_user($accessToken, ["password" => $password]);
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    if (!is_string($passwordHash) || $passwordHash === "") {
                        throw new RuntimeException("The password could not be secured.");
                    }
                    $assignments[] = servitech_google_account_quote_identifier($columns["password"]) . " = :password_hash";
                    $parameters[":password_hash"] = $passwordHash;
                }

                if ($columns["completion"] !== null) {
                    $assignments[] = servitech_google_account_quote_identifier($columns["completion"]) . " = COALESCE(" .
                        servitech_google_account_quote_identifier($columns["completion"]) . ", NOW())";
                }
            }

            if (!$assignments) {
                throw new RuntimeException("No missing account details were available to update.");
            }

            $assignments[] = "updated_at = NOW()";
            $update = $accountPdo->prepare(
                "UPDATE users SET " . implode(", ", $assignments) . " WHERE id = :user_id"
            );
            $update->execute($parameters);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException("The account details could not be saved.");
            }

            $completedStatus = servitech_refresh_google_account_completion_state($accountPdo, $userId);
            if ($completedStatus["required"]) {
                throw new RuntimeException("The account is still missing required setup details.");
            }

            $_SESSION["servitech_customer_toast"] = [
                "tone" => "success",
                "message" => "Your Google account setup is complete.",
            ];
            header("Location: " . servitech_url("/pages/customer/customer_dash.php"));
            exit();
        } catch (Throwable $exception) {
            error_log("Google account completion error: " . $exception->getMessage());
            $errors["general"] = $exception instanceof DomainException
                ? "That password could not be set. Please choose a different password and try again."
                : "We could not finish your account setup right now. Please try again.";
        }
    }
}

$csrfToken = servitech_csrf_token();
$needsBoth = $status["missing_password"] && $status["missing_contact"];
$setupDescription = $needsBoth
    ? "Please add a password and contact number to finish setting up your account."
    : ($status["missing_password"]
        ? "Please create a password to finish setting up your account."
        : "Please add a contact number to finish setting up your account.");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Complete Account Setup</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=20260623-google-account-setup") ?>">
</head>
<body class="auth-page auth-page--register google-account-setup-page">

<?php render_auth_header("account-setup-header-menu", "/auth/logout.php", "Log out"); ?>

  <main class="auth-shell">
    <section class="auth-card auth-card--register account-setup-card" aria-labelledby="account-setup-title">
      <div class="auth-card__header">
        <p class="auth-card__eyebrow">One last step</p>
        <h1 id="account-setup-title">Complete your account setup</h1>
        <p class="auth-card__subtitle"><?= htmlspecialchars($setupDescription, ENT_QUOTES, "UTF-8") ?></p>
      </div>

      <?php if ($errors["general"] !== ""): ?>
        <div class="form-alert form-alert--error" role="alert"><?= htmlspecialchars($errors["general"], ENT_QUOTES, "UTF-8") ?></div>
      <?php endif; ?>

      <form id="googleAccountSetupForm" method="POST" class="register-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">

        <?php if ($status["missing_contact"]): ?>
          <section class="form-section" aria-labelledby="contact-section-title">
            <div class="form-section__header">
              <h2 id="contact-section-title">Contact number</h2>
              <p>We use this number for queue, order, and service updates.</p>
            </div>
            <div class="form-field">
              <label for="contactMobile">Philippine mobile number</label>
              <div id="contactControl" class="contact-number-control<?= $errors["contact"] !== "" ? " is-invalid" : "" ?>">
                <span class="contact-number-prefix" aria-label="Philippine country code">+63</span>
                <input id="contactMobile" name="contact_mobile" type="tel" inputmode="numeric" value="<?= htmlspecialchars($contactInput, ENT_QUOTES, "UTF-8") ?>" placeholder="9XXXXXXXXX" autocomplete="tel-national" maxlength="10" pattern="9[0-9]{9}" aria-describedby="contactHint contactError" required>
              </div>
              <p class="field-hint" id="contactHint">Enter the 10-digit mobile number after +63, starting with 9.</p>
              <p class="field-error" id="contactError" aria-live="polite"><?= htmlspecialchars($errors["contact"], ENT_QUOTES, "UTF-8") ?></p>
            </div>
          </section>
        <?php endif; ?>

        <?php if ($status["missing_password"]): ?>
          <section class="form-section" aria-labelledby="password-section-title">
            <div class="form-section__header">
              <h2 id="password-section-title">Account password</h2>
              <p>This becomes your current password for Edit Profile and other secure account changes.</p>
            </div>
            <div class="registration-field-stack">
              <div class="form-field">
                <label for="password">Create password</label>
                <div class="password-input-wrap">
                  <input id="password" name="password" type="password" placeholder="Create a password" autocomplete="new-password" minlength="<?= SERVITECH_PASSWORD_MIN_LENGTH ?>" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" aria-describedby="passwordHint passwordError" required>
                  <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false" aria-hidden="true" tabindex="-1"></button>
                </div>
                <p class="field-hint" id="passwordHint">Use <?= SERVITECH_PASSWORD_MIN_LENGTH ?> to <?= SERVITECH_PASSWORD_MAX_BYTES ?> characters.</p>
                <p class="field-error" id="passwordError" aria-live="polite"><?= htmlspecialchars($errors["password"], ENT_QUOTES, "UTF-8") ?></p>
              </div>

              <div class="form-field">
                <label for="confirmPassword">Confirm password</label>
                <div class="password-input-wrap">
                  <input id="confirmPassword" name="confirm_password" type="password" placeholder="Re-enter your password" autocomplete="new-password" minlength="<?= SERVITECH_PASSWORD_MIN_LENGTH ?>" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" aria-describedby="confirmPasswordError" required>
                  <button type="button" class="password-toggle" data-password-toggle="confirmPassword" aria-label="Show confirm password" aria-pressed="false" aria-hidden="true" tabindex="-1"></button>
                </div>
                <p class="field-error" id="confirmPasswordError" aria-live="polite"><?= htmlspecialchars($errors["confirm_password"], ENT_QUOTES, "UTF-8") ?></p>
              </div>
            </div>
          </section>
        <?php endif; ?>

        <button type="submit" id="setupSubmit" class="auth-submit">Finish account setup</button>
        <p class="account-setup-note">You can continue using Google to sign in after creating this password.</p>
      </form>
    </section>
  </main>

<?php render_auth_footer(); ?>

  <script>
    (() => {
      const form = document.getElementById("googleAccountSetupForm");
      const submit = document.getElementById("setupSubmit");
      const contact = document.getElementById("contactMobile");
      const contactControl = document.getElementById("contactControl");
      const contactError = document.getElementById("contactError");
      const password = document.getElementById("password");
      const passwordError = document.getElementById("passwordError");
      const confirmPassword = document.getElementById("confirmPassword");
      const confirmPasswordError = document.getElementById("confirmPasswordError");
      const passwordMinLength = <?= SERVITECH_PASSWORD_MIN_LENGTH ?>;
      const passwordMaxLength = <?= SERVITECH_PASSWORD_MAX_BYTES ?>;

      function sanitizeContact() {
        if (!contact) return;
        let digits = contact.value.replace(/\D/g, "");
        if (digits.startsWith("63")) digits = digits.slice(2);
        if (digits.startsWith("0")) digits = digits.slice(1);
        contact.value = digits.slice(0, 10);
      }

      function validateContact() {
        if (!contact) return true;
        sanitizeContact();
        const valid = /^9\d{9}$/.test(contact.value);
        contactError.textContent = valid ? "" : "Enter a valid 10-digit Philippine mobile number after +63, starting with 9.";
        contactControl.classList.toggle("is-invalid", !valid);
        contact.setAttribute("aria-invalid", valid ? "false" : "true");
        return valid;
      }

      function validatePassword() {
        if (!password) return true;
        let message = "";
        if (!password.value) message = "Password is required.";
        else if ([...password.value].length < passwordMinLength) message = `Password must be at least ${passwordMinLength} characters.`;
        else if (new TextEncoder().encode(password.value).length > passwordMaxLength) message = `Password must not exceed ${passwordMaxLength} bytes.`;
        passwordError.textContent = message;
        password.classList.toggle("is-invalid", Boolean(message));
        return !message;
      }

      function validateConfirmation() {
        if (!confirmPassword) return true;
        const message = !confirmPassword.value
          ? "Confirm your password."
          : (confirmPassword.value !== password.value ? "Password and confirmation do not match." : "");
        confirmPasswordError.textContent = message;
        confirmPassword.classList.toggle("is-invalid", Boolean(message));
        return !message;
      }

      document.querySelectorAll("[data-password-toggle]").forEach((toggle) => {
        const input = document.getElementById(toggle.dataset.passwordToggle);
        const sync = () => {
          const hasValue = Boolean(input.value);
          toggle.classList.toggle("has-value", hasValue);
          toggle.setAttribute("aria-hidden", hasValue ? "false" : "true");
          toggle.tabIndex = hasValue ? 0 : -1;
        };
        input.addEventListener("input", sync);
        toggle.addEventListener("click", () => {
          const show = input.type === "password";
          input.type = show ? "text" : "password";
          toggle.classList.toggle("is-visible", show);
          toggle.setAttribute("aria-label", `${show ? "Hide" : "Show"} password`);
          toggle.setAttribute("aria-pressed", show ? "true" : "false");
        });
        sync();
      });

      contact?.addEventListener("input", () => { sanitizeContact(); validateContact(); });
      contact?.addEventListener("blur", validateContact);
      password?.addEventListener("input", () => { validatePassword(); if (confirmPassword?.value) validateConfirmation(); });
      password?.addEventListener("blur", validatePassword);
      confirmPassword?.addEventListener("input", validateConfirmation);
      confirmPassword?.addEventListener("blur", validateConfirmation);

      form.addEventListener("submit", (event) => {
        const valid = [validateContact(), validatePassword(), validateConfirmation()].every(Boolean);
        if (!valid) {
          event.preventDefault();
          form.querySelector(".is-invalid")?.focus();
          return;
        }
        submit.disabled = true;
        submit.textContent = "Saving account details...";
      });
    })();
  </script>
  <style>
    .account-setup-card { max-width: 720px; }
    .account-setup-note { margin: -6px 0 0; color: #6f635b; font-size: 13px; line-height: 1.5; text-align: center; }
  </style>
</body>
</html>
