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
$views = file_get_contents(__DIR__ . "/../pages/super_admin/_includes/super_admin_analytics_views.php") ?: "";
$landing = file_get_contents(__DIR__ . "/../pages/super_admin/super_admin_analytics.php") ?: "";
$css = file_get_contents(__DIR__ . "/../pages/super_admin/super_admin_analytics.css") ?: "";
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
super_analytics_assert(str_contains($dataSource, "queue_status_events e"), "Average status duration and timelines must use queue_status_events.");
super_analytics_assert(str_contains($dataSource, "super_analytics_fetch_cycle") && str_contains($dataSource, "cycle_start_date"), "Analytics queries must be scoped to a monthly cycle.");
super_analytics_assert(str_contains($dataSource, "super_analytics_record_export"), "CSV exports must be logged for cycle export reminders.");
super_analytics_assert(str_contains($dataSource, "detailed_records"), "Combined service and queue report must have detailed queue-level records.");
super_analytics_assert(str_contains(super_analytics_service_label_sql(), "Document Printing") && str_contains(super_analytics_service_label_sql(), "Lamination"), "Service request analytics must normalize key service types.");
super_analytics_assert(str_contains($dataSource, "staff_id") && str_contains($dataSource, "request_source"), "Analytics data source must support request-source and staff filters.");
super_analytics_assert(str_contains($dataSource, "notifications") && str_contains($dataSource, "store_availability_settings"), "Analytics data source must include notification and store availability category data.");

super_analytics_assert(str_contains($landing, "servitech_require_super_admin();"), "Analytics landing page must be Super Admin-only.");
super_analytics_assert(str_contains($landing, "A simplified reporting center for reviewing ServiTech operations, service requests, queue performance, workflow progress, and monthly analytics exports."), "Landing page must use the simplified requested subtitle.");
super_analytics_assert(str_contains($landing, "analytics_render_landing_cards"), "Landing page must show category cards only.");
super_analytics_assert(!str_contains($landing, "Status Transition History") && !str_contains($landing, "Requests by Service Type"), "Landing page must not stack full report details.");
super_analytics_assert(!str_contains($landing, "analytics-loading-shell"), "Landing page must not render skeleton/loading placeholder rows.");
super_analytics_assert(str_contains($views, "Open Report") && str_contains($views, "analytics-report-card"), "Category cards must use Open Report buttons and professional report-card markup.");
super_analytics_assert(str_contains($views, "Service Requests & Queue Performance"), "Service request and queue analytics must be combined into one report category.");
super_analytics_assert(!str_contains($views, "Service Request Analytics\"") && !str_contains($views, "Queue and Waiting Time Analytics\""), "Old separate service/queue category titles must not remain in the category list.");
super_analytics_assert(!str_contains($views, '"Average processing time"'), "Service and queue landing card must not preview Average Processing Time.");
super_analytics_assert(!str_contains($views, "Days before reset") && !str_contains($views, "Days Before Reset") && !str_contains($views, "Reset day") && !str_contains($views, "will reset soon"), "Analytics UI must not show reset countdown or reset warning language.");
super_analytics_assert(str_contains($views, "Analytics reset is currently disabled.") && str_contains($views, "Export functions remain available for reporting and backup purposes."), "Export center must show the reset-disabled notice.");
foreach ([
    "super_admin_analytics_completion.php",
    "super_admin_analytics_staff.php",
    "super_admin_analytics_notifications.php",
    "super_admin_analytics_quality.php",
    "super_admin_analytics_availability.php",
] as $hiddenRoute) {
    super_analytics_assert(!str_contains($views, '"route" => "' . $hiddenRoute . '"'), "{$hiddenRoute} must be hidden from the landing categories.");
}
super_analytics_assert(!str_contains($views, "<span>Payment</span>") && !str_contains($views, "payment_method"), "Payment analytics and payment filters must be excluded from the UI layer.");
super_analytics_assert(!str_contains(strtolower($landing . $views), "customer satisfaction"), "Customer Satisfaction Analytics must be excluded for now.");
super_analytics_assert(!str_contains(strtolower($landing . $views), "peak-hour") && !str_contains(strtolower($landing . $views), "volume per hour") && !str_contains(strtolower($landing . $views), "heatmap"), "Excluded queue-hour and heatmap analytics must not be rendered.");

$reportPages = [
    "super_admin_analytics_operations.php" => "Operations Overview",
    "super_admin_analytics_service_queue.php" => "Service Requests & Queue Performance",
    "super_admin_analytics_workflow.php" => "Status Tracking & Workflow",
    "super_admin_analytics_exports.php" => "Analytics Export Center",
];

