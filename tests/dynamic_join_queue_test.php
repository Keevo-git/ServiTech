<?php

require_once __DIR__ . "/../api/service_pricing.php";

function dynamic_queue_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

$rule = [
  "id" => 302,
  "rule_key" => "8_5x13_colored",
  "option_value_ids" => ["paper_size" => 13, "color_option" => 21],
];
$service = ["id" => 7, "name" => "Photocopy"];
$details = [
  "catalog_pricing_rule_id" => 302,
  "catalog_option_value_ids" => ["color_option" => "21", "paper_size" => 13],
];

$validated = servitech_pricing_validate_catalog_option_ids($rule, $service, $details);
dynamic_queue_assert($validated === ["color_option" => 21, "paper_size" => 13], "Photocopy option IDs must match regardless of submitted object order.");

$rejected = false;
try {
  servitech_pricing_validate_catalog_option_ids($rule, $service, [
    "catalog_pricing_rule_id" => 302,
    "catalog_option_value_ids" => ["paper_size" => 12, "color_option" => 21],
  ]);
} catch (DomainException $e) {
  $rejected = str_contains($e->getMessage(), "changed or is no longer available");
}
dynamic_queue_assert($rejected, "A manipulated option-ID combination must be rejected with the customer-safe message.");

$zeroIdsRejected = false;
try {
  servitech_pricing_validate_catalog_option_ids($rule, $service, [
    "catalog_pricing_rule_id" => 302,
    "catalog_option_value_ids" => ["paper_size" => 0, "color_option" => 0],
  ]);
} catch (DomainException $e) {
  $zeroIdsRejected = true;
}
dynamic_queue_assert($zeroIdsRejected, "Submitted empty/zero option IDs must not bypass rule validation.");

$legacy = servitech_pricing_validate_catalog_option_ids($rule, $service, []);
dynamic_queue_assert($legacy === ["color_option" => 21, "paper_size" => 13], "Old records without option-ID maps must remain readable/editable.");

$customerForms = [
  "custo2_docu_printing.php",
  "custo2_xerox.php",
  "custo2_rush_id.php",
  "custo2_laminating.php",
  "custo2_scanning.php",
  "custo1_repair_option.php",
  "custo1_installation_option.php",
];
foreach ($customerForms as $file) {
  $source = file_get_contents(__DIR__ . "/../pages/customer/{$file}") ?: "";
  dynamic_queue_assert(str_contains($source, "service_catalog_client.js"), "{$file} must load the shared catalog selection client.");
  dynamic_queue_assert(str_contains($source, "data-value-id"), "{$file} must render database option IDs.");
}

$printingSelectionSource = file_get_contents(__DIR__ . "/../pages/customer/custo1_printing_option.php") ?: "";
dynamic_queue_assert(
  str_contains($printingSelectionSource, "SELECT id, category, name"),
  "The Printing service selector must include category metadata required by the shared catalog classifier."
);
dynamic_queue_assert(
  str_contains($printingSelectionSource, "servitech_catalog_dedupe_services(\$printingServiceRows, false)"),
  "The Printing service selector must use the shared catalog deduplicator."
);
dynamic_queue_assert(
  str_contains($printingSelectionSource, 'servitech_catalog_service_kind($service) !== "document_printing"'),
  "Printing service availability must use catalog kinds instead of editable service names."
);
dynamic_queue_assert(
  str_contains($printingSelectionSource, "No active printing services are available right now."),
  "The Printing service selector must show a clear empty state."
);
dynamic_queue_assert(
  !str_contains($printingSelectionSource, "archived_at"),
  "The Printing service selector must use Active/Inactive only."
);

$activePrintingRows = [
  ["id" => 1, "category" => "printing", "name" => "Document Printing", "active" => 1],
  ["id" => 2, "category" => "printing", "name" => "Photocopy", "active" => 1],
  ["id" => 3, "category" => "printing", "name" => "Xerox", "active" => 1],
  ["id" => 4, "category" => "printing", "name" => "Rush ID", "active" => 1],
  ["id" => 5, "category" => "printing", "name" => "Lamination", "active" => 1],
];
$activePrintingKinds = array_map(
  "servitech_catalog_service_kind",
  servitech_catalog_dedupe_services($activePrintingRows, false)
);
dynamic_queue_assert(
  $activePrintingKinds === ["document_printing", "photocopy", "rush_id", "laminating"],
  "The Printing selector must return the four active configured services and treat Xerox as the Photocopy alias."
);

$mainSource = file_get_contents(__DIR__ . "/../assets/js/main.js") ?: "";
dynamic_queue_assert(!str_contains($mainSource, "The selected photocopy combination is currently unavailable."), "The old Photocopy error must be removed.");
dynamic_queue_assert(str_contains($mainSource, "catalog_option_value_ids"), "Queue payloads must submit option-ID maps.");

$documentSource = file_get_contents(__DIR__ . "/../assets/js/custo2_docu_printing.js") ?: "";
dynamic_queue_assert(!str_contains($documentSource, "normalizePaperKey"), "Document Printing must not hardcode paper aliases.");
dynamic_queue_assert(!str_contains($documentSource, "normalizeColorKey"), "Document Printing must not hardcode color aliases.");

foreach (["custo2_docu_printing.php", "custo2_xerox.php"] as $matrixForm) {
  $source = file_get_contents(__DIR__ . "/../pages/customer/{$matrixForm}") ?: "";
  dynamic_queue_assert(str_contains($source, "servitech_catalog_values_used_by_rules"), "{$matrixForm} must show only option IDs used by active valid combinations.");
  dynamic_queue_assert(str_contains($source, "servitech_store_send_no_cache_headers"), "{$matrixForm} must not embed stale catalog data.");
}

$publicApiSource = file_get_contents(__DIR__ . "/../api/services_public.php") ?: "";
dynamic_queue_assert(str_contains($publicApiSource, "id = :id AND active = TRUE"), "Landing service details must resolve active services by database ID.");
dynamic_queue_assert(!str_contains($publicApiSource, "name = :name"), "Landing service details must not depend on editable display-name equality.");
dynamic_queue_assert(str_contains($publicApiSource, "servitech_catalog_customer_availability"), "Landing service lists must hide active services with incomplete pricing.");
dynamic_queue_assert(str_contains($mainSource, "buildCatalogMatrixCards"), "Landing price cards must be built from active dynamic catalog rules.");
dynamic_queue_assert(str_contains($mainSource, "serviceDataLoadPromise = loadServicesFromDatabase()"), "Landing modals must re-fetch the database catalog when opened.");

echo "Dynamic Join Queue tests passed.\n";
