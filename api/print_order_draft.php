<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/queue_helpers.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

function print_order_draft_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit();
}

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  print_order_draft_json(["ok" => false, "error" => "Not logged in"], 401);
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  print_order_draft_json(["ok" => false, "error" => "Method not allowed"], 405);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!is_array($data)) {
  print_order_draft_json(["ok" => false, "error" => "Invalid JSON payload."], 422);
}

$order_type = strtolower(trim((string)($data["order_type"] ?? "")));
$paper_size = trim((string)($data["paper_size"] ?? ""));
$quantity = max(0, (int)($data["quantity"] ?? 0));
$color_option = trim((string)($data["color_option"] ?? ""));
$payment_method = strtolower(trim((string)($data["payment_method"] ?? "")));
$uploaded_files = isset($data["uploaded_files"]) && is_array($data["uploaded_files"]) ? $data["uploaded_files"] : [];

$errors = [];
if ($order_type !== "online") {
  $errors[] = "Invalid order type.";
}
if ($paper_size === "") {
  $errors[] = "Paper size is required.";
}
if ($quantity < 1) {
  $errors[] = "Quantity must be at least 1.";
}
if ($color_option === "") {
  $errors[] = "Color option is required.";
}
if (!in_array($payment_method, ["cash", "gcash"], true)) {
  $errors[] = "Select a valid payment method.";
}
if (empty($uploaded_files)) {
  $errors[] = "Upload at least one file before continuing.";
}

if ($errors) {
  print_order_draft_json(["ok" => false, "error" => implode(" ", $errors)], 422);
}

$existingDraft = $_SESSION["print_order_draft"] ?? null;
if (is_array($existingDraft) && !empty($existingDraft["uploaded_files"]) && is_array($existingDraft["uploaded_files"])) {
  servitech_cleanup_uploaded_print_files($existingDraft["uploaded_files"]);
}

$_SESSION["print_order_draft"] = [
  "service_label" => trim((string)($data["service_label"] ?? "Document Printing")),
  "order_type" => "online",
  "paper_size" => $paper_size,
  "quantity" => $quantity,
  "color_option" => $color_option,
  "notes" => trim((string)($data["notes"] ?? "")),
  "file_name" => trim((string)($data["file_name"] ?? "")),
  "file_names" => isset($data["file_names"]) && is_array($data["file_names"]) ? array_values($data["file_names"]) : [],
  "payment_method" => $payment_method,
  "total_files" => isset($data["total_files"]) ? max(0, (int)$data["total_files"]) : 0,
  "total_images" => isset($data["total_images"]) ? max(0, (int)$data["total_images"]) : 0,
  "total_pages" => isset($data["total_pages"]) ? max(0, (int)$data["total_pages"]) : 0,
  "price_per_page" => isset($data["price_per_page"]) ? max(0, (float)$data["price_per_page"]) : 0,
  "estimated_total" => isset($data["estimated_total"]) ? max(0, (float)$data["estimated_total"]) : 0,
  "file_analysis" => isset($data["file_analysis"]) && is_array($data["file_analysis"]) ? $data["file_analysis"] : [],
  "uploaded_files" => $uploaded_files,
  "created_at" => date(DATE_ATOM),
];

unset($_SESSION["print_order_confirmation"], $_SESSION["print_order_flash_error"], $_SESSION["print_order_form"]);

print_order_draft_json(["ok" => true]);
