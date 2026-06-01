<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/service_pricing.php";
require_once __DIR__ . "/upload_helpers.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

function service_payment_draft_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit();
}

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  service_payment_draft_json(["ok" => false, "error" => "Not logged in"], 401);
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  service_payment_draft_json(["ok" => false, "error" => "Method not allowed"], 405);
}

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
  service_payment_draft_json(["ok" => false, "error" => "Invalid JSON payload."], 422);
}

$service_label = trim((string)($data["service_label"] ?? ""));
$payment_method = strtolower(trim((string)($data["payment_method"] ?? "")));
$quantity = max(0, (int)($data["quantity"] ?? 0));
$allowed_services = ["Rush ID", "Laminating", "Xerox"];

$errors = [];
if (!in_array($service_label, $allowed_services, true)) {
  $errors[] = "Unsupported payment service.";
}
if ($payment_method !== "gcash") {
  $errors[] = "Payment confirmation is only required for GCash.";
}
if ($quantity < 1) {
  $errors[] = "Quantity must be at least 1.";
}
if ($service_label === "Rush ID" && trim((string)($data["package_label"] ?? "")) === "") {
  $errors[] = "Select a Rush ID package.";
}
if ($service_label === "Laminating" && trim((string)($data["lamination_type"] ?? "")) === "") {
  $errors[] = "Select lamination type.";
}
if ($service_label === "Xerox" && trim((string)($data["paper_size"] ?? "")) === "") {
  $errors[] = "Select paper size.";
}

if ($errors) {
  service_payment_draft_json(["ok" => false, "error" => implode(" ", $errors)], 422);
}

$uploadedFiles = isset($data["uploaded_files"]) && is_array($data["uploaded_files"]) ? $data["uploaded_files"] : [];
try {
  $uploadedFiles = servitech_upload_resolve_owned_metadata($pdo, $user_id, $uploadedFiles);
} catch (DomainException $e) {
  service_payment_draft_json(["ok" => false, "error" => $e->getMessage()], 422);
}

$draft = [
  "service_label" => $service_label,
  "category" => "printing",
  "order_type" => "online",
  "paper_size" => trim((string)($data["paper_size"] ?? "")),
  "quantity" => $quantity,
  "payment_method" => "gcash",
  "package_label" => trim((string)($data["package_label"] ?? "")),
  "lamination_type" => trim((string)($data["lamination_type"] ?? "")),
  "notes" => trim((string)($data["notes"] ?? "")),
  "file_name" => trim((string)($data["file_name"] ?? "")),
  "file_names" => isset($data["file_names"]) && is_array($data["file_names"]) ? array_values($data["file_names"]) : [],
  "total_files" => isset($data["total_files"]) ? max(0, (int)$data["total_files"]) : 0,
  "total_images" => isset($data["total_images"]) ? max(0, (int)$data["total_images"]) : 0,
  "total_pages" => isset($data["total_pages"]) ? max(0, (int)$data["total_pages"]) : 0,
  "price_per_page" => isset($data["price_per_page"]) ? max(0, (float)$data["price_per_page"]) : 0,
  "estimated_total" => isset($data["estimated_total"]) ? max(0, (float)$data["estimated_total"]) : 0,
  "file_analysis" => isset($data["file_analysis"]) && is_array($data["file_analysis"]) ? $data["file_analysis"] : [],
  "uploaded_files" => $uploadedFiles,
  "created_at" => date(DATE_ATOM),
];
$draft = servitech_upload_apply_metadata_to_details($draft, $uploadedFiles);

try {
  $draft = servitech_pricing_apply($pdo, "printing", $draft);
} catch (DomainException $e) {
  service_payment_draft_json(["ok" => false, "error" => $e->getMessage()], 422);
}

$_SESSION["service_payment_draft"] = $draft;

unset($_SESSION["service_payment_confirmation"], $_SESSION["service_payment_flash_error"], $_SESSION["service_payment_form"]);

service_payment_draft_json(["ok" => true]);
