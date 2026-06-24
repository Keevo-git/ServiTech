<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/guest_guard.php";
servitech_require_guest_page();

$state = strtolower(trim((string)($_SESSION["verification_registration_state"] ?? "pending")));
if (!in_array($state, ["sent", "retry", "pending"], true)) {
    $state = "pending";
}
$email = strtolower(trim((string)($_SESSION["verification_email_hint"] ?? "")));

function servitech_mask_verification_email(string $email): string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "your email address";
    }
    [$local, $domain] = explode("@", $email, 2);
    $visible = substr($local, 0, min(2, strlen($local)));
    return $visible . str_repeat("•", max(3, min(8, strlen($local) - strlen($visible)))) . "@" . $domain;
}

$maskedEmail = servitech_mask_verification_email($email);
$isSent = $state === "sent";
$isRetry = $state === "retry";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Verify Your Email</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=20260625-auth-verification-flow") ?>">
</head>
<body class="auth-page auth-page--login">

<?php render_auth_header("verification-pending-header-menu", "/auth/log_in.php", "Login"); ?>

  <main class="auth-shell">
    <section class="auth-card auth-card--login auth-verification-card" aria-labelledby="verification-pending-title">
      <div class="auth-verification-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false">
          <path d="M3.75 6.75 12 12.5l8.25-5.75M5.25 19h13.5A2.25 2.25 0 0 0 21 16.75v-9.5A2.25 2.25 0 0 0 18.75 5H5.25A2.25 2.25 0 0 0 3 7.25v9.5A2.25 2.25 0 0 0 5.25 19Z"></path>
        </svg>
      </div>

      <div class="auth-card__header">
        <p class="auth-card__eyebrow">Account Verification</p>
        <h1 id="verification-pending-title"><?= $isRetry ? "Verification email pending" : "Check your email" ?></h1>
        <?php if ($isSent): ?>
          <p class="auth-card__subtitle">Your account was created. We sent a verification link to:</p>
        <?php elseif ($isRetry): ?>
          <p class="auth-card__subtitle">Your registration reached Supabase, but email delivery needs another attempt.</p>
        <?php else: ?>
          <p class="auth-card__subtitle">Verify your email address before logging in to your ServiTech account.</p>
        <?php endif; ?>
      </div>

      <?php if ($email !== ""): ?>
        <p class="auth-verification-address"><?= htmlspecialchars($maskedEmail, ENT_QUOTES, "UTF-8") ?></p>
      <?php endif; ?>

      <?php if ($isRetry): ?>
        <div class="form-alert form-alert--warning" role="status">
          Wait a moment, then request another verification email. Repeated requests may be temporarily rate-limited by Supabase.
        </div>
      <?php else: ?>
        <div class="form-alert form-alert--success" role="status">
          Open the email and select the verification link. After verification, return here and log in normally.
        </div>
      <?php endif; ?>

      <div class="auth-verification-actions">
        <?php if ($isRetry): ?>
          <a href="<?= auth_url("/auth/resend_verification.php") ?>" class="auth-submit auth-action-link">Resend verification email</a>
          <a href="<?= auth_url("/auth/regis.php") ?>" class="auth-action-secondary">Back to registration</a>
        <?php else: ?>
          <a href="<?= auth_url("/auth/log_in.php") ?>" class="auth-submit auth-action-link">Continue to login</a>
          <a href="<?= auth_url("/auth/resend_verification.php") ?>" class="auth-action-secondary">Resend verification email</a>
        <?php endif; ?>
      </div>

      <p class="auth-note auth-verification-note">Check your spam or junk folder if the message does not appear in your inbox.</p>
    </section>
  </main>

<?php render_auth_footer(); ?>
<?php servitech_render_guest_history_guard(); ?>
  <script src="<?= auth_url("/assets/js/header-menu.js") ?>" defer></script>
</body>
</html>
