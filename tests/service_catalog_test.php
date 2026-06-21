<?php
require_once __DIR__ . "/../api/service_catalog.php";
require_once __DIR__ . "/../api/service_pricing.php";

function catalog_test_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$service = ["category" => "printing", "name" => "Scanning"];
catalog_test_assert(servitech_catalog_service_kind($service) === "scanning", "Scanning must resolve to its catalog kind.");
catalog_test_assert(servitech_pricing_service_kind("printing", "Scan") === "scanning", "Scan alias must resolve for backend pricing.");
catalog_test_assert(servitech_catalog_group_contract("scanning") === ["paper_size" => "Paper Size"], "Scanning must expose only Paper Size.");
catalog_test_assert(servitech_catalog_expected_rule_groups("scanning", ["paper_size" => "a4"]), "Paper-size pricing rules must be accepted.");
catalog_test_assert(!servitech_catalog_expected_rule_groups("scanning", ["paper_size" => "a4", "color_option" => "bw"]), "Extra Scanning option groups must be rejected.");

$normalized = servitech_catalog_normalize_admin_payload($service, [
    "groups" => [[
        "group_key" => "paper_size",
        "name" => "Anything",
        "values" => [["value_key" => "letter", "label" => "Letter", "active" => 1]],
    ]],
    "rules" => [[
        "rule_key" => "letter",
        "option_value_keys" => ["paper_size" => "letter"],
        "price" => 10,
        "price_type" => "fixed",
        "active" => 1,
    ]],
]);
catalog_test_assert(count($normalized["groups"]) === 1, "Scanning must retain exactly one option group.");
catalog_test_assert($normalized["groups"][0]["name"] === "Paper Size", "The fixed group label must be normalized.");

$rejected = false;
try {
    servitech_catalog_normalize_admin_payload($service, [
        "groups" => [["group_key" => "color_option", "name" => "Color", "values" => []]],
        "rules" => [],
    ]);
} catch (DomainException $e) {
    $rejected = true;
}
catalog_test_assert($rejected, "Generic category creation must be rejected for Scanning.");

function catalog_test_value(string $key, string $label): array {
    return ["value_key" => $key, "label" => $label, "active" => 1];
}

$documentCatalog = servitech_catalog_normalize_admin_payload(
    ["category" => "printing", "name" => "Document Printing"],
    [
        "groups" => [
            ["group_key" => "paper_size", "values" => [catalog_test_value("letter", "Letter"), catalog_test_value("a3", "A3")]],
            ["group_key" => "color_option", "values" => [catalog_test_value("full", "Full Colored"), catalog_test_value("bw", "Black and White")]],
        ],
        "rules" => [],
    ]
);
catalog_test_assert(count($documentCatalog["rules"]) === 4, "Every active paper/color combination must be created.");

$documentWithDuplicate = $documentCatalog;
$documentWithDuplicate["rules"][] = $documentWithDuplicate["rules"][0];
$deduplicated = servitech_catalog_normalize_admin_payload(
    ["category" => "printing", "name" => "Document Printing"],
    $documentWithDuplicate
);
catalog_test_assert(count($deduplicated["rules"]) === 4, "Duplicate pricing combinations must be removed.");

$photocopyCatalog = servitech_catalog_normalize_admin_payload(
    ["category" => "printing", "name" => "Photocopy"],
    [
        "groups" => [
            ["group_key" => "paper_size", "values" => [catalog_test_value("letter", "Letter"), catalog_test_value("a4", "A4")]],
            ["group_key" => "color_option", "values" => [catalog_test_value("colored", "Colored"), catalog_test_value("gray", "Gray Scale")]],
        ],
        "rules" => [],
    ]
);
catalog_test_assert(count($photocopyCatalog["rules"]) === 4, "Photocopy must create its complete paper/color matrix.");

