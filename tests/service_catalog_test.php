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

echo "Service catalog tests passed.\n";
