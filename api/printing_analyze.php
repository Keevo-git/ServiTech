<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/upload_helpers.php";
require_once __DIR__ . "/service_catalog.php";

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

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit();
}

function printing_json_exit(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit();
}

function normalize_uploaded_files(?array $uploaded): array {
  if (!$uploaded || !isset($uploaded["name"])) return [];

  if (!is_array($uploaded["name"])) {
    return [[
      "name" => (string)$uploaded["name"],
      "type" => (string)($uploaded["type"] ?? ""),
      "tmp_name" => (string)($uploaded["tmp_name"] ?? ""),
      "error" => (int)($uploaded["error"] ?? UPLOAD_ERR_NO_FILE),
      "size" => (int)($uploaded["size"] ?? 0),
    ]];
  }

  $files = [];
  $count = count($uploaded["name"]);
  for ($i = 0; $i < $count; $i++) {
    $files[] = [
      "name" => (string)($uploaded["name"][$i] ?? ""),
      "type" => (string)($uploaded["type"][$i] ?? ""),
      "tmp_name" => (string)($uploaded["tmp_name"][$i] ?? ""),
      "error" => (int)($uploaded["error"][$i] ?? UPLOAD_ERR_NO_FILE),
      "size" => (int)($uploaded["size"][$i] ?? 0),
    ];
  }

  return $files;
}

function compute_print_pricing(PDO $pdo, int $catalogPricingRuleId, int $quantity, int $totalPages): array {
  if ($catalogPricingRuleId <= 0) {
    return ["ok" => false, "error" => "Select a valid active print option."];
  }

  $service = servitech_catalog_fetch_service_by_kind($pdo, "document_printing", true);
  if (!$service) {
    return ["ok" => false, "error" => "Document Printing is currently unavailable."];
  }

  $catalog = servitech_catalog_fetch($pdo, (int)$service["id"], true);
  $rule = servitech_catalog_find_rule($catalog, $catalogPricingRuleId);
  if (!$rule) {
    return ["ok" => false, "error" => "The selected print option is currently unavailable."];
  }
  if (($rule["price_type"] ?? "") !== "fixed" || !isset($rule["price"]) || !is_numeric($rule["price"])) {
    return ["ok" => false, "error" => "The selected print option is marked for assessment."];
  }

  $pricePerPage = max(0, (float)$rule["price"]);
  if ($pricePerPage <= 0) {
    return ["ok" => false, "error" => "The selected paper/color price is unavailable."];
  }
  $safeQty = max(1, $quantity);
  $safePages = max(0, $totalPages);
  $estimatedTotal = $safePages * $pricePerPage * $safeQty;

  return [
    "ok" => true,
    "price_per_page" => $pricePerPage,
    "estimated_total" => (float)$estimatedTotal,
    "catalog_pricing_rule_id" => $catalogPricingRuleId,
    "paper_size" => servitech_catalog_option_label($rule, "paper_size"),
    "color_option" => servitech_catalog_option_label($rule, "color_option"),
  ];
}

$paper_size = trim((string)($_POST["paper_size"] ?? ""));
$color_option = trim((string)($_POST["color_option"] ?? ""));
$catalog_pricing_rule_id = isset($_POST["catalog_pricing_rule_id"]) ? max(0, (int)$_POST["catalog_pricing_rule_id"]) : 0;
$quantity = max(1, (int)($_POST["quantity"] ?? 1));

$provided_total_pages = isset($_POST["total_pages"]) ? max(0, (int)$_POST["total_pages"]) : null;
$provided_total_files = isset($_POST["total_files"]) ? max(0, (int)$_POST["total_files"]) : 0;
$provided_total_images = isset($_POST["total_images"]) ? max(0, (int)$_POST["total_images"]) : 0;

$uploadedFiles = normalize_uploaded_files($_FILES["files"] ?? null);

if (empty($uploadedFiles) && $provided_total_pages === null) {
  printing_json_exit(["ok" => false, "error" => "No files uploaded."], 422);
}
try {
  servitech_upload_assert_limits($uploadedFiles, "size");
} catch (DomainException $e) {
  printing_json_exit([
    "ok" => false,
    "error_scope" => "file",
    "error" => $e->getMessage(),
  ], 422);
}

$fileResults = [];
$total_files = 0;
$total_images = 0;
$total_pages = 0;
$unsupported = [];
$uploadErrors = [];
$validationErrors = [];

