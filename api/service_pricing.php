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
    || (str_contains($label, "document") && str_contains($label, "print"))
    || (str_contains($label, "print") && str_contains($label, "order"))
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

function servitech_pricing_fetch_active_service(PDO $pdo, string $kind, string $serviceLabel, bool $lockForSubmission = false): array {
  $lockSql = $lockForSubmission && $pdo->inTransaction() ? "FOR SHARE" : "";
  $requestedId = isset($GLOBALS["servitech_requested_catalog_service_id"])
    ? (int)$GLOBALS["servitech_requested_catalog_service_id"]
    : 0;
  if ($requestedId > 0) {
    $stmt = $pdo->prepare("
      SELECT id, category, name, description
      FROM services
      WHERE id = :id
        AND active = TRUE
      LIMIT 1 {$lockSql}
    ");
    $stmt->execute([":id" => $requestedId]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($service)) {
      throw new DomainException(servitech_pricing_selection_changed_message());
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
    ORDER BY sort_order ASC, id ASC
    LIMIT 1 {$lockSql}
  ");
  $params = in_array($kind, ["repair", "installation"], true)
    ? [":name" => servitech_pricing_clean_service_label($serviceLabel)]
    : [];
  $stmt->execute($params);
  $service = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!is_array($service)) {
    throw new DomainException(servitech_pricing_selection_changed_message());
  }
  return $service;
}

function servitech_pricing_lock_catalog_rows(PDO $pdo, int $serviceId): void {
  if ($serviceId <= 0 || !$pdo->inTransaction()) return;

  $groups = $pdo->prepare("SELECT id FROM service_option_groups WHERE service_id = :service_id FOR SHARE");
  $groups->execute([":service_id" => $serviceId]);
  $groups->fetchAll(PDO::FETCH_COLUMN);

  $values = $pdo->prepare("
    SELECT v.id
    FROM service_option_values v
    JOIN service_option_groups g ON g.id = v.group_id
    WHERE g.service_id = :service_id
    FOR SHARE OF v
  ");
  $values->execute([":service_id" => $serviceId]);
  $values->fetchAll(PDO::FETCH_COLUMN);

  $rules = $pdo->prepare("SELECT id FROM service_pricing_rules WHERE service_id = :service_id FOR SHARE");
  $rules->execute([":service_id" => $serviceId]);
  $rules->fetchAll(PDO::FETCH_COLUMN);
}

function servitech_pricing_selection_changed_message(): string {
  return "The selected service option has changed or is no longer available. Please review your selection before joining the queue.";
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
      $pages = servitech_document_count_pdf_pages($path);
      if ($pages < 1) throw new DomainException("Unable to detect the page count for {$name}. Please upload a valid, unlocked PDF.");
      $analysis[] = ["file_name" => $name, "file_type" => $ext, "page_count" => $pages];
      $totalPages += $pages;
    } elseif ($ext === "doc" || $ext === "docx") {
      $pages = $ext === "docx"
        ? servitech_document_count_docx_pages($path)
        : servitech_document_count_doc_pages($path);
      if ($pages < 1) throw new DomainException("Unable to render and count pages for {$name}. Please upload a valid, unlocked DOC/DOCX file or convert it to PDF.");
      $analysis[] = ["file_name" => $name, "file_type" => $ext, "page_count" => $pages];
      $totalPages += $pages;
    } elseif ($ext === "ppt" || $ext === "pptx") {
      $slides = $ext === "pptx"
        ? servitech_document_count_pptx_slides($path)
        : servitech_document_count_ppt_slides($path);
      if ($slides < 1) throw new DomainException("Unable to detect the slide count for {$name}. Please upload a valid, unlocked presentation.");
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

function servitech_pricing_normalize_option_ids($value): array {
  if (!is_array($value)) return [];
  $normalized = [];
  foreach ($value as $groupKey => $optionId) {
    $groupKey = servitech_catalog_slug((string)$groupKey);
    $optionId = (int)$optionId;
    if ($groupKey !== "" && $optionId > 0) $normalized[$groupKey] = $optionId;
  }
  ksort($normalized);
  return $normalized;
}

function servitech_pricing_log_catalog_issue(string $reason, array $service, array $details, ?array $rule = null): void {
  $context = [
    "reason" => $reason,
    "service_id" => (int)($service["id"] ?? 0),
    "service_name" => (string)($service["name"] ?? $details["service_label"] ?? ""),
    "pricing_rule_id" => (int)($details["catalog_pricing_rule_id"] ?? 0),
    "selected_option_ids" => servitech_pricing_normalize_option_ids($details["catalog_option_value_ids"] ?? []),
    "selected_labels" => array_filter([
      "paper_size" => $details["paper_size"] ?? null,
      "color_option" => $details["color_option"] ?? null,
      "package" => $details["package_label"] ?? null,
      "lamination_type" => $details["lamination_type"] ?? null,
      "device_type" => $details["device_type"] ?? null,
    ], static fn($value) => $value !== null && $value !== ""),
    "expected_option_ids" => servitech_pricing_normalize_option_ids($rule["option_value_ids"] ?? []),
    "expected_rule_key" => (string)($rule["rule_key"] ?? ""),
  ];
  error_log("ServiTech catalog selection failure: " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function servitech_pricing_validate_catalog_option_ids(array $rule, array $service, array $details): array {
  $wasSubmitted = array_key_exists("catalog_option_value_ids", $details);
  $submitted = servitech_pricing_normalize_option_ids($details["catalog_option_value_ids"] ?? []);
  $expected = servitech_pricing_normalize_option_ids($rule["option_value_ids"] ?? []);
  // Existing records created before option-ID snapshots can still be repriced from their saved rule ID.
  if (!$wasSubmitted) return $expected;
  if ($submitted !== $expected) {
    servitech_pricing_log_catalog_issue("selected_option_ids_do_not_match_rule", $service, $details, $rule);
    throw new DomainException(servitech_pricing_selection_changed_message());
  }
  return $expected;
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
  $details["quantity_snapshot"] = max(1, (int)($details["quantity"] ?? 1));
  $details["final_total_snapshot"] = isset($details["estimated_total"]) && is_numeric($details["estimated_total"])
    ? max(0, (float)$details["estimated_total"])
    : null;
  $details["payment_method_snapshot"] = trim((string)($details["payment_method"] ?? ""));
  $details["customer_notes_snapshot"] = trim((string)($details["notes"] ?? ""));
  foreach ([
    "paper_size" => "paper_size_snapshot",
    "color_option" => "color_option_snapshot",
    "package_label" => "package_snapshot",
    "device_type" => "device_snapshot",
    "repair_type" => "service_type_snapshot",
    "installation_type" => "installation_type_snapshot",
    "lamination_type" => "lamination_type_snapshot",
  ] as $source => $snapshot) {
    if (isset($details[$source]) && trim((string)$details[$source]) !== "") {
      $details[$snapshot] = trim((string)$details[$source]);
    }
  }
  return $details;
}

function servitech_pricing_apply(PDO $pdo, string $category, array $details, bool $lockForSubmission = false): array {
  if ($lockForSubmission && !$pdo->inTransaction()) {
    throw new LogicException("Final pricing validation must run inside the queue submission transaction.");
  }
  $serviceLabel = trim((string)($details["service_label"] ?? ""));
  $kind = servitech_pricing_service_kind($category, $serviceLabel);
  if ($kind === "") throw new DomainException("Unsupported service.");

  $requestedId = isset($details["catalog_service_id"]) ? (int)$details["catalog_service_id"] : 0;
  $GLOBALS["servitech_requested_catalog_service_id"] = $requestedId;
  $cleanLabel = servitech_pricing_clean_service_label($serviceLabel);

  try {
  $service = servitech_pricing_fetch_active_service($pdo, $kind, $serviceLabel, $lockForSubmission);
  } finally {
    unset($GLOBALS["servitech_requested_catalog_service_id"]);
  }
  $quantity = max(1, (int)($details["quantity"] ?? 1));
  $details["quantity"] = $quantity;
  $details["catalog_service_id"] = (int)$service["id"];
  $details["catalog_service_name"] = (string)$service["name"];
  $details["pricing_calculated_at"] = date(DATE_ATOM);
  if ($lockForSubmission) {
    servitech_pricing_lock_catalog_rows($pdo, (int)$service["id"]);
  }

  $catalogRuleId = isset($details["catalog_pricing_rule_id"]) ? (int)$details["catalog_pricing_rule_id"] : 0;
  $catalogManagedKinds = ["document_printing", "xerox", "rush_id", "laminating", "scanning", "repair", "installation"];
  if ($catalogRuleId <= 0 && in_array($kind, $catalogManagedKinds, true)) {
    servitech_pricing_log_catalog_issue("pricing_rule_id_missing", $service, $details);
    throw new DomainException(servitech_pricing_selection_changed_message());
  }
  if ($catalogRuleId > 0) {
    $catalog = servitech_catalog_fetch($pdo, (int)$service["id"], true);
    $rule = servitech_catalog_find_rule($catalog, $catalogRuleId);
    if (!$rule) {
      servitech_pricing_log_catalog_issue("active_pricing_rule_not_found", $service, $details);
      throw new DomainException(servitech_pricing_selection_changed_message());
    }

    $ruleLabel = servitech_catalog_rule_display_label($rule);
    $validatedOptionIds = servitech_pricing_validate_catalog_option_ids($rule, $service, $details);
    $details["selected_option_value_ids"] = array_values($validatedOptionIds);
    $details["selected_option_value_id_map"] = $validatedOptionIds;
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
          throw new DomainException(servitech_pricing_selection_changed_message());
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
      $details = array_merge($details, servitech_pricing_analyze_saved_uploads($pdo, (array)($details["uploaded_files"] ?? [])));
      if ($fixedPrice === null) {
        unset($details["price_per_page"], $details["estimated_total"]);
      } else {
        $details["price_per_page"] = $fixedPrice;
        $details["estimated_total"] = servitech_pricing_round($fixedPrice * $quantity * (int)$details["total_pages"]);
      }
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

  throw new DomainException(servitech_pricing_selection_changed_message());
}
