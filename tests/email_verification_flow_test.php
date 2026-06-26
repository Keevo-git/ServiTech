<?php

require_once __DIR__ . "/../config/supabase_auth.php";

$failures = [];

function verification_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function verification_source(string $path): string
{
    return file_get_contents(__DIR__ . "/../" . $path) ?: "";
}

$unverifiedEmailUser = [
    "id" => "11111111-1111-1111-1111-111111111111",
    "email_confirmed_at" => null,
    "app_metadata" => ["provider" => "email", "providers" => ["email"]],
    "identities" => [["provider" => "email"]],
];
verification_assert(
    !servitech_supabase_user_is_usable($unverifiedEmailUser, "password"),
    "Case B: an unverified password identity must not be usable."
);

$verifiedEmailUser = $unverifiedEmailUser;
$verifiedEmailUser["email_confirmed_at"] = "2026-06-25T04:00:00Z";
verification_assert(
    servitech_supabase_user_is_usable($verifiedEmailUser, "password"),
    "Case C: a confirmed password identity must be usable."
);

$verifiedGoogleUser = [
    "id" => "22222222-2222-2222-2222-222222222222",
    "email_confirmed_at" => "2026-06-25T04:00:00Z",
    "app_metadata" => ["provider" => "google", "providers" => ["google"]],
    "identities" => [["provider" => "google"]],
];
verification_assert(
    servitech_supabase_user_is_usable($verifiedGoogleUser, "google"),
    "Case E: a confirmed Google identity must remain usable."
);
verification_assert(
    !servitech_supabase_user_is_usable($verifiedEmailUser, "google"),
    "Case E: the Google exception must require an actual Google identity."
);
verification_assert(
    servitech_supabase_error_requires_email_verification("Email not confirmed"),
    "Supabase's unconfirmed-login error must be classified correctly."
);
verification_assert(
    servitech_supabase_error_is_email_delivery_failure("Error sending confirmation email")
        && servitech_supabase_error_is_email_rate_limited("Email rate limit exceeded"),
    "Supabase delivery failures and rate limits must be classified separately from successful signup."
);

$register = verification_source("auth/register.php");
$login = verification_source("auth/login.php");
$passwordLogin = verification_source("auth/_password_login.php");
$loginPage = verification_source("auth/log_in.php");
$pendingPage = verification_source("auth/verification_pending.php");
$resend = verification_source("auth/resend_verification.php");
$callback = verification_source("auth/verification_callback.php");
$google = verification_source("auth/google_login.php");
$reset = verification_source("auth/reset_password.php");
$migration = verification_source("database/migrations/20260625_require_verified_supabase_accounts.sql");

