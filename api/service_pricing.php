<?php
require_once __DIR__ . "/upload_helpers.php";

function servitech_pricing_clean_service_label(string $label): string {
  $label = trim($label);
  $clean = preg_replace('/\s+(?:\x{2013}|\x{2014}|-)\s+.*$/u', '', $label);
  return trim(is_string($clean) ? $clean : $label);
}

function servitech_pricing_service_kind(string $category, string $serviceLabel): string {
  $category = strtolower(trim($category));
  $label = strtolower(servitech_pricing_clean_service_label($serviceLabel));

  if ($category === "online_printorder" || $label === "document printing" || $label === "online print order") {
    return "document_printing";
  }
  if ($label === "xerox") return "xerox";
  if ($label === "rush id") return "rush_id";
  if ($label === "laminating") return "laminating";
  if ($category === "repair") return "repair";
  if ($category === "installation") return "installation";

  return "";
}

function servitech_pricing_numeric_map(array $stored, array $fallback, string $serviceName): array {
  $result = [];
  foreach ($fallback as $key => $default) {
    $value = array_key_exists($key, $stored) ? $stored[$key] : $default;
    if (!is_numeric($value) || (float)$value < 0) {
      throw new DomainException("{$serviceName} pricing is invalid. Ask an admin to review the service catalog.");
    }
    $result[$key] = (float)$value;
  }
  return $result;
}

function servitech_pricing_decode_map(array $service): array {
  $stored = json_decode((string)($service["pricing_json"] ?? ""), true);
  return is_array($stored) ? $stored : [];
}

function servitech_pricing_fetch_active_service(PDO $pdo, string $kind, string $serviceLabel): array {
  $where = match ($kind) {
    "document_printing" => "category = 'printing' AND LOWER(name) LIKE '%document%printing%'",
    "xerox" => "category = 'printing' AND LOWER(name) LIKE '%xerox%'",
    "rush_id" => "category = 'printing' AND LOWER(name) LIKE '%rush%' AND LOWER(name) LIKE '%id%'",
    "laminating" => "category = 'printing' AND LOWER(name) LIKE '%laminat%'",
    "repair" => "category = 'repair' AND LOWER(TRIM(name)) = LOWER(TRIM(:name))",
    "installation" => "category = 'installation' AND LOWER(TRIM(name)) = LOWER(TRIM(:name))",
    default => throw new DomainException("Unsupported service."),
  };

  $stmt = $pdo->prepare("
    SELECT id, category, name, description, price, price_range, pricing_json::text AS pricing_json
    FROM services
    WHERE {$where}
      AND active = TRUE
    ORDER BY sort_order ASC, id ASC
    LIMIT 1
  ");
  $params = in_array($kind, ["repair", "installation"], true)
    ? [":name" => servitech_pricing_clean_service_label($serviceLabel)]
    : [];
  $stmt->execute($params);
  $service = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!is_array($service)) {
    throw new DomainException("The selected service is currently unavailable.");
  }
  return $service;
}

function servitech_pricing_normalize_paper(string $paper): string {
  $value = strtolower(trim($paper));
  if (str_contains($value, "short bond") || str_contains($value, "8.5 x 11")) return "short";
  if (str_contains($value, "long bond") || str_contains($value, "8.5 x 13")) return "long";
  if ($value === "a4") return "a4";
  if ($value === "a3") return "a3";
  return "";
}

function servitech_pricing_normalize_color(string $color): string {
  $value = strtolower(trim($color));
  if (in_array($value, ["black & white", "black and white", "bw"], true)) return "bw";
  if (in_array($value, ["colored full", "colored - full", "colored (full)"], true)) return "full";
  if (in_array($value, ["colored half", "colored - half", "colored (half)"], true)) return "half";
  return "";
}

function servitech_pricing_document_prices(array $service): array {
  return [
    "longFull" => 10.0, "longHalf" => 5.0,
    "shortFull" => 10.0, "shortHalf" => 5.0,
    "a4Full" => 10.0, "a4Half" => 5.0,
  ];
}

function servitech_pricing_xerox_prices(array $service): array {
  return servitech_pricing_numeric_map(servitech_pricing_decode_map($service), [
    "long" => 5.0,
    "short" => 3.0,
    "a4" => 3.0,
    "a3" => 5.0,
  ], "Xerox");
}

function servitech_pricing_rush_id_prices(array $service): array {
  return servitech_pricing_numeric_map(servitech_pricing_decode_map($service), [
    "package1" => 40.0,
    "package2" => 30.0,
    "package3" => 30.0,
    "package4" => 50.0,
    "package5" => 30.0,
    "package6" => 50.0,
  ], "Rush ID");
}

function servitech_pricing_laminating_prices(array $service): array {
  return servitech_pricing_numeric_map(servitech_pricing_decode_map($service), [
    "thin" => 20.0,
    "thick" => 30.0,
  ], "Laminating");
}

