<?php
ini_set("session.save_path", sys_get_temp_dir());
require_once __DIR__ . "/../api/queue_state_machine.php";
require_once __DIR__ . "/../config/join_queue_flow.php";

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
        str_contains($message, "Your GCash payment for Queue T-001 has been approved.")
            && str_contains($message, $service["service_label"]),
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

$pendingReview = [
    "status" => "PENDING",
    "category" => "repair",
    "details" => [
        "service_label" => "Repair Services",
        "payment_method" => "gcash",
        "reference_number" => "0001234567890",
    ],
    "payment_method" => "gcash",
    "reference_number" => "0001234567890",
    "payment_status" => "WAITING FOR ADMIN REVIEW",
];
gcash_flow_assert(
    servitech_queue_allowed_transitions($pendingReview) === ["APPROVED", "CANCELLED"],
    "GCash approval must be available for waiting-for-admin-review payments."
);

$onlinePayment = $pendingReview;
$onlinePayment["payment_method"] = "online_payment";
$onlinePayment["details"]["payment_method"] = "online_payment";
gcash_flow_assert(
    servitech_queue_allowed_transitions($onlinePayment) === ["APPROVED", "CANCELLED"],
    "Online Payment queues must use the same pending-review approval rule."
);

$cash = ["status" => "PENDING", "category" => "repair", "payment_method" => "cash"];
gcash_flow_assert(
    servitech_queue_allowed_transitions($cash) === ["ONGOING", "CANCELLED"],
    "Cash must keep its separate non-review flow."
);

$repairWithoutPayment = ["status" => "PENDING", "category" => "repair", "details" => ["service_label" => "Repair Services"]];
gcash_flow_assert(
    servitech_queue_allowed_transitions($repairWithoutPayment) === ["ONGOING", "CANCELLED"],
    "Repair queues without payment must move through the normal admin review flow."
);

$installationWithoutPayment = ["status" => "PENDING", "category" => "installation", "details" => ["service_label" => "Installation Services"]];
gcash_flow_assert(
    servitech_queue_allowed_transitions($installationWithoutPayment) === ["ONGOING", "CANCELLED"],
    "Installation queues without payment must move through the normal admin review flow."
);

$sessionBeforeDraftTest = $_SESSION;
$_SESSION["user_id"] = 77;
$_SESSION[SERVITECH_SERVICE_PAYMENT_DRAFT_KEY] = [
    "token" => str_repeat("a", 64),
    "user_id" => 77,
    "payment_method" => "gcash",
    "created_at" => time(),
];
$draftForTest = servitech_service_payment_draft();
gcash_flow_assert(is_array($draftForTest), "A valid session-bound GCash draft must remain active.");
gcash_flow_assert(servitech_service_payment_draft_matches(str_repeat("a", 64), $draftForTest), "The payment page token must match its server-side draft.");
gcash_flow_assert(!servitech_service_payment_draft_matches(str_repeat("b", 64), $draftForTest), "A changed payment URL token must not access the draft.");
gcash_flow_assert(str_contains(servitech_service_payment_draft_url($draftForTest), "draft_token="), "The draft redirect must use a payment token.");
$_SESSION = $sessionBeforeDraftTest;

