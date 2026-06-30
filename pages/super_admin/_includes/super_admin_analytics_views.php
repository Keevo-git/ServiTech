<?php
require_once __DIR__ . "/super_admin_analytics_data.php";

function analytics_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function analytics_minutes($value): string
{
    if ($value === null || $value === "") {
        return "-";
    }
    return number_format((float)$value, 2) . " min";
}

function analytics_number($value): string
{
    return number_format((int)($value ?? 0));
}

function analytics_percent($value): string
{
    return number_format((float)($value ?? 0), 2) . "%";
}

function analytics_datetime($value): string
{
    $value = trim((string)$value);
    if ($value === "") {
        return "-";
    }

    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone("Asia/Manila"))->format("M d, Y h:i A");
    } catch (Throwable) {
        return "-";
    }
}

function analytics_label(string $value): string
{
    $value = str_replace(["_", "-"], " ", trim($value));
    return $value === "" ? "-" : ucwords(strtolower($value));
}

function analytics_filter_query(array $filters, array $extra = []): string
{
    $allowed = ["cycle_id", "start_date", "end_date", "service_type", "status", "request_source", "staff_id"];
    $query = [];
    foreach (array_merge($filters, $extra) as $key => $value) {
        if (!in_array((string)$key, $allowed, true) && $key !== "export") {
            continue;
        }
        if (trim((string)$value) !== "") {
            $query[$key] = $value;
        }
    }
    return $query ? "?" . http_build_query($query) : "";
}

function analytics_report_url(string $page, array $filters = [], array $extra = []): string
{
    return admin_url("/pages/super_admin/" . $page . analytics_filter_query($filters, $extra));
}

function analytics_default_state(): array
{
    return [
        "summary" => [],
        "longest_waiting_request" => [],
        "shortest_waiting_request" => [],
        "longest_waiting_requests" => [],
        "delayed_requests" => [],
        "stale_requests" => [],
        "status_distribution" => [],
        "status_durations" => [],
        "requests_by_service" => [],
        "requests_by_period" => ["day" => [], "week" => [], "month" => []],
        "request_source_mix" => [],
        "completed_vs_cancelled" => [],
        "service_completion" => [],
        "completion_extremes" => [],
        "cancellation_reasons" => [],
        "workflow_routes" => [],
        "incomplete_timestamps" => [],
        "history" => [],
        "detailed_records" => [],
        "staff" => ["available" => false, "rows" => [], "message" => "Staff workload analytics will appear once staff handling data is available."],
        "notifications" => ["summary" => [], "by_type" => [], "latest_status_updates" => [], "failed_logs" => []],
        "corrections" => ["correction_requests" => 0, "by_service" => [], "missing_details" => [], "activity" => []],
        "store" => ["available" => false, "settings" => [], "hours" => [], "holidays" => [], "changes" => [], "most_active_service_day" => [], "blocked_requests" => 0],
        "cycle_center" => ["previous_cycles" => [], "export_logs" => []],
        "most_requested_service" => ["service_label" => "-", "total" => 0],
        "options" => ["services" => [], "statuses" => [], "request_sources" => [], "staff" => [], "cycles" => []],
        "cycle" => [],
        "cycle_days_remaining" => 0,
        "cycle_warning_level" => "",
        "cycle_export_status" => ["exported" => false, "exported_at" => null, "export_type" => ""],
    ];
}

function analytics_load_context(PDO $pdo, array $source): array
{
    $filters = super_analytics_clean_filter($source);
    $analytics = analytics_default_state();
    $ready = false;
    $error = "";

    try {
        if (!super_analytics_schema_ready($pdo)) {
            throw new RuntimeException("Analytics schema is not installed. Run the Table 9 analytics migration first.");
        }
        $analytics = super_analytics_fetch($pdo, $filters);
        $filters = $analytics["filters"] ?? $filters;
        $ready = true;
    } catch (Throwable $exception) {
        error_log("super admin analytics page error: " . $exception->getMessage());
        $error = $exception->getMessage();
    }

    return [
        "ready" => $ready,
        "error" => $error,
        "filters" => $filters,
        "analytics" => $analytics,
        "summary" => $analytics["summary"] ?? [],
        "options" => $analytics["options"] ?? [],
        "cycle" => $analytics["cycle"] ?? [],
        "days_remaining" => (int)($analytics["cycle_days_remaining"] ?? 0),
        "warning_level" => (string)($analytics["cycle_warning_level"] ?? ""),
        "export_status" => $analytics["cycle_export_status"] ?? [],
    ];
}

function analytics_status_rows(array $analytics): array
{
    $rows = [];
    foreach (($analytics["status_distribution"] ?? []) as $status => $total) {
        $rows[] = ["label" => $status, "total" => $total];
    }
    return $rows;
}

function analytics_status_duration_value(array $durations, string $status)
{
    foreach ($durations as $row) {
        if ((string)($row["status"] ?? "") === $status) {
            return $row["avg_minutes"] ?? null;
        }
    }
    return null;
}

function analytics_render_bars(array $rows, string $labelKey, string $valueKey, string $emptyMessage = "No analytics data available for this category yet."): void
{
    if (!$rows) {
        echo '<p class="analytics-empty">' . analytics_h($emptyMessage) . '</p>';
        return;
    }

    $max = 1;
    foreach ($rows as $row) {
        $max = max($max, (float)($row[$valueKey] ?? 0));
    }

    foreach ($rows as $row) {
        $label = (string)($row[$labelKey] ?? "-");
        $value = (float)($row[$valueKey] ?? 0);
        $width = $value > 0 ? max(5, (int)round(($value / $max) * 100)) : 0;
        $formatted = number_format($value, $value === floor($value) ? 0 : 2);
        echo '<div class="analytics-bar-row">';
        echo '<div class="analytics-bar-row__meta"><span>' . analytics_h($label) . '</span><strong>' . analytics_h($formatted) . '</strong></div>';
        echo '<div class="analytics-bar-track"><span style="width: ' . $width . '%"></span></div>';
        echo '</div>';
    }
}

