<?php

function operational_controls_source(string $path): string
{
    $source = file_get_contents(__DIR__ . "/../" . $path);
    if ($source === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    return $source;
}

function operational_controls_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$settings = operational_controls_source("pages/super_admin/super_admin_system_settings.php");
operational_controls_assert(!str_contains($settings, "SMTP Diagnostics"), "System Settings must not show the SMTP Diagnostics card.");
operational_controls_assert(!str_contains($settings, "Check password-reset email configuration without exposing SMTP secrets."), "System Settings must not show the SMTP diagnostic description.");
operational_controls_assert(!str_contains($settings, "super_admin_smtp_diagnostics.php"), "System Settings must not link to SMTP Diagnostics.");
operational_controls_assert(str_contains($settings, "Operational Controls"), "System Settings must show Operational Controls.");
operational_controls_assert(str_contains($settings, "super_admin_operational_controls.php"), "System Settings must link to Operational Controls.");

$page = operational_controls_source("pages/super_admin/super_admin_operational_controls.php");
operational_controls_assert(str_contains($page, "servitech_require_super_admin();"), "Operational Controls page must be Super Admin-only.");
operational_controls_assert(str_contains($page, "operational_controls_update"), "Operational Controls changes must be activity logged.");
operational_controls_assert(str_contains($page, "window.servitechAdminToast"), "Operational Controls page must use admin toasts.");
operational_controls_assert(str_contains($page, "data-operational-confirm"), "Operational Controls sensitive actions must use confirmation modals.");

$migration = operational_controls_source("database/migrations/20260627_add_operational_controls.sql");
foreach ([
    "operational_control_settings",
    "operational_service_settings",
    "operational_payment_method_settings",
    "servitech_is_super_admin",
    "all_services_closed",
    "manual_status",
    "payment_method_key",
] as $needle) {
    operational_controls_assert(str_contains($migration, $needle), "Migration must include {$needle}.");
}
operational_controls_assert(!str_contains(strtolower($migration), "drop table"), "Operational Controls migration must not drop tables.");

$helper = operational_controls_source("config/operational_controls.php");
operational_controls_assert(str_contains($helper, "Services are temporarily unavailable. Please check back later."), "Helper must return all-services closure message.");
operational_controls_assert(str_contains($helper, "This service is temporarily unavailable. Please try again later."), "Helper must return service closure message.");
operational_controls_assert(str_contains($helper, "This payment method is currently unavailable."), "Helper must return payment closure message.");

foreach ([
    "api/queue_create.php",
    "api/print_order_draft.php",
    "api/print_order_create.php",
    "api/service_payment_create.php",
] as $path) {
    $source = operational_controls_source($path);
    operational_controls_assert(str_contains($source, "operational_controls.php"), "{$path} must load operational controls.");
    operational_controls_assert(str_contains($source, "servitech_operational_assert_service_available"), "{$path} must enforce service availability.");
    operational_controls_assert(str_contains($source, "servitech_operational_assert_payment_method_available"), "{$path} must enforce payment-method availability.");
}

$publicApi = operational_controls_source("api/services_public.php");
operational_controls_assert(str_contains($publicApi, "servitech_operational_customer_service_unavailable"), "Public services API must filter manually closed services.");

foreach ([
    "pages/customer/customer_dash.php",
    "pages/customer/custo1_printing_option.php",
    "pages/customer/custo1_repair_option.php",
    "pages/customer/custo1_installation_option.php",
    "pages/customer/custo2_docu_printing.php",
    "pages/customer/custo2_xerox.php",
    "pages/customer/custo2_scanning.php",
    "pages/customer/custo2_laminating.php",
    "pages/customer/custo2_rush_id.php",
] as $path) {
    $source = operational_controls_source($path);
    operational_controls_assert(str_contains($source, "operational_controls.php"), "{$path} must load operational controls.");
}

foreach ([
    "pages/customer/custo2_docu_printing.php",
    "pages/customer/custo2_xerox.php",
    "pages/customer/custo2_scanning.php",
    "pages/customer/custo2_laminating.php",
    "pages/customer/custo2_rush_id.php",
] as $path) {
    $source = operational_controls_source($path);
    operational_controls_assert(str_contains($source, "servitech_operational_customer_payment_options"), "{$path} must render payment options from operational settings.");
}

echo "Operational controls static checks passed.\n";
