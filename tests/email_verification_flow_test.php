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

$register = verification_source("auth/register.php");
$login = verification_source("auth/login.php");
$loginPage = verification_source("auth/log_in.php");
$resend = verification_source("auth/resend_verification.php");
$google = verification_source("auth/google_login.php");
$reset = verification_source("auth/reset_password.php");
$migration = verification_source("database/migrations/20260625_require_verified_supabase_accounts.sql");

verification_assert(
    str_contains($register, 'registered=verify')
        && str_contains($register, '$_SESSION["verification_email_hint"] = $email')
        && str_contains($register, 'servitech_supabase_sign_up($email, $password_raw, [')
        && !str_contains($register, 'servitech_account_public_url("/auth/log_in.php?verification=success")')
        && !str_contains($register, 'servitech_supabase_complete_login($privilegedPdo, $authResponse)'),
    "Case A: password signup must stop at the verification notice instead of completing login."
);
verification_assert(
    str_contains($login, 'servitech_supabase_complete_login($privilegedPdo, $authResponse, "password")')
        && str_contains($login, 'servitech_login_failure_redirect("verify_email", $rememberMe)'),
    "Case B/C: password login must enforce confirmation and expose the verification state."
);
verification_assert(
    str_contains($loginPage, 'id="resendVerificationPrompt"')
        && str_contains($loginPage, "Resend verification")
        && str_contains($loginPage, "Confirm your email before logging in"),
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
    str_contains($resend, "servitech_supabase_resend_signup(\$submittedEmail)")
        && !str_contains($resend, 'servitech_account_public_url("/auth/log_in.php?verification=success")'),
    "Case D: Supabase resend must be wired to the active resend page."
);

$loginCss = verification_source("assets/css/style.css");
verification_assert(
    str_contains($loginCss, ".auth-verification-resend")
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