function analytics_render_metric_grid(array $metrics): void
{
    echo '<section class="analytics-metric-grid" aria-label="Key metrics">';
    foreach ($metrics as $metric) {
        echo '<article class="analytics-metric-card">';
        echo '<span>' . analytics_h($metric["label"] ?? "") . '</span>';
        echo '<strong>' . analytics_h($metric["value"] ?? "-") . '</strong>';
        if (trim((string)($metric["note"] ?? "")) !== "") {
            echo '<small>' . analytics_h($metric["note"]) . '</small>';
        }
        echo '</article>';
    }
    echo '</section>';
}

function analytics_render_empty(string $message = "No analytics data available for this category yet."): void
{
    echo '<article class="analytics-empty-card"><strong>No data to display</strong><p>' . analytics_h($message) . '</p></article>';
}

function analytics_render_cycle_banner(array $context): void
{
    $cycle = $context["cycle"] ?? [];
    $warningLevel = (string)($context["warning_level"] ?? "");
    $daysRemaining = (int)($context["days_remaining"] ?? 0);
    $exportStatus = $context["export_status"] ?? [];

    if ($warningLevel === "") {
        return;
    }

    echo '<section class="analytics-cycle-banner' . ($warningLevel !== "" ? ' analytics-cycle-banner--warning' : '') . '" role="status">';
    echo '<div>';
    echo '<strong>Current analytics cycle: ' . analytics_h($cycle["start_date"] ?? "-") . ' to ' . analytics_h($cycle["end_date"] ?? "-") . '</strong>';
    if ($warningLevel !== "") {
        echo '<p>Analytics for the current monthly cycle will reset soon. Please export the analytics report before the cycle ends to keep a copy of this month\'s results.</p>';
    } else {
        echo '<p>This reporting cycle is used for analytics snapshots only. Raw queue and order records are preserved.</p>';
    }
    if (!empty($exportStatus["exported"])) {
        echo '<small>Last export: ' . analytics_h(analytics_datetime($exportStatus["exported_at"] ?? "")) . '</small>';
    }
    echo '</div>';
    echo '<div class="analytics-cycle-actions">';
    echo '<span>' . ($daysRemaining === 0 ? 'Reset day' : analytics_h((string)$daysRemaining) . ' days remaining') . '</span>';
    echo '<a class="admin-owner-button-secondary" href="' . analytics_report_url("super_admin_analytics_exports.php", $context["filters"] ?? []) . '">Open Export Center</a>';
    echo '</div>';
    echo '</section>';
}

function analytics_render_filters(array $context, string $actionPage, array $visible): void
{
    $filters = $context["filters"] ?? [];
    $options = $context["options"] ?? [];
    echo '<form class="analytics-filter-bar" method="get" action="' . admin_url('/pages/super_admin/' . $actionPage) . '">';

    if (in_array("cycle", $visible, true)) {
        echo '<label><span>Analytics Cycle</span><select name="cycle_id">';
        foreach (($options["cycles"] ?? []) as $optionCycle) {
            $optionId = (int)($optionCycle["id"] ?? 0);
            $selectedCycleId = (int)($filters["cycle_id"] ?? 0);
            $isCurrentCycle = $selectedCycleId > 0
                ? $selectedCycleId === $optionId
                : (string)($optionCycle["status"] ?? "") === "active";
            echo '<option value="' . $optionId . '"' . ($isCurrentCycle ? ' selected' : '') . '>' . analytics_h(($optionCycle["cycle_key"] ?? "") . " (" . ($optionCycle["status"] ?? "") . ")") . '</option>';
        }
        echo '</select></label>';
    }

    if (in_array("date", $visible, true)) {
        echo '<label><span>Date From</span><input type="date" name="start_date" value="' . analytics_h($filters["start_date"] ?? "") . '"></label>';
        echo '<label><span>Date To</span><input type="date" name="end_date" value="' . analytics_h($filters["end_date"] ?? "") . '"></label>';
    }

    if (in_array("service", $visible, true)) {
        echo '<label><span>Service Type</span><select name="service_type"><option value="">All services</option>';
        foreach (($options["services"] ?? []) as $service) {
            echo '<option value="' . analytics_h($service) . '"' . (($filters["service_type"] ?? "") === $service ? ' selected' : '') . '>' . analytics_h($service) . '</option>';
        }
        echo '</select></label>';
    }

    if (in_array("status", $visible, true)) {
        echo '<label><span>Status</span><select name="status"><option value="">All statuses</option>';
        foreach (($options["statuses"] ?? []) as $status) {
            echo '<option value="' . analytics_h($status) . '"' . (($filters["status"] ?? "") === $status ? ' selected' : '') . '>' . analytics_h($status) . '</option>';
        }
        echo '</select></label>';
    }

    if (in_array("source", $visible, true)) {
        echo '<label><span>Request Source</span><select name="request_source"><option value="">All sources</option>';
        foreach (($options["request_sources"] ?? []) as $source) {
            echo '<option value="' . analytics_h($source) . '"' . (($filters["request_source"] ?? "") === $source ? ' selected' : '') . '>' . analytics_h(analytics_label($source)) . '</option>';
        }
        echo '</select></label>';
    }

    if (in_array("staff", $visible, true) && !empty($options["staff"])) {
        echo '<label><span>Staff/Admin</span><select name="staff_id"><option value="">All staff</option>';
        foreach ($options["staff"] as $staffOption) {
            $staffId = (int)($staffOption["id"] ?? 0);
            echo '<option value="' . $staffId . '"' . ((int)($filters["staff_id"] ?? 0) === $staffId ? ' selected' : '') . '>' . analytics_h($staffOption["name"] ?? "Staff") . '</option>';
        }
        echo '</select></label>';
    }

    echo '<div class="analytics-filter-actions">';
    echo '<button class="admin-owner-button" type="submit">Apply Filter</button>';
    echo '<a class="admin-owner-button-secondary" href="' . admin_url('/pages/super_admin/' . $actionPage) . '">Reset</a>';
    echo '</div>';
    echo '</form>';
}

