<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/../config/session_check.php";

$messageType = "";
$messageText = "";
$submittedEmail = "";
$requestMethod = (string)($_SERVER["REQUEST_METHOD"] ?? "GET");

function forgot_password_absolute_url(string $path): string
{
    $host = (string)($_SERVER["HTTP_HOST"] ?? "");
    if ($host === "") {
        return auth_url_raw($path);
    }

    $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
    return $scheme . "://" . $host . auth_url_raw($path);
}

function forgot_password_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_reset_requests (
            id BIGSERIAL PRIMARY KEY,
            user_id BIGINT NOT NULL,
            email TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at TIMESTAMPTZ NOT NULL,
            used_at TIMESTAMPTZ NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS password_reset_requests_token_hash_idx ON password_reset_requests (token_hash)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS password_reset_requests_user_id_idx ON password_reset_requests (user_id)");
}

function forgot_password_send_mail(string $email, string $resetUrl): bool
{
    $subject = "Reset your ServiTech password";
    $body = "We received a request to reset your ServiTech password.\n\n"
        . "Open this link to choose a new password:\n{$resetUrl}\n\n"
        . "This link expires in 1 hour. If you did not request this, you can ignore this email.";
    $headers = [
        "From: ServiTech <servitech@gmail.com>",
        "Reply-To: servitech@gmail.com",
        "Content-Type: text/plain; charset=UTF-8",
    ];

    return @mail($email, $subject, $body, implode("\r\n", $headers));
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
                forgot_password_ensure_table($pdo);

                $token = bin2hex(random_bytes(32));
                $tokenHash = hash("sha256", $token);
                $email = (string)$user["email"];
                $resetUrl = forgot_password_absolute_url("/auth/reset_password.php?token=" . urlencode($token));

                $insert = $pdo->prepare("
                    INSERT INTO password_reset_requests (user_id, email, token_hash, expires_at)
                    VALUES (:user_id, :email, :token_hash, NOW() + INTERVAL '1 hour')
                ");
                $insert->execute([
                    ":user_id" => (int)$user["id"],
                    ":email" => $email,
                    ":token_hash" => $tokenHash,
                ]);

                forgot_password_send_mail($email, $resetUrl);
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

      <a href="<?= auth_url("/auth/log_in.php") ?>" class="back-login">Back to login</a>
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
