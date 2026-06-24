<?php
require_once __DIR__ . "/_shared.php";
require_once __DIR__ . "/guest_guard.php";
require_once __DIR__ . "/../config/csrf.php";
servitech_require_guest_page();

$state = strtolower(trim((string)($_SESSION["verification_registration_state"] ?? "pending")));
if ($state === "retry") {
    $state = "signup_delivery_failed";
}
if (!in_array($state, ["sent", "resent", "signup_delivery_failed", "resend_failed", "pending"], true)) {
    $state = "pending";
}

$email = strtolower(trim((string)($_SESSION["verification_email_hint"] ?? "")));
$deliveryMessage = trim((string)($_SESSION["verification_delivery_message"] ?? ""));
unset($_SESSION["verification_delivery_message"]);
$resendAvailableAt = max(0, (int)($_SESSION["verification_resend_available_at"] ?? 0));
$resendWaitSeconds = max(0, $resendAvailableAt - time());
$csrfToken = servitech_csrf_token();

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
$hasDeliveryIssue = in_array($state, ["signup_delivery_failed", "resend_failed"], true);
$signupFailed = $state === "signup_delivery_failed";
$wasResent = $state === "resent";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Verify Your Email</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=20260625-verification-pending-v2") ?>">
</head>
<body class="auth-page auth-page--login">

<?php render_auth_header("verification-pending-header-menu", "/auth/log_in.php", "Login"); ?>

  <main class="auth-shell auth-verification-shell">
    <section class="auth-card auth-card--login auth-verification-card" aria-labelledby="verification-pending-title">
      <div class="auth-verification-icon<?= $hasDeliveryIssue ? " auth-verification-icon--warning" : "" ?>" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false">
          <?php if ($hasDeliveryIssue): ?>
            <path d="M12 8.25v4.5m0 3h.01M10.1 4.7 3.4 16.3A2 2 0 0 0 5.13 19h13.74a2 2 0 0 0 1.73-3L13.9 4.7a2.2 2.2 0 0 0-3.8 0Z"></path>
          <?php else: ?>
            <path d="M3.75 6.75 12 12.5l8.25-5.75M5.25 19h13.5A2.25 2.25 0 0 0 21 16.75v-9.5A2.25 2.25 0 0 0 18.75 5H5.25A2.25 2.25 0 0 0 3 7.25v9.5A2.25 2.25 0 0 0 5.25 19Z"></path>
          <?php endif; ?>
        </svg>
      </div>

      <div class="auth-card__header auth-verification-header">
        <p class="auth-card__eyebrow">Account Verification</p>
        <h1 id="verification-pending-title"><?= $signupFailed ? "We couldn't send the verification email" : ($hasDeliveryIssue ? "Let's try sending the link again" : "Check your email to verify your account") ?></h1>
        <?php if ($signupFailed): ?>
          <p class="auth-card__subtitle">Supabase did not complete your signup because the verification email could not be sent.</p>
        <?php elseif ($hasDeliveryIssue): ?>
          <p class="auth-card__subtitle">Your account is still waiting for verification, but the latest email request was not accepted for delivery.</p>
        <?php else: ?>
          <p class="auth-card__subtitle"><?= $wasResent ? "We requested a fresh verification link for:" : "We sent a verification link to:" ?></p>
        <?php endif; ?>
      </div>

      <?php if ($email !== ""): ?>
        <div class="auth-verification-address-wrap">
          <span>Email address</span>
          <strong><?= htmlspecialchars($maskedEmail, ENT_QUOTES, "UTF-8") ?></strong>
        </div>
      <?php endif; ?>

      <?php if ($hasDeliveryIssue): ?>
        <div class="form-alert form-alert--warning auth-verification-alert" role="alert">
          <?= htmlspecialchars($deliveryMessage !== "" ? $deliveryMessage : ($signupFailed ? "No account was created. Return to registration and try again." : "We couldn't send a new verification email. Wait a moment, then try again."), ENT_QUOTES, "UTF-8") ?>
        </div>
      <?php elseif ($wasResent): ?>
        <div class="form-alert form-alert--success auth-verification-alert" role="status">
          Request accepted. A new verification email should arrive shortly.
        </div>
      <?php endif; ?>

      <?php if ($signupFailed): ?>
        <div class="auth-verification-recovery">
          <strong>What to do next</strong>
          <span>Return to registration, confirm your email address, and submit the form again. Once Supabase accepts the email, you’ll return here with inbox instructions.</span>
        </div>
      <?php else: ?>
      <ol class="auth-verification-steps" aria-label="What to do next">
        <li>
          <span class="auth-verification-step-number">1</span>
          <div><strong>Check your inbox</strong><span>Look for an email from ServiTech. Delivery can take a minute.</span></div>
        </li>
        <li>
          <span class="auth-verification-step-number">2</span>
          <div><strong>Open the verification link</strong><span>Click the link in the email to activate your account.</span></div>
        </li>
        <li>
          <span class="auth-verification-step-number">3</span>
          <div><strong>Return and log in</strong><span>After verification, use your email and password as normal.</span></div>
        </li>
      </ol>

      <div class="auth-verification-help">
        <strong>Email not showing up?</strong>
        <span>Check Spam, Junk, or Promotions, confirm the address above, and wait a short moment before resending.</span>
      </div>
      <?php endif; ?>

      <div class="auth-verification-actions">
        <?php if ($signupFailed): ?>
          <a href="<?= auth_url("/auth/regis.php") ?>" class="auth-submit auth-action-link">Back to registration</a>
        <?php elseif ($email !== ""): ?>
          <form action="<?= auth_url("/auth/resend_verification.php") ?>" method="POST" class="auth-verification-resend-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8") ?>">
            <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, "UTF-8") ?>">
            <input type="hidden" name="return_to" value="pending">
            <button
              type="submit"
              class="auth-submit"
              data-resend-button
              data-wait-seconds="<?= $resendWaitSeconds ?>"
              <?= $resendWaitSeconds > 0 ? "disabled" : "" ?>
            ><?= $resendWaitSeconds > 0 ? "Resend available in " . $resendWaitSeconds . "s" : "Resend verification email" ?></button>
          </form>
        <?php else: ?>
          <a href="<?= auth_url("/auth/resend_verification.php") ?>" class="auth-submit auth-action-link">Resend verification email</a>
        <?php endif; ?>
        <a href="<?= auth_url("/auth/log_in.php") ?>" class="auth-action-secondary">Back to login</a>
        <?php if (!$signupFailed): ?>
          <a href="<?= auth_url("/auth/regis.php") ?>" class="auth-verification-text-link">Use a different email address</a>
        <?php endif; ?>
      </div>
    </section>
  </main>

<?php render_auth_footer(); ?>
<?php servitech_render_guest_history_guard(); ?>
  <script src="<?= auth_url("/assets/js/header-menu.js") ?>" defer></script>
  <script>
    (() => {
      const button = document.querySelector("[data-resend-button]");
      if (!button) return;

      let remaining = Number(button.dataset.waitSeconds || 0);
      if (remaining <= 0) return;

      const tick = () => {
        remaining -= 1;
        if (remaining <= 0) {
          button.disabled = false;
          button.textContent = "Resend verification email";
          return;
        }
        button.textContent = `Resend available in ${remaining}s`;
        window.setTimeout(tick, 1000);
      };
      window.setTimeout(tick, 1000);
    })();
  </script>
</body>
</html>