function analytics_render_report_header(string $title, string $description, array $context): void
{
    $cycle = $context["cycle"] ?? [];
    echo '<section class="analytics-report-header">';
    echo '<a class="analytics-back-link" href="' . admin_url('/pages/super_admin/super_admin_analytics.php') . '">Back to Analytics</a>';
    echo '<div><p class="analytics-eyebrow">Super Admin Report</p><h1>' . analytics_h($title) . '</h1><p>' . analytics_h($description) . '</p></div>';
    echo '<span class="analytics-cycle-chip">' . analytics_h(($cycle["start_date"] ?? "-") . " to " . ($cycle["end_date"] ?? "-")) . '</span>';
    echo '</section>';
}

function analytics_render_export_row(array $context, string $currentPage, bool $activeCsv = false): void
{
    echo '<section class="analytics-export-row" aria-label="Export analytics">';
    if ($activeCsv) {
        echo '<a class="admin-owner-button-secondary" href="' . analytics_report_url($currentPage, $context["filters"] ?? [], ["export" => "csv"]) . '">Export CSV</a>';
    } else {
        echo '<a class="admin-owner-button-secondary" href="' . analytics_report_url("super_admin_analytics_exports.php", $context["filters"] ?? []) . '">Export CSV</a>';
    }
    echo '<button class="admin-owner-button-secondary" type="button" disabled title="Excel export is prepared in the UI and can be connected when the export service is available.">Export Excel</button>';
    echo '<button class="admin-owner-button-secondary" type="button" disabled title="PDF export is prepared in the UI and can be connected when the export service is available.">Export PDF</button>';
    echo '</section>';
}

function analytics_category_definitions(array $context): array
{
    $analytics = $context["analytics"] ?? [];
    $summary = $context["summary"] ?? [];
    $mostRequested = $analytics["most_requested_service"] ?? [];
    $cycle = $context["cycle"] ?? [];
    $daysRemaining = (int)($context["days_remaining"] ?? 0);
    $exportStatus = $context["export_status"] ?? [];

    return [
        [
            "icon" => "OP",
            "title" => "Operations Overview",
            "route" => "super_admin_analytics_operations.php",
            "description" => "Review overall system activity, completion rate, active workload, and current monthly analytics cycle.",
            "metrics" => [
                ["label" => "Total requests", "value" => analytics_number($summary["total_requests"] ?? 0)],
                ["label" => "Completed", "value" => analytics_number($summary["completed_requests"] ?? 0)],
                ["label" => "Completion rate", "value" => analytics_percent($summary["completion_rate"] ?? 0)],
            ],
        ],
        [
            "icon" => "SQ",
            "title" => "Service Requests & Queue Performance",
            "route" => "super_admin_analytics_service_queue.php",
            "description" => "Analyze service demand, queue waiting time, service processing time, request trends, and status distribution.",
            "metrics" => [
                ["label" => "Total requests", "value" => analytics_number($summary["total_requests"] ?? 0)],
                ["label" => "Most requested service", "value" => (string)($mostRequested["service_label"] ?? "-")],
                ["label" => "Average queue waiting time", "value" => analytics_minutes($summary["avg_queue_waiting_minutes"] ?? 0)],
            ],
        ],
        [
            "icon" => "WF",
            "title" => "Status Tracking & Workflow",
            "route" => "super_admin_analytics_workflow.php",
            "description" => "Review status transition history, workflow handling time, stalled requests, and incomplete timestamps.",
            "metrics" => [
                ["label" => "Average pending time", "value" => analytics_minutes(analytics_status_duration_value($analytics["status_durations"] ?? [], "PENDING"))],
                ["label" => "Average ongoing time", "value" => analytics_minutes(analytics_status_duration_value($analytics["status_durations"] ?? [], "ONGOING"))],
                ["label" => "Stalled requests", "value" => analytics_number(count($analytics["stale_requests"] ?? []))],
            ],
        ],
        [
            "icon" => "EX",
            "title" => "Monthly Analytics Cycle & Export Center",
            "route" => "super_admin_analytics_exports.php",
            "description" => "Manage the current monthly analytics cycle, export reports, view warnings, and access archived analytics.",
            "metrics" => [
                ["label" => "Current cycle", "value" => (string)($cycle["cycle_key"] ?? "-")],
                ["label" => "Days before reset", "value" => $daysRemaining === 0 ? "Reset day" : analytics_number($daysRemaining)],
                ["label" => "Export status", "value" => !empty($exportStatus["exported"]) ? "Exported" : "Not exported"],
            ],
        ],
    ];
}

function analytics_render_landing_cards(array $context): void
{
    echo '<section class="analytics-card-grid" aria-label="Analytics categories">';
    foreach (analytics_category_definitions($context) as $card) {
        echo '<article class="analytics-report-card">';
        echo '<div class="analytics-report-card__top"><span class="analytics-card-icon" aria-hidden="true">' . analytics_h($card["icon"]) . '</span><div><h2>' . analytics_h($card["title"]) . '</h2><p>' . analytics_h($card["description"]) . '</p></div></div>';
        echo '<dl class="analytics-card-metrics">';
        foreach ($card["metrics"] as $metric) {
            echo '<div><dt>' . analytics_h($metric["label"]) . '</dt><dd>' . analytics_h($metric["value"]) . '</dd></div>';
        }
        echo '</dl>';
        echo '<a class="analytics-open-report" href="' . analytics_report_url($card["route"], $context["filters"] ?? []) . '">Open Report</a>';
        echo '</article>';
    }
    echo '</section>';
}

