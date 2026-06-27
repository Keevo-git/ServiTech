<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/guest_guard.php";
require_once __DIR__ . "/../config/account.php";
require_once __DIR__ . "/../config/input_limits.php";

function servitech_internal_login_message_map(string $ownerLabel): array
{
    return [
        "required" => ["error", "Enter your email address and password to continue."],
        "google_required" => ["error", "This account uses Google sign-in. Use the customer login page for Google account access."],
        "session_expired" => ["error", "Your session expired or your account access changed. Please log in again."],
        "verify_email" => ["error", "Please verify your email before logging in."],
        "throttled" => ["error", "Too many failed login attempts. Wait a few minutes before trying again."],
        "inactive" => ["error", "This account is deactivated. Please contact a Super Admin."],
        "fail" => ["error", "Invalid email or password."],
        "wrong_role_super_admin" => ["error", "This login page is only for Super Admin accounts."],
        "wrong_role_admin" => ["error", "This login page is only for employee Admin accounts."],
        "wrong_role_customer" => ["error", "Internal accounts must use the correct Super Admin or Admin login page."],
        "logout" => ["success", "You have been logged out."],
        "success" => ["success", "{$ownerLabel} login successful."],
    ];
}

function servitech_render_internal_login_page(array $config): void
{
    servitech_require_guest_page();

    $csrfToken = servitech_csrf_token();
    $rememberMeRetry = !empty($_SESSION["login_remember_retry"]);
    unset($_SESSION["login_remember_retry"]);

    $title = (string)$config["title"];
    $subtitle = (string)$config["subtitle"];
    $badge = (string)$config["badge"];
    $button = (string)$config["button"];
    $path = (string)$config["path"];
    $otherPath = (string)$config["other_path"];
    $otherLabel = (string)$config["other_label"];
    $menuId = (string)$config["menu_id"];
    $loginCode = strtolower(trim((string)($_GET["login"] ?? "")));
    $messageMap = servitech_internal_login_message_map($title);
    $pageMessage = $messageMap[$loginCode] ?? null;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES, "UTF-8") ?> | ServiTech</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=20260625-auth-verification") ?>">
  <?php render_auth_toast_assets(); ?>
</head>
<body class="auth-page auth-page--login">

<?php render_auth_header($menuId, $otherPath, $otherLabel); ?>

  <main class="auth-shell">
    <section class="auth-card auth-card--login" aria-labelledby="internal-login-title">
      <div class="auth-card__header">
        <p class="auth-card__eyebrow"><?= htmlspecialchars($badge, ENT_QUOTES, "UTF-8") ?></p>
        <h1 id="internal-login-title"><?= htmlspecialchars($title, ENT_QUOTES, "UTF-8") ?></h1>
        <p class="auth-card__subtitle"><?= htmlspecialchars($subtitle, ENT_QUOTES, "UTF-8") ?></p>
      </div>

      <div id="loginMessage" class="form-alert" role="alert" hidden></div>

      <form id="loginForm" action="<?= auth_url($path) ?>" method="POST" class="register-form login-form" novalidate autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
        <div class="form-field">
          <label for="loginEmail">Email Address</label>
          <input id="loginEmail" name="email" type="email" placeholder="Enter your email address" autocomplete="email" maxlength="<?= SERVITECH_LIMIT_EMAIL ?>" required>
          <p class="field-error" id="loginEmailError" aria-live="polite"></p>
        </div>

        <div class="form-field">
          <label for="loginPassword">Password</label>
          <div class="password-input-wrap">
            <input id="loginPassword" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" required>
            <button type="button" class="password-toggle" id="passwordToggle" aria-label="Show password" aria-pressed="false" aria-hidden="true" tabindex="-1"></button>
          </div>
          <p class="field-error" id="loginPasswordError" aria-live="polite"></p>
          <div class="auth-login-options">
            <label class="remember-me-control" for="rememberMe">
              <input id="rememberMe" name="remember_me" type="checkbox" value="1"<?= $rememberMeRetry ? " checked" : "" ?>>
              <span class="login-option-text">Remember me</span>
            </label>
            <a href="<?= auth_url("/auth/forgot_password.php") ?>" class="forgot-link login-option-text">Forgot Password?</a>
          </div>
        </div>

        <button type="submit" id="loginSubmit" class="auth-submit"><?= htmlspecialchars($button, ENT_QUOTES, "UTF-8") ?></button>
        <p class="auth-note">Use only your assigned ServiTech internal account. This page does not create or reveal credentials.</p>
      </form>

      <a href="<?= auth_url("/auth/log_in.php") ?>" class="back-login">Customer login</a>
    </section>
  </main>

