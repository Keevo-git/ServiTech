<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/upload_helpers.php";

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

function normalize_paper_size(string $paper): string {
  $v = strtolower(trim($paper));
  if ($v === "") return "";
  if (strpos($v, "short bond") !== false || strpos($v, "8.5 x 11") !== false) return "short";
  if (strpos($v, "long bond") !== false || strpos($v, "8.5 x 13") !== false) return "long";
  if ($v === "a4") return "a4";
  if ($v === "a3") return "a3";
  return "";
}

function normalize_color_option(string $color): string {
  $v = strtolower(trim($color));
  if ($v === "") return "";

  if ($v === "black & white" || $v === "black and white" || $v === "bw") return "bw";
  if ($v === "colored full" || $v === "colored - full" || $v === "colored (full)") return "full";
  if ($v === "colored half" || $v === "colored - half" || $v === "colored (half)") return "half";

  return "";
}

function extract_document_printing_price(string $description, string $option): ?float {
  $pattern = "/\\b" . preg_quote($option, "/") . "\\s*[-\\x{2013}\\x{2014}]?\\s*₱?\\s*([0-9]+(?:\\.[0-9]+)?)/iu";
  if (preg_match($pattern, $description, $matches)) {
    return max(0, (float)$matches[1]);
  }

  return null;
}

function extract_document_printing_block_price(string $description, string $blockName, string $option): ?float {
  $blocks = preg_split("/\\r?\\n\\s*\\r?\\n/", $description) ?: [];
  foreach ($blocks as $block) {
    if (stripos($block, $blockName) === false) {
      continue;
    }

    $pattern = "/\\b" . preg_quote($option, "/") . "\\s*(?:\\/\\s*B&W)?\\s*[-\\x{2013}\\x{2014}]?\\s*\\x{20B1}?\\s*([0-9]+(?:\\.[0-9]+)?)/iu";
    if (preg_match($pattern, $block, $matches)) {
      return max(0, (float)$matches[1]);
    }
  }

  return null;
}

function extract_document_printing_price_range(string $priceRange): array {
  if (!preg_match_all("/[0-9]+(?:\\.[0-9]+)?/", $priceRange, $matches) || empty($matches[0])) {
    return [];
  }

  $prices = array_map(static fn($value) => max(0, (float)$value), $matches[0]);
  sort($prices, SORT_NUMERIC);

  return $prices;
}

function fetch_document_printing_prices(PDO $pdo): array {
  $prices = [
    "long_full" => 10.0,
    "long_half" => 5.0,
    "short_full" => 10.0,
    "short_half" => 5.0,
    "a4_full" => 10.0,
    "a4_half" => 5.0,
    "a3_full" => 10.0,
    "a3_half" => 5.0,
  ];

  try {
    $stmt = $pdo->prepare("
      SELECT description, price, price_range, pricing_json::text AS pricing_json
      FROM services
      WHERE category = 'printing'
        AND LOWER(name) LIKE '%document%printing%'
        AND active = TRUE
      ORDER BY sort_order ASC, id ASC
      LIMIT 1
    ");
    $stmt->execute();
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($service)) {
      return $prices;
    }

    $description = (string)($service["description"] ?? "");
    $storedPricing = json_decode((string)($service["pricing_json"] ?? ""), true);
    $rangePrices = extract_document_printing_price_range((string)($service["price_range"] ?? ""));
    $default = $rangePrices[0] ?? (isset($service["price"]) ? max(0, (float)$service["price"]) : $prices["short_half"]);
    $half = extract_document_printing_price($description, "Half") ?? $default;
    $full = extract_document_printing_price($description, "Full") ?? ($rangePrices[count($rangePrices) - 1] ?? max($half, $default));

    return [
      "long_full" => isset($storedPricing["longFull"]) ? (float)$storedPricing["longFull"] : (extract_document_printing_block_price($description, "Long Bond", "Full") ?? $full),
      "long_half" => isset($storedPricing["longHalf"]) ? (float)$storedPricing["longHalf"] : (extract_document_printing_block_price($description, "Long Bond", "Half") ?? $half),
      "short_full" => isset($storedPricing["shortFull"]) ? (float)$storedPricing["shortFull"] : (extract_document_printing_block_price($description, "Short Bond", "Full") ?? $full),
      "short_half" => isset($storedPricing["shortHalf"]) ? (float)$storedPricing["shortHalf"] : (extract_document_printing_block_price($description, "Short Bond", "Half") ?? $half),
      "a4_full" => isset($storedPricing["a4Full"]) ? (float)$storedPricing["a4Full"] : (extract_document_printing_block_price($description, "A4", "Full") ?? $full),
      "a4_half" => isset($storedPricing["a4Half"]) ? (float)$storedPricing["a4Half"] : (extract_document_printing_block_price($description, "A4", "Half") ?? $half),
      "a3_full" => isset($storedPricing["a3Full"]) ? (float)$storedPricing["a3Full"] : (extract_document_printing_block_price($description, "A3", "Full") ?? $full),
      "a3_half" => isset($storedPricing["a3Half"]) ? (float)$storedPricing["a3Half"] : (extract_document_printing_block_price($description, "A3", "Half") ?? $half),
    ];
  } catch (Throwable $e) {
    return $prices;
  }
}