function analytics_render_operations(array $context): void
{
    $analytics = $context["analytics"];
    $summary = $context["summary"];
    analytics_render_metric_grid([
        ["label" => "Total Service Requests", "value" => analytics_number($summary["total_requests"] ?? 0)],
        ["label" => "Completed Requests", "value" => analytics_number($summary["completed_requests"] ?? 0)],
        ["label" => "Cancelled Requests", "value" => analytics_number($summary["cancelled_requests"] ?? 0)],
        ["label" => "Active Workload", "value" => analytics_number($summary["active_workload"] ?? 0)],
        ["label" => "Completion Rate", "value" => analytics_percent($summary["completion_rate"] ?? 0)],
    ]);
    echo '<section class="analytics-panel-grid">';
    echo '<article class="analytics-panel"><h2>Queue Status Distribution</h2>';
    analytics_render_bars(analytics_status_rows($analytics), "label", "total", "No queue status records found.");
    echo '</article>';
    echo '<article class="analytics-panel"><h2>Monthly Cycle Summary</h2><div class="analytics-definition-list">';
    echo '<div><span>Cycle Range</span><strong>' . analytics_h(($context["cycle"]["start_date"] ?? "-") . " to " . ($context["cycle"]["end_date"] ?? "-")) . '</strong></div>';
    echo '<div><span>Most Requested Service</span><strong>' . analytics_h($analytics["most_requested_service"]["service_label"] ?? "-") . '</strong></div>';
    echo '<div><span>Average Queue Waiting Time</span><strong>' . analytics_minutes($summary["avg_queue_waiting_minutes"] ?? 0) . '</strong></div>';
    echo '<div><span>Average Service Processing Time</span><strong>' . analytics_minutes($summary["avg_service_processing_minutes"] ?? 0) . '</strong></div>';
    echo '</div></article></section>';
}

