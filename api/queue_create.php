<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/queue_helpers.php";
require_once __DIR__ . "/service_pricing.php";
require_once __DIR__ . "/queue_state_machine.php";
require_once __DIR__ . "/upload_helpers.php";
require_once __DIR__ . "/../config/join_queue_flow.php";
require_once __DIR__ . "/../config/store_availability.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "Not logged in"]);
  exit();
}
if (!servitech_is_customer()) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Customer access required"]);
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
$payment_method = strtolower(trim((string)($data["payment_method"] ?? "")));
$reference_number = trim((string)($data["reference_number"] ?? ""));

if ($service_label === "") {
  echo json_encode(["ok" => false, "error" => "Service label required"]);
  exit();
}
$normalizedServiceLabel = strtolower((string)preg_replace('/\s+/', ' ', $service_label));
$isRushIdQueue = $normalizedServiceLabel === "rush id";

$allowedCategories = ["printing", "online_printorder", "repair", "installation", "walkin", "general"];
if (!in_array($category, $allowedCategories, true)) {
  $category = "printing";
}

if (!in_array($payment_method, ["cash", "gcash"], true)) {
  $payment_method = "";
}

if ($payment_method === "gcash" && $reference_number !== "" && !preg_match('/^\d{13}$/', $reference_number)) {
  echo json_encode(["ok" => false, "error" => "GCash reference number must be exactly 13 digits."]);
  exit();
}

$prefix = servitech_get_queue_prefix_for_category($category);

// Document Print always uses the unified print queue. Keep the stored label compatible with existing records.
$normalizedServiceLabel = strtolower(trim((string)preg_replace('/\s+/', ' ', $service_label)));
if (in_array($normalizedServiceLabel, ["document printing", "document print", "online document printing", "online document print"], true) || $category === "online_printorder") {
  $category = "printing";
  $prefix = "P";
  $service_label = "Document Printing";
}

$isDocumentPrinting = $category === "printing"
  && $service_label === "Document Printing";
$serviceKind = servitech_pricing_service_kind($category, $service_label);
$supportsFileUploads = in_array($serviceKind, ["document_printing", "rush_id"], true);
if (in_array($serviceKind, ["document_printing", "rush_id", "laminating", "xerox"], true) && $payment_method === "") {
  echo json_encode(["ok" => false, "error" => "Select a payment method."]);
  exit();
}
if ($payment_method !== "" && $category !== "printing") {
  echo json_encode(["ok" => false, "error" => "Payment options are only available for print services."]);
  exit();
}

$details = [
  "service_label" => $service_label,
  "paper_size" => $data["paper_size"] ?? null,
  "quantity" => isset($data["quantity"]) ? max(1, (int)$data["quantity"]) : null,
  "color_option" => $data["color_option"] ?? null,
  "payment_method" => $payment_method !== "" ? $payment_method : null,
  "reference_number" => $payment_method === "gcash" ? $reference_number : null,
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
  "catalog_service_id" => isset($data["catalog_service_id"]) ? max(0, (int)$data["catalog_service_id"]) : null,
  "catalog_pricing_rule_id" => isset($data["catalog_pricing_rule_id"]) ? max(0, (int)$data["catalog_pricing_rule_id"]) : null,
  "catalog_addon_rule_ids" => isset($data["catalog_addon_rule_ids"]) && is_array($data["catalog_addon_rule_ids"])
    ? array_values($data["catalog_addon_rule_ids"])
    : [],
  "service_option_key" => isset($data["service_option_key"]) ? trim((string)$data["service_option_key"]) : null,
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
  servitech_store_assert_queue_available($pdo, $category, $service_label);
  $pdo->beginTransaction();
  if (!$supportsFileUploads && !empty($details["uploaded_files"])) {
    throw new DomainException("This service does not support file attachments.");
  }
  if ($isRushIdQueue && empty($details["uploaded_files"])) {
    throw new DomainException("Upload at least one JPG, JPEG, or PNG photo for Rush ID.");
  }

  if (!empty($details["uploaded_files"]) && is_array($details["uploaded_files"])) {
    $resolvedUploadedFiles = servitech_upload_resolve_owned_metadata($pdo, $user_id, $details["uploaded_files"]);
    if ($isRushIdQueue) {
      servitech_upload_assert_rush_id_uploaded_files($resolvedUploadedFiles);
    }
    $details = servitech_upload_apply_metadata_to_details(
      $details,
      $resolvedUploadedFiles
    );
  }
  $details = servitech_pricing_apply($pdo, $category, $details);
  if ($payment_method !== "" && !isset($details["estimated_total"])) {
    throw new DomainException("Payment is not available for this service.");
  }

  $queueIdentity = servitech_generate_queue_identity($pdo, $prefix);
  $queue_code = $queueIdentity["queue_code"];
  if (!servitech_queue_code_matches_category($queue_code, $category)) {
    throw new RuntimeException("Queue prefix/category mapping mismatch.");
  }

  $ins = $pdo->prepare("
    INSERT INTO queues (user_id, queue_code, category, lifecycle_stage, queue_cycle_date, daily_sequence, details, price)
    VALUES (:user_id, :queue_code, :category, 'QUEUE', :queue_cycle_date, :daily_sequence, :details::jsonb, :price)
    RETURNING id
  ");
  $ins->execute([
    ":user_id" => $user_id,
    ":queue_code" => $queue_code,
    ":category" => $category,
    ":queue_cycle_date" => $queueIdentity["queue_cycle_date"],
    ":daily_sequence" => $queueIdentity["daily_sequence"],
    ":details" => json_encode($details, JSON_UNESCAPED_UNICODE),
    ":price" => isset($details["estimated_total"]) ? max(0, (float)$details["estimated_total"]) : null,
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
  }

  $paymentLabel = $payment_method === "gcash" ? "GCash payment details submitted" : ($payment_method === "cash" ? "Cash payment selected" : "Queue submitted");
  servitech_add_notification($pdo, $user_id, $category, $queue_id, "Queue {$queue_code}: {$paymentLabel}.");
  if ($payment_method === "gcash") {
    servitech_notify_admins(
      $pdo,
      "admin_new_order_payment_review",
      $queue_id,
      "Queue {$queue_code}: New request submitted for {$service_label}. GCash payment: Review the order and update its status.",
      "admin_new_order_payment_review:{$queue_id}",
      true
    );
  } else {
    servitech_notify_admins(
      $pdo,
      "admin_new_order",
      $queue_id,
      "Queue {$queue_code}: New request submitted for {$service_label}.",
      "admin_new_order:{$queue_id}",
      true
    );
  }

  $pdo->commit();

  servitech_mark_join_queue_completed($queue_code);
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
