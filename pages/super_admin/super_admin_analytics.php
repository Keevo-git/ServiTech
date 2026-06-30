<?php
require_once __DIR__ . "/../admin/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/../../config/app.php";
require_once __DIR__ . "/../admin/_includes/admin_db.php";
require_once __DIR__ . "/_includes/super_admin_analytics_data.php";

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
    $value = str_replace("_", " ", trim($value));
    return $value === "" ? "-" : ucwords(strtolower($value));
}

function analytics_query_url(array $filters, array $extra = []): string
{
    $query = array_filter(array_merge($filters, $extra), static fn($value): bool => trim((string)$value) !== "");
    return admin_url("/pages/super_admin/super_admin_analytics.php" . ($query ? "?" . http_build_query($query) : ""));
}

function analytics_render_bars(array $rows, string $labelKey, string $valueKey, string $emptyMessage = "No data available."): void
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
        $width = $value > 0 ? max(4, (int)round(($value / $max) * 100)) : 0;
        echo '<div class="analytics-bar-row">';
        echo '<div class="analytics-bar-row__meta"><span>' . analytics_h($label) . '</span><strong>' . analytics_h(number_format($value, $value === floor($value) ? 0 : 2)) . '</strong></div>';
        echo '<div class="analytics-bar-track"><span style="width: ' . $width . '%"></span></div>';
        echo '</div>';
    }
}

$filters = super_analytics_clean_filter($_GET);
$analyticsReady = false;
$analyticsError = "";
$analytics = [
    "summary" => [],
    "longest_waiting_request" => [],
    "status_distribution" => [],
    "status_durations" => [],
    "requests_by_service" => [],
    "requests_by_period" => ["day" => [], "week" => [], "month" => []],
    "completed_vs_cancelled" => [],
    "service_completion" => [],
    "history" => [],
    "most_requested_service" => ["service_label" => "-", "total" => 0],
    "options" => ["services" => [], "statuses" => [], "payment_methods" => [], "request_sources" => []],
    "cycle" => [],
    "cycle_days_remaining" => 0,
    "cycle_warning_level" => "",
    "cycle_export_status" => ["exported" => false, "exported_at" => null, "export_type" => ""],
];

try {
    if (!super_analytics_schema_ready($pdo)) {
        throw new RuntimeException("Analytics schema is not installed. Run the Table 9 analytics migration first.");
    }
    $analytics = super_analytics_fetch($pdo, $filters);
    $analyticsReady = true;
} catch (Throwable $exception) {
    error_log("super admin analytics page error: " . $exception->getMessage());
    $analyticsError = $exception->getMessage();
}

if ($analyticsReady && strtolower(trim((string)($_GET["export"] ?? ""))) === "csv") {
    super_analytics_record_export(
        $pdo,
        $analytics,
        "csv",
        (int)($_SESSION["user_id"] ?? 0),
        $filters,
        count($analytics["history"])
    );
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=servitech-owner-analytics-status-history.csv");
    $out = fopen("php://output", "w");
    foreach (super_analytics_csv_rows($analytics) as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$summary = $analytics["summary"];
$longest = $analytics["longest_waiting_request"];
$mostRequested = $analytics["most_requested_service"];
$options = $analytics["options"];
$cycle = $analytics["cycle"];
$daysRemaining = (int)($analytics["cycle_days_remaining"] ?? 0);
$warningLevel = (string)($analytics["cycle_warning_level"] ?? "");
$exportStatus = $analytics["cycle_export_status"];
$dashboardNow = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
$selectedCategory = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($filters["category"] ?? "overview")));
$validCategories = [
    "overview", "service-requests", "queue-waiting", "workflow", "completion",
    "staff", "communication", "corrections", "store", "cycle",
];
if (!in_array($selectedCategory, $validCategories, true)) {
    $selectedCategory = "overview";
}