foreach ([
    [["category" => "printing", "name" => "Rush ID"], "package", "package_7", "Package 7"],
    [["category" => "printing", "name" => "Rush ID"], "addon", "retouch", "Photo Retouch"],
    [["category" => "printing", "name" => "Laminating"], "lamination_type", "matte", "Matte"],
    [["category" => "printing", "name" => "Scanning"], "paper_size", "a3", "A3"],
    [["category" => "installation", "name" => "Installation Services"], "installation_type", "drivers", "Driver Installation"],
] as [$simpleService, $groupKey, $valueKey, $label]) {
    $simple = servitech_catalog_normalize_admin_payload($simpleService, [
        "groups" => [["group_key" => $groupKey, "values" => [catalog_test_value($valueKey, $label)]]],
        "rules" => [],
    ]);
    catalog_test_assert(count($simple["rules"]) === 1, "{$label} must receive a pricing rule automatically.");
}

$deviceInstallation = servitech_catalog_normalize_admin_payload(
    ["category" => "installation", "name" => "Installation Services"],
    [
        "groups" => [
            ["group_key" => "installation_type", "values" => [catalog_test_value("windows", "Windows Installation")]],
            ["group_key" => "device_type", "active" => 1, "values" => [catalog_test_value("laptop", "Laptop")]],
        ],
        "rules" => [[
            "rule_key" => "laptop_windows",
            "option_value_keys" => ["device_type" => "laptop", "installation_type" => "windows"],
            "price" => 1000,
            "price_type" => "fixed",
            "active" => 1,
        ]],
    ]
);
catalog_test_assert(count($deviceInstallation["rules"]) === 1, "Device-mode Installation must retain only explicitly configured combinations.");
catalog_test_assert((int)$deviceInstallation["groups"][1]["active"] === 1, "Installation Device mode must remain enabled.");

$emptyDeviceModeRejected = false;
try {
    servitech_catalog_normalize_admin_payload(
        ["category" => "installation", "name" => "Installation Services"],
        [
            "groups" => [
                ["group_key" => "installation_type", "values" => []],
                ["group_key" => "device_type", "active" => 1, "values" => [catalog_test_value("tablet", "Tablet")]],
            ],
            "rules" => [],
        ]
    );
} catch (DomainException $e) {
    $emptyDeviceModeRejected = true;
}
catalog_test_assert($emptyDeviceModeRejected, "Installation Device mode must not be saved without an active device service.");

$blankFixedPriceRejected = false;
try {
    servitech_catalog_normalize_admin_payload(
        ["category" => "printing", "name" => "Scanning"],
        [
            "groups" => [["group_key" => "paper_size", "values" => [catalog_test_value("letter", "Letter")]]],
            "rules" => [[
                "rule_key" => "letter",
                "option_value_keys" => ["paper_size" => "letter"],
                "price" => "",
                "price_type" => "fixed",
                "active" => 1,
            ]],
        ]
    );
} catch (DomainException $e) {
    $blankFixedPriceRejected = true;
}
catalog_test_assert($blankFixedPriceRejected, "Backend validation must reject an active fixed option without a price.");

$repairCatalog = servitech_catalog_normalize_admin_payload(
    ["category" => "repair", "name" => "Device Repair"],
    [
        "groups" => [
            ["group_key" => "device_type", "values" => [catalog_test_value("tablet", "Tablet")]],
            ["group_key" => "repair_type", "values" => [catalog_test_value("lcd", "LCD Replacement"), catalog_test_value("others", "Others")]],
        ],
        "rules" => [[
            "rule_key" => "tablet_lcd",
            "option_value_keys" => ["device_type" => "tablet", "repair_type" => "lcd"],
            "price" => 1500,
            "price_type" => "fixed",
            "active" => 1,
        ]],
    ]
);
catalog_test_assert(count($repairCatalog["rules"]) === 1, "Repair must retain only explicitly configured device/service combinations.");
catalog_test_assert(servitech_pricing_is_other_request("Tablet / Others"), "Others detection must require a customer description.");

$adminEditorSource = file_get_contents(__DIR__ . "/../pages/admin/Services/manage_services.js") ?: "";
foreach (["data-new-description", "data-new-price", "data-add-repair", "data-installation-device-mode", "data-add-installation"] as $requiredControl) {
    catalog_test_assert(str_contains($adminEditorSource, $requiredControl), "Admin editor must include {$requiredControl} guidance/control.");
}

echo "Service catalog tests passed.\n";