function analytics_render_service_queue(array $context): void
{
    $analytics = $context["analytics"];
    $summary = $context["summary"];
    analytics_render_metric_grid([
        ["label" => "Total Service Requests", "value" => analytics_number($summary["total_requests"] ?? 0)],
        ["label" => "Average Queue Waiting Time", "value" => analytics_minutes($summary["avg_queue_waiting_minutes"] ?? 0)],
        ["label" => "Average Service Processing Time", "value" => analytics_minutes($summary["avg_service_processing_minutes"] ?? 0)],
        ["label" => "Median Waiting Time", "value" => analytics_minutes($summary["median_queue_waiting_minutes"] ?? 0)],
        ["label" => "Longest Waiting Request", "value" => analytics_h($analytics["longest_waiting_request"]["queue_code"] ?? "-"), "note" => analytics_minutes($analytics["longest_waiting_request"]["queue_waiting_minutes"] ?? null)],
    ]);

    echo '<section class="analytics-section-block"><div class="analytics-section-heading"><h2>Service Demand</h2><p>Demand patterns across service types and reporting periods.</p></div><div class="analytics-panel-grid">';
    echo '<article class="analytics-panel"><h3>Requests by Service Type</h3>';
    analytics_render_bars($analytics["requests_by_service"] ?? [], "service_label", "total", "No service request records found.");
    echo '</article><article class="analytics-panel"><h3>Requests by Day</h3>';
    analytics_render_bars($analytics["requests_by_period"]["day"] ?? [], "period_label", "total", "No daily request trend found.");
    echo '</article></div>';
    echo '<div class="analytics-panel-grid">';
    echo '<article class="analytics-panel"><h3>Requests by Week</h3>';
    analytics_render_bars($analytics["requests_by_period"]["week"] ?? [], "period_label", "total", "No weekly request trend found.");
    echo '</article><article class="analytics-panel"><h3>Requests by Month</h3>';
    analytics_render_bars($analytics["requests_by_period"]["month"] ?? [], "period_label", "total", "No monthly request trend found.");
    echo '</article></div>';
    echo '<article class="analytics-panel"><h3>Most Requested Services Ranking</h3><div class="analytics-table-wrap"><table><thead><tr><th>Rank</th><th>Service Type</th><th>Total Requests</th><th>Completed</th><th>Cancelled</th><th>Completion Percentage</th></tr></thead><tbody>';
    if (empty($analytics["service_completion"])) {
        echo '<tr><td colspan="6">No service ranking data available.</td></tr>';
    } else {
        foreach ($analytics["service_completion"] as $index => $service) {
            echo '<tr><td>' . ($index + 1) . '</td><td>' . analytics_h($service["service_label"] ?? "") . '</td><td>' . analytics_number($service["total"] ?? 0) . '</td><td>' . analytics_number($service["completed"] ?? 0) . '</td><td>' . analytics_number($service["cancelled"] ?? 0) . '</td><td>' . analytics_percent($service["completion_percentage"] ?? 0) . '</td></tr>';
        }
    }
    echo '</tbody></table></div></article></section>';

    echo '<section class="analytics-section-block"><div class="analytics-section-heading"><h2>Queue Performance</h2><p>Waiting-time measures used for queueing and service-flow analysis.</p></div><div class="analytics-panel-grid">';
    echo '<article class="analytics-panel"><h3>Waiting Time Highlights</h3><div class="analytics-definition-list">';
    echo '<div><span>Average Queue Waiting Time</span><strong>' . analytics_minutes($summary["avg_queue_waiting_minutes"] ?? 0) . '</strong></div>';
    echo '<div><span>Average Service Processing Time</span><strong>' . analytics_minutes($summary["avg_service_processing_minutes"] ?? 0) . '</strong></div>';
    echo '<div><span>Longest Waiting Request</span><strong>' . analytics_h($analytics["longest_waiting_request"]["queue_code"] ?? "-") . ' / ' . analytics_minutes($analytics["longest_waiting_request"]["queue_waiting_minutes"] ?? null) . '</strong></div>';
    echo '<div><span>Shortest Waiting Request</span><strong>' . analytics_h($analytics["shortest_waiting_request"]["queue_code"] ?? "-") . ' / ' . analytics_minutes($analytics["shortest_waiting_request"]["queue_waiting_minutes"] ?? null) . '</strong></div>';
    echo '</div></article>';
    echo '<article class="analytics-panel"><h3>Longest Waiting Requests</h3><div class="analytics-table-wrap"><table><thead><tr><th>Queue ID</th><th>Customer</th><th>Service Type</th><th>Status</th><th>Waiting Time</th><th>Created At</th></tr></thead><tbody>';
    if (empty($analytics["longest_waiting_requests"])) {
        echo '<tr><td colspan="6">No waiting-time data available.</td></tr>';
    } else {
        foreach ($analytics["longest_waiting_requests"] as $row) {
            echo '<tr><td>' . analytics_h($row["queue_code"] ?? "") . '</td><td>' . analytics_h($row["customer_name"] ?? "") . '</td><td>' . analytics_h($row["service_label"] ?? "") . '</td><td>' . analytics_h($row["status_group"] ?? "") . '</td><td>' . analytics_minutes($row["queue_waiting_minutes"] ?? null) . '</td><td>' . analytics_datetime($row["request_created_at"] ?? "") . '</td></tr>';
        }
    }
    echo '</tbody></table></div></article>';
    echo '<article class="analytics-panel"><h3>Requests That Waited Longer Than Expected</h3>';
    analytics_render_bars($analytics["delayed_requests"] ?? [], "queue_code", "queue_waiting_minutes", "No requests exceeded the current waiting-time threshold.");
    echo '</article></div></section>';

    echo '<section class="analytics-section-block"><div class="analytics-section-heading"><h2>Status Distribution</h2><p>Completion mix, status duration, and requests without recent movement.</p></div><div class="analytics-panel-grid">';
    echo '<article class="analytics-panel"><h3>Average Time Spent Per Status</h3>';
    analytics_render_bars($analytics["status_durations"] ?? [], "status", "avg_minutes", "No status duration data found.");
    echo '</article><article class="analytics-panel"><h3>Queue Status Distribution</h3>';
    analytics_render_bars(analytics_status_rows($analytics), "label", "total", "No status distribution data found.");
    echo '</article></div>';
    echo '<article class="analytics-panel"><h3>Completed vs Cancelled Requests</h3>';
    analytics_render_bars($analytics["completed_vs_cancelled"] ?? [], "status_group", "total", "No completed or cancelled records found.");
    echo '</article>';
    echo '<article class="analytics-panel"><h3>Requests Without Recent Status Update</h3><div class="analytics-table-wrap"><table><thead><tr><th>Queue ID</th><th>Customer</th><th>Service Type</th><th>Status</th><th>Last Status Update</th></tr></thead><tbody>';
    if (empty($analytics["stale_requests"])) {
        echo '<tr><td colspan="5">No active requests are older than the stale-update threshold.</td></tr>';
    } else {
        foreach ($analytics["stale_requests"] as $row) {
            echo '<tr><td>' . analytics_h($row["queue_code"] ?? "") . '</td><td>' . analytics_h($row["customer_name"] ?? "") . '</td><td>' . analytics_h($row["service_label"] ?? "") . '</td><td>' . analytics_h($row["status_group"] ?? "") . '</td><td>' . analytics_datetime($row["last_status_at"] ?? "") . '</td></tr>';
        }
    }
    echo '</tbody></table></div></article></section>';

    echo '<section class="analytics-section-block"><div class="analytics-section-heading"><h2>Detailed Records</h2><p>Queue-level timestamps and computed waiting/processing duration.</p></div><article class="analytics-panel"><div class="analytics-table-wrap"><table><thead><tr><th>Queue ID</th><th>Customer Name</th><th>Service Type</th><th>Current/Final Status</th><th>Created At</th><th>Approved At</th><th>Ongoing At</th><th>Done At</th><th>Queue Waiting Time</th><th>Service Processing Time</th></tr></thead><tbody>';
    if (empty($analytics["detailed_records"])) {
        echo '<tr><td colspan="10">No detailed service and queue records found.</td></tr>';
    } else {
        foreach ($analytics["detailed_records"] as $row) {
            echo '<tr><td>' . analytics_h($row["queue_code"] ?? "") . '</td><td>' . analytics_h($row["customer_name"] ?? "") . '</td><td>' . analytics_h($row["service_label"] ?? "") . '</td><td>' . analytics_h($row["status_group"] ?? "") . '</td><td>' . analytics_datetime($row["request_created_at"] ?? "") . '</td><td>' . analytics_datetime($row["approved_at"] ?? "") . '</td><td>' . analytics_datetime($row["ongoing_at"] ?? "") . '</td><td>' . analytics_datetime($row["done_at"] ?? "") . '</td><td>' . analytics_minutes($row["queue_waiting_minutes"] ?? null) . '</td><td>' . analytics_minutes($row["service_processing_minutes"] ?? null) . '</td></tr>';
        }
    }
    echo '</tbody></table></div></article></section>';
}

