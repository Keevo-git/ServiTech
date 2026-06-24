<?php

require_once __DIR__ . "/../config/customer_profile_feedback.php";

$failures = [];

function customer_profile_feedback_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$passwordOnly = servitech_customer_profile_update_feedback(["Password"], false, true, false);
customer_profile_feedback_assert($passwordOnly === [
    "type" => "success",
    "message" => "Password updated successfully.",
], "A successful password-only update must produce password success feedback.");

$profileOnly = servitech_customer_profile_update_feedback(["Full name"], true, false, false);
customer_profile_feedback_assert($profileOnly === [
    "type" => "success",
    "message" => "Full name updated successfully.",
], "A profile-only update must preserve profile success feedback.");

$mixed = servitech_customer_profile_update_feedback(["Full name", "Password"], true, true, false);
customer_profile_feedback_assert($mixed === [
    "type" => "success",
    "message" => "Full name and password updated successfully.",
], "A mixed profile and password update must mention both changes.");

$unchanged = servitech_customer_profile_update_feedback([], false, false, false);
customer_profile_feedback_assert($unchanged === [
    "type" => "info",
    "message" => "No changes were detected.",
], "Only a truly unchanged submission may produce no-change feedback.");

$editProfilePage = file_get_contents(__DIR__ . "/../pages/customer/custo_edit_profile.php") ?: "";
customer_profile_feedback_assert(
    str_contains($editProfilePage, '$passwordUpdated = array_key_exists("password", $authChanges);'),
    "A successful Supabase password update must be recorded in the final result state."
);
customer_profile_feedback_assert(
    !str_contains($editProfilePage, '!$passwordOnlyUpdate &&'),
    "Password submissions must not suppress simultaneous profile field changes."
);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Customer Edit Profile feedback checks passed.\n";
