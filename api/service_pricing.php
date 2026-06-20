<?php
require_once __DIR__ . "/upload_helpers.php";
require_once __DIR__ . "/service_catalog.php";

function servitech_pricing_clean_service_label(string $label): string {
  $label = trim($label);
  $clean = preg_replace('/\s+(?:\x{2013}|\x{2014}|-)\s+.*$/u', '', $label);
  return trim(is_string($clean) ? $clean : $label);
}

function servitech_pricing_service_kind(string $category, string $serviceLabel): string {
  $category = strtolower(trim($category));
  $label = strtolower(servitech_pricing_clean_service_label($serviceLabel));

  if (
    $category === "online_printorder"
    || $label === "document printing"
    || $label === "document print"
    || $label === "online document printing"
    || $label === "online document print"
    || $label === "online print order"
  ) {
    return "document_printing";
  }
  if ($label === "xerox" || $label === "photocopy") return "xerox";
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
  $requestedId = isset($GLOBALS["servitech_requested_catalog_service_id"])
    ? (int)$GLOBALS["servitech_requested_catalog_service_id"]
    : 0;
  if ($requestedId > 0) {
    $stmt = $pdo->prepare("
      SELECT id, category, name, description, price, price_range, pricing_json::text AS pricing_json
      FROM services
      WHERE id = :id
        AND active = TRUE
      LIMIT 1
    ");
    $stmt->execute([":id" => $requestedId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($service)) {
      throw new DomainException("The selected service is currently unavailable.");
    }
    $actualKind = servitech_pricing_service_kind((string)$service["category"], (string)$service["name"]);
    if ($actualKind !== $kind) {
      throw new DomainException("The selected service does not match this form.");
    }
    return $service;
  }

  $where = match ($kind) {
    "document_printing" => "category = 'printing' AND LOWER(name) LIKE '%document%' AND (LOWER(name) LIKE '%printing%' OR LOWER(name) LIKE '%print%')",
    "xerox" => "category = 'printing' AND (LOWER(name) LIKE '%xerox%' OR LOWER(name) LIKE '%photocopy%')",
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
  if (str_contains($value, "letter") || str_contains($value, "short bond") || str_contains($value, "8.5 x 11")) return "letter";
  if (str_contains($value, "8.5x13") || str_contains($value, "8.5 x 13") || str_contains($value, "long bond")) return "long";
  if ($value === "a4") return "a4";
  if ($value === "a3") return "a3";
  return "";
}

function servitech_pricing_normalize_color(string $color): string {
  $value = strtolower(trim($color));
  if (in_array($value, ["black & white", "black and white", "bw"], true)) return "bw";
  if (in_array($value, ["full colored", "colored full", "colored - full", "colored (full)"], true)) return "full";
  if (in_array($value, ["half colored", "colored half", "colored - half", "colored (half)"], true)) return "half";
  if (in_array($value, ["colored", "full color", "color"], true)) return "colored";
  return "";
}

function servitech_pricing_document_prices(array $service): array {
  $stored = servitech_pricing_decode_map($service);
  $legacyLetterFull = $stored["letterFull"] ?? $stored["shortFull"] ?? null;
  $legacyLetterHalf = $stored["letterHalf"] ?? $stored["shortHalf"] ?? null;
  $legacyLetterBw = $stored["letterBw"] ?? $stored["shortBw"] ?? $stored["letterHalf"] ?? $stored["shortHalf"] ?? null;

  return servitech_pricing_numeric_map($stored + [
    "letterFull" => $legacyLetterFull,
    "letterHalf" => $legacyLetterHalf,
    "letterBw" => $legacyLetterBw,
    "longBw" => $stored["longBw"] ?? $stored["longHalf"] ?? null,
    "a4Bw" => $stored["a4Bw"] ?? $stored["a4Half"] ?? null,
  ], [
    "letterFull" => 10.0, "letterHalf" => 5.0, "letterBw" => 5.0,
    "longFull" => 10.0, "longHalf" => 5.0, "longBw" => 5.0,
    "a4Full" => 10.0, "a4Half" => 5.0, "a4Bw" => 5.0,
  ], "Document Printing");
}

function servitech_pricing_xerox_prices(array $service): array {
  $stored = servitech_pricing_decode_map($service);
  return servitech_pricing_numeric_map($stored + [
    "letterColored" => $stored["letterColored"] ?? $stored["shortColored"] ?? $stored["short"] ?? null,
    "letterBw" => $stored["letterBw"] ?? $stored["shortBw"] ?? $stored["short"] ?? null,
    "longColored" => $stored["longColored"] ?? $stored["long"] ?? null,
    "longBw" => $stored["longBw"] ?? $stored["long"] ?? null,
    "a4Colored" => $stored["a4Colored"] ?? $stored["a4"] ?? null,
    "a4Bw" => $stored["a4Bw"] ?? $stored["a4"] ?? null,
  ], [
    "letterColored" => 3.0, "letterBw" => 3.0,
    "longColored" => 5.0, "longBw" => 5.0,
    "a4Colored" => 3.0, "a4Bw" => 3.0,
  ], "Photocopy");
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

function servitech_pricing_paper_label(string $paper): string {
  return match ($paper) {
    "letter" => "Letter",
    "long" => "8.5x13",
    "a4" => "A4",
    default => strtoupper($paper),
  };
}

function servitech_pricing_color_label(string $color): string {
  return match ($color) {
    "full" => "Full Colored",
    "half" => "Half Colored",
    "bw" => "Black and White",
    "colored" => "Colored",
    default => $color,
  };
}

function servitech_pricing_is_other_request(string $label): bool {
  return (bool)preg_match('/\b(other|others)\b/i', $label);
}

function servitech_pricing_apply_snapshot(array $details, array $service, string $optionId, string $optionName, ?float $price, string $status, string $optionDetails = ""): array {
  $serviceId = (int)($service["id"] ?? 0);
  $serviceName = (string)($service["name"] ?? "");
  $details["catalog_service_id"] = $serviceId;
  $details["catalog_service_name"] = $serviceName;
  $details["selected_service_id"] = $serviceId;
  $details["selected_option_id"] = $optionId;
  $details["service_name_snapshot"] = $serviceName;
  $details["option_name_snapshot"] = $optionName;
  $details["option_details_snapshot"] = $optionDetails !== "" ? $optionDetails : $optionName;
  $details["price_snapshot"] = $price;
  $details["pricing_status"] = $status;
  return $details;
}

function servitech_pricing_apply(PDO $pdo, string $category, array $details): array {
  $serviceLabel = trim((string)($details["service_label"] ?? ""));
  $kind = servitech_pricing_service_kind($category, $serviceLabel);
  if ($kind === "") throw new DomainException("Unsupported service.");

  $requestedId = isset($details["catalog_service_id"]) ? (int)$details["catalog_service_id"] : 0;
  $GLOBALS["servitech_requested_catalog_service_id"] = $requestedId;
  $cleanLabel = servitech_pricing_clean_service_label($serviceLabel);

  try {
  $service = servitech_pricing_fetch_active_service($pdo, $kind, $serviceLabel);
  } finally {
    unset($GLOBALS["servitech_requested_catalog_service_id"]);
  }
  $quantity = max(1, (int)($details["quantity"] ?? 1));
  $details["quantity"] = $quantity;
  $details["catalog_service_id"] = (int)$service["id"];
  $details["catalog_service_name"] = (string)$service["name"];
  $details["pricing_calculated_at"] = date(DATE_ATOM);

  $catalogRuleId = isset($details["catalog_pricing_rule_id"]) ? (int)$details["catalog_pricing_rule_id"] : 0;
  if ($catalogRuleId > 0) {
    $catalog = servitech_catalog_fetch($pdo, (int)$service["id"], true);
    $rule = servitech_catalog_find_rule($catalog, $catalogRuleId);
    if (!$rule) {
      throw new DomainException("The selected service option is currently unavailable.");
    }

    $ruleLabel = servitech_catalog_rule_display_label($rule);
    $priceType = (string)($rule["price_type"] ?? "assessment");
    $fixedPrice = ($priceType === "fixed" && isset($rule["price"]) && is_numeric($rule["price"]))
      ? max(0, (float)$rule["price"])
      : null;
    $pricingStatus = $fixedPrice !== null ? "fixed" : "for_assessment";

    $paperLabel = servitech_catalog_option_label($rule, "paper_size");
    $colorLabel = servitech_catalog_option_label($rule, "color_option");
    $packageLabel = servitech_catalog_option_label($rule, "package");
    $deviceLabel = servitech_catalog_option_label($rule, "device_type");
    $repairLabel = servitech_catalog_option_label($rule, "repair_type");
    $installationLabel = servitech_catalog_option_label($rule, "installation_type");

    if ($paperLabel !== "") $details["paper_size"] = $paperLabel;
    if ($colorLabel !== "") $details["color_option"] = $colorLabel;
    if ($packageLabel !== "") $details["package_label"] = trim($packageLabel . " - " . (string)($rule["description"] ?? ""), " -");
    if ($deviceLabel !== "") $details["device_type"] = $deviceLabel;
    if ($repairLabel !== "") $details["repair_type"] = $repairLabel;
    if ($installationLabel !== "") $details["installation_type"] = $installationLabel;

    if (servitech_pricing_is_other_request($ruleLabel) || servitech_pricing_is_other_request($repairLabel) || servitech_pricing_is_other_request($installationLabel)) {
      $notes = trim((string)($details["notes"] ?? ""));
      if ($notes === "") {
        throw new DomainException("Please describe your request when selecting Others.");
      }
    }

    $details["service_label"] = (string)$service["name"];
    $details["pricing_source"] = "service_catalog";
    $details["catalog_pricing_rule_id"] = $catalogRuleId;

    if ($kind === "document_printing") {
      if ($fixedPrice === null) {
        throw new DomainException("The selected print option is marked for assessment and cannot be submitted with online pricing.");
      }
      $details = array_merge($details, servitech_pricing_analyze_saved_uploads($pdo, (array)($details["uploaded_files"] ?? [])));
      $details["price_per_page"] = $fixedPrice;
      $details["estimated_total"] = servitech_pricing_round($fixedPrice * $quantity * (int)$details["total_pages"]);
    } elseif (in_array($kind, ["xerox", "rush_id", "laminating"], true)) {
      if ($fixedPrice === null) {
        unset($details["price_per_page"], $details["estimated_total"]);
      } else {
        $details["price_per_page"] = $fixedPrice;
        $details["estimated_total"] = servitech_pricing_round($fixedPrice * $quantity);
      }
    } else {
      unset($details["price_per_page"], $details["estimated_total"]);
      if ($fixedPrice !== null) {
        $details["estimated_total"] = servitech_pricing_round($fixedPrice * $quantity);
      }
      if ($pricingStatus === "for_assessment") {
        $details["price_range"] = "For assessment";
      }
    }

    return servitech_pricing_apply_snapshot(
      $details,
      $service,
      (string)$catalogRuleId,
      $ruleLabel,
      $fixedPrice,
      $pricingStatus,
      trim(implode(" / ", array_filter([$paperLabel, $colorLabel, $packageLabel, $deviceLabel, $repairLabel, $installationLabel])))
    );
  }

  if ($kind === "document_printing") {
    $paper = servitech_pricing_normalize_paper((string)($details["paper_size"] ?? ""));
    $color = servitech_pricing_normalize_color((string)($details["color_option"] ?? ""));
    if ($paper === "" || $paper === "a3") throw new DomainException("Select a valid paper size.");
    if (!in_array($color, ["full", "half", "bw"], true)) throw new DomainException("Select a valid color option.");
    $details = array_merge($details, servitech_pricing_analyze_saved_uploads($pdo, (array)($details["uploaded_files"] ?? [])));
    $prices = servitech_pricing_document_prices($service);
    $suffix = match ($color) {
      "full" => "Full",
      "half" => "Half",
      "bw" => "Bw",
    };
    $unitPrice = $prices[$paper . $suffix];
    $details["price_per_page"] = $unitPrice;
    $details["estimated_total"] = servitech_pricing_round($unitPrice * $quantity * (int)$details["total_pages"]);
    $optionName = servitech_pricing_paper_label($paper) . " / " . servitech_pricing_color_label($color);
    $details = servitech_pricing_apply_snapshot($details, $service, $paper . "_" . $color, $optionName, $unitPrice, "fixed", $optionName);
    return $details;
  }

  if ($kind === "xerox") {
    $paper = servitech_pricing_normalize_paper((string)($details["paper_size"] ?? ""));
    $color = servitech_pricing_normalize_color((string)($details["color_option"] ?? "colored"));
    if ($paper === "" || $paper === "a3") throw new DomainException("Select a valid photocopy paper size.");
    if (!in_array($color, ["colored", "bw"], true)) throw new DomainException("Select a valid photocopy color option.");
    $prices = servitech_pricing_xerox_prices($service);
    $unitPrice = $prices[$paper . ($color === "colored" ? "Colored" : "Bw")];
    $optionName = servitech_pricing_paper_label($paper) . " / " . servitech_pricing_color_label($color);
  } elseif ($kind === "rush_id") {
    if (!preg_match('/package\s*([1-6])/i', (string)($details["package_label"] ?? ""), $matches)) {
      throw new DomainException("Select a valid Rush ID package.");
    }
    $unitPrice = servitech_pricing_rush_id_prices($service)["package" . $matches[1]];
    $optionName = "Package " . $matches[1];
  } elseif ($kind === "laminating") {
    $type = strtolower(trim((string)($details["lamination_type"] ?? "")));
    if (!in_array($type, ["thin", "thick"], true)) throw new DomainException("Select a valid lamination type.");
    $unitPrice = servitech_pricing_laminating_prices($service)[$type];
    $optionName = ucfirst($type);
  } else {
    unset($details["price_per_page"], $details["estimated_total"]);
    $details["service_label"] = (string)$service["name"];
    $notes = trim((string)($details["notes"] ?? ""));
    if (servitech_pricing_is_other_request((string)$service["name"]) && $notes === "") {
      throw new DomainException("Please describe your request when selecting Others.");
    }
    if (trim((string)($service["price_range"] ?? "")) !== "") {
      $details["price_range"] = (string)$service["price_range"];
    }
    $fixedPrice = isset($service["price"]) && is_numeric($service["price"]) ? max(0, (float)$service["price"]) : null;
    $status = $fixedPrice !== null ? "fixed" : "for_assessment";
    $details["pricing_source"] = $status === "fixed" ? "service_catalog" : "manual_assessment";
    $details = servitech_pricing_apply_snapshot($details, $service, "service", (string)$service["name"], $fixedPrice, $status, (string)($service["price_range"] ?? ""));
    return $details;
  }

  $details["service_label"] = (string)$service["name"];
  $details["price_per_page"] = $unitPrice;
  $details["estimated_total"] = servitech_pricing_round($unitPrice * $quantity);
  $details = servitech_pricing_apply_snapshot($details, $service, strtolower((string)($details["service_option_key"] ?? $optionName)), $optionName, $unitPrice, "fixed", $optionName);
  return $details;
}

function servitech_pricing_validate_admin_catalog(string $category, string $name, ?float $price, ?array $pricing): void {
  if ($price !== null && $price < 0) throw new DomainException("Price cannot be negative.");
  if ($pricing === null) return;

  $kind = servitech_pricing_service_kind($category, $name);
  $validated = match ($kind) {
    "document_printing" => servitech_pricing_numeric_map($pricing, array_fill_keys([
      "letterFull", "letterHalf", "letterBw", "longFull", "longHalf", "longBw", "a4Full", "a4Half", "a4Bw",
    ], null), "Document Printing"),
    "xerox" => servitech_pricing_numeric_map($pricing, array_fill_keys([
      "letterColored", "letterBw", "longColored", "longBw", "a4Colored", "a4Bw",
    ], null), "Photocopy"),
    "rush_id" => servitech_pricing_numeric_map($pricing, array_fill_keys([
      "package1", "package2", "package3", "package4", "package5", "package6",
    ], null), "Rush ID"),
    "laminating" => servitech_pricing_numeric_map($pricing, array_fill_keys(["thin", "thick"], null), "Laminating"),
    default => [],
  };

  if ($kind === "document_printing") {
    foreach (["letter", "long", "a4"] as $paper) {
      if ($validated[$paper . "Full"] < $validated[$paper . "Half"]) {
        throw new DomainException("Document Print full-color prices cannot be lower than half-color prices.");
      }
    }
  }
}
