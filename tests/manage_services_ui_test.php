<?php

function manage_services_ui_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$page = file_get_contents(__DIR__ . "/../pages/admin/Services/edit_services.php") ?: "";
$script = file_get_contents(__DIR__ . "/../pages/admin/Services/manage_services.js") ?: "";
$styles = file_get_contents(__DIR__ . "/../pages/admin/Services/manage_services.css") ?: "";

foreach ([
    'id="msConfirmOverlay"',
    'role="alertdialog"',
    'aria-labelledby="msConfirmTitle"',
    'id="msConfirmCancel"',
    'id="msConfirmAccept"',
] as $requiredMarkup) {
    manage_services_ui_assert(str_contains($page, $requiredMarkup), "Confirmation markup must include {$requiredMarkup}.");
}

manage_services_ui_assert(!str_contains($script, "window.confirm"), "Native browser confirmations must not be used.");
manage_services_ui_assert(substr_count($script, "confirmAction({") >= 8, "Major Manage Services actions must use the accessible confirmation dialog.");

foreach ([
    "Save Service Changes",
    "Activate Option",
    "Deactivate Option",
    "Archive Option",
    "Add Repair Service",
    "Add Installation Service",
    "Enable Device-Based Installation",
    "Existing submitted orders will keep their original saved details and price.",
] as $requiredBehavior) {
    manage_services_ui_assert(str_contains($script, $requiredBehavior), "Confirmation behavior must include {$requiredBehavior}.");
}

foreach ([
    "100dvh",
    ".ms-matrix-wrap{overflow:auto}",
    "@media(max-width:980px)",
    "@media(max-width:760px)",
    "@media(max-width:520px)",
    ".ms-archive-button",
    ".ms-confirm-overlay",
    "min-height:46px",
] as $requiredStyle) {
    manage_services_ui_assert(str_contains($styles, $requiredStyle), "Responsive styles must include {$requiredStyle}.");
}

echo "Manage Services UI tests passed.\n";
