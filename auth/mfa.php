<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";

header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

if (!servitech_supabase_auth_enabled() || !servitech_is_logged_in() || !servitech_is_admin()) {
    header("Location: " . servitech_url("/auth/log_in.php"));
    exit();
}

if (servitech_supabase_session_aal() === "aal2") {
    header("Location: " . servitech_url("/pages/admin/admin_dashboard.php"));
    exit();
}

$accessToken = trim((string)($_SESSION["supabase_access_token"] ?? ""));
$message = "";
$qrCode = trim((string)($_SESSION["mfa_enrollment_qr"] ?? ""));
$pendingFactorId = trim((string)($_SESSION["mfa_pending_factor_id"] ?? ""));
$verifiedFactors = [];

function servitech_mfa_safe_qr_data_uri(string $candidate): string
{
    $candidate = trim($candidate);
    $svg = "";
    if (preg_match('#^data:image/svg\+xml(?:;charset=[^;,]+)?;base64,([A-Za-z0-9+/=]+)$#i', $candidate, $matches)) {
        $decoded = base64_decode($matches[1], true);
        $svg = is_string($decoded) ? $decoded : "";
    } elseif (preg_match('#^data:image/svg\+xml(?:;charset=[^;,]+)?;(?:utf-8|utf8),(.+)$#is', $candidate, $matches)) {
        $svg = rawurldecode($matches[1]);
    } elseif (str_starts_with(ltrim($candidate), "<svg")) {
        $svg = $candidate;
    }

    if (
        $svg === ""
        || strlen($svg) > 200000
        || !preg_match('/^\s*<svg\b/i', $svg)
        || preg_match('/<(?:script|foreignObject|iframe|object|embed)\b|\bon[a-z]+\s*=|\b(?:href|src)\s*=|url\s*\(/i', $svg)
    ) {
        throw new RuntimeException("Supabase did not return a safe MFA QR code.");
    }

    return "data:image/svg+xml;base64," . base64_encode($svg);
}

try {
    $authUser = servitech_supabase_get_user($accessToken);
    foreach ((array)($authUser["factors"] ?? []) as $factor) {
        if (!is_array($factor)) {
            continue;
        }
        $factorId = trim((string)($factor["id"] ?? ""));
        if (
            preg_match('/^[0-9a-f-]{36}$/i', $factorId)
            && strtolower(trim((string)($factor["factor_type"] ?? "totp"))) === "totp"
            && strtolower(trim((string)($factor["status"] ?? ""))) === "verified"
        ) {
            $verifiedFactors[$factorId] = $factor;
        }
    }
} catch (Throwable $exception) {
    error_log("MFA factor lookup failed: " . $exception->getMessage());
    $message = "Your MFA settings could not be loaded. Please log in again.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $message === "") {
    servitech_enforce_same_origin(false);
    servitech_enforce_csrf_token(false);
    $action = trim((string)($_POST["action"] ?? ""));

    try {
        if ($action === "enroll") {
            if (!servitech_supabase_admin_mfa_enrollment_allowed() || $verifiedFactors) {
                throw new DomainException("MFA enrollment is not available for this account.");
            }
            $enrollment = servitech_supabase_mfa_enroll_totp($accessToken, "ServiTech Admin");
            $factorId = trim((string)($enrollment["id"] ?? ""));
            $candidateQr = servitech_mfa_safe_qr_data_uri(
                (string)($enrollment["totp"]["qr_code"] ?? "")
            );
            if (!preg_match('/^[0-9a-f-]{36}$/i', $factorId)) {
                throw new RuntimeException("Supabase did not return a valid MFA factor.");
            }
            $_SESSION["mfa_pending_factor_id"] = $factorId;
            $_SESSION["mfa_enrollment_qr"] = $candidateQr;
            $pendingFactorId = $factorId;
            $qrCode = $candidateQr;
        } elseif ($action === "verify") {
            $factorId = trim((string)($_POST["factor_id"] ?? ""));
            $code = trim((string)($_POST["code"] ?? ""));
            $allowedFactor = isset($verifiedFactors[$factorId])
                || ($pendingFactorId !== "" && hash_equals($pendingFactorId, $factorId));
            if (!$allowedFactor || !preg_match('/^\d{6}$/', $code)) {
                throw new DomainException("Enter the current 6-digit authenticator code.");
            }

            $challenge = servitech_supabase_mfa_challenge($accessToken, $factorId);
            $challengeId = trim((string)($challenge["id"] ?? ""));
            if (!preg_match('/^[0-9a-f-]{36}$/i', $challengeId)) {
                throw new RuntimeException("Supabase did not return a valid MFA challenge.");
            }
            $verifiedSession = servitech_supabase_mfa_verify(
                $accessToken,
                $factorId,
                $challengeId,
                $code
            );
            servitech_supabase_store_auth_session($verifiedSession);
            if (servitech_supabase_session_aal() !== "aal2") {
                throw new RuntimeException("The MFA challenge did not produce an AAL2 session.");
            }
            if (!servitech_supabase_rebind_application_profile($pdo, true)) {
                throw new RuntimeException("The ServiTech admin profile could not be rebound.");
            }
            unset($_SESSION["mfa_pending_factor_id"], $_SESSION["mfa_enrollment_qr"]);
            session_regenerate_id(true);
            header("Location: " . servitech_url("/pages/admin/admin_dashboard.php"));
            exit();
        } else {
            throw new DomainException("Invalid MFA action.");
        }
    } catch (DomainException $exception) {
        $message = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log("MFA operation failed: " . $exception->getMessage());
        $message = "MFA could not be completed. Please try again or contact the system operator.";
    }
}