$statusRows = [];
foreach (($analytics["status_distribution"] ?? []) as $status => $total) {
    $statusRows[] = ["label" => $status, "total" => $total];
}
$categoryCards = [
    [
        "id" => "overview",
        "title" => "Operations Overview",
        "description" => "Review overall service performance, active workload, completion rate, and monthly cycle status.",
        "metrics" => [
            ["label" => "Total Requests", "value" => analytics_number($summary["total_requests"] ?? 0)],
            ["label" => "Active Workload", "value" => analytics_number($summary["active_workload"] ?? 0)],
            ["label" => "Completion Rate", "value" => analytics_percent($summary["completion_rate"] ?? 0)],
        ],
    ],
    [
        "id" => "service-requests",
        "title" => "Service Request Analytics",
        "description" => "Analyze service demand, request trends, source mix, and completion per service type.",
        "metrics" => [
            ["label" => "Most Requested", "value" => (string)($mostRequested["service_label"] ?? "-")],
            ["label" => "Service Types", "value" => analytics_number(count($analytics["requests_by_service"] ?? []))],
            ["label" => "Sources", "value" => analytics_number(count($analytics["request_source_mix"] ?? []))],
        ],
    ],
    [
        "id" => "queue-waiting",
        "title" => "Queue and Waiting Time Analytics",
        "description" => "Analyze waiting time, service processing time, delayed requests, and stale queue movement.",
        "metrics" => [
            ["label" => "Average Waiting", "value" => analytics_minutes($summary["avg_queue_waiting_minutes"] ?? 0)],
            ["label" => "Median Waiting", "value" => analytics_minutes($summary["median_queue_waiting_minutes"] ?? 0)],
            ["label" => "Delayed Requests", "value" => analytics_number(count($analytics["delayed_requests"] ?? []))],
        ],
    ],
    [
        "id" => "workflow",
        "title" => "Status Tracking and Workflow Analytics",
        "description" => "Track status transitions, workflow routes, status durations, and incomplete timestamps.",
        "metrics" => [
            ["label" => "Timeline Events", "value" => analytics_number(count($analytics["history"] ?? []))],
            ["label" => "Workflow Routes", "value" => analytics_number(count($analytics["workflow_routes"] ?? []))],
            ["label" => "Incomplete Timestamps", "value" => analytics_number(count($analytics["incomplete_timestamps"] ?? []))],
        ],
    ],
    [
        "id" => "completion",
        "title" => "Service Completion and Cancellation Analytics",
        "description" => "Compare completed and cancelled requests, service completion speed, and cancellation reasons.",
        "metrics" => [
            ["label" => "Completed", "value" => analytics_number($summary["completed_requests"] ?? 0)],
            ["label" => "Cancelled", "value" => analytics_number($summary["cancelled_requests"] ?? 0)],
            ["label" => "Cancellation Rate", "value" => analytics_percent($summary["cancellation_rate"] ?? 0)],
        ],
    ],
    [
        "id" => "staff",
        "title" => "Staff Workload and Productivity Analytics",
        "description" => "Review workload and status-update activity by staff when handling data is available.",
        "metrics" => [
            ["label" => "Staff With Updates", "value" => analytics_number(count($analytics["staff"]["rows"] ?? []))],
            ["label" => "Top Staff", "value" => (string)(($analytics["staff"]["rows"][0]["staff_name"] ?? "-"))],
            ["label" => "Data Status", "value" => !empty($analytics["staff"]["available"]) ? "Available" : "Pending"],
        ],
    ],
    [
        "id" => "communication",
        "title" => "Communication and Notification Analytics",
        "description" => "Inspect notification volume, unread updates, missing customer notifications, and latest status messages.",
        "metrics" => [
            ["label" => "Notifications", "value" => analytics_number($analytics["notifications"]["summary"]["total"] ?? 0)],
            ["label" => "Unread", "value" => analytics_number($analytics["notifications"]["summary"]["unread"] ?? 0)],
            ["label" => "First Update Avg", "value" => analytics_minutes($analytics["notifications"]["summary"]["avg_first_update_minutes"] ?? 0)],
        ],
    ],
    [
        "id" => "corrections",
        "title" => "Error, Correction, and Miscommunication Analytics",
        "description" => "Surface requests needing correction, missing details, send-back activity, and future correction-tracking gaps.",
        "metrics" => [
            ["label" => "Correction Requests", "value" => analytics_number($analytics["corrections"]["correction_requests"] ?? 0)],
            ["label" => "Missing Details", "value" => analytics_number(count($analytics["corrections"]["missing_details"] ?? []))],
            ["label" => "Activity Logs", "value" => analytics_number(count($analytics["corrections"]["activity"] ?? []))],
        ],
    ],
    [
        "id" => "store",
        "title" => "Store Availability and Cutoff Analytics",
        "description" => "Review store status, operating hours, closed dates, active service day, and availability changes.",
        "metrics" => [
            ["label" => "Store Status", "value" => analytics_label((string)($analytics["store"]["settings"]["store_status"] ?? "unavailable"))],
            ["label" => "Closed Dates", "value" => analytics_number(count($analytics["store"]["holidays"] ?? []))],
            ["label" => "Top Day", "value" => trim((string)($analytics["store"]["most_active_service_day"]["day_name"] ?? "-"))],
        ],
    ],
    [
        "id" => "cycle",
        "title" => "Monthly Analytics Cycle and Export Center",
        "description" => "Manage the active analytics cycle, export reminders, export logs, and previous monthly cycles.",
        "metrics" => [
            ["label" => "Cycle", "value" => (string)($cycle["cycle_key"] ?? "-")],
            ["label" => "Days Remaining", "value" => $daysRemaining === 0 ? "Reset day" : analytics_number($daysRemaining)],
            ["label" => "Export Status", "value" => !empty($exportStatus["exported"]) ? "Exported" : "Not Exported"],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Owner Reports & Analytics | ServiTech</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260612header-global-type') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin_owner.css?v=20260630-analytics') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/super_admin/super_admin_analytics.css?v=20260630-table9') ?>">
</head>
<body class="admin-analytics-page">

<?php
$adminHeaderVariant = "dashboard";
require __DIR__ . "/../admin/_includes/admin_header.php";
?>

<main class="container main-container analytics-page">
  <section class="analytics-hero">
    <div>
      <p class="analytics-eyebrow">Super Admin Analytics</p>
      <h1>Owner Reports & Analytics</h1>
      <p>A categorized overview of ServiTech operations, service requests, queue performance, workflow progress, and system activity.</p>
    </div>
    <span class="analytics-generated">Generated <?= analytics_h($dashboardNow->format("M d, Y h:i A")) ?></span>
  </section>

  <?php if (!$analyticsReady): ?>
    <div class="analytics-warning" role="status">
      <?= analytics_h($analyticsError !== "" ? $analyticsError : "Analytics are temporarily unavailable.") ?>
    </div>
  <?php endif; ?>

  <?php if ($analyticsReady): ?>
    <section class="analytics-cycle-banner<?= $warningLevel !== "" ? " analytics-cycle-banner--warning" : "" ?>" role="status">
      <div>
        <strong>Current analytics cycle: <?= analytics_h($cycle["start_date"] ?? "-") ?> to <?= analytics_h($cycle["end_date"] ?? "-") ?></strong>
        <?php if ($warningLevel !== ""): ?>
          <p>Analytics for the current monthly cycle will reset soon. Please export the analytics report before the cycle ends to keep a copy of this month's results.</p>
        <?php else: ?>
          <p>This dashboard is scoped to the active monthly analytics cycle. Raw queue and customer records are not deleted when a cycle ends.</p>
        <?php endif; ?>
        <?php if (!empty($exportStatus["exported"])): ?>
          <small>This cycle was already exported on <?= analytics_h(analytics_datetime($exportStatus["exported_at"] ?? "")) ?>.</small>
        <?php elseif ($warningLevel !== ""): ?>
          <small>No export has been logged for this cycle yet.</small>
        <?php endif; ?>
      </div>
      <div class="analytics-cycle-actions">
        <span><?= $daysRemaining === 0 ? "Reset day" : analytics_h((string)$daysRemaining) . " days remaining" ?></span>
        <a class="admin-owner-button" href="<?= analytics_query_url($filters, ["export" => "csv"]) ?>">Export Analytics</a>
        <a class="admin-owner-button-secondary" href="#previous-cycles">View Previous Cycles</a>
      </div>
    </section>
  <?php endif; ?>

  <form class="analytics-filters" method="get" action="<?= admin_url('/pages/super_admin/super_admin_analytics.php') ?>">
    <input type="hidden" name="category" value="<?= analytics_h($selectedCategory) ?>">
    <label id="previous-cycles">
      <span>Analytics Cycle</span>
      <select name="cycle_id">
        <?php foreach ($options["cycles"] ?? [] as $optionCycle): ?>
          <?php
            $optionId = (int)($optionCycle["id"] ?? 0);
            $selectedCycleId = (int)($filters["cycle_id"] ?? 0);
            $isCurrentCycle = $selectedCycleId > 0
              ? $selectedCycleId === $optionId
              : (string)($optionCycle["status"] ?? "") === "active";
          ?>
          <option value="<?= $optionId ?>" <?= $isCurrentCycle ? "selected" : "" ?>>
            <?= analytics_h(($optionCycle["cycle_key"] ?? "") . " (" . ($optionCycle["status"] ?? "") . ")") ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>Date From</span>
      <input type="date" name="start_date" value="<?= analytics_h($filters["start_date"]) ?>">
    </label>
    <label>
      <span>Date To</span>
      <input type="date" name="end_date" value="<?= analytics_h($filters["end_date"]) ?>">
    </label>
    <label>
      <span>Service Type</span>
      <select name="service_type">
        <option value="">All services</option>
        <?php foreach ($options["services"] as $service): ?>
          <option value="<?= analytics_h($service) ?>" <?= $filters["service_type"] === $service ? "selected" : "" ?>><?= analytics_h($service) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>Status</span>
      <select name="status">
        <option value="">All statuses</option>
        <?php foreach ($options["statuses"] as $status): ?>
          <option value="<?= analytics_h($status) ?>" <?= $filters["status"] === $status ? "selected" : "" ?>><?= analytics_h($status) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>Source</span>
      <select name="request_source">
        <option value="">All sources</option>
        <?php foreach ($options["request_sources"] as $source): ?>
          <option value="<?= analytics_h($source) ?>" <?= $filters["request_source"] === $source ? "selected" : "" ?>><?= analytics_h(analytics_label($source)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (!empty($options["staff"])): ?>
      <label>
        <span>Staff/Admin</span>
        <select name="staff_id">
          <option value="">All staff</option>
          <?php foreach ($options["staff"] as $staffOption): ?>
            <?php $staffId = (int)($staffOption["id"] ?? 0); ?>
            <option value="<?= $staffId ?>" <?= (int)($filters["staff_id"] ?? 0) === $staffId ? "selected" : "" ?>><?= analytics_h($staffOption["name"] ?? "Staff") ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <div class="analytics-filter-actions">
      <button class="admin-owner-button" type="submit">Apply</button>
      <a class="admin-owner-button-secondary" href="<?= admin_url('/pages/super_admin/super_admin_analytics.php') ?>">Clear</a>
    </div>
  </form>

  <p class="analytics-loading" hidden>Loading analytics category...</p>

  <section class="analytics-category-grid" id="analytics-categories" aria-label="Analytics categories">
    <?php foreach ($categoryCards as $card): ?>
      <?php $isSelectedCard = $selectedCategory === $card["id"]; ?>
      <article class="analytics-category-card<?= $isSelectedCard ? " is-selected" : "" ?>">
        <div>
          <h2><?= analytics_h($card["title"]) ?></h2>
          <p><?= analytics_h($card["description"]) ?></p>
        </div>
        <dl>
          <?php foreach ($card["metrics"] as $metric): ?>
            <div>
              <dt><?= analytics_h($metric["label"]) ?></dt>
              <dd><?= analytics_h($metric["value"]) ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
        <a class="admin-owner-button-secondary" href="<?= analytics_query_url($filters, ["category" => $card["id"]]) ?>#analytics-detail">
          <?= $isSelectedCard ? "Viewing Details" : "View Details" ?>
        </a>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="analytics-section analytics-detail-section" id="analytics-detail">
    <div class="analytics-section-title">
      <div>
        <?php foreach ($categoryCards as $card): ?>
          <?php if ($card["id"] === $selectedCategory): ?>
            <h2><?= analytics_h($card["title"]) ?></h2>
            <p><?= analytics_h($card["description"]) ?></p>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <a class="admin-owner-button-secondary" href="#analytics-categories">Back to Categories</a>
    </div>

    <?php if ($selectedCategory === "overview"): ?>
      <section class="analytics-kpis">
        <article><span>Total Service Requests</span><strong><?= analytics_number($summary["total_requests"] ?? 0) ?></strong></article>
        <article><span>Completed Requests</span><strong><?= analytics_number($summary["completed_requests"] ?? 0) ?></strong></article>
        <article><span>Cancelled Requests</span><strong><?= analytics_number($summary["cancelled_requests"] ?? 0) ?></strong></article>
        <article><span>Active/Pending Workload</span><strong><?= analytics_number($summary["active_workload"] ?? 0) ?></strong></article>
        <article><span>Completion Rate</span><strong><?= analytics_percent($summary["completion_rate"] ?? 0) ?></strong></article>
        <article><span>Most Requested Service</span><strong><?= analytics_h($mostRequested["service_label"] ?? "-") ?></strong></article>
        <article><span>Average Queue Waiting Time</span><strong><?= analytics_minutes($summary["avg_queue_waiting_minutes"] ?? 0) ?></strong></article>
        <article><span>Average Service Processing Time</span><strong><?= analytics_minutes($summary["avg_service_processing_minutes"] ?? 0) ?></strong></article>
      </section>
      <div class="analytics-two-column">
        <article class="analytics-panel">
          <h3>Current Monthly Cycle</h3>
          <p class="analytics-empty"><?= analytics_h($cycle["start_date"] ?? "-") ?> to <?= analytics_h($cycle["end_date"] ?? "-") ?>. <?= $daysRemaining === 0 ? "Reset day." : analytics_h((string)$daysRemaining) . " days remaining." ?></p>
          <?php if ($warningLevel !== ""): ?><p class="analytics-warning-text">Analytics for the current monthly cycle will reset soon. Please export the analytics report before the cycle ends to keep a copy of this month's results.</p><?php endif; ?>
        </article>
        <article class="analytics-panel">
          <h3>Status Distribution</h3>
          <?php analytics_render_bars($statusRows, "label", "total", "No status records found."); ?>
        </article>
      </div>
    <?php elseif ($selectedCategory === "service-requests"): ?>
      <div class="analytics-two-column">
        <article class="analytics-panel"><h3>Requests by Service Type</h3><?php analytics_render_bars($analytics["requests_by_service"], "service_label", "total", "No service requests found."); ?></article>
        <article class="analytics-panel"><h3>Online vs Walk-in Requests</h3><?php analytics_render_bars($analytics["request_source_mix"] ?? [], "request_source", "total", "No request source data found."); ?></article>
      </div>
      <div class="analytics-three-column">
        <article class="analytics-panel"><h3>Requests by Day</h3><?php analytics_render_bars($analytics["requests_by_period"]["day"], "period_label", "total", "No daily trend data."); ?></article>
        <article class="analytics-panel"><h3>Requests by Week</h3><?php analytics_render_bars($analytics["requests_by_period"]["week"], "period_label", "total", "No weekly trend data."); ?></article>
        <article class="analytics-panel"><h3>Requests by Month</h3><?php analytics_render_bars($analytics["requests_by_period"]["month"], "period_label", "total", "No monthly trend data."); ?></article>
      </div>
      <article class="analytics-panel">
        <h3>Most Requested Services and Completion Percentage</h3>
        <div class="analytics-table-wrap"><table><thead><tr><th>Service Type</th><th>Total</th><th>Completed</th><th>Cancelled</th><th>Completion</th></tr></thead><tbody>
        <?php if (!$analytics["service_completion"]): ?><tr><td colspan="5">No service completion data available.</td></tr><?php else: ?>
          <?php foreach ($analytics["service_completion"] as $service): ?><tr><td><?= analytics_h($service["service_label"] ?? "") ?></td><td><?= analytics_number($service["total"] ?? 0) ?></td><td><?= analytics_number($service["completed"] ?? 0) ?></td><td><?= analytics_number($service["cancelled"] ?? 0) ?></td><td><?= analytics_percent($service["completion_percentage"] ?? 0) ?></td></tr><?php endforeach; ?>
        <?php endif; ?></tbody></table></div>
      </article>
    <?php elseif ($selectedCategory === "queue-waiting"): ?>
      <section class="analytics-kpis">
        <article><span>Average Queue Waiting Time</span><strong><?= analytics_minutes($summary["avg_queue_waiting_minutes"] ?? 0) ?></strong></article>
        <article><span>Average Service Processing Time</span><strong><?= analytics_minutes($summary["avg_service_processing_minutes"] ?? 0) ?></strong></article>
        <article><span>Median Waiting Time</span><strong><?= analytics_minutes($summary["median_queue_waiting_minutes"] ?? 0) ?></strong></article>
        <article><span>Shortest Waiting Request</span><strong><?= analytics_h($analytics["shortest_waiting_request"]["queue_code"] ?? "-") ?></strong><small><?= analytics_minutes($analytics["shortest_waiting_request"]["queue_waiting_minutes"] ?? null) ?></small></article>
      </section>
      <div class="analytics-two-column">
        <article class="analytics-panel"><h3>Queue Status Distribution</h3><?php analytics_render_bars($statusRows, "label", "total", "No status records found."); ?></article>
        <article class="analytics-panel"><h3>Average Time Per Status</h3><?php analytics_render_bars($analytics["status_durations"], "status", "avg_minutes", "No transition durations found."); ?></article>
      </div>
      <article class="analytics-panel"><h3>Longest Waiting Requests</h3><div class="analytics-table-wrap"><table><thead><tr><th>Queue ID</th><th>Customer</th><th>Service</th><th>Status</th><th>Waiting Time</th><th>Created At</th></tr></thead><tbody>
      <?php if (!$analytics["longest_waiting_requests"]): ?><tr><td colspan="6">No waiting-time data available.</td></tr><?php else: ?>
        <?php foreach ($analytics["longest_waiting_requests"] as $row): ?><tr><td><?= analytics_h($row["queue_code"] ?? "") ?></td><td><?= analytics_h($row["customer_name"] ?? "") ?></td><td><?= analytics_h($row["service_label"] ?? "") ?></td><td><?= analytics_h($row["status_group"] ?? "") ?></td><td><?= analytics_minutes($row["queue_waiting_minutes"] ?? null) ?></td><td><?= analytics_datetime($row["request_created_at"] ?? "") ?></td></tr><?php endforeach; ?>
      <?php endif; ?></tbody></table></div></article>
      <div class="analytics-two-column">
        <article class="analytics-panel"><h3>Requests Waiting Longer Than Expected</h3><?php analytics_render_bars($analytics["delayed_requests"] ?? [], "queue_code", "queue_waiting_minutes", "No requests exceeded the current waiting-time threshold."); ?></article>
        <article class="analytics-panel"><h3>No Recent Status Update</h3><div class="analytics-table-wrap"><table><thead><tr><th>Queue ID</th><th>Service</th><th>Status</th><th>Last Status Update</th></tr></thead><tbody>
        <?php if (empty($analytics["stale_requests"])): ?><tr><td colspan="4">No active requests are older than the stale-update threshold.</td></tr><?php else: ?>
          <?php foreach ($analytics["stale_requests"] as $row): ?><tr><td><?= analytics_h($row["queue_code"] ?? "") ?></td><td><?= analytics_h($row["service_label"] ?? "") ?></td><td><?= analytics_h($row["status_group"] ?? "") ?></td><td><?= analytics_datetime($row["last_status_at"] ?? "") ?></td></tr><?php endforeach; ?>
        <?php endif; ?></tbody></table></div></article>
      </div>
    <?php elseif ($selectedCategory === "workflow"): ?>
      <div class="analytics-two-column">
        <article class="analytics-panel"><h3>Average Time Spent in Each Status</h3><?php analytics_render_bars($analytics["status_durations"], "status", "avg_minutes", "No status duration data found."); ?></article>
        <article class="analytics-panel"><h3>Most Common Workflow Route</h3><?php analytics_render_bars($analytics["workflow_routes"] ?? [], "route", "total", "No workflow route data found."); ?></article>
      </div>
      <article class="analytics-panel"><h3>Requests with Incomplete Status Timestamps</h3><div class="analytics-table-wrap"><table><thead><tr><th>Queue ID</th><th>Customer</th><th>Service</th><th>Status</th><th>Created At</th></tr></thead><tbody>
      <?php if (!$analytics["incomplete_timestamps"]): ?><tr><td colspan="5">No incomplete timestamp records found.</td></tr><?php else: ?>
        <?php foreach ($analytics["incomplete_timestamps"] as $row): ?><tr><td><?= analytics_h($row["queue_code"] ?? "") ?></td><td><?= analytics_h($row["customer_name"] ?? "") ?></td><td><?= analytics_h($row["service_label"] ?? "") ?></td><td><?= analytics_h($row["status_group"] ?? "") ?></td><td><?= analytics_datetime($row["request_created_at"] ?? "") ?></td></tr><?php endforeach; ?>
      <?php endif; ?></tbody></table></div></article>
      <article class="analytics-panel"><h3>Status Transition History</h3><div class="analytics-table-wrap"><table><thead><tr><th>Queue ID</th><th>Customer Name</th><th>Service Type</th><th>Status</th><th>Entered At</th><th>Exited At</th><th>Duration Min</th><th>Next Status</th><th>Remarks</th></tr></thead><tbody>
      <?php if (!$analytics["history"]): ?><tr><td colspan="9">No status transition history found for the selected filters.</td></tr><?php else: ?>
        <?php foreach ($analytics["history"] as $event): ?><tr><td><?= analytics_h($event["queue_code"] ?? "") ?></td><td><?= analytics_h($event["customer_name_snapshot"] ?? "") ?></td><td><?= analytics_h($event["service_type"] ?? "") ?></td><td><?= analytics_h($event["status"] ?? "") ?></td><td><?= analytics_datetime($event["entered_at"] ?? "") ?></td><td><?= analytics_datetime($event["exited_at"] ?? "") ?></td><td><?= analytics_h($event["duration_minutes"] ?? "-") ?></td><td><?= analytics_h($event["next_status"] ?? "-") ?></td><td><?= analytics_h($event["remarks"] ?? "") ?></td></tr><?php endforeach; ?>
      <?php endif; ?></tbody></table></div></article>
    <?php elseif ($selectedCategory === "completion"): ?>
      <section class="analytics-kpis">
        <article><span>Completion Rate</span><strong><?= analytics_percent($summary["completion_rate"] ?? 0) ?></strong></article>
        <article><span>Cancellation Rate</span><strong><?= analytics_percent($summary["cancellation_rate"] ?? 0) ?></strong></article>
        <article><span>Fastest Completed Service</span><strong><?= analytics_h($analytics["completion_extremes"][0]["service_label"] ?? "-") ?></strong></article>
        <article><span>Slowest Completed Service</span><strong><?= analytics_h($analytics["completion_extremes"] ? ($analytics["completion_extremes"][count($analytics["completion_extremes"]) - 1]["service_label"] ?? "-") : "-") ?></strong></article>
      </section>
      <div class="analytics-two-column">
        <article class="analytics-panel"><h3>Completed vs Cancelled</h3><?php analytics_render_bars($analytics["completed_vs_cancelled"], "status_group", "total", "No completed or cancelled requests found."); ?></article>
        <article class="analytics-panel"><h3>Cancelled by Reason</h3><?php analytics_render_bars($analytics["cancellation_reasons"] ?? [], "reason", "total", "No cancellation reasons recorded."); ?></article>
      </div>
      <article class="analytics-panel"><h3>Completion and Cancellation by Service Type</h3><div class="analytics-table-wrap"><table><thead><tr><th>Service</th><th>Total</th><th>Completed</th><th>Cancelled</th><th>Avg Completion Time</th><th>Completion</th></tr></thead><tbody>
      <?php if (!$analytics["service_completion"]): ?><tr><td colspan="6">No completion records found.</td></tr><?php else: ?>
        <?php foreach ($analytics["service_completion"] as $service): ?><tr><td><?= analytics_h($service["service_label"] ?? "") ?></td><td><?= analytics_number($service["total"] ?? 0) ?></td><td><?= analytics_number($service["completed"] ?? 0) ?></td><td><?= analytics_number($service["cancelled"] ?? 0) ?></td><td><?= analytics_minutes($service["avg_completion_minutes"] ?? null) ?></td><td><?= analytics_percent($service["completion_percentage"] ?? 0) ?></td></tr><?php endforeach; ?>
      <?php endif; ?></tbody></table></div></article>
    <?php elseif ($selectedCategory === "staff"): ?>
      <?php if (empty($analytics["staff"]["available"])): ?><article class="analytics-panel"><p class="analytics-empty">Staff workload analytics will appear once staff handling data is available.</p></article><?php else: ?>
        <article class="analytics-panel"><h3>Staff Workload Table</h3><div class="analytics-table-wrap"><table><thead><tr><th>Staff</th><th>Requests Handled</th><th>Completed</th><th>Status Updates</th><th>Active Workload</th><th>Avg Handling Time</th></tr></thead><tbody>
        <?php foreach ($analytics["staff"]["rows"] as $staff): ?><tr><td><?= analytics_h($staff["staff_name"] ?? "Staff") ?></td><td><?= analytics_number($staff["requests_handled"] ?? 0) ?></td><td><?= analytics_number($staff["completed_requests"] ?? 0) ?></td><td><?= analytics_number($staff["status_updates"] ?? 0) ?></td><td><?= analytics_number($staff["active_workload"] ?? 0) ?></td><td><?= analytics_minutes($staff["avg_handling_minutes"] ?? null) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></article>
        <article class="analytics-panel"><h3>Completed Requests per Staff</h3><?php analytics_render_bars($analytics["staff"]["rows"], "staff_name", "completed_requests", "No staff completion records found."); ?></article>
      <?php endif; ?>
    <?php elseif ($selectedCategory === "communication"): ?>
      <section class="analytics-kpis">
        <article><span>Total Notifications Sent</span><strong><?= analytics_number($analytics["notifications"]["summary"]["total"] ?? 0) ?></strong></article>
        <article><span>Unread Notifications</span><strong><?= analytics_number($analytics["notifications"]["summary"]["unread"] ?? 0) ?></strong></article>
        <article><span>Average Time Before First Customer Update</span><strong><?= analytics_minutes($analytics["notifications"]["summary"]["avg_first_update_minutes"] ?? 0) ?></strong></article>
        <article><span>Requests Without Customer Notification</span><strong><?= analytics_number($analytics["notifications"]["summary"]["requests_without_customer_notification"] ?? 0) ?></strong></article>
      </section>
      <div class="analytics-two-column">
        <article class="analytics-panel"><h3>Notifications by Type</h3><?php analytics_render_bars($analytics["notifications"]["by_type"] ?? [], "type", "total", "No notification data found."); ?></article>
        <article class="analytics-panel"><h3>Latest Status Updates Sent to Customers</h3><div class="analytics-table-wrap"><table><thead><tr><th>Queue Ref</th><th>Type</th><th>Message</th><th>Sent At</th></tr></thead><tbody>
        <?php if (empty($analytics["notifications"]["latest_status_updates"])): ?><tr><td colspan="4">No recent status notifications found.</td></tr><?php else: ?>
          <?php foreach ($analytics["notifications"]["latest_status_updates"] as $notice): ?><tr><td><?= analytics_h($notice["reference_id"] ?? "-") ?></td><td><?= analytics_h($notice["type"] ?? "") ?></td><td><?= analytics_h($notice["message"] ?? "") ?></td><td><?= analytics_datetime($notice["created_at"] ?? "") ?></td></tr><?php endforeach; ?>
        <?php endif; ?></tbody></table></div></article>
      </div>
    <?php elseif ($selectedCategory === "corrections"): ?>
      <section class="analytics-kpis">
        <article><span>Requests Needing Correction</span><strong><?= analytics_number($analytics["corrections"]["correction_requests"] ?? 0) ?></strong></article>
        <article><span>Missing File/Details Count</span><strong><?= analytics_number(count($analytics["corrections"]["missing_details"] ?? [])) ?></strong></article>
        <article><span>Admin-Edited Activity Logs</span><strong><?= analytics_number(count($analytics["corrections"]["activity"] ?? [])) ?></strong></article>
        <article><span>Future Fields</span><strong>Recommended</strong><small>correction_count, correction_reason, corrected_by, corrected_at</small></article>
      </section>
      <div class="analytics-two-column">
        <article class="analytics-panel"><h3>Correction Count per Service Type</h3><?php analytics_render_bars($analytics["corrections"]["by_service"] ?? [], "service_label", "total", "No correction requests found."); ?></article>
        <article class="analytics-panel"><h3>Requests with Missing or Incomplete Details</h3><div class="analytics-table-wrap"><table><thead><tr><th>Queue ID</th><th>Customer</th><th>Service</th><th>Status</th></tr></thead><tbody>
        <?php if (empty($analytics["corrections"]["missing_details"])): ?><tr><td colspan="4">No missing detail records found.</td></tr><?php else: ?>
          <?php foreach ($analytics["corrections"]["missing_details"] as $row): ?><tr><td><?= analytics_h($row["queue_code"] ?? "") ?></td><td><?= analytics_h($row["customer_name"] ?? "") ?></td><td><?= analytics_h($row["service_label"] ?? "") ?></td><td><?= analytics_h($row["status_group"] ?? "") ?></td></tr><?php endforeach; ?>
        <?php endif; ?></tbody></table></div></article>
      </div>
      <article class="analytics-panel"><h3>Recent Correction or Edit Activity</h3><div class="analytics-table-wrap"><table><thead><tr><th>Action</th><th>Module</th><th>Record</th><th>Description</th><th>Date</th></tr></thead><tbody>
      <?php if (empty($analytics["corrections"]["activity"])): ?><tr><td colspan="5">No correction activity logs found.</td></tr><?php else: ?>
        <?php foreach ($analytics["corrections"]["activity"] as $row): ?><tr><td><?= analytics_h($row["action_type"] ?? "") ?></td><td><?= analytics_h($row["target_module"] ?? "") ?></td><td><?= analytics_h($row["target_record_id"] ?? "") ?></td><td><?= analytics_h($row["description"] ?? "") ?></td><td><?= analytics_datetime($row["created_at"] ?? "") ?></td></tr><?php endforeach; ?>
      <?php endif; ?></tbody></table></div></article>
    <?php elseif ($selectedCategory === "store"): ?>
      <?php if (empty($analytics["store"]["available"])): ?><article class="analytics-panel"><p class="analytics-empty">Store availability analytics will appear once store availability tables are installed.</p></article><?php else: ?>
        <section class="analytics-kpis">
          <article><span>Store Status</span><strong><?= analytics_h(analytics_label((string)($analytics["store"]["settings"]["store_status"] ?? "-"))) ?></strong></article>
          <article><span>Queue Cutoff</span><strong><?= analytics_h($analytics["store"]["settings"]["queue_cutoff_time"] ?? "-") ?></strong></article>
          <article><span>Most Active Service Day</span><strong><?= analytics_h(trim((string)($analytics["store"]["most_active_service_day"]["day_name"] ?? "-"))) ?></strong><small><?= analytics_number($analytics["store"]["most_active_service_day"]["total"] ?? 0) ?> requests</small></article>
          <article><span>Closed Dates</span><strong><?= analytics_number(count($analytics["store"]["holidays"] ?? [])) ?></strong></article>
        </section>
        <div class="analytics-two-column">
          <article class="analytics-panel"><h3>Store Open/Closed Schedule</h3><div class="analytics-table-wrap"><table><thead><tr><th>Day</th><th>Open</th><th>Opens</th><th>Closes</th></tr></thead><tbody><?php foreach ($analytics["store"]["hours"] as $hour): ?><tr><td><?= analytics_h((string)($hour["day_of_week"] ?? "")) ?></td><td><?= !empty($hour["is_open"]) ? "Yes" : "No" ?></td><td><?= analytics_h($hour["opens_at"] ?? "-") ?></td><td><?= analytics_h($hour["closes_at"] ?? "-") ?></td></tr><?php endforeach; ?></tbody></table></div></article>
          <article class="analytics-panel"><h3>Recent Availability Changes</h3><div class="analytics-table-wrap"><table><thead><tr><th>Admin</th><th>Action</th><th>Description</th><th>Date</th></tr></thead><tbody><?php if (!$analytics["store"]["changes"]): ?><tr><td colspan="4">No store availability changes found.</td></tr><?php else: ?><?php foreach ($analytics["store"]["changes"] as $change): ?><tr><td><?= analytics_h($change["user_name"] ?? "") ?></td><td><?= analytics_h($change["action_type"] ?? "") ?></td><td><?= analytics_h($change["description"] ?? "") ?></td><td><?= analytics_datetime($change["created_at"] ?? "") ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></article>
        </div>
      <?php endif; ?>
    <?php elseif ($selectedCategory === "cycle"): ?>
      <section class="analytics-kpis">
        <article><span>Current Cycle</span><strong><?= analytics_h($cycle["cycle_key"] ?? "-") ?></strong><small><?= analytics_h($cycle["start_date"] ?? "-") ?> to <?= analytics_h($cycle["end_date"] ?? "-") ?></small></article>
        <article><span>Days Before Reset</span><strong><?= $daysRemaining === 0 ? "Reset day" : analytics_number($daysRemaining) ?></strong></article>
        <article><span>Export Status</span><strong><?= !empty($exportStatus["exported"]) ? "Exported" : "Not Exported" ?></strong><small><?= !empty($exportStatus["exported_at"]) ? analytics_datetime($exportStatus["exported_at"]) : "No export logged" ?></small></article>
        <article><span>Previous Cycles</span><strong><?= analytics_number(count($analytics["cycle_center"]["previous_cycles"] ?? [])) ?></strong></article>
      </section>
      <section class="analytics-export-row" aria-label="Export analytics">
        <a class="admin-owner-button-secondary" href="<?= analytics_query_url($filters, ["category" => "cycle", "export" => "csv"]) ?>">Export CSV</a>
        <button class="admin-owner-button-secondary" type="button" disabled title="Excel export is not configured for this analytics page yet.">Export Excel</button>
        <button class="admin-owner-button-secondary" type="button" disabled title="PDF export is not configured for this analytics page yet.">Export PDF</button>
      </section>
      <div class="analytics-two-column">
        <article class="analytics-panel"><h3>Previous Analytics Cycles</h3><div class="analytics-table-wrap"><table><thead><tr><th>Cycle</th><th>Date Range</th><th>Status</th><th>Snapshot</th></tr></thead><tbody><?php foreach ($analytics["cycle_center"]["previous_cycles"] ?? [] as $row): ?><tr><td><?= analytics_h($row["cycle_key"] ?? "") ?></td><td><?= analytics_h(($row["start_date"] ?? "") . " to " . ($row["end_date"] ?? "")) ?></td><td><?= analytics_h($row["status"] ?? "") ?></td><td><?= analytics_datetime($row["snapshot_created_at"] ?? "") ?></td></tr><?php endforeach; ?></tbody></table></div></article>
        <article class="analytics-panel"><h3>Export Logs</h3><div class="analytics-table-wrap"><table><thead><tr><th>Type</th><th>Exported By</th><th>Exported At</th><th>Rows</th></tr></thead><tbody><?php if (empty($analytics["cycle_center"]["export_logs"])): ?><tr><td colspan="4">No exports logged for this cycle.</td></tr><?php else: ?><?php foreach ($analytics["cycle_center"]["export_logs"] as $log): ?><tr><td><?= analytics_h(strtoupper((string)($log["export_type"] ?? ""))) ?></td><td><?= analytics_h($log["exported_by"] ?? "System") ?></td><td><?= analytics_datetime($log["exported_at"] ?? "") ?></td><td><?= analytics_number($log["row_count"] ?? 0) ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></article>
      </div>
    <?php endif; ?>
  </section>
</main>

<?php require_once __DIR__ . "/../admin/_includes/admin_footer.php"; ?>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>
