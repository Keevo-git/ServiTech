<?php

require_once __DIR__ . "/../config/supabase_auth.php";
require_once __DIR__ . "/../config/account.php";

$failures = [];

function reset_flow_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function reset_flow_source(string $path): string
{
    return file_get_contents(__DIR__ . "/../" . $path) ?: "";
}

$forgot = reset_flow_source("auth/forgot_password.php");
$reset = reset_flow_source("auth/reset_password.php");
$login = reset_flow_source("auth/log_in.php");
$supabase = reset_flow_source("config/supabase_auth.php");
$css = reset_flow_source("assets/css/style.css");

$expectedRecoveryUrl = rtrim(servitech_supabase_env("APP_PUBLIC_URL", "https://servitech.store"), "/")
    . "/auth/reset_password.php";
reset_flow_assert(
    servitech_supabase_recovery_redirect_url() === $expectedRecoveryUrl,
    "Case A/B: the recovery email redirect must use APP_PUBLIC_URL and the reset page."
);
reset_flow_assert(
    str_contains($forgot, "servitech_supabase_send_recovery(")
        && str_contains($forgot, "servitech_supabase_recovery_redirect_url()"),
    "Case A: forgot password must send a Supabase recovery email to the dedicated callback."
);
reset_flow_assert(
    str_contains($supabase, 'servitech_supabase_auth_request("verify", "POST"')
        && str_contains($supabase, '"type" => "recovery"')
        && str_contains($supabase, '"token_hash" => $tokenHash'),
    "Case B: token_hash recovery links must be verified by Supabase Auth."
);
reset_flow_assert(
    str_contains($reset, '$_GET["token_hash"]')
        && str_contains($reset, 'window.location.hash')
        && str_contains($reset, 'name="recovery_access_token"')
        && str_contains($reset, 'reset_password_store_supabase_recovery')
        && str_contains($reset, 'window.history.replaceState'),
    "Case B: query-token and fragment-session callbacks must both enter reset mode and clear browser history."
);
reset_flow_assert(
    str_contains($reset, 'id="newPassword"')
        && str_contains($reset, 'id="confirmPassword"')
        && str_contains($reset, "Confirm New Password")
        && str_contains($reset, 'id="resetPasswordSubmit"'),
    "Cases B/C/F: the responsive reset page must show both password fields and a clear submit action."
);
reset_flow_assert(
    str_contains($reset, 'servitech_supabase_update_user($recoveryAccessToken, ["password" => $password])')
        && str_contains($reset, 'servitech_remember_revoke_all_for_user')
        && str_contains($reset, 'reset_password_finish_success'),
    "Cases C/D: a valid submission must update the active auth backend and invalidate local remembered access."
);
reset_flow_assert(
    str_contains($reset, "Passwords do not match.")
        && str_contains($reset, "This reset link is invalid or has expired. Please request a new one.")
        && str_contains($reset, "We could not update your password right now. Please try again.")
        && str_contains($login, "Your password has been reset successfully."),
    "Cases C/E: reset validation, expiry, operational errors, and success need explicit feedback."
);
reset_flow_assert(
    str_contains($reset, 'header("Cache-Control: private, no-store')
        && str_contains($reset, 'header("Referrer-Policy: no-referrer")')
        && str_contains($reset, 'name="robots" content="noindex, nofollow"'),
    "Recovery credentials must not be cached, leaked through referrers, or indexed."
);
reset_flow_assert(
    str_contains($css, ".auth-recovery-status")
        && str_contains($css, ".auth-reset-card .field-hint")
        && str_contains($css, "prefers-reduced-motion")
        && str_contains($css, "@media (max-width: 480px)"),
    "Case F: reset callback feedback must follow the existing responsive and accessible auth theme."
);

reset_flow_assert(
    servitech_password_validation_error("short") !== ""
        && servitech_password_validation_error("new-password-123") === "",
    "Case C: server-side password requirements must remain enforced."
);

// Guard the neighboring auth flows that share these files.
reset_flow_assert(
    str_contains($login, 'action="<?= auth_url("/auth/login.php") ?>"')
        && str_contains($login, "handleGoogleCredential")
        && str_contains($login, 'params.get("verification") === "success"'),
    "Login, Google sign-in, and verification feedback must remain wired."
);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Reset password flow checks passed.\n";