<?php render_auth_footer(); ?>

  <script src="<?= auth_url("/assets/js/csrf.js") ?>" defer></script>
  <script>
    const loginPageUrl = <?= auth_json_url($path) ?>;
    const loginButtonLabel = <?= json_encode($button, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const pageMessage = <?= json_encode($pageMessage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const loginForm = document.getElementById("loginForm");
    const loginSubmit = document.getElementById("loginSubmit");
    const loginMessage = document.getElementById("loginMessage");
    const loginEmail = document.getElementById("loginEmail");
    const loginPassword = document.getElementById("loginPassword");
    const loginEmailError = document.getElementById("loginEmailError");
    const loginPasswordError = document.getElementById("loginPasswordError");
    const passwordToggle = document.getElementById("passwordToggle");

    const loginFields = {
      email: {
        input: loginEmail,
        error: loginEmailError,
        validate: (value) => {
          const trimmedValue = value.trim();
          if (!trimmedValue) return "Email address is required.";
          return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmedValue) ? "" : "Enter a valid email address.";
        }
      },
      password: {
        input: loginPassword,
        error: loginPasswordError,
        validate: (value) => value ? "" : "Password is required."
      }
    };

    function toast(type, text) {
      if (window.servitechToast) {
        window.servitechToast(text, { tone: type === "success" ? "success" : "error" });
      }
    }

    function setMessage(type, text) {
      loginMessage.className = "form-alert " + (type === "success" ? "form-alert--success" : "form-alert--error");
      loginMessage.textContent = text;
      loginMessage.hidden = false;
      toast(type, text);
    }

    function clearMessage() {
      loginMessage.hidden = true;
      loginMessage.textContent = "";
    }

    function setFieldState(fieldConfig, message) {
      fieldConfig.error.textContent = message;
      fieldConfig.input.classList.toggle("is-invalid", Boolean(message));
      fieldConfig.input.setAttribute("aria-invalid", message ? "true" : "false");
    }

    function validateField(fieldName, showMessage = true) {
      const field = loginFields[fieldName];
      const message = field.validate(field.input.value);
      if (showMessage) setFieldState(field, message);
      return !message;
    }

    function validateLoginForm() {
      return Object.keys(loginFields).map((fieldName) => validateField(fieldName)).every(Boolean);
    }

    Object.keys(loginFields).forEach((fieldName) => {
      const field = loginFields[fieldName];
      field.input.addEventListener("input", () => validateField(fieldName));
      field.input.addEventListener("blur", () => validateField(fieldName));
    });

    loginForm.addEventListener("submit", (event) => {
      clearMessage();
      if (!validateLoginForm()) {
        event.preventDefault();
        toast("error", "Enter a valid email address and password.");
        return;
      }
      loginSubmit.disabled = true;
      loginSubmit.textContent = "Signing in...";
      loginForm.classList.add("is-submitting");
    });

    passwordToggle.addEventListener("click", () => {
      const showPassword = loginPassword.type === "password";
      loginPassword.type = showPassword ? "text" : "password";
      passwordToggle.setAttribute("aria-label", showPassword ? "Hide password" : "Show password");
      passwordToggle.setAttribute("aria-pressed", showPassword ? "true" : "false");
      passwordToggle.classList.toggle("is-visible", showPassword);
    });

    function updatePasswordToggleVisibility() {
      const hasPassword = Boolean(loginPassword.value);
      passwordToggle.classList.toggle("has-value", hasPassword);
      passwordToggle.tabIndex = hasPassword ? 0 : -1;
      passwordToggle.setAttribute("aria-hidden", hasPassword ? "false" : "true");
      if (!hasPassword) {
        loginPassword.type = "password";
        passwordToggle.classList.remove("is-visible");
        passwordToggle.setAttribute("aria-label", "Show password");
        passwordToggle.setAttribute("aria-pressed", "false");
      }
    }

    loginPassword.addEventListener("input", updatePasswordToggleVisibility);
    loginPassword.addEventListener("change", updatePasswordToggleVisibility);
    window.addEventListener("pageshow", updatePasswordToggleVisibility);
    updatePasswordToggleVisibility();

    if (Array.isArray(pageMessage) && pageMessage.length >= 2) {
      setMessage(pageMessage[0], pageMessage[1]);
      window.history.replaceState({}, document.title, loginPageUrl);
    }
  </script>

<?php servitech_render_guest_history_guard(); ?>
  <script src="<?= auth_url("/assets/js/header-menu.js") ?>" defer></script>
</body>
</html>
<?php
}
