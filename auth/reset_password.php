<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/../config/session_check.php";

$token = (string)($_GET["token"] ?? $_POST["token"] ?? "");
$tokenLooksValid = preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1;
$messageType = "";
$messageText = "";
$tokenIsUsable = false;
$requestMethod = (string)($_SERVER["REQUEST_METHOD"] ?? "GET");

function reset_password_lookup(PDO $pdo, string $token): ?array
{
    if ($token === "" || !preg_match('/\A[a-f0-9]{64}\z/i', $token)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, user_id, email
        FROM password_reset_requests
        WHERE token_hash = :token_hash
          AND used_at IS NULL
          AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([":token_hash" => hash("sha256", $token)]);
    $request = $stmt->fetch();

    return $request ?: null;
}

$resetRequest = null;
if ($tokenLooksValid) {
    try {
        require_once __DIR__ . "/../config/db.php";
        $resetRequest = reset_password_lookup($pdo, $token);
        $tokenIsUsable = (bool)$resetRequest;
    } catch (Throwable $e) {
        $resetRequest = null;
        $tokenIsUsable = false;
    }
}

if ($requestMethod === "POST") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);

    $password = (string)($_POST["password"] ?? "");
    $confirmPassword = (string)($_POST["confirm_password"] ?? "");

    if (!$resetRequest) {
        $messageType = "error";
        $messageText = "This reset link is invalid or has expired. Request a new password reset link.";
    } elseif (strlen($password) < 8) {
        $messageType = "error";
        $messageText = "Password must be at least 8 characters.";
        $tokenIsUsable = true;
    } elseif ($password !== $confirmPassword) {
        $messageType = "error";
        $messageText = "Passwords do not match.";
        $tokenIsUsable = true;
    } else {
        try {
            $pdo->beginTransaction();

            $update = $pdo->prepare("UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :user_id");
            $update->execute([
                ":password_hash" => password_hash($password, PASSWORD_DEFAULT),
                ":user_id" => (int)$resetRequest["user_id"],
            ]);

            $consume = $pdo->prepare("UPDATE password_reset_requests SET used_at = NOW() WHERE id = :id");
            $consume->execute([":id" => (int)$resetRequest["id"]]);

            $pdo->commit();
            $tokenIsUsable = false;
            $messageType = "success";
            $messageText = "Your password has been updated. You can now log in with your new password.";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("reset password error: " . $e->getMessage());
            $messageType = "error";
            $messageText = "We could not update your password right now. Please try again.";
            $tokenIsUsable = true;
        }
    }
} elseif (!$tokenIsUsable) {
    $messageType = "error";
    $messageText = "This reset link is invalid or has expired. Request a new password reset link.";
}

$csrfToken = servitech_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Reset Password</title>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=" . AUTH_UI_VERSION) ?>">
</head>
<body class="auth-page auth-page--login">

<?php render_auth_header("reset-password-header-menu", "/auth/log_in.php", "Login"); ?>

  <main class="auth-shell">
    <section class="auth-card auth-card--login" aria-labelledby="reset-password-title">
      <div class="auth-card__header">
        <p class="auth-card__eyebrow">Account Recovery</p>
        <h1 id="reset-password-title">Reset Password</h1>
        <p class="auth-card__subtitle">Choose a new password for your ServiTech account.</p>
      </div>

      <?php if ($messageText !== ""): ?>
        <div class="form-alert <?= $messageType === "success" ? "form-alert--success" : "form-alert--error" ?>" role="alert">
          <?= htmlspecialchars($messageText, ENT_QUOTES, "UTF-8") ?>
        </div>
      <?php endif; ?>

      <?php if ($tokenIsUsable): ?>
        <form id="resetPasswordForm" action="<?= auth_url("/auth/reset_password.php") ?>" method="POST" class="register-form login-form" novalidate autocomplete="on">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, "UTF-8") ?>">

          <div class="form-field">
            <label for="newPassword">New Password</label>
            <input id="newPassword" name="password" type="password" placeholder="Create a new password" autocomplete="new-password" minlength="8" required>
            <p class="field-error" id="newPasswordError" aria-live="polite"></p>
          </div>

          <div class="form-field">
            <label for="confirmPassword">Confirm Password</label>
            <input id="confirmPassword" name="confirm_password" type="password" placeholder="Re-enter your new password" autocomplete="new-password" minlength="8" required>
            <p class="field-error" id="confirmPasswordError" aria-live="polite"></p>
          </div>

          <button type="submit" id="resetPasswordSubmit" class="auth-submit">Update Password</button>
        </form>
      <?php endif; ?>

      <a href="<?= auth_url($messageType === "success" ? "/auth/log_in.php" : "/auth/forgot_password.php") ?>" class="back-login">
        <?= $messageType === "success" ? "Back to login" : "Request a new reset link" ?>
      </a>
    </section>
  </main>

<?php render_auth_footer(); ?>

  <script src="<?= auth_url("/assets/js/header-menu.js") ?>" defer></script>
  <?php if ($tokenIsUsable): ?>
  <script>
    const resetPasswordForm = document.getElementById("resetPasswordForm");
    const resetPasswordSubmit = document.getElementById("resetPasswordSubmit");
    const newPassword = document.getElementById("newPassword");
    const confirmPassword = document.getElementById("confirmPassword");
    const newPasswordError = document.getElementById("newPasswordError");
    const confirmPasswordError = document.getElementById("confirmPasswordError");

    function setFieldState(input, error, message) {
      error.textContent = message;
      input.classList.toggle("is-invalid", Boolean(message));
      input.setAttribute("aria-invalid", message ? "true" : "false");
    }

    function validatePasswords() {
      let passwordMessage = "";
      let confirmMessage = "";

      if (!newPassword.value) {
        passwordMessage = "Password is required.";
      } else if (newPassword.value.length < 8) {
        passwordMessage = "Password must be at least 8 characters.";
      }

      if (!confirmPassword.value) {
        confirmMessage = "Please confirm your password.";
      } else if (confirmPassword.value !== newPassword.value) {
        confirmMessage = "Passwords do not match.";
      }

      setFieldState(newPassword, newPasswordError, passwordMessage);
      setFieldState(confirmPassword, confirmPasswordError, confirmMessage);
      return !passwordMessage && !confirmMessage;
    }

    newPassword.addEventListener("input", validatePasswords);
    confirmPassword.addEventListener("input", validatePasswords);
    newPassword.addEventListener("blur", validatePasswords);
    confirmPassword.addEventListener("blur", validatePasswords);

    resetPasswordForm.addEventListener("submit", (event) => {
      if (!validatePasswords()) {
        event.preventDefault();
        return;
      }

      resetPasswordSubmit.disabled = true;
      resetPasswordSubmit.textContent = "Updating...";
    });
  </script>
  <?php endif; ?>

</body>
</html>
