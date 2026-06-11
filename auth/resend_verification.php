<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/guest_guard.php";
servitech_require_guest_page();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/account.php";
require_once __DIR__ . "/../config/mail.php";

$message = "";
$submittedEmail = "";
$csrfToken = servitech_csrf_token();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);

    $submittedEmail = strtolower(trim((string)($_POST["email"] ?? "")));
    if ($submittedEmail === "" || !filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
        $message = "Enter a valid email address.";
    } else {
        $message = "If the account exists and still needs verification, a new link will be sent shortly.";

        try {
            if (servitech_account_email_verification_required()) {
                $token = servitech_email_verification_token();
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET email_verification_token = :token_hash,
                        email_verification_expires = NOW() + INTERVAL '24 hours',
                        email_verification_sent_at = NOW(),
                        updated_at = NOW()
                    WHERE LOWER(email) = LOWER(:email)
                      AND email_verified_at IS NULL
                      AND (
                        email_verification_sent_at IS NULL
                        OR email_verification_sent_at < NOW() - INTERVAL '2 minutes'
                      )
                    RETURNING email
                ");
                $stmt->execute([
                    ":token_hash" => $token["token_hash"],
                    ":email" => $submittedEmail,
                ]);
                $user = $stmt->fetch();

                if ($user) {
                    $mailResult = servitech_send_email_verification_mail(
                        (string)$user["email"],
                        servitech_email_verification_url($token["token"])
                    );
                    if (empty($mailResult["ok"])) {
                        error_log("resend verification mail failed: " . (string)($mailResult["error"] ?? "unknown error"));
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("resend verification error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Resend Email Verification</title>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=" . AUTH_UI_VERSION) ?>">
</head>
<body class="auth-page auth-page--login">

<?php render_auth_header("resend-verification-header-menu", "/auth/log_in.php", "Login"); ?>

  <main class="auth-shell">
    <section class="auth-card auth-card--login" aria-labelledby="resend-verification-title">
      <div class="auth-card__header">
        <p class="auth-card__eyebrow">Account Verification</p>
        <h1 id="resend-verification-title">Resend Verification Email</h1>
        <p class="auth-card__subtitle">Enter your email address to request a fresh verification link.</p>
      </div>

      <?php if ($message !== ""): ?>
        <div class="form-alert <?= strpos($message, "Enter") === 0 ? "form-alert--error" : "form-alert--success" ?>" role="alert">
          <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
        </div>
      <?php endif; ?>

      <form action="<?= auth_url("/auth/resend_verification.php") ?>" method="POST" class="register-form login-form" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
        <div class="form-field">
          <label for="verificationEmail">Email Address</label>
          <input id="verificationEmail" name="email" type="email" value="<?= htmlspecialchars($submittedEmail, ENT_QUOTES, "UTF-8") ?>" autocomplete="email" required>
        </div>
        <button type="submit" class="auth-submit">Send Verification Link</button>
      </form>

      <a href="<?= auth_url("/auth/log_in.php") ?>" class="back-login">Back to login</a>
    </section>
  </main>

<?php render_auth_footer(); ?>
<?php servitech_render_guest_history_guard(); ?>
  <script src="<?= auth_url("/assets/js/header-menu.js") ?>" defer></script>
</body>
</html>