function servitech_pricing_count_pdf_pages(string $path): int {
  $content = @file_get_contents($path);
  if ($content === false || $content === "") return 1;
  $count = preg_match_all('/\/Type\s*\/Page\b/i', $content, $matches);
  if ($count > 0) return $count;
  if (preg_match_all('/\/Count\s+(\d+)/i', $content, $matches) && !empty($matches[1])) {
    return max(1, max(array_map("intval", $matches[1])));
  }
  return 1;
}

function servitech_pricing_estimate_doc_pages(string $path): int {
  $size = @filesize($path);
  return max(1, (int)ceil(($size ?: 1) / (45 * 1024)));
}

function servitech_pricing_estimate_docx_pages(string $path): int {
  if (!class_exists("ZipArchive")) return servitech_pricing_estimate_doc_pages($path);
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return servitech_pricing_estimate_doc_pages($path);
  $appXml = $zip->getFromName("docProps/app.xml");
  if ($appXml !== false && preg_match('/<Pages>(\d+)<\/Pages>/i', $appXml, $matches) && (int)$matches[1] > 0) {
    $zip->close();
    return (int)$matches[1];
  }
  $docXml = $zip->getFromName("word/document.xml");
  $zip->close();
  if ($docXml === false || $docXml === "") return servitech_pricing_estimate_doc_pages($path);
  return max(1, (int)ceil(max(1, str_word_count(trim(strip_tags($docXml)))) / 500));
}

function servitech_pricing_count_pptx_slides(string $path): int {
  if (!class_exists("ZipArchive")) return max(1, (int)ceil((@filesize($path) ?: 1) / (150 * 1024)));
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return max(1, (int)ceil((@filesize($path) ?: 1) / (150 * 1024)));
  $slides = 0;
  for ($i = 0; $i < $zip->numFiles; $i++) {
    if (preg_match('/^ppt\/slides\/slide\d+\.xml$/i', (string)$zip->getNameIndex($i))) $slides++;
  }
  $zip->close();
  return max(1, $slides);
}

function servitech_pricing_estimate_ppt_slides(string $path): int {
  $content = @file_get_contents($path);
  if ($content !== false && $content !== "") {
    $slides = preg_match_all('/Slide/i', $content, $matches);
    if (is_int($slides) && $slides > 0) return $slides;
  }
  return max(1, (int)ceil((@filesize($path) ?: 1) / (150 * 1024)));
}

