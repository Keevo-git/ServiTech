<?php

require_once __DIR__ . "/../pages/admin/customer_list/_auth_backed_customer_scope.php";

$failures = [];

function customer_auth_scope_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function customer_auth_scope_source(string $path): string
{
    return file_get_contents(__DIR__ . "/../" . $path) ?: "";
}

$scope = admin_auth_backed_customer_scope_sql();
customer_auth_scope_assert(
    str_contains($scope, "INNER JOIN auth.users auth_account")
        && str_contains($scope, "auth_account.id = users.auth_user_id"),
    "Customer rows must be joined to an existing Supabase Auth account by auth_user_id."
);
customer_auth_scope_assert(
    str_contains($scope, "users.auth_user_id IS NOT NULL")
        && str_contains($scope, "auth_account.deleted_at IS NULL"),
    "Unlinked and deleted Auth accounts must be excluded."
);
customer_auth_scope_assert(
    str_contains($scope, "auth_account.email_confirmed_at IS NOT NULL")
        && str_contains($scope, "users.email_verified_at IS NOT NULL"),
    "The existing confirmed-account activation rule must remain intact."
);
customer_auth_scope_assert(
    !str_contains(strtolower($scope), "provider"),
    "The Auth scope must remain provider-neutral for password and Google users."
);

$listSource = customer_auth_scope_source("pages/admin/customer_list/custoL.php");
$detailsSource = customer_auth_scope_source("pages/admin/customer_list/customer_details.php");
$messageSource = customer_auth_scope_source("pages/admin/customer_list/send_customer_message.php");

foreach ([
    "Customer List" => $listSource,
    "customer details" => $detailsSource,
    "customer messaging" => $messageSource,
] as $surface => $source) {
    customer_auth_scope_assert(
        str_contains($source, "admin_auth_backed_customer_scope_sql()")
            && str_contains($source, "admin_auth_backed_customer_connection()"),
        "The {$surface} query must use the canonical Auth-backed customer scope."
    );
    customer_auth_scope_assert(
        !str_contains($source, "auth_user_id IS NULL OR email_verified_at IS NOT NULL"),
        "The {$surface} query must not retain the orphan-permitting legacy condition."
    );
}

customer_auth_scope_assert(
    str_contains($listSource, "ORDER BY users.id ASC")
        && str_contains($listSource, "searchInput?.addEventListener('input'")
        && str_contains($listSource, "data-details-url"),
    "Customer List ordering, search, and detail-row actions must remain wired."
);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Admin Auth-backed customer scope checks passed.\n";