foreach ($reportPages as $file => $title) {
    $path = __DIR__ . "/../pages/super_admin/" . $file;
    $page = file_get_contents($path) ?: "";
    super_analytics_assert(is_file($path), "{$file} must exist.");
    super_analytics_assert(str_contains($landing . $views, $file), "Landing category cards must route to {$file}.");
    super_analytics_assert(str_contains($page, "servitech_require_super_admin();"), "{$file} must be Super Admin-only.");
    super_analytics_assert(str_contains($page, $title), "{$file} must render the {$title} title.");
    super_analytics_assert(str_contains($page . $views, "Back to Analytics"), "{$file} must provide back navigation.");
    super_analytics_assert(str_contains($page, "analytics_render_filters"), "{$file} must expose report filters.");
    super_analytics_assert(str_contains($page, "analytics_render_export_row"), "{$file} must include export controls.");
}

$serviceQueue = file_get_contents(__DIR__ . "/../pages/super_admin/super_admin_analytics_service_queue.php") ?: "";
$serviceQueueFunctionStart = strpos($views, "function analytics_render_service_queue");
$serviceQueueFunctionEnd = strpos($views, "function analytics_render_workflow");
$serviceQueueFunction = ($serviceQueueFunctionStart !== false && $serviceQueueFunctionEnd !== false)
    ? substr($views, $serviceQueueFunctionStart, $serviceQueueFunctionEnd - $serviceQueueFunctionStart)
    : "";
super_analytics_assert(str_contains($views, "Service Demand") && str_contains($views, "Queue Performance") && str_contains($views, "Status Distribution") && str_contains($views, "Detailed Records"), "Combined report must include the requested sub-sections.");
super_analytics_assert(str_contains($views, "Queue Waiting Time"), "Combined report must show queue waiting metrics.");
super_analytics_assert(!str_contains($serviceQueueFunction, "Average Service Processing Time") && !str_contains($serviceQueueFunction, "Service Processing Time") && !str_contains($serviceQueueFunction, "service_processing_minutes"), "Service and queue report UI must not show service processing time.");
super_analytics_assert(str_contains($views, "Requests by Week") && str_contains($views, "Requests by Month") && str_contains($views, "Completed vs Cancelled Requests"), "Combined report must include weekly/monthly trends and completion mix.");
super_analytics_assert(str_contains($serviceQueue, "analytics_render_service_queue"), "Combined route must render the service queue report function.");

super_analytics_assert(str_contains($css, ".analytics-card-grid") && str_contains($css, "grid-template-columns: repeat(2"), "Landing page must use a 2-column desktop category card grid.");
super_analytics_assert(str_contains($css, ".analytics-report-card") && str_contains($css, ".analytics-open-report"), "CSS must style professional category cards and Open Report buttons.");
super_analytics_assert(str_contains($css, ".analytics-metric-card") && str_contains($css, ".analytics-filter-bar"), "CSS must style metric cards and filter bars.");
super_analytics_assert(!str_contains($css, ".analytics-loading-shell") && str_contains($css, "[hidden]"), "CSS must not reserve space for hidden loading placeholders.");
super_analytics_assert(str_contains($css, "@media (max-width: 880px)") && str_contains($css, "@media (max-width: 620px)"), "Analytics UI must include tablet and mobile responsive rules.");

super_analytics_assert(str_contains($dashboard, "Owner Reports & Analytics") && str_contains($dashboard, "super_admin_analytics.php") && str_contains($dashboard, "View All"), "Dashboard analytics section must link to the categorized landing page.");

$stateMachine = file_get_contents(__DIR__ . "/../api/queue_state_machine.php") ?: "";
$customerCancel = file_get_contents(__DIR__ . "/../api/queue_cancel_request.php") ?: "";
$cycleScript = file_get_contents(__DIR__ . "/../scripts/run_analytics_cycle.php") ?: "";
super_analytics_assert(str_contains($stateMachine, "servitech_record_queue_analytics_initial_status"), "New queues must automatically create initial analytics status events.");
super_analytics_assert(str_contains($stateMachine, "servitech_record_queue_analytics_transition"), "Admin status transitions must automatically update analytics events.");
super_analytics_assert(str_contains($customerCancel, "servitech_record_queue_analytics_transition"), "Customer cancellations must feed cancellation analytics.");
super_analytics_assert(str_contains($cycleScript, '"reset_disabled" => true'), "Analytics lifecycle script must be disabled for now.");
super_analytics_assert(!str_contains($cycleScript, "analytics_monthly_snapshots") && !str_contains($cycleScript, "UPDATE analytics_cycles") && !str_contains($cycleScript, "INSERT INTO analytics_cycles"), "Disabled lifecycle script must not snapshot, archive, or create cycles.");

echo "Super Admin categorized analytics tests passed.\n";