$factorIdForVerification = $pendingFactorId !== ""
    ? $pendingFactorId
    : (string)(array_key_first($verifiedFactors) ?? "");
$csrfToken = servitech_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech Admin Verification</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(servitech_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="auth-page auth-page--login">
  <main class="auth-shell">
    <section class="auth-card auth-card--login" aria-labelledby="mfa-title">
      <div class="auth-card__header">
        <p class="auth-card__eyebrow">Admin security</p>
        <h1 id="mfa-title">Authenticator verification</h1>
        <p class="auth-card__subtitle">Admin access requires a second factor.</p>
      </div>

      <?php if ($message !== ""): ?>
        <div class="form-alert form-alert--error" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?></div>
      <?php endif; ?>

      <?php if ($qrCode !== "" && $pendingFactorId !== ""): ?>
        <p>Scan this one-time setup QR code with your authenticator app, then enter its 6-digit code.</p>
        <p><img src="<?= htmlspecialchars($qrCode, ENT_QUOTES, 'UTF-8') ?>" alt="Authenticator enrollment QR code" width="220" height="220"></p>
      <?php endif; ?>

      <?php if ($factorIdForVerification !== ""): ?>
        <form method="post" action="<?= htmlspecialchars(servitech_url('/auth/mfa.php'), ENT_QUOTES, 'UTF-8') ?>" class="login-form" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="verify">
          <input type="hidden" name="factor_id" value="<?= htmlspecialchars($factorIdForVerification, ENT_QUOTES, 'UTF-8') ?>">
          <label for="mfa-code">6-digit code</label>
          <input id="mfa-code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus>
          <button type="submit" class="btn btn-primary">Verify and continue</button>
        </form>
      <?php elseif (servitech_supabase_admin_mfa_enrollment_allowed()): ?>
        <form method="post" action="<?= htmlspecialchars(servitech_url('/auth/mfa.php'), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="enroll">
          <button type="submit" class="btn btn-primary">Set up authenticator</button>
        </form>
      <?php else: ?>
        <div class="form-alert form-alert--error" role="alert">No verified authenticator is enrolled. Ask the operator to temporarily enable controlled admin MFA enrollment.</div>
      <?php endif; ?>

      <p><a href="<?= htmlspecialchars(servitech_url('/auth/logout.php'), ENT_QUOTES, 'UTF-8') ?>">Log out</a></p>
    </section>
  </main>
</body>
</html>
