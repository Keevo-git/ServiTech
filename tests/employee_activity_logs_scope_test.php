<?php

function employee_activity_scope_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function employee_activity_scope_source(string $path): string
{
    return file_get_contents(__DIR__ . "/../" . $path) ?: "";
}

$page = employee_activity_scope_source("pages/super_admin/super_admin_employee_activity_logs.php");
$helper = employee_activity_scope_source("config/activity_log.php");

employee_activity_scope_assert(!str_contains($page, 'label for="action_type"'), "Action filter must not render.");
employee_activity_scope_assert(!str_contains($page, 'label for="module"'), "Module filter must not render.");
employee_activity_scope_assert(!str_contains($page, "<th>Action</th>"), "Action table column must not render.");
employee_activity_scope_assert(!str_contains($page, "<th>Module</th>"), "Module table column must not render.");

foreach ([
    "<th>Time</th>",
    "<th>Employee</th>",
    "<th>Description</th>",
    "<th>Status</th>",
] as $column) {
    employee_activity_scope_assert(str_contains($page, $column), "Latest Activity table must include {$column}.");
}

foreach ([
    'name="q"',
    'name="user_id"',
    'name="date_from"',
    'name="date_to"',
    ">Apply</button>",
    ">Clear</a>",
] as $filterMarkup) {
    employee_activity_scope_assert(str_contains($page, $filterMarkup), "Filter UI must keep {$filterMarkup}.");
}

foreach ([
    "super_admin_login_success",
    "admin_login_success",
    "logout",
    "order_status_update",
    "order_mark_done",
    "order_cancel",
    "queue_status_update",
    "customer_message_send",
    "payment_approve",
    "payment_reject",
    "employee_first_time_setup_complete",
    "admin_password_change",
] as $importantAction) {
    employee_activity_scope_assert(str_contains($helper, '"' . $importantAction . '"'), "Activity helper must allow {$importantAction}.");
}

foreach ([
    "store_settings_update",
    "store_holiday_update",
    "service_update",
    "employee_verification_email_sent",
    "employee_first_time_setup_failed",
    "admin_login_failed",
] as $noisyAction) {
    employee_activity_scope_assert(!preg_match('/"' . preg_quote($noisyAction, '/') . '"/', $helper), "Activity helper must not allow {$noisyAction}.");
}

employee_activity_scope_assert(str_contains($helper, "servitech_activity_should_store_event"), "Activity helper must gate storage through the allowlist.");
employee_activity_scope_assert(str_contains($page, "activity_readable_description"), "Employee Activity Logs page must map raw events to readable descriptions.");
employee_activity_scope_assert(str_contains($page, "LOWER(COALESCE(u.email, '')) LIKE :q"), "Search must include employee email.");
employee_activity_scope_assert(str_contains($page, 'l.action_type IN ({$allowedActionSql})'), "Query must limit activity logs to allowed actions.");

echo "Employee activity log scope checks passed.\n";
