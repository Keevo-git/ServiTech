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
  if ($label === "laminating" || $label === "lamination") return "laminating";
  if ($label === "scanning" || $label === "scan") return "scanning";
  if ($category === "repair") return "repair";
  if ($category === "installation") return "installation";

  return "";
}

function servitech_pricing_fetch_active_service(PDO $pdo, string $kind, string $serviceLabel): array {
  $requestedId = isset($GLOBALS["servitech_requested_catalog_service_id"])
    ? (int)$GLOBALS["servitech_requested_catalog_service_id"]
    : 0;
  if ($requestedId > 0) {
    $stmt = $pdo->prepare("
      SELECT id, category, name, description
      FROM services
      WHERE id = :id
        AND active = TRUE
        AND archived_at IS NULL
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
    "scanning" => "category = 'printing' AND LOWER(name) LIKE '%scan%'",
    "repair" => "category = 'repair' AND LOWER(TRIM(name)) = LOWER(TRIM(:name))",
    "installation" => "category = 'installation' AND LOWER(TRIM(name)) = LOWER(TRIM(:name))",
    default => throw new DomainException("Unsupported service."),
  };

  $stmt = $pdo->prepare("
    SELECT id, category, name, description
    FROM services
    WHERE {$where}
      AND active = TRUE
      AND archived_at IS NULL
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

function servitech_pricing_is_other_request(string $label): bool {
  return (bool)preg_match('/\b(other|others)\b/i', $label);
}

function servitech_pricing_validate_repair_selection(array $rule, array $details): void {
  $submittedDeviceKey = trim((string)($details["device_type_key"] ?? ""));
  $submittedRepairKey = trim((string)($details["repair_type_key"] ?? ""));
  $ruleDeviceKey = trim((string)($rule["option_value_keys"]["device_type"] ?? ""));
  $ruleRepairKey = trim((string)($rule["option_value_keys"]["repair_type"] ?? ""));

  if ($submittedDeviceKey === "" || $submittedRepairKey === "") {
    throw new DomainException("Choose a valid device and repair service type.");
  }
  if ($ruleDeviceKey === "" || $ruleRepairKey === ""
      || !hash_equals($ruleDeviceKey, $submittedDeviceKey)
      || !hash_equals($ruleRepairKey, $submittedRepairKey)) {
    throw new DomainException("The selected repair service is not available for that device.");
  }
}

function servitech_pricing_apply_snapshot(array $details, array $service, string $optionId, string $optionName, ?float $price, string $status, string $optionDetails = ""): array {
  $serviceId = (int)($service["id"] ?? 0);
  $serviceName = (string)($service["name"] ?? "");
  $details["catalog_service_id"] = $serviceId;
  $details["catalog_service_name"] = $serviceName;
  $details["selected_service_id"] = $serviceId;
  $details["service_id_snapshot"] = $serviceId;
  $details["selected_option_id"] = $optionId;
  $details["service_name_snapshot"] = $serviceName;
  $details["option_name_snapshot"] = $optionName;
  $details["option_details_snapshot"] = $optionDetails !== "" ? $optionDetails : $optionName;
  $details["price_snapshot"] = $price;
  $details["pricing_status"] = $status;
  $details["price_type_snapshot"] = $status === "fixed" ? "fixed" : "assessment";
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
  $catalogManagedKinds = ["document_printing", "xerox", "rush_id", "laminating", "scanning", "repair", "installation"];
  if ($catalogRuleId <= 0 && in_array($kind, $catalogManagedKinds, true)) {
    throw new DomainException("Select a valid active service option from the service catalog.");
  }
  if ($catalogRuleId > 0) {
    $catalog = servitech_catalog_fetch($pdo, (int)$service["id"], true);
    $rule = servitech_catalog_find_rule($catalog, $catalogRuleId);
    if (!$rule) {
      throw new DomainException("The selected service option is currently unavailable.");
    }

    $ruleLabel = servitech_catalog_rule_display_label($rule);
    $details["selected_option_value_ids"] = array_values(array_map("intval", $rule["option_value_ids"] ?? []));
    $details["selected_option_labels_snapshot"] = $rule["option_labels"] ?? [];
    $priceType = (string)($rule["price_type"] ?? "assessment");
    $fixedPrice = ($priceType === "fixed" && isset($rule["price"]) && is_numeric($rule["price"]))
      ? max(0, (float)$rule["price"])
      : null;
    $pricingStatus = $fixedPrice !== null ? "fixed" : "for_assessment";
    $snapshotPrice = $fixedPrice;

    $paperLabel = servitech_catalog_option_label($rule, "paper_size");
    $colorLabel = servitech_catalog_option_label($rule, "color_option");
    $packageLabel = servitech_catalog_option_label($rule, "package");
    $deviceLabel = servitech_catalog_option_label($rule, "device_type");
    $repairLabel = servitech_catalog_option_label($rule, "repair_type");
    $installationLabel = servitech_catalog_option_label($rule, "installation_type");
    $laminationLabel = servitech_catalog_option_label($rule, "lamination_type");

    if ($kind === "repair") {
      servitech_pricing_validate_repair_selection($rule, $details);
    }

    if ($paperLabel !== "") $details["paper_size"] = $paperLabel;
    if ($colorLabel !== "") $details["color_option"] = $colorLabel;
    if ($packageLabel !== "") $details["package_label"] = trim($packageLabel . " - " . (string)($rule["description"] ?? ""), " -");
    if ($deviceLabel !== "") $details["device_type"] = $deviceLabel;
    if ($repairLabel !== "") $details["repair_type"] = $repairLabel;
    if ($installationLabel !== "") $details["installation_type"] = $installationLabel;
    if ($laminationLabel !== "") $details["lamination_type"] = $laminationLabel;

    if ($kind === "rush_id") {
      if ($packageLabel === "") throw new DomainException("Select a valid Rush ID package.");
      $addonIds = isset($details["catalog_addon_rule_ids"]) && is_array($details["catalog_addon_rule_ids"])
        ? $details["catalog_addon_rule_ids"]
        : [];
      $addonIds = array_values(array_unique(array_filter(array_map("intval", $addonIds), static fn($id) => $id > 0)));
      if (count($addonIds) > 10) throw new DomainException("Too many Rush ID add-ons were selected.");

      $addonSnapshots = [];
      $addonTotal = 0.0;
      $addonHasAssessment = false;
      foreach ($addonIds as $addonId) {
        $addonRule = servitech_catalog_find_rule($catalog, $addonId);
        $addonLabel = $addonRule ? servitech_catalog_option_label($addonRule, "addon") : "";
        if (!$addonRule || $addonLabel === "" || servitech_catalog_option_label($addonRule, "package") !== "") {
          throw new DomainException("A selected Rush ID add-on is currently unavailable.");
        }
        $addonPrice = (($addonRule["price_type"] ?? "") === "fixed" && isset($addonRule["price"]) && is_numeric($addonRule["price"]))
          ? max(0, (float)$addonRule["price"])
          : null;
        if ($addonPrice === null) {
          $addonHasAssessment = true;
        } else {
          $addonTotal += $addonPrice;
        }
        $addonSnapshots[] = [
          "rule_id" => $addonId,
          "name" => $addonLabel,
          "price" => $addonPrice,
          "price_type" => $addonPrice === null ? "for_assessment" : "fixed",
        ];
      }
      $details["catalog_addon_rule_ids"] = $addonIds;
      $details["add_ons_snapshot"] = $addonSnapshots;
      $details["add_ons_price_snapshot"] = $addonHasAssessment ? null : servitech_pricing_round($addonTotal);
      $details["selected_option_ids"] = array_merge([$catalogRuleId], $addonIds);
      if ($fixedPrice === null || $addonHasAssessment) {
        $snapshotPrice = null;
        $pricingStatus = "for_assessment";
      } else {
        $snapshotPrice = servitech_pricing_round($fixedPrice + $addonTotal);
      }
    }

    if (servitech_pricing_is_other_request($ruleLabel) || servitech_pricing_is_other_request($repairLabel) || servitech_pricing_is_other_request($installationLabel)) {
      $notes = trim((string)($details["notes"] ?? ""));
      if ($notes === "") {
        throw new DomainException("Please describe your request when selecting Others.");
      }
      if ($kind === "repair") $details["customer_issue_description"] = $notes;
    }

    if ($kind === "repair") {
      $details["device_snapshot"] = $deviceLabel;
      $details["service_type_snapshot"] = $repairLabel;
    }

    // Save the configured service name as part of the immutable order snapshot.
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
    } elseif (in_array($kind, ["xerox", "rush_id", "laminating", "scanning"], true)) {
      $effectivePrice = $kind === "rush_id" ? $snapshotPrice : $fixedPrice;
      if ($effectivePrice === null) {
        unset($details["price_per_page"], $details["estimated_total"]);
      } else {
        $details["price_per_page"] = $effectivePrice;
        $details["estimated_total"] = servitech_pricing_round($effectivePrice * $quantity);
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
      $snapshotPrice,
      $pricingStatus,
      trim(implode(" / ", array_filter([$paperLabel, $colorLabel, $packageLabel, $deviceLabel, $repairLabel, $installationLabel, $laminationLabel])))
    );
  }

  throw new DomainException("Select a valid active service option from the service catalog.");
}