function count_pdf_pages(string $path): int {
  if (!is_file($path)) return 1;
  $content = @file_get_contents($path);
  if ($content === false || $content === "") return 1;

  $count = preg_match_all('/\/Type\s*\/Page\b/i', $content, $matches);
  if ($count > 0) return $count;

  $fallback = 0;
  if (preg_match_all('/\/Count\s+(\d+)/i', $content, $m) && !empty($m[1])) {
    $ints = array_map("intval", $m[1]);
    $fallback = max($ints);
  }

  return max(1, $fallback);
}

function estimate_doc_pages_from_size(string $path): int {
  $size = @filesize($path);
  if (!is_int($size) || $size <= 0) return 1;
  return max(1, (int)ceil($size / (45 * 1024)));
}

function estimate_docx_pages(string $path): int {
  if (!class_exists("ZipArchive") || !is_file($path)) {
    return estimate_doc_pages_from_size($path);
  }

  $zip = new ZipArchive();
  if ($zip->open($path) !== true) {
    return estimate_doc_pages_from_size($path);
  }

  $pagesFromProps = 0;
  $appXml = $zip->getFromName("docProps/app.xml");
  if ($appXml !== false && preg_match('/<Pages>(\d+)<\/Pages>/i', $appXml, $m)) {
    $pagesFromProps = (int)$m[1];
  }

  if ($pagesFromProps > 0) {
    $zip->close();
    return $pagesFromProps;
  }

  $docXml = $zip->getFromName("word/document.xml");
  $zip->close();

  if ($docXml === false || $docXml === "") {
    return estimate_doc_pages_from_size($path);
  }

  $plain = trim(strip_tags($docXml));
  $words = str_word_count($plain);
  if ($words <= 0) return 1;

  return max(1, (int)ceil($words / 500));
}

function count_pptx_slides(string $path): int {
  if (!class_exists("ZipArchive") || !is_file($path)) {
    return max(1, (int)ceil((@filesize($path) ?: 1) / (150 * 1024)));
  }

  $zip = new ZipArchive();
  if ($zip->open($path) !== true) {
    return max(1, (int)ceil((@filesize($path) ?: 1) / (150 * 1024)));
  }

  $slides = 0;
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string)$zip->getNameIndex($i);
    if (preg_match('/^ppt\/slides\/slide\d+\.xml$/i', $name)) {
      $slides++;
    }
  }

  $zip->close();

  if ($slides > 0) return $slides;
  return max(1, (int)ceil((@filesize($path) ?: 1) / (150 * 1024)));
}

function estimate_ppt_slides(string $path): int {
  $content = @file_get_contents($path);
  if ($content !== false && $content !== "") {
    $slides = preg_match_all('/Slide/i', $content, $matches);
    if (is_int($slides) && $slides > 0) return $slides;
  }

  $size = @filesize($path);
  return max(1, (int)ceil(($size ?: 1) / (150 * 1024)));
}

