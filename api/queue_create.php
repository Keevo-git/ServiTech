<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/queue_helpers.php";
require_once __DIR__ . "/service_pricing.php";
require_once __DIR__ . "/queue_state_machine.php";
require_once __DIR__ . "/upload_helpers.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "Not logged in"]);
  exit();
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!is_array($data)) {
  echo json_encode(["ok" => false, "error" => "Invalid JSON"]);
  exit();
}

$category = strtolower(trim((string)($data["category"] ?? "printing")));
$service_label = trim((string)($data["service_label"] ?? ""));
$order_type = strtolower(trim((string)($data["order_type"] ?? "")));
$payment_method = strtolower(trim((string)($data["payment_method"] ?? "")));
$reference_number = trim((string)($data["reference_number"] ?? ""));

if ($service_label === "") {
  echo json_encode(["ok" => false, "error" => "Service label required"]);
  exit();
}

$allowedCategories = ["printing", "online_printorder", "repair", "installation", "walkin", "general"];
if (!in_array($category, $allowedCategories, true)) {
  $category = "printing";
}

if (!in_array($order_type, ["walkin", "online"], true)) {
  $order_type = "";
}

if (!in_array($payment_method, ["cash", "gcash"], true)) {
  $payment_method = "";
}

if ($payment_method === "gcash" && $reference_number === "") {
  echo json_encode(["ok" => false, "error" => "Reference number is required for GCash payments."]);
  exit();
}

if ($payment_method === "gcash" && !preg_match('/^\d{13}$/', $reference_number)) {
  echo json_encode(["ok" => false, "error" => "GCash reference number must be exactly 13 digits."]);
  exit();
}

$prefix = servitech_get_queue_prefix_for_category($category);

// Printing queues must always respect the selected order type:
// Walk-in  => P**** / printing
// Online   => OP**** / online_printorder
if ($service_label === "Document Printing" || $category === "online_printorder") {
  if ($order_type === "") {
    echo json_encode(["ok" => false, "error" => "Order type is required for document printing."]);
    exit();
  }

  $printMeta = servitech_get_print_order_queue_meta($order_type);
  $category = $printMeta["category"];
  $prefix = $printMeta["prefix"];
}

$isOnlineDocumentPrinting = $category === "online_printorder"
  && $order_type === "online"
  && in_array($service_label, ["Document Printing", "Online Print Order"], true);
if ($payment_method !== "" && !$isOnlineDocumentPrinting) {
  echo json_encode(["ok" => false, "error" => "Payment options are only available for Online Document Printing."]);
  exit();
}

$details = [
  "service_label" => $service_label,
  "order_type" => $order_type !== "" ? $order_type : null,
  "paper_size" => $data["paper_size"] ?? null,
  "quantity" => isset($data["quantity"]) ? max(1, (int)$data["quantity"]) : null,
  "color_option" => $data["color_option"] ?? null,
  "payment_method" => $payment_method !== "" ? $payment_method : null,
  "reference_number" => $payment_method === "gcash" ? $reference_number : null,
  "payment_status" => $payment_method === "gcash" ? "Submitted" : ($payment_method === "cash" ? "Pay at Store" : null),
  "package_label" => $data["package_label"] ?? null,
  "lamination_type" => $data["lamination_type"] ?? null,
  "device_type" => $data["device_type"] ?? null,
  "notes" => $data["notes"] ?? null,
  "file_name" => $data["file_name"] ?? null,
  "file_names" => isset($data["file_names"]) && is_array($data["file_names"]) ? $data["file_names"] : null,
  "total_files" => isset($data["total_files"]) ? max(0, (int)$data["total_files"]) : null,
  "total_images" => isset($data["total_images"]) ? max(0, (int)$data["total_images"]) : null,
  "total_pages" => isset($data["total_pages"]) ? max(0, (int)$data["total_pages"]) : null,
  "price_per_page" => isset($data["price_per_page"]) ? max(0, (float)$data["price_per_page"]) : null,
  "estimated_total" => isset($data["estimated_total"]) ? max(0, (float)$data["estimated_total"]) : null,
  "file_analysis" => isset($data["file_analysis"]) && is_array($data["file_analysis"]) ? $data["file_analysis"] : null,
  "uploaded_files" => isset($data["uploaded_files"]) && is_array($data["uploaded_files"]) ? $data["uploaded_files"] : null,
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
  if (!empty($details["uploaded_files"]) && is_array($details["uploaded_files"])) {
    $details = servitech_upload_apply_metadata_to_details(
      $details,
      servitech_upload_resolve_owned_metadata($pdo, $user_id, $details["uploaded_files"])
    );
  }
  $details = servitech_pricing_apply($pdo, $category, $details);
  if ($payment_method !== "" && !isset($details["estimated_total"])) {
    throw new DomainException("Online payment is not available for this service.");
  }

  $queueIdentity = servitech_generate_queue_identity($pdo, $prefix);
  $queue_code = $queueIdentity["queue_code"];
  if (!servitech_queue_code_matches_category($queue_code, $category)) {
    throw new RuntimeException("Queue prefix/category mapping mismatch.");
  }

  $ins = $pdo->prepare("
    INSERT INTO queues (user_id, queue_code, category, lifecycle_stage, queue_cycle_date, daily_sequence, details)
    VALUES (:user_id, :queue_code, :category, 'QUEUE', :queue_cycle_date, :daily_sequence, :details::jsonb)
    RETURNING id
  ");
  $ins->execute([
    ":user_id" => $user_id,
    ":queue_code" => $queue_code,
    ":category" => $category,
    ":queue_cycle_date" => $queueIdentity["queue_cycle_date"],
    ":daily_sequence" => $queueIdentity["daily_sequence"],
    ":details" => json_encode($details, JSON_UNESCAPED_UNICODE),
  ]);
  $queueRow = $ins->fetch(PDO::FETCH_ASSOC);
  $queue_id = (int)($queueRow["id"] ?? 0);
  if ($queue_id <= 0) {
    throw new RuntimeException("Queue was not created.");
  }
  servitech_record_queue_initial_status($pdo, $queue_id, $category);
  servitech_upload_link_to_queue($pdo, $user_id, $queue_id, (array)($details["uploaded_files"] ?? []));

  if ($payment_method !== "") {
    $paymentStmt = $pdo->prepare("
      INSERT INTO payments (queue_id, user_id, amount, payment_method, reference_number, status)
      VALUES (:queue_id, :user_id, :amount, :payment_method, :reference_number, :status)
    ");
    $paymentStmt->execute([
      ":queue_id" => $queue_id,
      ":user_id" => $user_id,
      ":amount" => isset($details["estimated_total"]) ? max(0, (float)$details["estimated_total"]) : 0,
      ":payment_method" => $payment_method,
      ":reference_number" => $payment_method === "gcash" ? $reference_number : null,
      ":status" => "PENDING",
    ]);
  }

  $paymentLabel = $payment_method === "gcash" ? "GCash payment details submitted" : ($payment_method === "cash" ? "Cash payment selected" : "Queue submitted");
  servitech_add_notification($pdo, $user_id, $category, $queue_id, "Queue {$queue_code}: {$paymentLabel}.");
  if ($payment_method === "gcash") {
    servitech_notify_admins($pdo, $category, $queue_id, "Queue {$queue_code}: New GCash payment reference submitted. Review the order and update its status.");
  }

  $pdo->commit();

  echo json_encode(["ok" => true, "queue_code" => $queue_code, "queue_id" => $queue_id]);
  exit();
} catch (DomainException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => $e->getMessage()]);
  exit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("queue_create error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
  exit();
}