$queueCreate = file_get_contents(__DIR__ . "/../api/queue_create.php");
$mainJs = file_get_contents(__DIR__ . "/../assets/js/main.js");
$documentPrintJs = file_get_contents(__DIR__ . "/../assets/js/custo2_docu_printing.js");
$paymentPage = file_get_contents(__DIR__ . "/../pages/customer/custo_service_payment.php");
$paymentCss = file_get_contents(__DIR__ . "/../assets/css/customer-payment.css");
$paymentSubmit = file_get_contents(__DIR__ . "/../api/service_payment_create.php");
$paymentCancel = file_get_contents(__DIR__ . "/../api/service_payment_cancel.php");
$authGuard = file_get_contents(__DIR__ . "/../components/auth_guard.php");
gcash_flow_assert(str_contains((string)$queueCreate, '"redirect_url"'), "GCash queue responses must provide a payment redirect.");
gcash_flow_assert(str_contains((string)$queueCreate, '"draft" => true'), "GCash join submissions must create a draft instead of a queue.");
gcash_flow_assert(strpos((string)$queueCreate, '"draft" => true') < strpos((string)$queueCreate, '$queueIdentity = servitech_generate_queue_identity'), "GCash must leave queue_create before a queue number is generated.");
gcash_flow_assert(str_contains((string)$mainJs, 'result.payment_method === "gcash"'), "Generic join forms must follow the GCash redirect.");
gcash_flow_assert(str_contains((string)$documentPrintJs, 'window.location.href = gcashResult.redirect_url'), "Document Printing must follow the same GCash payment redirect.");
foreach (["Document", "xerox", "rush", "laminat", "scan"] as $serviceMarker) {
    gcash_flow_assert(stripos((string)$paymentPage, $serviceMarker) !== false, "Payment page is missing {$serviceMarker} service handling.");
}
gcash_flow_assert(str_contains((string)$queueCreate, '$paymentNotRequired = in_array($serviceKind, ["repair", "installation"], true);'), "Repair and Installation must bypass payment selection in queue_create.");
gcash_flow_assert(str_contains((string)$queueCreate, 'unset($details["payment_method"], $details["reference_number"]);'), "Repair and Installation must not save submitted payment fields.");
gcash_flow_assert(str_contains((string)$paymentSubmit, 'in_array($serviceKind, ["repair", "installation"], true)'), "Repair and Installation drafts must not create GCash payments.");
gcash_flow_assert(str_contains((string)$paymentPage, 'name="viewport"'), "Payment page must declare a responsive viewport.");
gcash_flow_assert(str_contains((string)$paymentPage, 'Complete your GCash Payment'), "Payment page must show the customer-friendly payment-step title.");
gcash_flow_assert(str_contains((string)$paymentPage, 'Assigned after payment submission'), "A draft must not pretend that a queue number already exists.");
gcash_flow_assert(str_contains((string)$paymentPage, 'Payment details required'), "An incomplete draft must be clearly labelled as incomplete.");
gcash_flow_assert(str_contains((string)$paymentPage, 'window.openQueueSuccessModal'), "Completed GCash submissions must reuse the existing queue success modal.");
gcash_flow_assert(str_contains((string)$paymentPage, 'Please enter a valid 13-digit GCash reference number.'), "Payment page must show exact 13-digit reference validation.");
gcash_flow_assert(str_contains((string)$paymentPage, 'await fetch(paymentForm.action'), "Payment page must submit payment details without a full-page success redirect.");
gcash_flow_assert(str_contains((string)$paymentPage, '<?php if ($reviewed): ?>'), "Submitted pending-review GCash payments must not render a standalone success card.");
gcash_flow_assert(str_contains((string)$paymentPage, 'formaction="<?= service_payment_esc(servitech_url(\'/api/service_payment_cancel.php\')) ?>"'), "The payment page must provide an explicit safe cancellation path.");
gcash_flow_assert(str_contains((string)$paymentCss, '@media (max-width: 767px)'), "Payment page must provide a mobile layout.");
gcash_flow_assert(str_contains((string)$paymentCss, 'grid-template-columns: repeat(2'), "Payment page must provide a desktop/tablet summary layout.");
gcash_flow_assert(substr_count((string)$queueCreate, "admin_new_order_payment_review") === 0, "A GCash draft must not notify the admin before payment details are submitted.");
gcash_flow_assert(substr_count((string)$paymentSubmit, "admin_new_order_payment_review") === 2, "Completed GCash payment details must create one notification call with one event key.");
gcash_flow_assert(substr_count((string)$paymentSubmit, "servitech_notify_admins(") === 1, "Completed GCash payment details must create exactly one admin notification.");
gcash_flow_assert(strpos((string)$paymentSubmit, "preg_match('/^\\d{13}$/', \$referenceNumber)") < strpos((string)$paymentSubmit, "INSERT INTO queues"), "Exact 13-digit reference validation must happen before queue creation.");
gcash_flow_assert(str_contains((string)$paymentSubmit, "service_payment_json_response"), "Payment submit handler must support same-page JSON success responses.");
gcash_flow_assert(str_contains((string)$authGuard, "servitech_service_payment_draft_url"), "Customer navigation must return incomplete GCash drafts to the payment page.");
gcash_flow_assert(str_contains((string)$paymentCancel, "servitech_upload_delete_owned_orphans"), "Cancelling a payment draft must clean up orphaned uploads.");

echo "GCash payment flow tests passed for " . count($services) . " service types." . PHP_EOL;
