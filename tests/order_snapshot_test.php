<?php

require_once __DIR__ . "/../api/service_pricing.php";
require_once __DIR__ . "/../api/queue_payment.php";

function order_snapshot_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$submitted = [
    "service_label" => "Document Printing",
    "paper_size" => "Letter / Short Bond",
    "color_option" => "Black and White",
    "quantity" => 2,
    "price_per_page" => 5.00,
    "estimated_total" => 10.00,
    "payment_method" => "cash",
    "notes" => "Keep margins narrow",
];
$snapshot = servitech_pricing_apply_snapshot(
    $submitted,
    ["id" => 10, "name" => "Document Printing"],
    "501",
    "Letter / Black and White",
    5.00,
    "fixed",
    "Letter / Short Bond / Black and White"
);

$catalogAfterAdminEdit = ["service_name" => "Document Printing", "unit_price" => 7.00, "active" => false];
order_snapshot_assert((float)$snapshot["price_snapshot"] === 5.00, "The submitted unit-price snapshot must remain PHP 5.00 after a catalog price change.");
order_snapshot_assert((float)$snapshot["final_total_snapshot"] === 10.00, "The submitted final-total snapshot must remain PHP 10.00.");
order_snapshot_assert($snapshot["paper_size_snapshot"] === "Letter / Short Bond", "Paper size must be snapshotted.");
order_snapshot_assert($snapshot["color_option_snapshot"] === "Black and White", "Color option must be snapshotted.");
order_snapshot_assert((int)$snapshot["quantity_snapshot"] === 2, "Quantity must be snapshotted.");
order_snapshot_assert($snapshot["payment_method_snapshot"] === "cash", "Payment method must be snapshotted.");
order_snapshot_assert($snapshot["customer_notes_snapshot"] === "Keep margins narrow", "Customer notes must be snapshotted.");
order_snapshot_assert($catalogAfterAdminEdit["unit_price"] === 7.00 && $snapshot["price_snapshot"] === 5.00, "Catalog mutations must not mutate an existing snapshot array.");

$displayedTotal = servitech_queue_payment_price([
    "price" => 10.00,
    "details" => $snapshot,
]);
order_snapshot_assert($displayedTotal === 10.00, "Historical display pricing must use the saved queue total, not a current catalog price.");

$serviceApi = file_get_contents(__DIR__ . "/../pages/super_admin/super_admin_services_api.php") ?: "";
order_snapshot_assert(!preg_match('/\\b(?:UPDATE|INSERT\\s+INTO)\\s+(?:queues|payments)\\b/i', $serviceApi), "Admin Edit Services must not update queue or payment records.");

$finalSubmissionFiles = [
    "queue_create.php",
    "print_order_create.php",
    "queue_update_details.php",
    "service_payment_create.php",
];
foreach ($finalSubmissionFiles as $file) {
    $source = file_get_contents(__DIR__ . "/../api/{$file}") ?: "";
    order_snapshot_assert(
        str_contains($source, "servitech_pricing_apply(") && str_contains($source, ", true)"),
        "{$file} must lock and revalidate current catalog pricing before its final write."
    );
}

$paymentFinalizer = file_get_contents(__DIR__ . "/../api/service_payment_create.php") ?: "";
order_snapshot_assert(
    strpos($paymentFinalizer, "servitech_pricing_apply(") < strpos($paymentFinalizer, "INSERT INTO queues"),
    "Delayed GCash drafts must be repriced before queue insertion."
);

$pricingSource = file_get_contents(__DIR__ . "/../api/service_pricing.php") ?: "";
foreach ([
    "FOR SHARE",
    "servitech_pricing_lock_catalog_rows",
    "service_name_snapshot",
    "paper_size_snapshot",
    "color_option_snapshot",
    "package_snapshot",
    "device_snapshot",
    "service_type_snapshot",
    "installation_type_snapshot",
    "price_snapshot",
    "price_type_snapshot",
    "final_total_snapshot",
] as $required) {
    order_snapshot_assert(str_contains($pricingSource, $required), "Submission snapshots/locking must include {$required}.");
}

foreach ([
    "../api/queue_list.php",
    "../pages/admin/queue_list/_queue_ui_helpers.php",
    "../pages/admin/order_management/_order_modal_helpers.php",
    "../pages/customer/get_latest_queues.php",
    "../pages/customer/custo_queue_monitor.php",
    "../pages/customer/custo_service_payment.php",
] as $displayFile) {
    $source = file_get_contents(__DIR__ . "/{$displayFile}") ?: "";
    order_snapshot_assert(
        str_contains($source, "service_name_snapshot"),
        basename($displayFile) . " must prefer saved submission snapshots for historical display."
    );
}
foreach ([
    "../api/queue_list.php",
    "../pages/admin/queue_list/_queue_ui_helpers.php",
    "../pages/admin/order_management/_order_modal_helpers.php",
] as $priceDisplayFile) {
    $source = file_get_contents(__DIR__ . "/{$priceDisplayFile}") ?: "";
    order_snapshot_assert(str_contains($source, "price_snapshot"), basename($priceDisplayFile) . " must display the saved price snapshot.");
}
order_snapshot_assert(
    str_contains(file_get_contents(__DIR__ . "/../pages/customer/custo_service_payment.php") ?: "", "final_total_snapshot"),
    "Customer payment review must display the saved final-total snapshot."
);

echo "Order snapshot protection tests passed.\n";
