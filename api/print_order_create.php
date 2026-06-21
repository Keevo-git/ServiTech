<?php
require_once __DIR__ . "/../config/join_queue_flow.php";
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/store_availability.php";
require_once __DIR__ . "/queue_helpers.php";
require_once __DIR__ . "/service_pricing.php";
require_once __DIR__ . "/queue_state_machine.php";
require_once __DIR__ . "/upload_helpers.php";

function print_order_wants_json(): bool {
  $accept = strtolower((string)($_SERVER["HTTP_ACCEPT"] ?? ""));
  $requestedWith = strtolower((string)($_SERVER["HTTP_X_REQUESTED_WITH"] ?? ""));
  return strpos($accept, "application/json") !== false || $requestedWith === "xmlhttprequest";
}

function print_order_json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($payload);
  exit();
}

function print_order_fail(string $message = "", int $status = 422): void {
  if (print_order_wants_json()) {
    print_order_json_response([
      "ok" => false,
      "error" => $message !== "" ? $message : "Unable to place your print order.",
    ], $status);
  }

  if ($message !== "") {
    $_SESSION["print_order_flash_error"] = $message;
  }
  header("Location: /pages/customer/custo_print_order_payment.php");
  exit();
}

servitech_enforce_csrf_token(print_order_wants_json());

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  if (print_order_wants_json()) {
    print_order_json_response(["ok" => false, "error" => "Not logged in"], 401);
  }
  header("Location: " . servitech_url("/auth/log_in.php"));
  exit();
}
if (!servitech_is_customer()) {
  if (print_order_wants_json()) {
    print_order_json_response(["ok" => false, "error" => "Customer access required"], 403);
  }
  http_response_code(403);
  exit("Customer access required.");
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  print_order_fail("Invalid request method.", 405);
}

$draft = $_SESSION["print_order_draft"] ?? null;
if (!is_array($draft) || empty($draft)) {
  print_order_fail("Your print order draft has expired. Please start again.");
}

$paper_size = trim((string)($draft["paper_size"] ?? ""));
$quantity = max(0, (int)($draft["quantity"] ?? 0));
$color_option = trim((string)($draft["color_option"] ?? ""));
$file_name = trim((string)($draft["file_name"] ?? ""));
$payment_method = strtolower(trim((string)($draft["payment_method"] ?? "")));
$reference_number = trim((string)($_POST["reference_number"] ?? ""));
$catalog_pricing_rule_id = isset($draft["catalog_pricing_rule_id"]) ? max(0, (int)$draft["catalog_pricing_rule_id"]) : 0;
if (servitech_store_document_printing_requires_gcash($pdo)) {
  $payment_method = "gcash";
}

$_SESSION["print_order_form"] = [
  "reference_number" => $reference_number,
];

$errors = [];
if ($paper_size === "") {
  $errors[] = "Select a paper size.";
}

$pendingServicePayment = servitech_service_payment_draft();
if (is_array($pendingServicePayment)) {
  if (print_order_wants_json()) {
    print_order_json_response([
      "ok" => false,
      "error" => "Complete or cancel your pending GCash payment before submitting another order.",
      "redirect_url" => servitech_service_payment_draft_url($pendingServicePayment, true),
    ], 409);
  }
  header("Location: " . servitech_service_payment_draft_url($pendingServicePayment, true));
  exit();
}
if ($quantity < 1) {
  $errors[] = "Quantity must be at least 1.";
}
if ($color_option === "") {
  $errors[] = "Color selection is required.";
}
if ($file_name === "") {
  $errors[] = "An uploaded file is required.";
}
if (!in_array($payment_method, ["cash", "gcash"], true)) {
  $errors[] = "Payment method is missing or invalid.";
}
if ($payment_method === "gcash" && $reference_number === "") {
  $errors[] = "Reference number is required for GCash payments.";
}
if ($payment_method === "gcash" && $reference_number !== "" && !preg_match('/^\d{13}$/', $reference_number)) {
  $errors[] = "Please enter a valid 13-digit GCash reference number.";
}

if ($errors) {
  print_order_fail(implode(" ", $errors));
}

$details = [
  "service_label" => "Document Printing",
  "paper_size" => $paper_size,
  "quantity" => $quantity,
  "color_option" => $color_option,
  "notes" => trim((string)($draft["notes"] ?? "")),
  "file_name" => $file_name,
  "file_names" => isset($draft["file_names"]) && is_array($draft["file_names"]) ? $draft["file_names"] : [],
  "payment_method" => $payment_method,
  "reference_number" => $payment_method === "gcash" ? $reference_number : null,
  "total_files" => isset($draft["total_files"]) ? max(0, (int)$draft["total_files"]) : 0,
  "total_images" => isset($draft["total_images"]) ? max(0, (int)$draft["total_images"]) : 0,
  "total_pages" => isset($draft["total_pages"]) ? max(0, (int)$draft["total_pages"]) : 0,
  "file_analysis" => isset($draft["file_analysis"]) && is_array($draft["file_analysis"]) ? $draft["file_analysis"] : [],
  "uploaded_files" => isset($draft["uploaded_files"]) && is_array($draft["uploaded_files"]) ? $draft["uploaded_files"] : [],
  "catalog_service_id" => isset($draft["catalog_service_id"]) ? max(0, (int)$draft["catalog_service_id"]) : null,
  "catalog_pricing_rule_id" => $catalog_pricing_rule_id ?: null,
  "catalog_option_value_ids" => isset($draft["catalog_option_value_ids"]) && is_array($draft["catalog_option_value_ids"])
    ? $draft["catalog_option_value_ids"]
    : null,
];

