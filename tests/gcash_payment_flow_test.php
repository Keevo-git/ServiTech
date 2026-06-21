<?php
require_once __DIR__ . "/../api/queue_state_machine.php";

function gcash_flow_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$services = [
    ["category" => "printing", "service_label" => "Document Printing"],
    ["category" => "printing", "service_label" => "Photocopy"],
    ["category" => "printing", "service_label" => "Rush ID"],
    ["category" => "printing", "service_label" => "Laminating"],
    ["category" => "printing", "service_label" => "Scanning"],
    ["category" => "repair", "service_label" => "Repair Services"],
    ["category" => "installation", "service_label" => "Installation Services"],
];

foreach ($services as $service) {
    $base = [
        "status" => "PENDING",
        "category" => $service["category"],
        "queue_code" => "T-001",
        "details" => [
            "service_label" => $service["service_label"],
            "payment_method" => "gcash",
            "reference_number" => "1234567890123",
        ],
        "payment_method" => "gcash",
        "reference_number" => "1234567890123",
        "payment_status" => "PENDING",
    ];
    gcash_flow_assert(
        servitech_queue_allowed_transitions($base) === ["APPROVED", "CANCELLED"],
        $service["service_label"] . " must offer GCash approval while pending review."
    );
    $message = servitech_queue_customer_status_message($base, "APPROVED");
    gcash_flow_assert(
        str_contains($message, $service["service_label"]) && str_contains($message, "payment has been approved"),
        $service["service_label"] . " must receive the correct approval notification."
    );

    $base["status"] = "APPROVED";
    $base["payment_status"] = "APPROVED";
    gcash_flow_assert(
        servitech_queue_allowed_transitions($base) === ["ONGOING"],
        $service["service_label"] . " must move from Approved to Ongoing."
    );
}

$missingReference = [
    "status" => "PENDING",
    "category" => "printing",
    "payment_method" => "gcash",
    "payment_status" => "PENDING",
    "reference_number" => "",
];
gcash_flow_assert(
    servitech_queue_allowed_transitions($missingReference) === ["CANCELLED"],
    "GCash approval must stay unavailable until payment details are submitted."
);

$cash = ["status" => "PENDING", "category" => "repair", "payment_method" => "cash"];
gcash_flow_assert(
    servitech_queue_allowed_transitions($cash) === ["ONGOING", "CANCELLED"],
    "Cash must keep its separate non-review flow."
);

$queueCreate = file_get_contents(__DIR__ . "/../api/queue_create.php");
$mainJs = file_get_contents(__DIR__ . "/../assets/js/main.js");
$documentPrintJs = file_get_contents(__DIR__ . "/../assets/js/custo2_docu_printing.js");
$paymentPage = file_get_contents(__DIR__ . "/../pages/customer/custo_service_payment.php");
$paymentCss = file_get_contents(__DIR__ . "/../assets/css/customer-payment.css");
$paymentSubmit = file_get_contents(__DIR__ . "/../api/service_payment_create.php");
gcash_flow_assert(str_contains((string)$queueCreate, '"redirect_url"'), "GCash queue responses must provide a payment redirect.");
gcash_flow_assert(str_contains((string)$mainJs, 'result.payment_method === "gcash"'), "Generic join forms must follow the GCash redirect.");
gcash_flow_assert(str_contains((string)$documentPrintJs, 'window.location.href = gcashResult.redirect_url'), "Document Printing must follow the same GCash payment redirect.");
foreach (["Document", "xerox", "rush", "laminat", "scan", "repair", "installation"] as $serviceMarker) {
    gcash_flow_assert(stripos((string)$paymentPage, $serviceMarker) !== false, "Payment page is missing {$serviceMarker} service handling.");
}
gcash_flow_assert(str_contains((string)$paymentPage, 'name="viewport"'), "Payment page must declare a responsive viewport.");
gcash_flow_assert(str_contains((string)$paymentPage, 'Complete your GCash Payment'), "Payment page must show the customer-friendly payment-step title.");
gcash_flow_assert(str_contains((string)$paymentCss, '@media (max-width: 767px)'), "Payment page must provide a mobile layout.");
gcash_flow_assert(str_contains((string)$paymentCss, 'grid-template-columns: repeat(2'), "Payment page must provide a desktop/tablet summary layout.");
gcash_flow_assert(substr_count((string)$queueCreate, "admin_new_order_payment_review") === 2, "GCash submission should use one notification call and one event key.");
gcash_flow_assert(strpos((string)$paymentSubmit, "servitech_notify_admins") === false, "Payment detail submission must not create a duplicate admin notification.");

echo "GCash payment flow tests passed for " . count($services) . " service types." . PHP_EOL;
