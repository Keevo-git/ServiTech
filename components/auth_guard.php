<?php
// Central auth gate + session settings
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/join_queue_flow.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/google_account_completion.php";

if (!servitech_is_logged_in()) {
    header("Location: " . servitech_url("/auth/log_in.php"));
    exit();
}

if (
    servitech_supabase_auth_enabled()
    && !servitech_supabase_rebind_application_profile(
        $pdo,
        false,
        servitech_supabase_profile_rebind_seconds()
    )
) {
    servitech_supabase_clear_auth_session();
    servitech_supabase_clear_application_session();
    header("Location: " . servitech_url("/auth/log_in.php?login=session_expired"));
    exit();
}

header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

if (servitech_is_admin()) {
    header("Location: " . servitech_url(servitech_internal_dashboard_path()));
    exit();
}

$authenticatedCustomerId = (int)($_SESSION["user_id"] ?? 0);
if (servitech_google_account_completion_required($pdo, $authenticatedCustomerId)) {
    header("Location: " . servitech_url(servitech_google_account_completion_path()));
    exit();
}

$servicePaymentDraft = servitech_service_payment_draft();
$requestPath = str_replace("\\", "/", (string)(parse_url((string)($_SERVER["REQUEST_URI"] ?? ""), PHP_URL_PATH) ?? ""));
$isServicePaymentPage = str_ends_with($requestPath, "/pages/customer/custo_service_payment.php");
if (is_array($servicePaymentDraft) && !$isServicePaymentPage) {
    header("Location: " . servitech_service_payment_draft_url($servicePaymentDraft, true));
    exit();
}
