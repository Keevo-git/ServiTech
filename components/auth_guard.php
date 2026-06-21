<?php
// Central auth gate + session settings
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/join_queue_flow.php";

if (!servitech_is_logged_in()) {
    header("Location: " . servitech_url("/auth/log_in.php"));
    exit();
}

if (servitech_is_admin()) {
    header("Location: " . servitech_url("/pages/admin/admin_dashboard.php"));
    exit();
}

$servicePaymentDraft = servitech_service_payment_draft();
$requestPath = str_replace("\\", "/", (string)(parse_url((string)($_SERVER["REQUEST_URI"] ?? ""), PHP_URL_PATH) ?? ""));
$isServicePaymentPage = str_ends_with($requestPath, "/pages/customer/custo_service_payment.php");
if (is_array($servicePaymentDraft) && !$isServicePaymentPage) {
    header("Location: " . servitech_service_payment_draft_url($servicePaymentDraft, true));
    exit();
}
