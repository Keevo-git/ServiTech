<?php
require_once __DIR__ . "/../api/service_pricing.php";

function repair_test_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$mainJs = file_get_contents(__DIR__ . "/../assets/js/main.js");
$repairPage = file_get_contents(__DIR__ . "/../pages/customer/custo1_repair_option.php");
$installationPage = file_get_contents(__DIR__ . "/../pages/customer/custo1_installation_option.php");
$documentPrintingPage = file_get_contents(__DIR__ . "/../pages/customer/custo2_docu_printing.php");
repair_test_assert(is_string($mainJs) && strpos($mainJs, "function getServitechCatalogRules()") !== false, "Catalog rule accessor is missing.");
repair_test_assert(strpos($mainJs, "function servitechCatalogRules()") === false, "Catalog data is still shadowed by a global function.");
repair_test_assert(strpos((string)$repairPage, "Choose device") !== false, "Device placeholder is missing.");
repair_test_assert(strpos((string)$repairPage, "Choose service type") !== false, "Service Type placeholder is missing.");
repair_test_assert(strpos((string)$repairPage, "No repair services are available for this device.") !== false, "Empty-device guidance is missing.");
repair_test_assert(strpos((string)$repairPage, "payment-assessment-note") === false, "Repair Join Queue form must not render a payment section.");
repair_test_assert(strpos((string)$installationPage, "payment-assessment-note") === false, "Installation Join Queue form must not render a payment section.");
repair_test_assert(strpos((string)$documentPrintingPage, "id=\"paymentSection\"") !== false, "Document Printing payment section must remain available.");

$sampleRule = ["option_value_keys" => ["device_type" => "phone", "repair_type" => "others"]];
servitech_pricing_validate_repair_selection($sampleRule, ["device_type_key" => "phone", "repair_type_key" => "others"]);
$mismatchRejected = false;
try {
    servitech_pricing_validate_repair_selection($sampleRule, ["device_type_key" => "desktop", "repair_type_key" => "others"]);
} catch (DomainException $e) {
    $mismatchRejected = true;
}
repair_test_assert($mismatchRejected, "Backend accepted a repair rule under the wrong device.");

if (getenv("SERVITECH_TEST_DATABASE") !== "1") {
    echo "Repair Join Queue unit tests passed. Database catalog check skipped (set SERVITECH_TEST_DATABASE=1 to enable)." . PHP_EOL;
    exit(0);
}

require_once __DIR__ . "/../config/db.php";

$service = servitech_catalog_fetch_service_by_kind($pdo, "repair", true);
repair_test_assert(is_array($service), "No active Repair service is available.");
$catalog = servitech_catalog_fetch($pdo, (int)$service["id"], true);

$devices = [];
foreach ($catalog["groups"] as $group) {
    if (($group["group_key"] ?? "") !== "device_type") continue;
    foreach ($group["values"] ?? [] as $value) {
        $devices[(string)$value["value_key"]] = (string)$value["label"];
    }
}
foreach (["phone" => "Phone", "laptop" => "Laptop", "desktop" => "Desktop"] as $key => $label) {
    repair_test_assert(($devices[$key] ?? "") === $label, "Active device {$label} is missing.");
}

$ruleCounts = array_fill_keys(array_keys($devices), 0);
$hasOthers = array_fill_keys(array_keys($devices), false);
foreach ($catalog["rules"] as $rule) {
    $deviceKey = (string)($rule["option_value_keys"]["device_type"] ?? "");
    $repairKey = (string)($rule["option_value_keys"]["repair_type"] ?? "");
    repair_test_assert($deviceKey !== "" && isset($devices[$deviceKey]), "An active rule has no active device.");
    repair_test_assert($repairKey !== "", "An active rule has no active Service Type.");
    $ruleCounts[$deviceKey]++;
    if (preg_match('/\bothers?\b/i', (string)($rule["option_labels"]["repair_type"] ?? ""))) {
        $hasOthers[$deviceKey] = true;
    }
}
foreach ($devices as $key => $label) {
    repair_test_assert(($ruleCounts[$key] ?? 0) > 0, "{$label} has no active repair services.");
    repair_test_assert(!empty($hasOthers[$key]), "{$label} has no active Others service.");
}

echo "Repair Join Queue tests passed. Active rules: " . json_encode($ruleCounts) . PHP_EOL;
