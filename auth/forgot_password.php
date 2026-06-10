<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/mail.php";
require_once __DIR__ . "/../config/account.php";

$messageType = "";
$messageText = "";
$submittedEmail = "";
$requestMethod = (string)($_SERVER["REQUEST_METHOD"] ?? "GET");
const FORGOT_PASSWORD_PUBLIC_MESSAGE = "If the email exists, a reset link will be sent shortly.";
const FORGOT_PASSWORD_RATE_LIMIT_WINDOW = 900;
const FORGOT_PASSWORD_RATE_LIMIT_MAX_ATTEMPTS = 3;

function forgot_password_reset_url(string $token): string
{
    return servitech_account_public_url("/auth/reset_password.php?token=" . urlencode($token));
}

function forgot_password_rate_limit_path(): string
{
    return __DIR__ . "/../logs/forgot_password_rate_limit.json";
}

function forgot_password_client_ip(): string
{
    foreach (["HTTP_CF_CONNECTING_IP", "HTTP_X_FORWARDED_FOR", "REMOTE_ADDR"] as $key) {
        $value = trim((string)($_SERVER[$key] ?? ""));
        if ($value === "") {
            continue;
        }

        $firstValue = trim(explode(",", $value)[0]);
        if (filter_var($firstValue, FILTER_VALIDATE_IP)) {
            return $firstValue;
        }
    }

    return "unknown";
}

function forgot_password_rate_limit_key(string $email): string
{
    return hash("sha256", strtolower($email) . "|" . forgot_password_client_ip());
}

function forgot_password_rate_limit_allows(string $email): bool
{
    $path = forgot_password_rate_limit_path();
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    $handle = @fopen($path, "c+");
    if (!$handle) {
        servitech_forgot_password_mail_log("Forgot password rate limit skipped: could not open rate limit file.");
        return true;
    }

    $allowed = true;
    $now = time();
    $key = forgot_password_rate_limit_key($email);

    if (flock($handle, LOCK_EX)) {
        $contents = stream_get_contents($handle);
        $data = json_decode(is_string($contents) ? $contents : "", true);
        if (!is_array($data)) {
            $data = [];
        }

        foreach ($data as $storedKey => $timestamps) {
            if (!is_array($timestamps)) {
                unset($data[$storedKey]);
                continue;
            }

            $data[$storedKey] = array_values(array_filter($timestamps, static function ($timestamp) use ($now): bool {
                return is_int($timestamp) && $timestamp >= ($now - FORGOT_PASSWORD_RATE_LIMIT_WINDOW);
            }));

            if (!$data[$storedKey]) {
                unset($data[$storedKey]);
            }
        }

        $attempts = $data[$key] ?? [];
        $allowed = count($attempts) < FORGOT_PASSWORD_RATE_LIMIT_MAX_ATTEMPTS;
        $attempts[] = $now;
        $data[$key] = $attempts;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data));
        fflush($handle);
        flock($handle, LOCK_UN);
    }

    fclose($handle);

    if (!$allowed) {
        servitech_forgot_password_mail_log("Forgot password rate limit hit for hash={$key}; ip=" . forgot_password_client_ip());
    }

    return $allowed;
}

if ($requestMethod === "POST") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);

    $submittedEmail = strtolower(trim((string)($_POST["email"] ?? "")));
    servitech_forgot_password_mail_log("Forgot password submit started. Server time: " . date(DATE_ATOM) . "; submitted email={$submittedEmail}");

    if ($submittedEmail === "" || !filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
        $messageType = "error";
        $messageText = "Enter a valid email address to request a reset link.";
        servitech_forgot_password_mail_log("Forgot password validation failed: invalid email.");
    } else {
        try {
            if (forgot_password_rate_limit_allows($submittedEmail)) {
                require_once __DIR__ . "/../config/db.php";
                servitech_forgot_password_mail_log("Database connection loaded.");

                $stmt = $pdo->prepare("SELECT id, email FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1");
                $stmt->execute([":email" => $submittedEmail]);
                $user = $stmt->fetch();
                servitech_forgot_password_mail_log("Users table lookup result for {$submittedEmail}: " . ($user ? "email exists" : "email does not exist"));

                if ($user) {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash("sha256", $token);
                    $email = (string)$user["email"];
                    $resetUrl = forgot_password_reset_url($token);
                    servitech_forgot_password_mail_log("Reset token generated for {$email}.");

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
                    servitech_forgot_password_mail_log("Token save result for user id " . (int)$user["id"] . ": affected rows=" . $update->rowCount());

                    $mailResult = servitech_send_password_reset_mail($email, $resetUrl);
                    if (!$mailResult["ok"]) {
                        $clear = $pdo->prepare("UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE id = :user_id");
                        $clear->execute([":user_id" => (int)$user["id"]]);
                        servitech_mail_log("forgot password mail error for {$email}: " . (string)$mailResult["error"]);

                        throw new RuntimeException("Password reset email could not be sent.");
                    }
                } else {
                    servitech_forgot_password_mail_log("No email sent because {$submittedEmail} is not registered.");
                }
            } else {
                servitech_forgot_password_mail_log("Forgot password request accepted without SMTP attempt because rate limit is active.");
            }

            $messageType = "success";
            $messageText = FORGOT_PASSWORD_PUBLIC_MESSAGE;
            $submittedEmail = "";
        } catch (Throwable $e) {
            servitech_mail_log("forgot password error: " . $e->getMessage());
            servitech_forgot_password_mail_log("Forgot password exception: " . $e->getMessage());
            $messageType = "success";
            $messageText = FORGOT_PASSWORD_PUBLIC_MESSAGE;
            $submittedEmail = "";
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
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=20260610steady-header") ?>">
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