function compute_print_pricing(PDO $pdo, string $paperRaw, string $colorRaw, int $quantity, int $totalPages): array {
  $paper = normalize_paper_size($paperRaw);
  if ($paper === "") {
    return ["ok" => false, "error" => "Select a valid paper size."];
  }

  $color = normalize_color_option($colorRaw);
  if ($color === "") {
    return ["ok" => false, "error" => "Select a valid color option."];
  }

  $prices = fetch_document_printing_prices($pdo);
  $paperPrefix = match ($paper) {
    "long" => "long",
    "a4" => "a4",
    "a3" => "a3",
    default => "short",
  };
  $pricePerPage = match ($color) {
    "full" => $prices[$paperPrefix . "_full"],
    "half" => $prices[$paperPrefix . "_half"],
    default => $prices[$paperPrefix . "_half"],
  };
  $safeQty = max(1, $quantity);
  $safePages = max(0, $totalPages);
  $estimatedTotal = $safePages * $pricePerPage * $safeQty;

  return [
    "ok" => true,
    "price_per_page" => $pricePerPage,
    "estimated_total" => (float)$estimatedTotal,
  ];
}

$paper_size = trim((string)($_POST["paper_size"] ?? ""));
$color_option = trim((string)($_POST["color_option"] ?? ""));
$quantity = max(1, (int)($_POST["quantity"] ?? 1));

$provided_total_pages = isset($_POST["total_pages"]) ? max(0, (int)$_POST["total_pages"]) : null;
$provided_total_files = isset($_POST["total_files"]) ? max(0, (int)$_POST["total_files"]) : 0;
$provided_total_images = isset($_POST["total_images"]) ? max(0, (int)$_POST["total_images"]) : 0;

$uploadedFiles = normalize_uploaded_files($_FILES["files"] ?? null);

if (empty($uploadedFiles) && $provided_total_pages === null) {
  printing_json_exit(["ok" => false, "error" => "No files uploaded."], 422);
}

$fileResults = [];
$total_files = 0;
$total_images = 0;
$total_pages = 0;
$unsupported = [];
$uploadErrors = [];

if (!empty($uploadedFiles)) {
  foreach ($uploadedFiles as $file) {
    $name = trim((string)($file["name"] ?? ""));
    $tmp = (string)($file["tmp_name"] ?? "");
    $error = (int)($file["error"] ?? UPLOAD_ERR_NO_FILE);
    $size = (int)($file["size"] ?? 0);

    if ($name === "" || $error === UPLOAD_ERR_NO_FILE) {
      continue;
    }

    if ($error !== UPLOAD_ERR_OK || $tmp === "" || !is_uploaded_file($tmp)) {
      $uploadErrors[] = $name !== "" ? $name : "Unknown file";
      continue;
    }

    if ($size <= 0 || $size > 20 * 1024 * 1024) {
      $unsupported[] = $name;
      continue;
    }

    try {
      $type = servitech_upload_validate_type($tmp, $name);
    } catch (Throwable $e) {
      $unsupported[] = $name;
      continue;
    }

    $ext = $type["extension"];
    $total_files++;

    if ($ext === "pdf") {
      $pages = count_pdf_pages($tmp);
      $fileResults[] = [
        "file_name" => $name,
        "file_type" => "pdf",
        "page_count" => $pages,
      ];
      $total_pages += $pages;
      continue;
    }

    if ($ext === "doc" || $ext === "docx") {
      $pages = ($ext === "docx") ? estimate_docx_pages($tmp) : estimate_doc_pages_from_size($tmp);
      $fileResults[] = [
        "file_name" => $name,
        "file_type" => $ext,
        "page_count" => $pages,
      ];
      $total_pages += $pages;
      continue;
    }

    if ($ext === "ppt" || $ext === "pptx") {
      $slides = ($ext === "pptx") ? count_pptx_slides($tmp) : estimate_ppt_slides($tmp);
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
    "error" => "Some files failed to upload properly.",
    "upload_errors" => $uploadErrors,
  ], 422);
}

if (!empty($unsupported)) {
  printing_json_exit([
    "ok" => false,
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
    "error" => "Unable to compute total pages. Please upload valid files.",
  ], 422);
}

$pricing = compute_print_pricing($pdo, $paper_size, $color_option, $quantity, $total_pages);
if (!$pricing["ok"]) {
  printing_json_exit([
    "ok" => false,
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
  "paper_size" => $paper_size,
  "color_option" => $color_option,
  "quantity" => $quantity,
]);
