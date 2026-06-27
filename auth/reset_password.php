<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/../config/account.php";
require_once __DIR__ . "/../config/remember_me.php";

header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Referrer-Policy: same-origin");

const RESET_PASSWORD_INVALID_MESSAGE = "This reset link is invalid or has expired. Please request a new one.";

$supabaseRecoveryMode = servitech_supabase_auth_enabled();
$requestMethod = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
$requestAction = strtolower(trim((string)($_POST["action"] ?? "update")));
$token = (string)($_GET["token"] ?? $_POST["token"] ?? "");
$tokenLooksValid = preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1;
$messageType = "";
$messageText = "";
$tokenIsUsable = false;
$awaitingRecoveryFragment = false;

function reset_password_lookup(PDO $pdo, string $token): ?array
{
    if ($token === "" || !preg_match('/\A[a-f0-9]{64}\z/i', $token)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, email
        FROM users
        WHERE reset_token = :token_hash
          AND reset_token_expires > NOW()
        LIMIT 1
    ");
    $stmt->execute([":token_hash" => hash("sha256", $token)]);
    $request = $stmt->fetch();

    return $request ?: null;
}

function reset_password_clear_supabase_recovery(): void
{
    unset($_SESSION["password_recovery"]);
}

function reset_password_store_supabase_recovery(array $authResponse): void
{
    $accessToken = trim((string)($authResponse["access_token"] ?? ""));
    if ($accessToken === "") {
        throw new DomainException("Supabase did not return a recovery session.");
    }

    $user = is_array($authResponse["user"] ?? null) ? $authResponse["user"] : [];
    if (!$user) {
        $userResponse = servitech_supabase_get_user($accessToken);
        $user = is_array($userResponse["user"] ?? null) ? $userResponse["user"] : $userResponse;
    }

    $userId = strtolower(trim((string)($user["id"] ?? "")));
    if ($userId === "") {
        throw new DomainException("The recovery session is not linked to an account.");
    }

    $claims = servitech_supabase_jwt_claims($accessToken);
    $expiresAt = (int)($claims["exp"] ?? (time() + 3600));
    if ($expiresAt <= time()) {
        throw new DomainException("The recovery session has expired.");
    }

    $_SESSION["password_recovery"] = [
        "access_token" => $accessToken,
        "user_id" => $userId,
        "expires_at" => $expiresAt,
        "created_at" => time(),
    ];
    session_regenerate_id(true);
}

function reset_password_supabase_recovery(): ?array
{
    $recovery = $_SESSION["password_recovery"] ?? null;
    if (!is_array($recovery)) {
        return null;
    }

    $accessToken = trim((string)($recovery["access_token"] ?? ""));
    $userId = trim((string)($recovery["user_id"] ?? ""));
    $expiresAt = (int)($recovery["expires_at"] ?? 0);
    if ($accessToken === "" || $userId === "" || $expiresAt <= time()) {
        reset_password_clear_supabase_recovery();
        return null;
    }

    return $recovery;
}

function reset_password_finish_success(string $recoveryAccessToken = ""): void
{
    if ($recoveryAccessToken !== "") {
        servitech_supabase_logout_token($recoveryAccessToken);
    }
    reset_password_clear_supabase_recovery();
    servitech_supabase_clear_auth_session();
    servitech_supabase_clear_application_session();
    servitech_remember_clear_cookie();
    session_regenerate_id(true);
    header("Location: " . auth_url_raw("/auth/log_in.php?reset=success"), true, 303);
    exit();
}

// Supabase's default recovery template redirects with a session in the URL
// fragment. Custom server-side templates commonly send token_hash and type in
// the query string instead. Accept both without exposing either to the form.
if ($supabaseRecoveryMode && $requestMethod === "GET") {
    $callbackError = trim((string)(
        $_GET["error_description"]
        ?? $_GET["error_code"]
        ?? $_GET["error"]
        ?? ""
    ));
    $tokenHash = trim((string)($_GET["token_hash"] ?? ""));
    $callbackType = strtolower(trim((string)($_GET["type"] ?? "")));
    $authorizationCode = trim((string)($_GET["code"] ?? ""));

    if ($callbackError !== "") {
        reset_password_clear_supabase_recovery();
        $messageType = "error";
        $messageText = RESET_PASSWORD_INVALID_MESSAGE;
    } elseif ($tokenHash !== "") {
        reset_password_clear_supabase_recovery();
        if ($callbackType !== "recovery") {
            $messageType = "error";
            $messageText = RESET_PASSWORD_INVALID_MESSAGE;
        } else {
            try {
                $authResponse = servitech_supabase_verify_recovery_token_hash($tokenHash);
                reset_password_store_supabase_recovery($authResponse);
                header("Location: " . auth_url_raw("/auth/reset_password.php"), true, 303);
                exit();
            } catch (Throwable $exception) {
                error_log("Supabase recovery token verification failed: " . $exception->getMessage());
                $messageType = "error";
                $messageText = RESET_PASSWORD_INVALID_MESSAGE;
            }
        }
    } elseif ($authorizationCode !== "") {
        // This server flow does not create a browser-bound PKCE verifier. A
        // bare code therefore cannot be exchanged safely, so fail clearly.
        reset_password_clear_supabase_recovery();
        $messageType = "error";
        $messageText = RESET_PASSWORD_INVALID_MESSAGE;
    }
}

$resetRequest = null;
if (!$supabaseRecoveryMode && $tokenLooksValid) {
    try {
        require_once __DIR__ . "/../config/db.php";
        $resetRequest = reset_password_lookup($pdo, $token);
    } catch (Throwable $exception) {
        error_log("Password reset lookup failed: " . $exception->getMessage());
        $resetRequest = null;
    }
}

if ($requestMethod === "POST") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);

    if ($supabaseRecoveryMode && $requestAction === "bootstrap") {
        reset_password_clear_supabase_recovery();
        $recoveryType = strtolower(trim((string)($_POST["recovery_type"] ?? "")));
        $recoveryAccessToken = trim((string)($_POST["recovery_access_token"] ?? ""));

        if ($recoveryType !== "recovery" || $recoveryAccessToken === "") {
            $messageType = "error";
            $messageText = RESET_PASSWORD_INVALID_MESSAGE;
        } else {
            try {
                reset_password_store_supabase_recovery(["access_token" => $recoveryAccessToken]);
                header("Location: " . auth_url_raw("/auth/reset_password.php"), true, 303);
                exit();
            } catch (Throwable $exception) {
                error_log("Supabase recovery session bootstrap failed: " . $exception->getMessage());
                $messageType = "error";
                $messageText = RESET_PASSWORD_INVALID_MESSAGE;
            }
        }
    } else {
        $password = (string)($_POST["password"] ?? "");
        $confirmPassword = (string)($_POST["confirm_password"] ?? "");
        $supabaseRecovery = $supabaseRecoveryMode ? reset_password_supabase_recovery() : null;

        if ($supabaseRecoveryMode && !$supabaseRecovery) {
            $messageType = "error";
            $messageText = RESET_PASSWORD_INVALID_MESSAGE;
        } elseif (!$supabaseRecoveryMode && !$resetRequest) {
            $messageType = "error";
            $messageText = RESET_PASSWORD_INVALID_MESSAGE;
        } elseif (($passwordError = servitech_password_validation_error($password)) !== "") {
            $messageType = "error";
            $messageText = $passwordError;
        } elseif ($password !== $confirmPassword) {
            $messageType = "error";
            $messageText = "Passwords do not match.";
        } else {
            try {
                if ($supabaseRecoveryMode) {
                    $recoveryAccessToken = (string)$supabaseRecovery["access_token"];
                    servitech_supabase_update_user($recoveryAccessToken, ["password" => $password]);
                    reset_password_finish_success($recoveryAccessToken);
                }

                $pdo->beginTransaction();
                $update = $pdo->prepare("UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :user_id");
                $update->execute([
                    ":password_hash" => password_hash($password, PASSWORD_DEFAULT),
                    ":user_id" => (int)$resetRequest["id"],
                ]);

                $consume = $pdo->prepare("UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE id = :user_id");
                $consume->execute([":user_id" => (int)$resetRequest["id"]]);
                servitech_remember_revoke_all_for_user($pdo, (int)$resetRequest["id"]);
                $pdo->commit();
                reset_password_finish_success();
            } catch (Throwable $exception) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Reset password update failed: " . $exception->getMessage());

                if ($supabaseRecoveryMode && ($exception instanceof DomainException || in_array((int)$exception->getCode(), [400, 401, 403], true))) {
                    reset_password_clear_supabase_recovery();
                    $messageText = RESET_PASSWORD_INVALID_MESSAGE;
                } else {
                    $messageText = "We could not update your password right now. Please try again.";
                }
                $messageType = "error";
            }
        }
    }
}

