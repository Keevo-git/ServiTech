<?php

function manage_services_ui_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$page = file_get_contents(__DIR__ . "/../pages/super_admin/super_admin_service_management.php") ?: "";
$script = file_get_contents(__DIR__ . "/../pages/admin/Services/manage_services.js") ?: "";
$styles = file_get_contents(__DIR__ . "/../pages/admin/Services/manage_services.css") ?: "";
$api = file_get_contents(__DIR__ . "/../pages/super_admin/super_admin_services_api.php") ?: "";
$catalog = file_get_contents(__DIR__ . "/../api/service_catalog.php") ?: "";
$publicApi = file_get_contents(__DIR__ . "/../api/services_public.php") ?: "";
$pricing = file_get_contents(__DIR__ . "/../api/service_pricing.php") ?: "";
$printingOptions = file_get_contents(__DIR__ . "/../pages/customer/custo1_printing_option.php") ?: "";

foreach ([
    'id="msConfirmOverlay"',
    'role="alertdialog"',
    'aria-labelledby="msConfirmTitle"',
    'id="msConfirmCancel"',
    'id="msConfirmAccept"',
    'id="msConfirmX"',
    'aria-label="Close modal"',
] as $requiredMarkup) {
    manage_services_ui_assert(str_contains($page, $requiredMarkup), "Confirmation markup must include {$requiredMarkup}.");
}

manage_services_ui_assert(!str_contains($script, "window.confirm"), "Native browser confirmations must not be used.");
manage_services_ui_assert(substr_count($script, "await confirmAction({") >= 7, "Major Manage Services actions must use the accessible confirmation dialog.");
manage_services_ui_assert(str_contains($page, 'id="ms_active"'), "Service status must use the Active/Inactive toggle.");
manage_services_ui_assert(str_contains($page, 'id="ms_active_label"'), "Service status toggle must have a visible Active/Inactive label.");
manage_services_ui_assert(!str_contains($page, "ms-archive-button"), "Archive buttons must not appear in the Admin Edit Services UI.");
manage_services_ui_assert(!str_contains($script, "Archive Option"), "Archive-specific confirmation behavior must be removed.");
manage_services_ui_assert(!str_contains($styles, ".ms-archive-button"), "Archive button styles must be removed from Admin Edit Services.");
manage_services_ui_assert(!str_contains($api, '"archived" => true'), "Delete action must not archive services from Admin Edit Services.");
manage_services_ui_assert(str_contains($api, "Use the Active/Inactive toggle instead."), "Delete action must point admins to the Active/Inactive toggle.");
manage_services_ui_assert(!str_contains($page, "Legacy service"), "Manage Services must not show legacy service warnings.");
manage_services_ui_assert(!str_contains($page, "ms-legacy-note"), "Manage Services must not use legacy warning styling.");
manage_services_ui_assert(str_contains($page, "ms-setup-note"), "Unsupported services should use setup guidance, not legacy/archive wording.");

foreach ([
    "super_admin_service_management.php" => $page,
    "super_admin_services_api.php" => $api,
    "service_catalog.php" => $catalog,
    "services_public.php" => $publicApi,
    "service_pricing.php" => $pricing,
    "custo1_printing_option.php" => $printingOptions,
] as $fileName => $source) {
    manage_services_ui_assert(!str_contains($source, "archived_at"), "{$fileName} must not use archived_at for current service availability.");
}

foreach ([
    "Save Service Changes",
    "Activate Service",
    "Deactivate Service",
    "Activate Price Option",
    "Deactivate Price Option",
    "Deactivate Device",
    "Add Repair Service",
    "Add Installation Service",
    "Enable Device-Based Installation",
    "Existing submitted orders will keep their original saved details and price.",
    "syncServiceStatusLabel",
    "isConfirmOpen",
    "confirmClose",
    "isTopModal",
    "openModalLayer",
    "closeModalLayer",
    "closeTopModal",
    "serviceModalStack",
    "syncModalLayers",
    "captureScrollPositions",
    "restoreScrollPositions",
    'document.documentElement.classList.toggle("ms-modal-open"',
    'entry.dialog.setAttribute("aria-modal"',
    "updateServiceCard",
    "reportEditorOpenError",
    'event.target.closest("[data-ms-edit]")',
    "Invalid or unsupported service payload",
    'installation_type: "Installation Type", device_type: "Devices"',
    'data-action="toggle-active"',
    'class="ms-status-cell"',
    "resequenceCatalog",
    "This option is active, but it will not appear to customers until at least one active price combination is configured.",
    "char-counter",
    "service-field--compact",
    'data-limit-ui="off"',
] as $requiredBehavior) {
    manage_services_ui_assert(str_contains($script, $requiredBehavior), "Confirmation behavior must include {$requiredBehavior}.");
}

