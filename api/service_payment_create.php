<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/queue_helpers.php";
require_once __DIR__ . "/service_pricing.php";
require_once __DIR__ . "/queue_state_machine.php";

servitech_enforce_csrf_token(false);

function service_payment_redirect(string $message = ""): void {
  if ($message !== "") {
    $_SESSION["service_payment_flash_error"] = $message;
  }
  header("Location: /pages/customer/custo_service_payment.php");
  exit();
}

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  header("Location: " . servitech_url("/auth/log_in.php"));
  exit();
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  service_payment_redirect();
}

$draft = $_SESSION["service_payment_draft"] ?? null;
if (!is_array($draft) || empty($draft)) {
  service_payment_redirect("Your payment draft has expired. Please start again.");
}

$reference_number = trim((string)($_POST["reference_number"] ?? ""));
$_SESSION["service_payment_form"] = ["reference_number" => $reference_number];

if ($reference_number === "") {
  service_payment_redirect("Reference number is required for GCash payments.");
}
if (!preg_match('/^\d{13}$/', $reference_number)) {
  service_payment_redirect("GCash reference number must be exactly 13 digits.");
}

$service_label = trim((string)($draft["service_label"] ?? "Service"));
$details = [
  "service_label" => $service_label,
  "order_type" => "online",
  "paper_size" => trim((string)($draft["paper_size"] ?? "")),
  "quantity" => max(1, (int)($draft["quantity"] ?? 1)),
  "package_label" => trim((string)($draft["package_label"] ?? "")),
  "lamination_type" => trim((string)($draft["lamination_type"] ?? "")),
  "notes" => trim((string)($draft["notes"] ?? "")),
  "file_name" => trim((string)($draft["file_name"] ?? "")),
  "file_names" => isset($draft["file_names"]) && is_array($draft["file_names"]) ? $draft["file_names"] : [],
  "payment_method" => "gcash",
  "reference_number" => $reference_number,
  "payment_status" => "Pending Verification",
  "total_files" => isset($draft["total_files"]) ? max(0, (int)$draft["total_files"]) : 0,
  "total_images" => isset($draft["total_images"]) ? max(0, (int)$draft["total_images"]) : 0,
  "total_pages" => isset($draft["total_pages"]) ? max(0, (int)$draft["total_pages"]) : 0,
  "price_per_page" => isset($draft["price_per_page"]) ? max(0, (float)$draft["price_per_page"]) : 0,
  "estimated_total" => isset($draft["estimated_total"]) ? max(0, (float)$draft["estimated_total"]) : 0,
  "file_analysis" => isset($draft["file_analysis"]) && is_array($draft["file_analysis"]) ? $draft["file_analysis"] : [],
  "uploaded_files" => isset($draft["uploaded_files"]) && is_array($draft["uploaded_files"]) ? $draft["uploaded_files"] : [],
];

foreach ($details as $key => $value) {
  if ($value === null || (is_string($value) && trim($value) === "")) {
    unset($details[$key]);
  }
}

try {
  $pdo->beginTransaction();
  $details = servitech_pricing_apply($pdo, "printing", $details);

  $queueIdentity = servitech_generate_queue_identity($pdo, "P");
  $queue_code = $queueIdentity["queue_code"];
  $queueStmt = $pdo->prepare("
    INSERT INTO queues (queue_code, user_id, category, lifecycle_stage, queue_cycle_date, daily_sequence, details)
    VALUES (:queue_code, :user_id, 'printing', 'QUEUE', :queue_cycle_date, :daily_sequence, :details::jsonb)
    RETURNING id
  ");
  $queueStmt->execute([
    ":queue_code" => $queue_code,
    ":user_id" => $user_id,
    ":queue_cycle_date" => $queueIdentity["queue_cycle_date"],
    ":daily_sequence" => $queueIdentity["daily_sequence"],
    ":details" => json_encode($details, JSON_UNESCAPED_UNICODE),
  ]);

  $queue_id = (int)($queueStmt->fetchColumn() ?: 0);
  if ($queue_id <= 0) {
    throw new RuntimeException("Queue was not created.");
  }
  servitech_record_queue_initial_status($pdo, $queue_id, "printing");

  $paymentStmt = $pdo->prepare("
    INSERT INTO payments (queue_id, user_id, amount, payment_method, reference_number, status)
    VALUES (:queue_id, :user_id, :amount, 'gcash', :reference_number, 'PENDING')
  ");
  $paymentStmt->execute([
    ":queue_id" => $queue_id,
    ":user_id" => $user_id,
    ":amount" => isset($details["estimated_total"]) ? max(0, (float)$details["estimated_total"]) : 0,
    ":reference_number" => $reference_number,
  ]);

  servitech_add_notification($pdo, $user_id, "printing", $queue_id, "Queue {$queue_code}: GCash payment details submitted for verification.");
  servitech_notify_admins($pdo, "printing", $queue_id, "Queue {$queue_code}: New {$service_label} GCash payment needs checking.");

  $pdo->commit();

  $_SESSION["service_payment_confirmation"] = [
    "queue_code" => $queue_code,
    "created_at" => date(DATE_ATOM),
  ];
  unset($_SESSION["service_payment_draft"], $_SESSION["service_payment_flash_error"], $_SESSION["service_payment_form"]);

  header("Location: /pages/customer/custo_service_payment.php?queue=" . urlencode($queue_code));
  exit();
} catch (DomainException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  service_payment_redirect($e->getMessage());
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("service_payment_create error: " . $e->getMessage());
  service_payment_redirect("Unable to place your order right now. Please try again.");
}