function analytics_render_workflow(array $context): void
{
    $analytics = $context["analytics"];
    analytics_render_metric_grid([
        ["label" => "Transition Events", "value" => analytics_number(count($analytics["history"] ?? []))],
        ["label" => "Incomplete Timestamps", "value" => analytics_number(count($analytics["incomplete_timestamps"] ?? []))],
        ["label" => "Stalled Requests", "value" => analytics_number(count($analytics["stale_requests"] ?? []))],
        ["label" => "Most Common Workflow Route", "value" => analytics_h($analytics["workflow_routes"][0]["route"] ?? "Pending -> Approved -> Ongoing -> For Pick-up -> Done")],
    ]);
    echo '<section class="analytics-panel-grid"><article class="analytics-panel"><h2>Average Time Spent in Each Status</h2>';
    analytics_render_bars($analytics["status_durations"] ?? [], "status", "avg_minutes", "No status duration data found.");
    echo '</article><article class="analytics-panel"><h2>Most Common Workflow Route</h2>';
    analytics_render_bars($analytics["workflow_routes"] ?? [], "route", "total", "No workflow route data found.");
    echo '</article></section>';
    echo '<section class="analytics-panel"><h2>Requests with Incomplete Status Timestamps</h2><div class="analytics-table-wrap"><table><thead><tr><th>Queue ID</th><th>Customer</th><th>Service Type</th><th>Status</th><th>Created At</th><th>Approved At</th><th>Ongoing At</th><th>Done At</th></tr></thead><tbody>';
    if (empty($analytics["incomplete_timestamps"])) {
        echo '<tr><td colspan="8">No incomplete timestamp records found.</td></tr>';
    } else {
        foreach ($analytics["incomplete_timestamps"] as $row) {
            echo '<tr><td>' . analytics_h($row["queue_code"] ?? "") . '</td><td>' . analytics_h($row["customer_name"] ?? "") . '</td><td>' . analytics_h($row["service_label"] ?? "") . '</td><td>' . analytics_h($row["status_group"] ?? "") . '</td><td>' . analytics_datetime($row["request_created_at"] ?? "") . '</td><td>' . analytics_datetime($row["approved_at"] ?? "") . '</td><td>' . analytics_datetime($row["ongoing_at"] ?? "") . '</td><td>' . analytics_datetime($row["done_at"] ?? "") . '</td></tr>';
        }
    }
    echo '</tbody></table></div></section>';
    echo '<section class="analytics-panel"><h2>Status Transition History</h2><div class="analytics-table-wrap"><table><thead><tr><th>Queue ID</th><th>Customer Name</th><th>Service Type</th><th>Status</th><th>Entered At</th><th>Exited At</th><th>Duration Min</th><th>Next Status</th><th>Remarks</th></tr></thead><tbody>';
    if (empty($analytics["history"])) {
        echo '<tr><td colspan="9">No status transition history found for the selected filters.</td></tr>';
    } else {
        foreach ($analytics["history"] as $event) {
            echo '<tr><td>' . analytics_h($event["queue_code"] ?? "") . '</td><td>' . analytics_h($event["customer_name_snapshot"] ?? "") . '</td><td>' . analytics_h($event["service_type"] ?? "") . '</td><td>' . analytics_h($event["status"] ?? "") . '</td><td>' . analytics_datetime($event["entered_at"] ?? "") . '</td><td>' . analytics_datetime($event["exited_at"] ?? "") . '</td><td>' . analytics_h($event["duration_minutes"] ?? "-") . '</td><td>' . analytics_h($event["next_status"] ?? "-") . '</td><td>' . analytics_h($event["remarks"] ?? "") . '</td></tr>';
        }
    }
    echo '</tbody></table></div></section>';
}

function analytics_render_completion(array $context): void
{
    $analytics = $context["analytics"];
    $summary = $context["summary"];
    $extremes = $analytics["completion_extremes"] ?? [];
    analytics_render_metric_grid([
        ["label" => "Completion Rate", "value" => analytics_percent($summary["completion_rate"] ?? 0)],
        ["label" => "Cancellation Rate", "value" => analytics_percent($summary["cancellation_rate"] ?? 0)],
        ["label" => "Completed Requests", "value" => analytics_number($summary["completed_requests"] ?? 0)],
        ["label" => "Cancelled Requests", "value" => analytics_number($summary["cancelled_requests"] ?? 0)],
        ["label" => "Fastest Service Type", "value" => analytics_h($extremes[0]["service_label"] ?? "-")],
    ]);
    echo '<section class="analytics-panel-grid"><article class="analytics-panel"><h2>Completed vs Cancelled Requests</h2>';
    analytics_render_bars($analytics["completed_vs_cancelled"] ?? [], "status_group", "total", "No completed or cancelled records found.");
    echo '</article><article class="analytics-panel"><h2>Cancelled Requests by Reason</h2>';
    analytics_render_bars($analytics["cancellation_reasons"] ?? [], "reason", "total", "No cancellation reasons recorded.");
    echo '</article></section>';
    echo '<section class="analytics-panel"><h2>Completion and Cancellation by Service Type</h2><div class="analytics-table-wrap"><table><thead><tr><th>Service Type</th><th>Total</th><th>Completed</th><th>Cancelled</th><th>Average Completion Time</th><th>Completion Percentage</th></tr></thead><tbody>';
    if (empty($analytics["service_completion"])) {
        echo '<tr><td colspan="6">No completion records found.</td></tr>';
    } else {
        foreach ($analytics["service_completion"] as $service) {
            echo '<tr><td>' . analytics_h($service["service_label"] ?? "") . '</td><td>' . analytics_number($service["total"] ?? 0) . '</td><td>' . analytics_number($service["completed"] ?? 0) . '</td><td>' . analytics_number($service["cancelled"] ?? 0) . '</td><td>' . analytics_minutes($service["avg_completion_minutes"] ?? null) . '</td><td>' . analytics_percent($service["completion_percentage"] ?? 0) . '</td></tr>';
        }
    }
    echo '</tbody></table></div></section>';
}

