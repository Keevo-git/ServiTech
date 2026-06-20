<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/queue_helpers.php";
require_once __DIR__ . "/service_pricing.php";
require_once __DIR__ . "/upload_helpers.php";

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
if (!servitech_is_customer()) {
  print_order_draft_json(["ok" => false, "error" => "Customer access required"], 403);
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  print_order_draft_json(["ok" => false, "error" => "Method not allowed"], 405);
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!is_array($data)) {
  print_order_draft_json(["ok" => false, "error" => "Invalid JSON payload."], 422);
}

$paper_size = trim((string)($data["paper_size"] ?? ""));
$quantity = max(0, (int)($data["quantity"] ?? 0));
$color_option = trim((string)($data["color_option"] ?? ""));
$payment_method = strtolower(trim((string)($data["payment_method"] ?? "")));
$uploaded_files = isset($data["uploaded_files"]) && is_array($data["uploaded_files"]) ? $data["uploaded_files"] : [];

$errors = [];
if (!in_array($paper_size, ["Letter", "8.5x13", "Short Bond (8.5 x 11)", "Long Bond (8.5 x 13)", "A4"], true)) {
  $errors[] = "Select a valid paper size.";
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

try {
  $uploaded_files = servitech_upload_resolve_owned_metadata($pdo, $user_id, $uploaded_files);
} catch (DomainException $e) {
  print_order_draft_json(["ok" => false, "error" => $e->getMessage()], 422);
}

$existingDraft = $_SESSION["print_order_draft"] ?? null;
if (is_array($existingDraft) && !empty($existingDraft["uploaded_files"]) && is_array($existingDraft["uploaded_files"])) {
  $incomingSavedPaths = [];
  foreach ($uploaded_files as $file) {
    if (!is_array($file)) {
      continue;
    }

    $uploadToken = trim((string)($file["upload_token"] ?? ""));
    if ($uploadToken !== "") {
      $incomingSavedPaths[$uploadToken] = true;
    }
  }

  $filesToCleanup = [];
  foreach ($existingDraft["uploaded_files"] as $file) {
    if (!is_array($file)) {
      continue;
    }

    $uploadToken = trim((string)($file["upload_token"] ?? ""));
    if ($uploadToken === "" || isset($incomingSavedPaths[$uploadToken])) {
      continue;
    }

    $filesToCleanup[] = $file;
  }

  servitech_upload_delete_owned_orphans($pdo, $user_id, $filesToCleanup);
}

$draft = [
  "service_label" => "Document Printing",
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
$draft = servitech_upload_apply_metadata_to_details($draft, $uploaded_files);

try {
  $draft = servitech_pricing_apply($pdo, "printing", $draft);
} catch (DomainException $e) {
  print_order_draft_json(["ok" => false, "error" => $e->getMessage()], 422);
}

$_SESSION["print_order_draft"] = $draft;

unset($_SESSION["print_order_confirmation"], $_SESSION["print_order_flash_error"], $_SESSION["print_order_form"]);

print_order_draft_json(["ok" => true]);
