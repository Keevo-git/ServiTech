<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

function rush_id_draft_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit();
}

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  rush_id_draft_json(["ok" => false, "error" => "Not logged in"], 401);
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  rush_id_draft_json(["ok" => false, "error" => "Method not allowed"], 405);
}

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
  rush_id_draft_json(["ok" => false, "error" => "Invalid JSON payload."], 422);
}

$payment_method = strtolower(trim((string)($data["payment_method"] ?? "")));
$quantity = max(0, (int)($data["quantity"] ?? 0));
$package_label = trim((string)($data["package_label"] ?? ""));
$uploaded_files = isset($data["uploaded_files"]) && is_array($data["uploaded_files"]) ? $data["uploaded_files"] : [];

$errors = [];
if ($payment_method !== "gcash") {
  $errors[] = "Rush ID payment confirmation is only required for GCash.";
}
if ($quantity < 1) {
  $errors[] = "Quantity must be at least 1.";
}
if ($package_label === "") {
  $errors[] = "Select a Rush ID package.";
}
if (empty($uploaded_files)) {
  $errors[] = "Upload at least one file before continuing.";
}

if ($errors) {
  rush_id_draft_json(["ok" => false, "error" => implode(" ", $errors)], 422);
}

$_SESSION["rush_id_draft"] = [
  "service_label" => trim((string)($data["service_label"] ?? "Rush ID")),
  "category" => "printing",
  "quantity" => $quantity,
  "payment_method" => "gcash",
  "package_label" => $package_label,
  "notes" => trim((string)($data["notes"] ?? "")),
  "file_name" => trim((string)($data["file_name"] ?? "")),
  "file_names" => isset($data["file_names"]) && is_array($data["file_names"]) ? array_values($data["file_names"]) : [],
  "total_files" => isset($data["total_files"]) ? max(0, (int)$data["total_files"]) : 0,
  "total_images" => isset($data["total_images"]) ? max(0, (int)$data["total_images"]) : 0,
  "total_pages" => isset($data["total_pages"]) ? max(0, (int)$data["total_pages"]) : 0,
  "price_per_page" => isset($data["price_per_page"]) ? max(0, (float)$data["price_per_page"]) : 0,
  "estimated_total" => isset($data["estimated_total"]) ? max(0, (float)$data["estimated_total"]) : 0,
  "file_analysis" => isset($data["file_analysis"]) && is_array($data["file_analysis"]) ? $data["file_analysis"] : [],
  "uploaded_files" => $uploaded_files,
  "created_at" => date(DATE_ATOM),
];

unset($_SESSION["rush_id_confirmation"], $_SESSION["rush_id_flash_error"], $_SESSION["rush_id_form"]);

rush_id_draft_json(["ok" => true]);