foreach ([
    'data-action="move-up"',
    'data-action="move-down"',
    'data-action="move-rule-up"',
    'data-action="move-rule-down"',
    'class="ms-arrange-cell"',
    "ms-rule-table__head",
    "ms-value-list__head",
    "Maximum ",
    " characters",
] as $removedServiceUi) {
    manage_services_ui_assert(!str_contains($page, $removedServiceUi), "Service Management page must not include old UI fragment {$removedServiceUi}.");
    manage_services_ui_assert(!str_contains($script, $removedServiceUi), "Manage Services script must not include old UI fragment {$removedServiceUi}.");
}

foreach ([
    "100dvh",
    ".ms-matrix-wrap{overflow:auto}",
    "@media(max-width:980px)",
    "@media(max-width:760px)",
    "@media(max-width:520px)",
    "@media(max-width:640px)",
    "right: calc(12px + env(safe-area-inset-right));",
    "top: calc(12px + env(safe-area-inset-top));",
    ".ms-confirm-overlay",
    "min-height:46px",
    "z-index: 2147483100",
    "z-index: 2147483300",
    ".ms-overlay.is-covered",
    "pointer-events: none",
    ".ms-overlay.is-open",
    ".ms-confirm-overlay.is-open",
    "html.ms-modal-open,body.ms-modal-open",
    ".ms-overlay.is-covered .ms-modal",
    "height: min(760px, calc(100dvh - 32px))",
    "flex: 1 1 0",
    "contain: paint",
    "transform: translateZ(0)",
    '.ms-switch>span[aria-hidden="true"]',
    ".ms-status-cell",
    ".char-counter",
    ".service-field--compact",
    "max-width: 360px",
] as $requiredStyle) {
    manage_services_ui_assert(str_contains($styles, $requiredStyle), "Responsive styles must include {$requiredStyle}.");
}

foreach ([
    ".ms-arrange-cell",
    ".ms-order-actions",
    ".ms-rule-table__head",
    ".ms-value-list__head",
    ".ms-inline-add input{max-width:360px}",
] as $removedStyle) {
    manage_services_ui_assert(!str_contains($styles, $removedStyle), "Responsive styles must not include removed arrangement/header selector {$removedStyle}.");
}

manage_services_ui_assert(substr_count($page, 'aria-label="Close modal"') >= 2, "Every Manage Services X button must use the same accessible close label.");
manage_services_ui_assert(str_contains($page, 'id="msOverlay" hidden'), "The editor overlay must start hidden and be opened only by the modal stack.");
manage_services_ui_assert(str_contains($page, 'id="msPageError"'), "The page must provide a visible error region when the editor cannot open.");
manage_services_ui_assert(str_contains($page, 'data-service-id='), "Edit buttons must expose the dynamic service identifier.");
manage_services_ui_assert(str_contains($page, 'data-service-category='), "Edit buttons must expose the dynamic service category.");
manage_services_ui_assert(str_contains($page, 'data-service-name='), "Edit buttons must expose the dynamic service name.");
manage_services_ui_assert(strpos($page, "admin_footer.php") < strpos($page, "manage_services.js"), "The global modal stack must load before Manage Services registers its handlers.");
manage_services_ui_assert(!str_contains($script, 'overlay.style.display'), "Modal visibility must not be managed with one-off inline display values.");
manage_services_ui_assert(!str_contains($script, "location.reload()"), "Saving must update the open editor instead of reloading and destroying modal state.");
manage_services_ui_assert(!str_contains($script, "ruleUsesInactiveOption"), "Parent option toggles must not destructively rewrite linked pricing-rule status.");
manage_services_ui_assert(!str_contains($script, ".inert = covered"), "Covered Manage Services editors must not apply inert to the entire deep scroll subtree.");
manage_services_ui_assert(!str_contains($script, "ms-status-control"), "Status text must not be rendered inside a switch where it can look like a duplicate toggle.");
manage_services_ui_assert(str_contains($catalog, "servitech_catalog_customer_rules_from_admin_catalog"), "Backend visibility must use the same active relationship rules as customer pages.");
manage_services_ui_assert(str_contains($api, '"catalog" => $savedCatalog'), "Save responses must re-read and return the committed catalog for an in-place editor refresh.");

echo "Manage Services UI tests passed.\n";
