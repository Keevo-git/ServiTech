<?php

require_once __DIR__ . "/../pages/super_admin/_includes/super_admin_analytics_data.php";

function super_analytics_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$migration = file_get_contents(__DIR__ . "/../database/migrations/20260630_add_table9_analytics_import.sql") ?: "";
$importer = file_get_contents(__DIR__ . "/../scripts/import_table9_dummy_analytics.php") ?: "";
$dataSource = file_get_contents(__DIR__ . "/../pages/super_admin/_includes/super_admin_analytics_data.php") ?: "";
$page = file_get_contents(__DIR__ . "/../pages/super_admin/super_admin_analytics.php") ?: "";
$dashboard = file_get_contents(__DIR__ . "/../pages/super_admin/super_admin_dashboard.php") ?: "";

super_analytics_assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS queue_status_events"), "Migration must create the status-events analytics table.");
super_analytics_assert(str_contains($migration, "request_created_at") && str_contains($migration, "request_source"), "Migration must add queue timestamp and request-source fields.");
super_analytics_assert(str_contains($migration, "queue_status_events_import_unique"), "Status event imports must have a stable duplicate-prevention key.");
super_analytics_assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS analytics_cycles"), "Migration must create monthly analytics cycles.");
super_analytics_assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS analytics_monthly_snapshots"), "Migration must create monthly snapshot storage.");
super_analytics_assert(str_contains($migration, "CREATE TABLE IF NOT EXISTS analytics_export_logs"), "Migration must create analytics export logs.");

super_analytics_assert(str_contains($importer, "ZipArchive") && !str_contains($importer, "PhpOffice"), "Importer must read XLSX without adding a spreadsheet dependency.");
super_analytics_assert(str_contains($importer, "Table9_After_Orders") && str_contains($importer, "Table9_Status_Events"), "Importer must read both required workbook sheets.");
super_analytics_assert(str_contains($importer, "WHERE queue_code = :queue_code") && !str_contains($importer, "INSERT INTO queues"), "Importer must match existing Queue IDs and avoid creating queue records.");
super_analytics_assert(str_contains($importer, "ON CONFLICT (queue_id, transition_no, status, entered_at)"), "Status events must be upserted idempotently.");
super_analytics_assert(str_contains($importer, "Missing queue IDs") && str_contains($importer, "Total rows read"), "Importer must print the requested execution summary.");

super_analytics_assert(str_contains($dataSource, "q.approved_at - COALESCE(q.request_created_at, q.created_at)"), "GCash waiting-time formula must use created-to-approved timestamps.");
super_analytics_assert(str_contains($dataSource, "q.ongoing_at - COALESCE(q.request_created_at, q.created_at)"), "Cash waiting-time formula must use created-to-ongoing timestamps.");
super_analytics_assert(str_contains($dataSource, "COALESCE(q.done_at, q.completed_at) - q.ongoing_at"), "Service processing time must use ongoing-to-done timestamps.");
super_analytics_assert(str_contains($dataSource, "queue_status_events e"), "Average status duration and timelines must use queue_status_events.");
super_analytics_assert(str_contains($dataSource, "super_analytics_fetch_cycle") && str_contains($dataSource, "cycle_start_date"), "Analytics queries must be scoped to a monthly cycle.");
super_analytics_assert(str_contains($dataSource, "super_analytics_record_export"), "CSV exports must be logged for cycle export reminders.");
super_analytics_assert(str_contains(super_analytics_service_label_sql(), "Document Printing") && str_contains(super_analytics_service_label_sql(), "Lamination"), "Service request analytics must normalize key service types.");

super_analytics_assert(str_contains($page, "servitech_require_super_admin();"), "Comprehensive analytics page must be Super Admin-only.");
super_analytics_assert(str_contains($page, "Date From") && str_contains($page, "Payment") && str_contains($page, "Source"), "Analytics page must expose the requested filters.");
super_analytics_assert(str_contains($page, "Average Queue Waiting Time") && str_contains($page, "Status Transition History"), "Analytics page must render queue/waiting analytics.");
super_analytics_assert(str_contains($page, "Requests by Service Type") && str_contains($page, "Most Requested Services"), "Analytics page must render service-request analytics.");
super_analytics_assert(str_contains($page, "Export CSV") && str_contains($page, "Export Excel") && str_contains($page, "Export PDF"), "Analytics page must provide export controls.");
super_analytics_assert(str_contains($page, "analytics-cycle-banner") && str_contains($page, "View Previous Cycles"), "Analytics page must show monthly cycle warnings and previous-cycle access.");
super_analytics_assert(!str_contains(strtolower($page), "peak-hour") && !str_contains(strtolower($page), "volume per hour"), "Excluded peak-hour analytics must not be rendered.");

super_analytics_assert(str_contains($dashboard, "Owner Reports & Analytics") && str_contains($dashboard, "super_admin_analytics.php") && str_contains($dashboard, "View All"), "Dashboard analytics section must link to the comprehensive page.");

$stateMachine = file_get_contents(__DIR__ . "/../api/queue_state_machine.php") ?: "";
$customerCancel = file_get_contents(__DIR__ . "/../api/queue_cancel_request.php") ?: "";
$cycleScript = file_get_contents(__DIR__ . "/../scripts/run_analytics_cycle.php") ?: "";
super_analytics_assert(str_contains($stateMachine, "servitech_record_queue_analytics_initial_status"), "New queues must automatically create initial analytics status events.");
super_analytics_assert(str_contains($stateMachine, "servitech_record_queue_analytics_transition"), "Admin status transitions must automatically update analytics events.");
super_analytics_assert(str_contains($customerCancel, "servitech_record_queue_analytics_transition"), "Customer cancellations must feed cancellation analytics.");
super_analytics_assert(str_contains($cycleScript, "analytics_monthly_snapshots") && str_contains($cycleScript, "Do not") === false, "Cycle script must snapshot ended cycles programmatically.");
super_analytics_assert(str_contains($cycleScript, "logs/analytics_cycle.log") && str_contains($cycleScript, "[7, 3, 1, 0]"), "Cycle script must log scheduled reset warnings.");

echo "Super Admin comprehensive analytics tests passed.\n";
