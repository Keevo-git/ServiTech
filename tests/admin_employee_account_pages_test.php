<?php

function admin_employee_pages_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function admin_employee_pages_source(string $path): string
{
    return file_get_contents(__DIR__ . "/../" . $path) ?: "";
}

$header = admin_employee_pages_source("pages/admin/_includes/admin_header.php");
$dashboard = admin_employee_pages_source("pages/admin/admin_dashboard.php");
$customerList = admin_employee_pages_source("pages/admin/customer_list/custoL.php");
$customerDetails = admin_employee_pages_source("pages/admin/customer_list/customer_details.php");
$profile = admin_employee_pages_source("pages/admin/admin_profile.php");

admin_employee_pages_assert(str_contains($header, '["label" => "Home", "href" => "/pages/admin/admin_dashboard.php"'), "Admin header must include Home linked to the employee dashboard.");
admin_employee_pages_assert(str_contains($header, '["label" => "Services", "href" => "/index.php"'), "Admin header must include Services linked to the public site.");
foreach ([
    '["label" => "Orders"',
    '["label" => "Queue"',
    '["label" => "Customers"',
    "admin-role-badge",
] as $removedHeaderItem) {
    admin_employee_pages_assert(!str_contains($header, $removedHeaderItem), "Admin header must not expose {$removedHeaderItem}.");
}

admin_employee_pages_assert(str_contains($header, "admin-notification-btn"), "Admin header must keep the notification button.");
admin_employee_pages_assert(str_contains($header, "admin-logout-link"), "Admin header must keep the logout link.");

admin_employee_pages_assert(!str_contains($dashboard, "Messages & Notifications"), "Dashboard quick access must not include Messages & Notifications.");
foreach (["Today's Queue", "Active Orders", "Customer Lookup", "My Profile"] as $quickCard) {
    admin_employee_pages_assert(str_contains($dashboard, $quickCard), "Dashboard quick access must keep {$quickCard}.");
}

foreach ([
    "pages/admin/admin_dashboard.php" => $dashboard,
    "pages/admin/customer_list/custoL.php" => $customerList,
    "pages/admin/admin_profile.php" => $profile,
    "pages/admin/order_management/printM.php" => admin_employee_pages_source("pages/admin/order_management/printM.php"),
    "pages/admin/order_management/repairM.php" => admin_employee_pages_source("pages/admin/order_management/repairM.php"),
    "pages/admin/order_management/installationM.php" => admin_employee_pages_source("pages/admin/order_management/installationM.php"),
    "pages/admin/queue_list/printing.php" => admin_employee_pages_source("pages/admin/queue_list/printing.php"),
    "pages/admin/queue_list/repair.php" => admin_employee_pages_source("pages/admin/queue_list/repair.php"),
    "pages/admin/queue_list/installation.php" => admin_employee_pages_source("pages/admin/queue_list/installation.php"),
] as $path => $source) {
    admin_employee_pages_assert(str_contains($source, "servitech_admin_employee_banner_title"), "{$path} must use the shared employee greeting helper.");
}

admin_employee_pages_assert(!str_contains($customerList, "data-details-url"), "Employee Customer List rows must not have detail URLs.");
admin_employee_pages_assert(!str_contains($customerList, "row.dataset.detailsUrl"), "Employee Customer List rows must not navigate to details on click.");
admin_employee_pages_assert(str_contains($customerList, '$adminCanViewCustomerDetails = servitech_is_super_admin();'), "Customer details action must be Super Admin-only on the list.");
admin_employee_pages_assert(str_contains($customerDetails, "servitech_require_super_admin();"), "Customer details endpoint must block Admin/Employee direct access.");

echo "Admin/Employee account page checks passed.\n";
