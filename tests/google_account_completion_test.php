<?php

require_once __DIR__ . "/../config/google_account_completion.php";

$failures = [];

function google_completion_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$localMissing = servitech_google_account_status_from_profile([
    "google_id" => "google-123",
    "contact" => "",
    "password_hash" => "",
    "local_password_set_at" => "",
], false);
google_completion_assert($localMissing["required"], "A local Google account missing password and contact must be gated.");
google_completion_assert($localMissing["missing_password"], "The missing local password must be detected.");
google_completion_assert($localMissing["missing_contact"], "The missing contact must be detected.");

$localComplete = servitech_google_account_status_from_profile([
    "google_id" => "google-123",
    "contact" => "+639123456789",
    "password_hash" => password_hash("valid-password", PASSWORD_DEFAULT),
    "local_password_set_at" => "",
], false);
google_completion_assert(!$localComplete["required"], "A completed local Google account must not be prompted again.");

$supabaseMissing = servitech_google_account_status_from_profile([
    "google_id" => "google-456",
    "contact" => "+639123456789",
    "password_hash" => "",
    "local_password_set_at" => "",
], true);
google_completion_assert($supabaseMissing["required"], "A Supabase Google account without a password marker must be gated.");
google_completion_assert(!$supabaseMissing["missing_contact"], "An existing contact must not be requested again.");

$supabaseComplete = servitech_google_account_status_from_profile([
    "google_id" => "google-456",
    "contact" => "+639123456789",
    "password_hash" => "",
    "local_password_set_at" => "2026-06-23T10:00:00+08:00",
], true);
google_completion_assert(!$supabaseComplete["required"], "A completed Supabase Google account must not be prompted again.");

$regularAccount = servitech_google_account_status_from_profile([
    "google_id" => "",
    "contact" => "",
    "password_hash" => "",
    "local_password_set_at" => "",
], false);
google_completion_assert(!$regularAccount["required"], "Non-Google registration must remain outside this completion flow.");

$existingGoogleSession = servitech_google_account_status_from_profile([
    "google_id" => "",
    "contact" => "",
    "password_hash" => "",
    "local_password_set_at" => "",
], true, true, false);
google_completion_assert(
    $existingGoogleSession["required"],
    "An existing Supabase Google session must be gated even before its legacy profile has google_id backfilled."
);

$linkedEmailIdentity = servitech_google_account_status_from_profile([
    "google_id" => "google-linked",
    "contact" => "+639123456789",
    "password_hash" => "",
    "local_password_set_at" => "",
], true, true, true);
google_completion_assert(
    !$linkedEmailIdentity["required"],
    "A Google-linked Supabase user with an existing email/password identity must not be forced to replace it."
);

google_completion_assert(
    servitech_google_account_normalize_contact("0912 345 6789") === "+639123456789",
    "Philippine 09 contact format should normalize to +63."
);
google_completion_assert(
    servitech_google_account_normalize_contact("+63 912 345 6789") === "+639123456789",
    "Philippine +63 contact format should normalize consistently."
);
google_completion_assert(
    servitech_google_account_normalize_contact("8123456789") === "",
    "A mobile number not starting with 9 must be rejected."
);

$completionPage = file_get_contents(__DIR__ . "/../pages/customer/complete_google_account.php") ?: "";
google_completion_assert(
    str_contains($completionPage, 'password_hash($password, PASSWORD_DEFAULT)'),
    "Local Google passwords must use PHP password hashing."
);
google_completion_assert(
    str_contains($completionPage, 'servitech_supabase_update_user($accessToken, ["password" => $password])'),
    "Supabase Google passwords must be written to Supabase Auth."
);

$editProfilePage = file_get_contents(__DIR__ . "/../pages/customer/custo_edit_profile.php") ?: "";
google_completion_assert(
    str_contains($editProfilePage, 'servitech_supabase_sign_in($currentEmail, $formData["current_password"])'),
    "Edit Profile must continue to verify a Supabase current password through Supabase Auth."
);
google_completion_assert(
    str_contains($editProfilePage, 'verifyStoredPassword((string)($profile["profile_password"] ?? ""), $formData["current_password"])'),
    "Edit Profile must continue to verify a local current password against the stored hash."
);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Google account completion checks passed.\n";
