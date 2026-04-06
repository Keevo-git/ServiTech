<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/queue_helpers.php";

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

if ($service_label === "") {
  echo json_encode(["ok" => false, "error" => "Service label required"]);
  exit();
}

$allowedCategories = ["printing", "repair", "installation", "walkin", "general"];
if (!in_array($category, $allowedCategories, true)) {
  $category = "printing";
}

if (!in_array($order_type, ["walkin", "online"], true)) {
  $order_type = "";
}

if (!in_array($payment_method, ["cash", "gcash"], true)) {
  $payment_method = "";
}

$details = [
  "service_label" => $service_label,
  "order_type" => $order_type !== "" ? $order_type : null,
  "paper_size" => $data["paper_size"] ?? null,
  "quantity" => isset($data["quantity"]) ? max(1, (int)$data["quantity"]) : null,
  "color_option" => $data["color_option"] ?? null,
  "payment_method" => $payment_method !== "" ? $payment_method : null,
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

$prefix = "P";
if ($category === "repair") {
  $prefix = "R";
} elseif ($category === "installation") {
  $prefix = "I";
} elseif ($category === "walkin") {
  $prefix = "W";
}

try {
  $pdo->beginTransaction();

  $queue_code = servitech_generate_queue_code($pdo, $prefix);

  $ins = $pdo->prepare("
    INSERT INTO queues (user_id, queue_code, category, details)
    VALUES (:user_id, :queue_code, :category, :details::jsonb)
  ");
  $ins->execute([
    ":user_id" => $user_id,
    ":queue_code" => $queue_code,
    ":category" => $category,
    ":details" => json_encode($details, JSON_UNESCAPED_UNICODE),
  ]);

  $pdo->commit();

  echo json_encode(["ok" => true, "queue_code" => $queue_code]);
  exit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("queue_create error: " . $e->getMessage());
  echo json_encode(["ok" => false, "error" => "DB error"]);
  exit();
}