function servitech_pricing_saved_upload_path(PDO $pdo, array $file): string {
  $token = servitech_upload_token_from_metadata($file);
  $stmt = $pdo->prepare("
    SELECT storage_key
    FROM uploads
    WHERE upload_token = :upload_token
      AND deleted_at IS NULL
    LIMIT 1
  ");
  $stmt->execute([":upload_token" => $token]);
  $storageKey = trim((string)($stmt->fetchColumn() ?: ""));
  if ($storageKey === "") {
    throw new DomainException("An uploaded print file is missing. Please upload it again.");
  }
  $fullPath = servitech_upload_storage_path($storageKey);
  if (!is_file($fullPath)) {
    throw new DomainException("An uploaded print file is missing. Please upload it again.");
  }
  return $fullPath;
}

function servitech_pricing_analyze_saved_uploads(PDO $pdo, array $uploadedFiles): array {
  if (empty($uploadedFiles)) throw new DomainException("Upload at least one file before continuing.");
  $analysis = [];
  $names = [];
  $totalImages = 0;
  $totalPages = 0;

  foreach ($uploadedFiles as $file) {
    if (!is_array($file)) throw new DomainException("An uploaded print file is invalid. Please upload it again.");
    $path = servitech_pricing_saved_upload_path($pdo, $file);
    $name = basename(trim((string)($file["original_name"] ?? basename($path))));
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $names[] = $name;

    if ($ext === "pdf") {
      $pages = servitech_pricing_count_pdf_pages($path);
      $analysis[] = ["file_name" => $name, "file_type" => $ext, "page_count" => $pages];
      $totalPages += $pages;
    } elseif ($ext === "doc" || $ext === "docx") {
      $pages = $ext === "docx" ? servitech_pricing_estimate_docx_pages($path) : servitech_pricing_estimate_doc_pages($path);
      $analysis[] = ["file_name" => $name, "file_type" => $ext, "page_count" => $pages];
      $totalPages += $pages;
    } elseif ($ext === "ppt" || $ext === "pptx") {
      $slides = $ext === "pptx" ? servitech_pricing_count_pptx_slides($path) : servitech_pricing_estimate_ppt_slides($path);
      $analysis[] = ["file_name" => $name, "file_type" => $ext, "slide_count" => $slides];
      $totalPages += $slides;
    } elseif (in_array($ext, ["jpg", "jpeg", "png"], true)) {
      $analysis[] = ["file_name" => $name, "file_type" => $ext, "page_count" => 1];
      $totalImages++;
      $totalPages++;
    } else {
      throw new DomainException("An uploaded print file type is unsupported. Please upload it again.");
    }
  }

  return [
    "file_name" => $names[0] ?? "",
    "file_names" => $names,
    "file_analysis" => $analysis,
    "total_files" => count($analysis),
    "total_images" => $totalImages,
    "total_pages" => $totalPages,
  ];
}

function servitech_pricing_round(float $amount): float {
  return round($amount, 2);
}

function servitech_pricing_apply(PDO $pdo, string $category, array $details): array {
  $serviceLabel = trim((string)($details["service_label"] ?? ""));
  $kind = servitech_pricing_service_kind($category, $serviceLabel);
  if ($kind === "") throw new DomainException("Unsupported service.");

  $cleanLabel = servitech_pricing_clean_service_label($serviceLabel);
  $assessedServices = ["Part(s) Upgrade", "Other Repair Request", "Other Installation Request"];
  if (in_array($cleanLabel, $assessedServices, true)) {
    unset($details["price_per_page"], $details["estimated_total"]);
    $details["service_label"] = $cleanLabel;
    $details["pricing_source"] = "manual_assessment";
    return $details;
  }

  $service = servitech_pricing_fetch_active_service($pdo, $kind, $serviceLabel);
  $quantity = max(1, (int)($details["quantity"] ?? 1));
  $details["quantity"] = $quantity;
  $details["catalog_service_id"] = (int)$service["id"];
  $details["catalog_service_name"] = (string)$service["name"];
  $details["pricing_calculated_at"] = date(DATE_ATOM);

  if ($kind === "document_printing") {
    $paper = servitech_pricing_normalize_paper((string)($details["paper_size"] ?? ""));
    $color = servitech_pricing_normalize_color((string)($details["color_option"] ?? ""));
    if ($paper === "" || $paper === "a3") throw new DomainException("Select a valid paper size.");
    if ($color === "") throw new DomainException("Select a valid color option.");
    $details = array_merge($details, servitech_pricing_analyze_saved_uploads($pdo, (array)($details["uploaded_files"] ?? [])));
    $prices = servitech_pricing_document_prices($service);
    $suffix = $color === "full" ? "Full" : "Half";
    $unitPrice = $prices[$paper . $suffix];
    $details["price_per_page"] = $unitPrice;
    $details["estimated_total"] = servitech_pricing_round($unitPrice * $quantity * (int)$details["total_pages"]);
    return $details;
  }

  if ($kind === "xerox") {
    $paper = servitech_pricing_normalize_paper((string)($details["paper_size"] ?? ""));
    if ($paper === "") throw new DomainException("Select a valid Xerox paper size.");
    $unitPrice = servitech_pricing_xerox_prices($service)[$paper];
  } elseif ($kind === "rush_id") {
    if (!preg_match('/package\s*([1-6])/i', (string)($details["package_label"] ?? ""), $matches)) {
      throw new DomainException("Select a valid Rush ID package.");
    }
    $unitPrice = servitech_pricing_rush_id_prices($service)["package" . $matches[1]];
  } elseif ($kind === "laminating") {
    $type = strtolower(trim((string)($details["lamination_type"] ?? "")));
    if (!in_array($type, ["thin", "thick"], true)) throw new DomainException("Select a valid lamination type.");
    $unitPrice = servitech_pricing_laminating_prices($service)[$type];
  } else {
    unset($details["price_per_page"], $details["estimated_total"]);
    $details["service_label"] = (string)$service["name"];
    if (trim((string)($service["price_range"] ?? "")) !== "") {
      $details["price_range"] = (string)$service["price_range"];
    }
    return $details;
  }

  $details["service_label"] = (string)$service["name"];
  $details["price_per_page"] = $unitPrice;
  $details["estimated_total"] = servitech_pricing_round($unitPrice * $quantity);
  return $details;
}

function servitech_pricing_validate_admin_catalog(string $category, string $name, ?float $price, ?array $pricing): void {
  if ($price !== null && $price < 0) throw new DomainException("Price cannot be negative.");
  if ($pricing === null) return;

  $kind = servitech_pricing_service_kind($category, $name);
  $validated = match ($kind) {
    "document_printing" => servitech_pricing_numeric_map($pricing, array_fill_keys([
      "longFull", "longHalf", "shortFull", "shortHalf", "a4Full", "a4Half",
    ], null), "Document Printing"),
    "xerox" => servitech_pricing_numeric_map($pricing, array_fill_keys(["long", "short", "a4", "a3"], null), "Xerox"),
    "rush_id" => servitech_pricing_numeric_map($pricing, array_fill_keys([
      "package1", "package2", "package3", "package4", "package5", "package6",
    ], null), "Rush ID"),
    "laminating" => servitech_pricing_numeric_map($pricing, array_fill_keys(["thin", "thick"], null), "Laminating"),
    default => [],
  };

  if ($kind === "document_printing") {
    foreach (["long", "short", "a4"] as $paper) {
      if ($validated[$paper . "Full"] < $validated[$paper . "Half"]) {
        throw new DomainException("Document Printing full-color prices cannot be lower than half-color prices.");
      }
    }
  }
}