foreach ($details as $key => $value) {
  if ($value === null) {
    unset($details[$key]);
    continue;
  }
  if (is_string($value) && trim($value) === "") {
    unset($details[$key]);
  }
}

try {
  $pdo->beginTransaction();

  $printMeta = [
    "category" => "printing",
    "prefix" => "P",
  ];
  $details = servitech_upload_apply_metadata_to_details(
    $details,
    servitech_upload_resolve_owned_metadata($pdo, $user_id, (array)($details["uploaded_files"] ?? []))
  );
  $details = servitech_pricing_apply($pdo, $printMeta["category"], $details);
  $queueIdentity = servitech_generate_queue_identity($pdo, $printMeta["prefix"]);
  $queue_code = $queueIdentity["queue_code"];
  if (!servitech_queue_code_matches_category($queue_code, $printMeta["category"])) {
    throw new RuntimeException("Queue prefix/category mapping mismatch.");
  }

  $queueStmt = $pdo->prepare("
    INSERT INTO queues (queue_code, user_id, category, lifecycle_stage, queue_cycle_date, daily_sequence, details, price)
    VALUES (:queue_code, :user_id, :category, 'QUEUE', :queue_cycle_date, :daily_sequence, :details::jsonb, :price)
    RETURNING id
  ");
  $queueStmt->execute([
    ":queue_code" => $queue_code,
    ":user_id" => $user_id,
    ":category" => $printMeta["category"],
    ":queue_cycle_date" => $queueIdentity["queue_cycle_date"],
    ":daily_sequence" => $queueIdentity["daily_sequence"],
    ":details" => json_encode($details, JSON_UNESCAPED_UNICODE),
    ":price" => isset($details["estimated_total"]) ? max(0, (float)$details["estimated_total"]) : null,
  ]);

  $queueRow = $queueStmt->fetch();
  $queue_id = (int)($queueRow["id"] ?? 0);
  if ($queue_id <= 0) {
    throw new RuntimeException("Queue was not created.");
  }
  servitech_record_queue_initial_status($pdo, $queue_id, $printMeta["category"]);
  servitech_upload_link_to_queue($pdo, $user_id, $queue_id, (array)($details["uploaded_files"] ?? []));

  $paymentStmt = $pdo->prepare("
    INSERT INTO payments (queue_id, user_id, amount, payment_method, reference_number)
    VALUES (:queue_id, :user_id, :amount, :payment_method, :reference_number)
  ");
  $paymentStmt->execute([
    ":queue_id" => $queue_id,
    ":user_id" => $user_id,
    ":amount" => isset($details["estimated_total"]) ? max(0, (float)$details["estimated_total"]) : 0,
    ":payment_method" => $payment_method,
    ":reference_number" => $payment_method === "gcash" ? $reference_number : null,
  ]);

  $paymentLabel = $payment_method === "gcash" ? "GCash payment details submitted" : "Cash payment selected";
  servitech_add_notification($pdo, $user_id, $printMeta["category"], $queue_id, "Queue {$queue_code}: {$paymentLabel}.");
  if ($payment_method === "gcash") {
    servitech_notify_admins(
      $pdo,
      "admin_new_order_payment_review",
      $queue_id,
      "Queue {$queue_code}: New print order submitted. GCash payment: Review the order and update its status.",
      "admin_new_order_payment_review:{$queue_id}",
      true
    );
  } else {
    servitech_notify_admins(
      $pdo,
      "admin_new_order",
      $queue_id,
      "Queue {$queue_code}: New print order submitted.",
      "admin_new_order:{$queue_id}",
      true
    );
  }

  $pdo->commit();

  $_SESSION["print_order_confirmation"] = [
    "queue_code" => $queue_code,
    "created_at" => date(DATE_ATOM),
  ];
  servitech_mark_join_queue_completed($queue_code);
  unset($_SESSION["print_order_draft"], $_SESSION["print_order_flash_error"], $_SESSION["print_order_form"]);

  if (print_order_wants_json()) {
    print_order_json_response([
      "ok" => true,
      "queue_code" => $queue_code,
      "queue_id" => $queue_id,
    ]);
  }

  header("Location: /pages/customer/custo_print_order_payment.php?queue=" . urlencode($queue_code));
  exit();
} catch (DomainException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  print_order_fail($e->getMessage());
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("print_order_create error: " . $e->getMessage());
  print_order_fail("Unable to place your print order right now. Please try again.", 500);
}
