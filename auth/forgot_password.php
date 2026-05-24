<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/mail.php";

$messageType = "";
$messageText = "";
$submittedEmail = "";
$requestMethod = (string)($_SERVER["REQUEST_METHOD"] ?? "GET");

function forgot_password_ensure_columns(PDO $pdo): void
{
    $pdo->exec("
        ALTER TABLE users
        ADD COLUMN IF NOT EXISTS reset_token TEXT,
        ADD COLUMN IF NOT EXISTS reset_token_expires TIMESTAMPTZ
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS users_reset_token_idx ON users (reset_token) WHERE reset_token IS NOT NULL");
}

function forgot_password_reset_url(string $token): string
{
    return "https://servitech.store/auth/reset_password.php?token=" . urlencode($token);
}

if ($requestMethod === "POST") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);

    $submittedEmail = strtolower(trim((string)($_POST["email"] ?? "")));

    if ($submittedEmail === "" || !filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
        $messageType = "error";
        $messageText = "Enter a valid email address to request a reset link.";
    } else {
        try {
            require_once __DIR__ . "/../config/db.php";

            $stmt = $pdo->prepare("SELECT id, email FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
            $stmt->execute([":email" => $submittedEmail]);
            $user = $stmt->fetch();

            if ($user) {
                forgot_password_ensure_columns($pdo);

                $token = bin2hex(random_bytes(32));
                $tokenHash = hash("sha256", $token);
                $email = (string)$user["email"];
                $resetUrl = forgot_password_reset_url($token);

                $update = $pdo->prepare("
                    UPDATE users
                    SET reset_token = :reset_token,
                        reset_token_expires = NOW() + INTERVAL '1 hour',
                        updated_at = NOW()
                    WHERE id = :user_id
                ");
                $update->execute([
                    ":user_id" => (int)$user["id"],
                    ":reset_token" => $tokenHash,
                ]);

                $mailResult = servitech_send_password_reset_mail($email, $resetUrl);
                if (!$mailResult["ok"]) {
                    $clear = $pdo->prepare("UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE id = :user_id");
                    $clear->execute([":user_id" => (int)$user["id"]]);
                    error_log("forgot password mail error: " . (string)$mailResult["error"]);

                    if (servitech_mail_debug_enabled()) {
                        throw new RuntimeException("Email sending failed: " . (string)$mailResult["error"]);
                    }
                }
            }

            $messageType = "success";
            $messageText = "If that email is registered, a password reset link has been sent.";
            $submittedEmail = "";
        } catch (Throwable $e) {
            error_log("forgot password error: " . $e->getMessage());
            $messageType = "error";
            $messageText = "We could not process the reset request right now. Please try again.";
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
  <title>ServiTech: Forgot Password</title>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=" . AUTH_UI_VERSION) ?>">
</head>
<body class="auth-page auth-page--login">

<?php render_auth_header("forgot-password-header-menu", "/auth/log_in.php", "Login"); ?>

  <main class="auth-shell">
    <section class="auth-card auth-card--login" aria-labelledby="forgot-password-title">
      <div class="auth-card__header">
        <p class="auth-card__eyebrow">Account Recovery</p>
        <h1 id="forgot-password-title">Forgot Password</h1>
        <p class="auth-card__subtitle">Enter your account email and we will send a secure link for choosing a new password.</p>
      </div>

      <?php if ($messageText !== ""): ?>
        <div class="form-alert <?= $messageType === "success" ? "form-alert--success" : "form-alert--error" ?>" role="alert">
          <?= htmlspecialchars($messageText, ENT_QUOTES, "UTF-8") ?>
        </div>
      <?php endif; ?>

      <form id="forgotPasswordForm" action="<?= auth_url("/auth/forgot_password.php") ?>" method="POST" class="register-form login-form" novalidate autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">

        <div class="form-field">
          <label for="resetEmail">Email Address</label>
          <input
            id="resetEmail"
            name="email"
            type="email"
            placeholder="Enter your email address"
            autocomplete="email"
            value="<?= htmlspecialchars($submittedEmail, ENT_QUOTES, "UTF-8") ?>"
            required
          >
          <p class="field-error" id="resetEmailError" aria-live="polite"></p>
        </div>

        <button type="submit" id="forgotPasswordSubmit" class="auth-submit">Send Reset Link</button>
      </form>

      <a href="<?= auth_url("/auth/log_in.php") ?>" class="back-login back-login--spaced">Back to login</a>
    </section>
  </main>

<?php render_auth_footer(); ?>

  <script src="<?= auth_url("/assets/js/header-menu.js") ?>" defer></script>
  <script>
    const forgotPasswordForm = document.getElementById("forgotPasswordForm");
    const forgotPasswordSubmit = document.getElementById("forgotPasswordSubmit");
    const resetEmail = document.getElementById("resetEmail");
    const resetEmailError = document.getElementById("resetEmailError");

    function validateResetEmail() {
      const value = resetEmail.value.trim();
      let message = "";

      if (!value) {
        message = "Email address is required.";
      } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        message = "Enter a valid email address.";
      }

      resetEmailError.textContent = message;
      resetEmail.classList.toggle("is-invalid", Boolean(message));
      resetEmail.setAttribute("aria-invalid", message ? "true" : "false");
      return !message;
    }

    resetEmail.addEventListener("input", validateResetEmail);
    resetEmail.addEventListener("blur", validateResetEmail);

    forgotPasswordForm.addEventListener("submit", (event) => {
      if (!validateResetEmail()) {
        event.preventDefault();
        return;
      }

      forgotPasswordSubmit.disabled = true;
      forgotPasswordSubmit.textContent = "Sending...";
    });
  </script>

</body>
</html>
