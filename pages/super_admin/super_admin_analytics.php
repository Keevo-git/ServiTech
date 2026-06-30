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
      <p>Comprehensive overview of ServiTech service requests, queue performance, and completion analytics.</p>
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
      <span>Payment</span>
      <select name="payment_method">
        <option value="">All payments</option>
        <?php foreach ($options["payment_methods"] as $method): ?>
          <option value="<?= analytics_h($method) ?>" <?= $filters["payment_method"] === $method ? "selected" : "" ?>><?= analytics_h(analytics_label($method)) ?></option>
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
    <div class="analytics-filter-actions">
      <button class="admin-owner-button" type="submit">Apply</button>
      <a class="admin-owner-button-secondary" href="<?= admin_url('/pages/super_admin/super_admin_analytics.php') ?>">Clear</a>
    </div>
  </form>

  <section class="analytics-export-row" aria-label="Export analytics">
    <a class="admin-owner-button-secondary" href="<?= analytics_query_url($filters, ["export" => "csv"]) ?>">Export CSV</a>
    <button class="admin-owner-button-secondary" type="button" disabled title="Excel export is not configured for this analytics page yet.">Export Excel</button>
    <button class="admin-owner-button-secondary" type="button" disabled title="PDF export is not configured for this analytics page yet.">Export PDF</button>
  </section>

  <section class="analytics-kpis">
    <article>
      <span>Total Service Requests</span>
      <strong><?= analytics_number($summary["total_requests"] ?? 0) ?></strong>
    </article>
    <article>
      <span>Average Queue Waiting Time</span>
      <strong><?= analytics_minutes($summary["avg_queue_waiting_minutes"] ?? 0) ?></strong>
    </article>
    <article>
      <span>Average Service Processing Time</span>
      <strong><?= analytics_minutes($summary["avg_service_processing_minutes"] ?? 0) ?></strong>
    </article>
    <article>
      <span>Longest Waiting Request</span>
      <strong><?= analytics_h($longest["queue_code"] ?? "-") ?></strong>
      <small><?= analytics_minutes($longest["queue_waiting_minutes"] ?? null) ?></small>
    </article>
    <article>
      <span>Completion Rate</span>
      <strong><?= analytics_h(number_format((float)($summary["completion_rate"] ?? 0), 2)) ?>%</strong>
    </article>
    <article>
      <span>Completed Requests</span>
      <strong><?= analytics_number($summary["completed_requests"] ?? 0) ?></strong>
    </article>
    <article>
      <span>Cancelled Requests</span>
      <strong><?= analytics_number($summary["cancelled_requests"] ?? 0) ?></strong>
    </article>
    <article>
      <span>Most Requested Service</span>
      <strong><?= analytics_h($mostRequested["service_label"] ?? "-") ?></strong>
      <small><?= analytics_number($mostRequested["total"] ?? 0) ?> requests</small>
    </article>
  </section>

  <section class="analytics-section">
    <div class="analytics-section-title">
      <div>
        <h2>Queue and Waiting Time Analytics</h2>
        <p>Waiting time is computed from request creation to approval for GCash, and from request creation to ongoing for Cash.</p>
      </div>
    </div>

    <div class="analytics-two-column">
      <article class="analytics-panel">
        <h3>Queue Status Distribution</h3>
        <?php
          $statusRows = [];
          foreach ($analytics["status_distribution"] as $status => $total) {
              $statusRows[] = ["label" => $status, "total" => $total];
          }
          analytics_render_bars($statusRows, "label", "total", "No status records found.");
        ?>
      </article>

      <article class="analytics-panel">
        <h3>Average Time Spent in Each Status</h3>
        <?php analytics_render_bars($analytics["status_durations"], "status", "avg_minutes", "No transition durations found."); ?>
      </article>
    </div>

    <article class="analytics-panel">
      <h3>Longest Waiting Request</h3>
      <div class="analytics-table-wrap">
        <table>
          <thead>
            <tr>
              <th>Queue ID</th>
              <th>Customer</th>
              <th>Service Type</th>
              <th>Payment</th>
              <th>Waiting Time</th>
              <th>Status</th>
              <th>Created At</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$longest): ?>
              <tr><td colspan="7">No waiting-time data available.</td></tr>
            <?php else: ?>
              <tr>
                <td><?= analytics_h($longest["queue_code"] ?? "") ?></td>
                <td><?= analytics_h($longest["customer_name"] ?? "") ?></td>
                <td><?= analytics_h($longest["service_label"] ?? "") ?></td>
                <td><?= analytics_h(analytics_label((string)($longest["payment_method"] ?? ""))) ?></td>
                <td><?= analytics_minutes($longest["queue_waiting_minutes"] ?? null) ?></td>
                <td><?= analytics_h($longest["status_group"] ?? "") ?></td>
                <td><?= analytics_datetime($longest["request_created_at"] ?? "") ?></td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <section class="analytics-section">
    <div class="analytics-section-title">
      <div>
        <h2>Service Request Analytics</h2>
        <p>Service totals, completion mix, and request trends based on queue records.</p>
      </div>
    </div>

    <div class="analytics-two-column">
      <article class="analytics-panel">
        <h3>Requests by Service Type</h3>
        <?php analytics_render_bars($analytics["requests_by_service"], "service_label", "total", "No service requests found."); ?>
      </article>
      <article class="analytics-panel">
        <h3>Completed vs Cancelled</h3>
        <?php analytics_render_bars($analytics["completed_vs_cancelled"], "status_group", "total", "No completed or cancelled requests found."); ?>
      </article>
    </div>

    <div class="analytics-three-column">
      <article class="analytics-panel">
        <h3>Requests by Day</h3>
        <?php analytics_render_bars($analytics["requests_by_period"]["day"], "period_label", "total", "No daily trend data."); ?>
      </article>
      <article class="analytics-panel">
        <h3>Requests by Week</h3>
        <?php analytics_render_bars($analytics["requests_by_period"]["week"], "period_label", "total", "No weekly trend data."); ?>
      </article>
      <article class="analytics-panel">
        <h3>Requests by Month</h3>
        <?php analytics_render_bars($analytics["requests_by_period"]["month"], "period_label", "total", "No monthly trend data."); ?>
      </article>
    </div>

    <article class="analytics-panel">
      <h3>Most Requested Services</h3>
      <div class="analytics-table-wrap">
        <table>
          <thead>
            <tr>
              <th>Service Type</th>
              <th>Total Requests</th>
              <th>Completed</th>
              <th>Cancelled</th>
              <th>Completion Percentage</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$analytics["service_completion"]): ?>
              <tr><td colspan="5">No service completion data available.</td></tr>
            <?php else: ?>
              <?php foreach ($analytics["service_completion"] as $service): ?>
                <tr>
                  <td><?= analytics_h($service["service_label"] ?? "") ?></td>
                  <td><?= analytics_number($service["total"] ?? 0) ?></td>
                  <td><?= analytics_number($service["completed"] ?? 0) ?></td>
                  <td><?= analytics_number($service["cancelled"] ?? 0) ?></td>
                  <td><?= analytics_h(number_format((float)($service["completion_percentage"] ?? 0), 2)) ?>%</td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <section class="analytics-section">
    <div class="analytics-section-title">
      <div>
        <h2>Status Transition History</h2>
        <p>Timeline records imported from Table9_Status_Events, limited to the latest 500 matching events.</p>
      </div>
    </div>

    <article class="analytics-panel">
      <div class="analytics-table-wrap">
        <table>
          <thead>
            <tr>
              <th>Queue ID</th>
              <th>Customer Name</th>
              <th>Service Type</th>
              <th>Status</th>
              <th>Entered At</th>
              <th>Exited At</th>
              <th>Duration Min</th>
              <th>Next Status</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$analytics["history"]): ?>
              <tr><td colspan="9">No status transition history found for the selected filters.</td></tr>
            <?php else: ?>
              <?php foreach ($analytics["history"] as $event): ?>
                <tr>
                  <td><?= analytics_h($event["queue_code"] ?? "") ?></td>
                  <td><?= analytics_h($event["customer_name_snapshot"] ?? "") ?></td>
                  <td><?= analytics_h($event["service_type"] ?? "") ?></td>
                  <td><?= analytics_h($event["status"] ?? "") ?></td>
                  <td><?= analytics_datetime($event["entered_at"] ?? "") ?></td>
                  <td><?= analytics_datetime($event["exited_at"] ?? "") ?></td>
                  <td><?= analytics_h($event["duration_minutes"] ?? "-") ?></td>
                  <td><?= analytics_h($event["next_status"] ?? "-") ?></td>
                  <td><?= analytics_h($event["remarks"] ?? "") ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </article>
  </section>
</main>

<?php require_once __DIR__ . "/../admin/_includes/admin_footer.php"; ?>
<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>
