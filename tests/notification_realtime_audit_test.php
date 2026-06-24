<?php

$failures = [];

function realtime_audit_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function realtime_audit_source(string $relativePath): string
{
    $source = file_get_contents(__DIR__ . "/../" . $relativePath);
    return is_string($source) ? $source : "";
}

$customerHeader = realtime_audit_source("components/header.php");
realtime_audit_assert(str_contains($customerHeader, "notificationPollMs = 4000"), "Customer notifications must retain a prompt polling fallback.");
realtime_audit_assert(str_contains($customerHeader, 'filter: "user_id=eq." + config.userId'), "Customer realtime subscription must be scoped to the signed-in user.");
realtime_audit_assert(str_contains($customerHeader, 'await loadNotifications();'), "Customer list and unread badge must refresh from one synchronized snapshot.");
realtime_audit_assert(str_contains($customerHeader, 'refreshNotifications();') && str_contains($customerHeader, 'function openDropdown()'), "Opening the customer panel must refresh stale data.");
realtime_audit_assert(str_contains($customerHeader, 'window.addEventListener("pagehide"'), "Customer polling/subscriptions must be cleaned up during navigation.");
realtime_audit_assert(str_contains($customerHeader, "alreadyRendered"), "Customer realtime inserts must not increment the badge twice for an existing item.");

$adminCenter = realtime_audit_source("pages/admin/_includes/admin_notification_center.php");
$adminSnapshot = realtime_audit_source("pages/admin/_includes/admin_notification_snapshot.php");
realtime_audit_assert(str_contains($adminCenter, "notificationPollMs = 5000"), "Admin notifications must poll promptly on every shared-header page.");
realtime_audit_assert(str_contains($adminCenter, "refreshNotifications(true)") && str_contains($adminCenter, "function openOverlay()"), "Opening the admin panel must fetch the latest snapshot.");
realtime_audit_assert(str_contains($adminCenter, 'document.addEventListener("visibilitychange"'), "Admin notifications must refresh when a hidden page becomes active.");
realtime_audit_assert(str_contains($adminCenter, 'window.addEventListener("pagehide"'), "Admin polling must be cleaned up during navigation.");
realtime_audit_assert(str_contains($adminCenter, "selectedIds.forEach") && str_contains($adminCenter, "template.content"), "Admin live list replacement must preserve valid selections.");
realtime_audit_assert(str_contains($adminCenter, "notificationMutated();"), "Read/delete mutations must reconcile with the server snapshot.");
realtime_audit_assert(str_contains($adminSnapshot, "admin_notification_center_data") && str_contains($adminSnapshot, "admin_notification_render_items"), "Admin badge, categories, and list must come from one authenticated snapshot.");

$tableSnapshot = realtime_audit_source("pages/admin/_includes/admin_realtime_snapshot.php");
$tablePoller = realtime_audit_source("pages/admin/queue_list/realtime-polling.js");
realtime_audit_assert(!str_contains($tableSnapshot, '"notification_count"'), "Queue table polling must not duplicate notification count work.");
realtime_audit_assert(!str_contains($tablePoller, "syncNotificationBadge"), "Only the shared notification controller should own the admin badge.");

$eventSources = [
    "api/queue_create.php" => ["customer_new_queue", "admin_new_order"],
    "api/queue_state_machine.php" => ["customer_status_change", "customer_send_back", "admin_cancelled"],
    "api/queue_cancel_request.php" => ["queue_cancelled", "admin_cancelled:customer"],
    "auth/registration_notifications.php" => ["new_customer_registration"],
    "api/print_order_create.php" => ["admin_new_order_payment_review"],
    "api/service_payment_create.php" => ["customer_gcash_review_submitted", "admin_new_order_payment_review"],
];
foreach ($eventSources as $path => $needles) {
    $source = realtime_audit_source($path);
    foreach ($needles as $needle) {
        realtime_audit_assert(str_contains($source, $needle), "Missing notification trigger {$needle} in {$path}.");
    }
}

$customerPages = [
    "pages/customer/customer_dash.php",
    "pages/customer/custo_service_status.php",
    "pages/customer/custo_edit_profile.php",
    "pages/customer/custo_queue_monitor.php",
    "pages/customer/custo1_printing_option.php",
];
foreach ($customerPages as $path) {
    realtime_audit_assert(str_contains(realtime_audit_source($path), "components/header.php"), "Customer shared notifications are missing from {$path}.");
}

$adminPages = [
    "pages/admin/admin_dashboard.php",
    "pages/admin/queue_list/printing.php",
    "pages/admin/order_management/printM.php",
    "pages/admin/store_availability.php",
    "pages/admin/announcement.php",
    "pages/admin/customer_list/custoL.php",
];
foreach ($adminPages as $path) {
    realtime_audit_assert(str_contains(realtime_audit_source($path), "admin_header.php"), "Admin shared notifications are missing from {$path}.");
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Notification realtime audit tests passed.\n";
