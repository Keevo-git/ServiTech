<?php
require_once __DIR__ . "/_shared.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Referrer-Policy: no-referrer");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>ServiTech: Completing Verification</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= auth_url("/assets/css/style.css?v=20260625-verification-callback") ?>">
</head>
<body class="auth-page auth-page--login">

<?php render_auth_header("verification-callback-header-menu", "/auth/log_in.php", "Login"); ?>

  <main class="auth-shell auth-verification-shell">
    <section class="auth-card auth-card--login auth-verification-card auth-verification-callback" aria-labelledby="verification-callback-title">
      <div class="auth-verification-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" focusable="false">
          <path d="m6.75 12.5 3.25 3.25 7.5-8"></path>
          <circle cx="12" cy="12" r="9"></circle>
        </svg>
      </div>
      <div class="auth-card__header auth-verification-header">
        <p class="auth-card__eyebrow">Account Verification</p>
        <h1 id="verification-callback-title">Completing your verification</h1>
        <p class="auth-card__subtitle" id="verification-callback-message">Please wait while we confirm the verification result.</p>
      </div>
      <noscript>
        <div class="form-alert form-alert--warning" role="alert">JavaScript is needed to finish this redirect. Return to login and try signing in; your email may already be verified.</div>
        <a href="<?= auth_url("/auth/log_in.php") ?>" class="auth-submit auth-action-link">Back to login</a>
      </noscript>
    </section>
  </main>

<?php render_auth_footer(); ?>
  <script>
    (() => {
      const query = new URLSearchParams(window.location.search);
      const fragment = new URLSearchParams(window.location.hash.replace(/^#/, ""));
      const loginTarget = (query.get("login") || fragment.get("login") || "").toLowerCase();
      const loginUrl = loginTarget === "admin"
        ? <?= auth_json_url("/auth/admin_login.php") ?>
        : <?= auth_json_url("/auth/log_in.php") ?>;
      const getValue = (name) => fragment.get(name) || query.get(name) || "";
      const error = getValue("error") || getValue("error_code") || getValue("error_description");
      const type = getValue("type");
      const hasConfirmationResult = Boolean(
        getValue("access_token") || getValue("code") || type === "signup" || type === "email"
      );

      // Remove Supabase tokens and error details from browser history before navigating.
      window.history.replaceState({}, document.title, window.location.pathname);

      if (error || !hasConfirmationResult) {
        window.location.replace(loginUrl + "?verification=invalid");
        return;
      }

      window.location.replace(loginUrl + "?verification=success");
    })();
  </script>
</body>
</html>