if ($supabaseRecoveryMode) {
    $tokenIsUsable = reset_password_supabase_recovery() !== null;
    $awaitingRecoveryFragment = !$tokenIsUsable && $messageText === "" && $requestMethod === "GET";
} else {
    $tokenIsUsable = (bool)$resetRequest;
    if (!$tokenIsUsable && $messageText === "") {
        $messageType = "error";
        $messageText = RESET_PASSWORD_INVALID_MESSAGE;
    }
}

$csrfToken = servitech_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>ServiTech: Reset Password</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=20260625-reset-password") ?>">
</head>
<body class="auth-page auth-page--login">

<?php render_auth_header("reset-password-header-menu", "/auth/log_in.php", "Login"); ?>

  <main class="auth-shell">
    <section class="auth-card auth-card--login auth-reset-card" aria-labelledby="reset-password-title">
      <div class="auth-card__header">
        <p class="auth-card__eyebrow">Account Recovery</p>
        <h1 id="reset-password-title">Reset Password</h1>
        <p class="auth-card__subtitle">Choose a new password for your ServiTech account.</p>
      </div>

      <div
        id="resetPasswordMessage"
        class="form-alert <?= $messageType === "success" ? "form-alert--success" : "form-alert--error" ?>"
        role="alert"
        <?= $messageText === "" ? "hidden" : "" ?>
      ><?= htmlspecialchars($messageText, ENT_QUOTES, "UTF-8") ?></div>

      <?php if ($awaitingRecoveryFragment): ?>
        <div id="recoveryCallbackStatus" class="auth-recovery-status" role="status" aria-live="polite">
          <span class="auth-recovery-spinner" aria-hidden="true"></span>
          <span>Checking your secure reset link&hellip;</span>
        </div>
        <noscript>
          <div class="form-alert form-alert--error" role="alert"><?= htmlspecialchars(RESET_PASSWORD_INVALID_MESSAGE, ENT_QUOTES, "UTF-8") ?> JavaScript is required to complete this link.</div>
        </noscript>
      <?php endif; ?>

      <?php if ($tokenIsUsable): ?>
        <form id="resetPasswordForm" action="<?= auth_url("/auth/reset_password.php") ?>" method="POST" class="register-form login-form" novalidate autocomplete="on">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
          <input type="hidden" name="action" value="update">
          <?php if (!$supabaseRecoveryMode): ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, "UTF-8") ?>">
          <?php endif; ?>

          <div class="form-field">
            <label for="newPassword">New Password</label>
            <input id="newPassword" name="password" type="password" placeholder="Create a new password" autocomplete="new-password" minlength="<?= SERVITECH_PASSWORD_MIN_LENGTH ?>" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" aria-describedby="newPasswordHint newPasswordError" required autofocus>
            <p class="field-hint" id="newPasswordHint">Use <?= SERVITECH_PASSWORD_MIN_LENGTH ?> to <?= SERVITECH_PASSWORD_MAX_BYTES ?> characters.</p>
            <p class="field-error" id="newPasswordError" aria-live="polite"></p>
          </div>

          <div class="form-field">
            <label for="confirmPassword">Confirm New Password</label>
            <input id="confirmPassword" name="confirm_password" type="password" placeholder="Re-enter your new password" autocomplete="new-password" minlength="<?= SERVITECH_PASSWORD_MIN_LENGTH ?>" maxlength="<?= SERVITECH_PASSWORD_MAX_BYTES ?>" aria-describedby="confirmPasswordError" required>
            <p class="field-error" id="confirmPasswordError" aria-live="polite"></p>
          </div>

          <button type="submit" id="resetPasswordSubmit" class="auth-submit">Update Password</button>
        </form>
      <?php endif; ?>

      <?php if ($supabaseRecoveryMode): ?>
        <form id="recoveryBootstrapForm" action="<?= auth_url("/auth/reset_password.php") ?>" method="POST" hidden>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
          <input type="hidden" name="action" value="bootstrap">
          <input type="hidden" id="recoveryAccessToken" name="recovery_access_token" value="">
          <input type="hidden" id="recoveryType" name="recovery_type" value="">
        </form>
      <?php endif; ?>

      <a href="<?= auth_url($tokenIsUsable ? "/auth/log_in.php" : "/auth/forgot_password.php") ?>" class="back-login back-login--spaced">
        <?= $tokenIsUsable ? "Cancel and return to login" : "Request a new reset link" ?>
      </a>
    </section>
  </main>