function analytics_render_staff(array $context): void
{
    $staff = $context["analytics"]["staff"] ?? ["available" => false, "rows" => []];
    if (empty($staff["available"])) {
        analytics_render_empty("Staff workload analytics will appear once staff handling data is available.");
        return;
    }
    $rows = $staff["rows"] ?? [];
    analytics_render_metric_grid([
        ["label" => "Requests Handled", "value" => analytics_number(array_sum(array_map(static fn($row): int => (int)($row["requests_handled"] ?? 0), $rows)))],
        ["label" => "Completed by Staff", "value" => analytics_number(array_sum(array_map(static fn($row): int => (int)($row["completed_requests"] ?? 0), $rows)))],
        ["label" => "Active Assigned Workload", "value" => analytics_number(array_sum(array_map(static fn($row): int => (int)($row["active_workload"] ?? 0), $rows)))],
        ["label" => "Top Staff", "value" => analytics_h($rows[0]["staff_name"] ?? "-")],
    ]);
    echo '<section class="analytics-panel-grid"><article class="analytics-panel"><h2>Completed Requests per Staff</h2>';
    analytics_render_bars($rows, "staff_name", "completed_requests", "No staff completion records found.");
    echo '</article><article class="analytics-panel"><h2>Status Updates Made per Staff</h2>';
    analytics_render_bars($rows, "staff_name", "status_updates", "No staff status-update records found.");
    echo '</article></section>';
    echo '<section class="analytics-panel"><h2>Staff Workload Table</h2><div class="analytics-table-wrap"><table><thead><tr><th>Staff</th><th>Requests Handled</th><th>Completed Requests</th><th>Status Updates</th><th>Active Workload</th><th>Average Handling Time</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td>' . analytics_h($row["staff_name"] ?? "Staff") . '</td><td>' . analytics_number($row["requests_handled"] ?? 0) . '</td><td>' . analytics_number($row["completed_requests"] ?? 0) . '</td><td>' . analytics_number($row["status_updates"] ?? 0) . '</td><td>' . analytics_number($row["active_workload"] ?? 0) . '</td><td>' . analytics_minutes($row["avg_handling_minutes"] ?? null) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function analytics_render_notifications(array $context): void
{
    $notifications = $context["analytics"]["notifications"] ?? [];
    analytics_render_metric_grid([
        ["label" => "Total Notifications Sent", "value" => analytics_number($notifications["summary"]["total"] ?? 0)],
        ["label" => "Unread Notifications", "value" => analytics_number($notifications["summary"]["unread"] ?? 0)],
        ["label" => "Average Time Before First Customer Update", "value" => analytics_minutes($notifications["summary"]["avg_first_update_minutes"] ?? 0)],
        ["label" => "Requests Without Customer Notification", "value" => analytics_number($notifications["summary"]["requests_without_customer_notification"] ?? 0)],
    ]);
    echo '<section class="analytics-panel-grid"><article class="analytics-panel"><h2>Notifications by Type</h2>';
    analytics_render_bars($notifications["by_type"] ?? [], "type", "total", "No notification type records found.");
    echo '</article><article class="analytics-panel"><h2>Failed Email or Notification Logs</h2>';
    analytics_render_bars($notifications["failed_logs"] ?? [], "type", "total", "No failed notification logs are available.");
    echo '</article></section>';
    echo '<section class="analytics-panel"><h2>Latest Status Updates Sent to Customers</h2><div class="analytics-table-wrap"><table><thead><tr><th>Queue Reference</th><th>Type</th><th>Message</th><th>Sent At</th></tr></thead><tbody>';
    if (empty($notifications["latest_status_updates"])) {
        echo '<tr><td colspan="4">No recent status notifications found.</td></tr>';
    } else {
        foreach ($notifications["latest_status_updates"] as $notice) {
            echo '<tr><td>' . analytics_h($notice["reference_id"] ?? "-") . '</td><td>' . analytics_h($notice["type"] ?? "") . '</td><td>' . analytics_h($notice["message"] ?? "") . '</td><td>' . analytics_datetime($notice["created_at"] ?? "") . '</td></tr>';
        }
    }
    echo '</tbody></table></div></section>';
}

function analytics_render_quality(array $context): void
{
    $analytics = $context["analytics"];
    $corrections = $analytics["corrections"] ?? [];
    analytics_render_metric_grid([
        ["label" => "Requests Needing Correction", "value" => analytics_number($corrections["correction_requests"] ?? 0)],
        ["label" => "Missing File or Details Count", "value" => analytics_number(count($corrections["missing_details"] ?? []))],
        ["label" => "Admin-Edited Request Details Count", "value" => analytics_number(count($corrections["activity"] ?? []))],
        ["label" => "Requests with Missing Required Timestamps", "value" => analytics_number(count($analytics["incomplete_timestamps"] ?? []))],
    ]);
    echo '<section class="analytics-panel-grid"><article class="analytics-panel"><h2>Correction Count per Service Type</h2>';
    analytics_render_bars($corrections["by_service"] ?? [], "service_label", "total", "No correction request records found.");
    echo '</article><article class="analytics-panel"><h2>Future Correction Fields</h2><div class="analytics-definition-list"><div><span>correction_count</span><strong>Recommended</strong></div><div><span>correction_reason</span><strong>Recommended</strong></div><div><span>corrected_by</span><strong>Recommended</strong></div><div><span>corrected_at</span><strong>Recommended</strong></div></div></article></section>';
    echo '<section class="analytics-panel"><h2>Requests with Missing or Corrected Details</h2><div class="analytics-table-wrap"><table><thead><tr><th>Queue ID</th><th>Customer</th><th>Service Type</th><th>Status</th></tr></thead><tbody>';
    if (empty($corrections["missing_details"])) {
        echo '<tr><td colspan="4">No missing detail records found.</td></tr>';
    } else {
        foreach ($corrections["missing_details"] as $row) {
            echo '<tr><td>' . analytics_h($row["queue_code"] ?? "") . '</td><td>' . analytics_h($row["customer_name"] ?? "") . '</td><td>' . analytics_h($row["service_label"] ?? "") . '</td><td>' . analytics_h($row["status_group"] ?? "") . '</td></tr>';
        }
    }
    echo '</tbody></table></div></section>';
}

function analytics_render_availability(array $context): void
{
    $store = $context["analytics"]["store"] ?? [];
    if (empty($store["available"])) {
        analytics_render_empty("Store availability analytics will appear once store availability tables are installed.");
        return;
    }
    analytics_render_metric_grid([
        ["label" => "Store Status", "value" => analytics_label((string)($store["settings"]["store_status"] ?? "-"))],
        ["label" => "Queue Cutoff", "value" => analytics_h($store["settings"]["queue_cutoff_time"] ?? "-")],
        ["label" => "Closed Dates", "value" => analytics_number(count($store["holidays"] ?? []))],
        ["label" => "Most Active Service Day", "value" => trim((string)($store["most_active_service_day"]["day_name"] ?? "-")), "note" => analytics_number($store["most_active_service_day"]["total"] ?? 0) . " requests"],
    ]);
    echo '<section class="analytics-panel-grid"><article class="analytics-panel"><h2>Store Open/Closed History</h2><div class="analytics-table-wrap"><table><thead><tr><th>Day</th><th>Open</th><th>Opens At</th><th>Closes At</th></tr></thead><tbody>';
    foreach (($store["hours"] ?? []) as $hour) {
        echo '<tr><td>' . analytics_h((string)($hour["day_of_week"] ?? "")) . '</td><td>' . (!empty($hour["is_open"]) ? "Yes" : "No") . '</td><td>' . analytics_h($hour["opens_at"] ?? "-") . '</td><td>' . analytics_h($hour["closes_at"] ?? "-") . '</td></tr>';
    }
    echo '</tbody></table></div></article><article class="analytics-panel"><h2>Services Unavailable Due to Schedule</h2><div class="analytics-table-wrap"><table><thead><tr><th>Date</th><th>Title</th><th>Note</th></tr></thead><tbody>';
    if (empty($store["holidays"])) {
        echo '<tr><td colspan="3">No schedule-based service unavailability records found.</td></tr>';
    } else {
        foreach ($store["holidays"] as $holiday) {
            echo '<tr><td>' . analytics_h($holiday["holiday_date"] ?? "") . '</td><td>' . analytics_h($holiday["title"] ?? "") . '</td><td>' . analytics_h($holiday["note"] ?? "") . '</td></tr>';
        }
    }
    echo '</tbody></table></div></article></section>';
    echo '<section class="analytics-panel"><h2>Recent Availability Changes</h2><div class="analytics-table-wrap"><table><thead><tr><th>Admin</th><th>Action</th><th>Description</th><th>Date</th></tr></thead><tbody>';
    if (empty($store["changes"])) {
        echo '<tr><td colspan="4">No store availability changes found.</td></tr>';
    } else {
        foreach ($store["changes"] as $change) {
            echo '<tr><td>' . analytics_h($change["user_name"] ?? "") . '</td><td>' . analytics_h($change["action_type"] ?? "") . '</td><td>' . analytics_h($change["description"] ?? "") . '</td><td>' . analytics_datetime($change["created_at"] ?? "") . '</td></tr>';
        }
    }
    echo '</tbody></table></div></section>';
}

function analytics_render_exports(array $context): void
{
    $analytics = $context["analytics"];
    $exportStatus = $context["export_status"] ?? [];
    analytics_render_metric_grid([
        ["label" => "Current Cycle", "value" => analytics_h($context["cycle"]["cycle_key"] ?? "-"), "note" => analytics_h(($context["cycle"]["start_date"] ?? "-") . " to " . ($context["cycle"]["end_date"] ?? "-"))],
        ["label" => "Days Before Reset", "value" => ((int)($context["days_remaining"] ?? 0) === 0 ? "Reset day" : analytics_number($context["days_remaining"] ?? 0))],
        ["label" => "Export Status", "value" => !empty($exportStatus["exported"]) ? "Exported" : "Not Exported", "note" => !empty($exportStatus["exported_at"]) ? analytics_datetime($exportStatus["exported_at"]) : "No export logged"],
        ["label" => "Previous Analytics Cycles", "value" => analytics_number(count($analytics["cycle_center"]["previous_cycles"] ?? []))],
    ]);
    echo '<section class="analytics-panel-grid"><article class="analytics-panel"><h2>Previous Analytics Cycles</h2><div class="analytics-table-wrap"><table><thead><tr><th>Cycle</th><th>Date Range</th><th>Status</th><th>Snapshot Created</th></tr></thead><tbody>';
    if (empty($analytics["cycle_center"]["previous_cycles"])) {
        echo '<tr><td colspan="4">No previous analytics cycles found.</td></tr>';
    } else {
        foreach ($analytics["cycle_center"]["previous_cycles"] as $row) {
            echo '<tr><td>' . analytics_h($row["cycle_key"] ?? "") . '</td><td>' . analytics_h(($row["start_date"] ?? "") . " to " . ($row["end_date"] ?? "")) . '</td><td>' . analytics_h($row["status"] ?? "") . '</td><td>' . analytics_datetime($row["snapshot_created_at"] ?? "") . '</td></tr>';
        }
    }
    echo '</tbody></table></div></article><article class="analytics-panel"><h2>Export Logs</h2><div class="analytics-table-wrap"><table><thead><tr><th>Type</th><th>Exported By</th><th>Exported At</th><th>Rows</th></tr></thead><tbody>';
    if (empty($analytics["cycle_center"]["export_logs"])) {
        echo '<tr><td colspan="4">No exports logged for this cycle.</td></tr>';
    } else {
        foreach ($analytics["cycle_center"]["export_logs"] as $log) {
            echo '<tr><td>' . analytics_h(strtoupper((string)($log["export_type"] ?? ""))) . '</td><td>' . analytics_h($log["exported_by"] ?? "System") . '</td><td>' . analytics_datetime($log["exported_at"] ?? "") . '</td><td>' . analytics_number($log["row_count"] ?? 0) . '</td></tr>';
        }
    }
    echo '</tbody></table></div></article></section>';
}
