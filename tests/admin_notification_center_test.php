<?php

require_once __DIR__ . "/../pages/admin/_includes/admin_notification_center.php";

$failures = [];

function admin_notification_test_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$gcashPrintOrder = [
    "type" => "admin_new_order_payment_review",
    "message" => "Queue P20260619-0002: New print order submitted. GCash payment: Review the order and update its status.",
    "created_at" => (new DateTimeImmutable("now", new DateTimeZone("Asia/Manila")))->format(DateTimeInterface::ATOM),
    "queue_category" => "printing",
    "queue_code" => "P20260619-0002",
];

admin_notification_test_assert(
    admin_notification_type_label($gcashPrintOrder) === "New Order: Review Payment",
    "GCash print orders should use the combined new-order payment-review label."
);
admin_notification_test_assert(
    admin_notification_event_category($gcashPrintOrder) === "new-orders",
    "GCash print orders should remain in the New Orders category."
);

$legacyPaymentReview = $gcashPrintOrder;
$legacyPaymentReview["type"] = "admin_payment_review";
admin_notification_test_assert(
    admin_notification_type_label($legacyPaymentReview) === "New Order: Review Payment",
    "Legacy payment-review records should not display the standalone Payment Review label."
);

$standardPrintOrder = $gcashPrintOrder;
$standardPrintOrder["type"] = "admin_new_order";
$standardPrintOrder["message"] = "Queue P20260619-0003: New print order submitted.";
admin_notification_test_assert(
    admin_notification_type_label($standardPrintOrder) === "New Order",
    "Standard print orders should keep the normal New Order label."
);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Admin notification center tests passed.\n";