if (!empty($uploadedFiles)) {
  foreach ($uploadedFiles as $file) {
    $name = trim((string)($file["name"] ?? ""));
    $tmp = (string)($file["tmp_name"] ?? "");
    $error = (int)($file["error"] ?? UPLOAD_ERR_NO_FILE);
    $size = (int)($file["size"] ?? 0);

    if ($name === "" || $error === UPLOAD_ERR_NO_FILE) {
      continue;
    }

    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
      $validationErrors[] = "Maximum file size is 25 MB per file.";
      continue;
    }

    if ($error !== UPLOAD_ERR_OK || $tmp === "" || !is_uploaded_file($tmp)) {
      $uploadErrors[] = $name !== "" ? $name : "Unknown file";
      continue;
    }

    if ($size <= 0) {
      $unsupported[] = $name;
      continue;
    }
    if ($size > servitech_upload_max_file_bytes()) {
      $validationErrors[] = "Maximum file size is 25 MB per file.";
      continue;
    }

    try {
      $type = servitech_upload_validate_type($tmp, $name);
    } catch (DomainException $e) {
      $validationErrors[] = $name . " " . $e->getMessage();
      continue;
    } catch (Throwable $e) {
      $unsupported[] = $name;
      continue;
    }

    $ext = $type["extension"];
    $total_files++;

    if ($ext === "pdf") {
      $pages = servitech_document_count_pdf_pages($tmp);
      if ($pages < 1) {
        $validationErrors[] = "Unable to detect the page count for {$name}. Please upload a valid, unlocked PDF.";
        $total_files--;
        continue;
      }
      $fileResults[] = [
        "file_name" => $name,
        "file_type" => "pdf",
        "page_count" => $pages,
      ];
      $total_pages += $pages;
      continue;
    }

    if ($ext === "doc" || $ext === "docx") {
      $pages = $ext === "docx"
        ? servitech_document_count_docx_pages($tmp)
        : servitech_document_count_doc_pages($tmp);
      if ($pages < 1) {
        $validationErrors[] = "Unable to render and count pages for {$name}. Please upload a valid, unlocked DOC/DOCX file or convert it to PDF.";
        $total_files--;
        continue;
      }
      $fileResults[] = [
        "file_name" => $name,
        "file_type" => $ext,
        "page_count" => $pages,
      ];
      $total_pages += $pages;
      continue;
    }

    if ($ext === "ppt" || $ext === "pptx") {
      $slides = $ext === "pptx"
        ? servitech_document_count_pptx_slides($tmp)
        : servitech_document_count_ppt_slides($tmp);
      if ($slides < 1) {
        $validationErrors[] = "Unable to detect the slide count for {$name}. Please upload a valid, unlocked presentation.";
        $total_files--;
        continue;
      }
      $fileResults[] = [
        "file_name" => $name,
        "file_type" => $ext,
        "slide_count" => $slides,
      ];
      $total_pages += $slides;
      continue;
    }

    if ($ext === "jpg" || $ext === "jpeg" || $ext === "png") {
      $fileResults[] = [
        "file_name" => $name,
        "file_type" => $ext,
        "page_count" => 1,
      ];
      $total_images++;
      $total_pages++;
      continue;
    }

    $unsupported[] = $name;
  }
}

if (!empty($uploadErrors)) {
  printing_json_exit([
    "ok" => false,
    "error_scope" => "file",
    "error" => "Some files failed to upload properly.",
    "upload_errors" => $uploadErrors,
  ], 422);
}

if (!empty($validationErrors)) {
  printing_json_exit([
    "ok" => false,
    "error_scope" => "file",
    "error" => implode(" ", $validationErrors),
    "validation_errors" => $validationErrors,
  ], 422);
}

if (!empty($unsupported)) {
  printing_json_exit([
    "ok" => false,
    "error_scope" => "file",
    "error" => "Unsupported file type detected.",
    "unsupported_files" => $unsupported,
  ], 422);
}

if (empty($uploadedFiles)) {
  $total_pages = (int)$provided_total_pages;
  $total_files = $provided_total_files;
  $total_images = $provided_total_images;
}

if ($total_pages < 1) {
  printing_json_exit([
    "ok" => false,
    "error_scope" => "file",
    "error" => "Unable to compute total pages. Please upload valid files.",
  ], 422);
}

$pricing = compute_print_pricing($pdo, $catalog_pricing_rule_id, $quantity, $total_pages);
if (!$pricing["ok"]) {
  printing_json_exit([
    "ok" => false,
    "error_scope" => "form",
    "error" => $pricing["error"],
    "files" => $fileResults,
    "total_files" => $total_files,
    "total_images" => $total_images,
    "total_pages" => $total_pages,
  ], 422);
}

printing_json_exit([
  "ok" => true,
  "files" => $fileResults,
  "total_files" => $total_files,
  "total_images" => $total_images,
  "total_pages" => $total_pages,
  "price_per_page" => $pricing["price_per_page"],
  "estimated_total" => $pricing["estimated_total"],
  "catalog_pricing_rule_id" => $pricing["catalog_pricing_rule_id"],
  "paper_size" => $pricing["paper_size"] ?: $paper_size,
  "color_option" => $pricing["color_option"] ?: $color_option,
  "quantity" => $quantity,
]);
