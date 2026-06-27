<?php

function admin_order_export_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function admin_order_export_source(string $path): string
{
    return file_get_contents(__DIR__ . "/../" . $path) ?: "";
}

$helper = admin_order_export_source("pages/admin/order_management/_order_modal_helpers.php");
$script = admin_order_export_source("pages/admin/order_management/orderM.js");
$export = admin_order_export_source("pages/admin/order_management/export_report.php");
$css = admin_order_export_source("pages/admin/order_management/orderM.css");

foreach ([
    "data-order-filter-month",
    "data-order-filter-year",
    "data-order-export",
    "Export Report",
    "All months",
    "All years",
] as $toolbarNeedle) {
    admin_order_export_assert(str_contains($helper, $toolbarNeedle), "Order filter toolbar must include {$toolbarNeedle}.");
}

foreach ([
    "monthlyFilterValid",
    "Please select a year for the monthly filter.",
    "buildExportUrl",
    "submitted_date",
    "statuses",
    "No orders found for the selected filters.",
] as $scriptNeedle) {
    admin_order_export_assert(str_contains($script, $scriptNeedle), "Order filter script must include {$scriptNeedle}.");
}

foreach ([
    'require_once __DIR__ . "/../_includes/admin_auth.php"',
    "Content-Type: text/csv",
    "Content-Disposition: attachment",
    "order_report_export",
    "admin_order_soft_delete_column_ready",
    "LOWER(COALESCE(q.queue_code",
    "EXTRACT(MONTH FROM q.created_at AT TIME ZONE 'Asia/Manila')",
    "EXTRACT(YEAR FROM q.created_at AT TIME ZONE 'Asia/Manila')",
    "Order ID",
    "Customer Name",
    "Mode of Payment",
    "Processed By / Updated By",
] as $exportNeedle) {
    admin_order_export_assert(str_contains($export, $exportNeedle), "Export handler must include {$exportNeedle}.");
}

foreach (["password", "auth_user_id", "refresh_token", "access_token"] as $sensitiveNeedle) {
    admin_order_export_assert(!str_contains(strtolower($export), $sensitiveNeedle), "Export handler must not include sensitive {$sensitiveNeedle} fields.");
}

admin_order_export_assert(str_contains($css, ".order-filter-export"), "Export button must have ServiTech styling.");
admin_order_export_assert(str_contains($css, "@media (max-width: 560px)"), "Filter/export controls must keep mobile responsive styling.");

echo "Admin order export report checks passed.\n";