verification_assert(
    str_contains($register, '/auth/verification_pending.php')
        && str_contains($register, '$_SESSION["verification_email_hint"] = $email')
        && str_contains($register, '$_SESSION["verification_registration_state"] = "sent"')
        && str_contains($register, 'servitech_supabase_sign_up(')
        && str_contains($register, 'servitech_supabase_confirmation_redirect_url()')
        && !str_contains($register, 'servitech_account_public_url("/auth/log_in.php?verification=success")')
        && !str_contains($register, 'servitech_supabase_complete_login($privilegedPdo, $authResponse)'),
    "Case A: password signup must stop at the verification notice instead of completing login."
);
verification_assert(
    str_contains($pendingPage, "Check your email to verify your account")
        && str_contains($pendingPage, "Check Spam, Junk, or Promotions")
        && str_contains($pendingPage, "Open the verification link")
        && str_contains($pendingPage, "Back to login")
        && str_contains($pendingPage, "Use a different email address")
        && str_contains($pendingPage, "Resend verification email")
        && str_contains($pendingPage, 'action="<?= auth_url("/auth/resend_verification.php") ?>"')
        && str_contains($pendingPage, "form-alert--warning"),
    "Cases B/C/E: the pending page must provide clear instructions, delivery guidance, and a working resend action."
);
verification_assert(
    str_contains($register, '$_SESSION["verification_registration_state"] = "signup_delivery_failed"')
        && str_contains($register, "servitech_supabase_error_is_email_delivery_failure")
        && str_contains($pendingPage, "No account was created")
        && str_contains($pendingPage, "Back to registration")
        && !str_contains($pendingPage, "email delivery needs another attempt"),
    "A rejected delivery must be shown as a specific actionable issue, never as a normal sent state."
);
verification_assert(
    str_contains($login, 'servitech_handle_password_login("customer")')
        && str_contains($passwordLogin, 'servitech_supabase_complete_login($privilegedPdo, $authResponse, "password")')
        && str_contains($passwordLogin, 'servitech_login_failure_redirect($config, "verify_email", $rememberMe)'),
    "Case B/C: password login must enforce confirmation and expose the verification state."
);
verification_assert(
    str_contains($loginPage, 'id="resendVerificationPrompt"')
        && str_contains($loginPage, "Resend verification")
        && str_contains($loginPage, "Verify your email address before logging in"),
    "Registration/login feedback must clearly explain verification and offer resend."
);
$loginButtonPosition = strpos($loginPage, 'id="loginSubmit"');
$resendPromptPosition = strpos($loginPage, 'id="resendVerificationPrompt"');
$dividerPosition = strpos($loginPage, 'class="auth-divider"');
verification_assert(
    $loginButtonPosition !== false
        && $resendPromptPosition !== false
        && $dividerPosition !== false
        && $loginButtonPosition < $resendPromptPosition
        && $resendPromptPosition < $dividerPosition,
    "Cases D/E: resend verification must sit below Login and before the social-auth divider."
);
verification_assert(
    str_contains($resend, "servitech_supabase_resend_signup(")
        && str_contains($resend, "\$submittedEmail,")
        && str_contains($resend, "servitech_supabase_confirmation_redirect_url()")
        && str_contains($resend, '$_SESSION["verification_registration_state"] = "resent"')
        && str_contains($resend, '$_SESSION["verification_registration_state"] = "resend_failed"')
        && !str_contains($resend, 'servitech_account_public_url("/auth/log_in.php?verification=success")'),
    "Case D: Supabase resend must be wired to the active resend page."
);
verification_assert(
    str_contains($callback, 'window.location.replace(loginUrl + "?verification=success")')
        && str_contains($callback, 'window.location.replace(loginUrl + "?verification=invalid")')
        && str_contains($callback, "window.history.replaceState")
        && str_contains($callback, "Cache-Control: no-store"),
    "Case D: the Supabase confirmation callback must clear token-bearing history and report success or failure cleanly."
);

$loginCss = verification_source("assets/css/style.css");
verification_assert(
    str_contains($loginCss, ".auth-verification-resend")
        && str_contains($loginCss, ".auth-verification-steps")
        && str_contains($loginCss, ".auth-verification-help")
        && str_contains($loginCss, "@media (max-width: 480px)"),
    "Cases D/E: the verification helper must have intentional desktop and mobile styling."
);
verification_assert(
    str_contains($google, 'servitech_supabase_complete_login($privilegedPdo, $authResponse, "google")'),
    "Case E: Google must use its separate trusted-provider path."
);
verification_assert(
    str_contains($reset, "servitech_supabase_update_user"),
    "Password recovery must remain connected to Supabase Auth."
);
verification_assert(
    str_contains($migration, "AND u.email_verified_at IS NOT NULL")
        && str_contains($migration, "email_verified_at, consent_accepted_at")
        && str_contains($migration, "profile creation deferred")
        && str_contains($migration, "NULL,"),
    "Pending profiles and RLS ownership must remain inactive before verification."
);

$supabaseAuth = verification_source("config/supabase_auth.php");
verification_assert(
    str_contains($supabaseAuth, "function servitech_supabase_ensure_application_profile")
        && str_contains($supabaseAuth, "servitech_supabase_ensure_application_profile(\$pdo, \$authUser)"),
    "A verified first login must repair a profile that the signup trigger could not create."
);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Email verification flow checks passed.\n";