<?php render_auth_footer(); ?>

  <script src="<?= auth_url("/assets/js/header-menu.js") ?>" defer></script>
  <script>
    (() => {
      const supabaseRecoveryMode = <?= $supabaseRecoveryMode ? "true" : "false" ?>;
      const awaitingRecoveryFragment = <?= $awaitingRecoveryFragment ? "true" : "false" ?>;
      const resetPageUrl = <?= auth_json_url("/auth/reset_password.php") ?>;
      const invalidLinkMessage = <?= json_encode(RESET_PASSWORD_INVALID_MESSAGE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const message = document.getElementById("resetPasswordMessage");
      const callbackStatus = document.getElementById("recoveryCallbackStatus");
      const bootstrapForm = document.getElementById("recoveryBootstrapForm");

      function showInvalidRecoveryLink() {
        if (callbackStatus) callbackStatus.hidden = true;
        if (message) {
          message.className = "form-alert form-alert--error";
          message.textContent = invalidLinkMessage;
          message.hidden = false;
        }
      }

      if (supabaseRecoveryMode && bootstrapForm && window.location.hash.length > 1) {
        const fragment = new URLSearchParams(window.location.hash.replace(/^#/, ""));
        const accessToken = fragment.get("access_token") || "";
        const recoveryType = (fragment.get("type") || "").toLowerCase();
        const callbackError = fragment.get("error_description") || fragment.get("error_code") || fragment.get("error") || "";

        // Remove credentials and provider error details from browser history
        // before sending the recovery session to this same-origin endpoint.
        window.history.replaceState({}, document.title, resetPageUrl);

        document.getElementById("recoveryAccessToken").value = callbackError ? "" : accessToken;
        document.getElementById("recoveryType").value = callbackError ? "invalid" : recoveryType;
        bootstrapForm.submit();
      } else if (supabaseRecoveryMode && awaitingRecoveryFragment) {
        showInvalidRecoveryLink();
      }

      const resetPasswordForm = document.getElementById("resetPasswordForm");
      if (!resetPasswordForm) return;

      const resetPasswordSubmit = document.getElementById("resetPasswordSubmit");
      const newPassword = document.getElementById("newPassword");
      const confirmPassword = document.getElementById("confirmPassword");
      const newPasswordError = document.getElementById("newPasswordError");
      const confirmPasswordError = document.getElementById("confirmPasswordError");
      const passwordMinLength = <?= SERVITECH_PASSWORD_MIN_LENGTH ?>;
      const passwordMaxBytes = <?= SERVITECH_PASSWORD_MAX_BYTES ?>;

      function passwordByteLength(value) {
        return new TextEncoder().encode(value).length;
      }

      function setFieldState(input, error, fieldMessage) {
        error.textContent = fieldMessage;
        input.classList.toggle("is-invalid", Boolean(fieldMessage));
        input.setAttribute("aria-invalid", fieldMessage ? "true" : "false");
      }

      function validatePasswords() {
        let passwordMessage = "";
        let confirmMessage = "";

        if (!newPassword.value) {
          passwordMessage = "Password is required.";
        } else if (newPassword.value.length < passwordMinLength) {
          passwordMessage = `Password must be at least ${passwordMinLength} characters.`;
        } else if (passwordByteLength(newPassword.value) > passwordMaxBytes) {
          passwordMessage = `Password must not exceed ${passwordMaxBytes} bytes.`;
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
    })();
  </script>

</body>
</html>
