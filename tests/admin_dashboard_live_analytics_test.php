<?php

require_once __DIR__ . "/../pages/admin/_includes/dashboard_stats.php";

function dashboard_analytics_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function dashboard_fixture_metrics(array $rows, string $today = "2026-06-24"): array
{
    $managedCategories = [
        "printing", "online_printorder", "printing_online", "walkin", "printing_walkin",
        "xerox", "photocopy", "rush-id", "laminating", "scanning", "repair", "installation",
    ];
    $activeStatuses = ["PENDING", "APPROVED", "ONGOING", "FOR PICK-UP"];
    $normalizeStatus = static function (string $status): string {
        $status = strtoupper(preg_replace('/[\s_]+/', ' ', trim($status)));
        return match ($status) {
            "PENDING PAYMENT" => "PENDING",
            "FOR PICK UP", "FOR PICKUP" => "FOR PICK-UP",
            "COMPLETED" => "DONE",
            "CANCEL", "CANCELED" => "CANCELLED",
            default => $status,
        };
    };
    $isManaged = static fn(array $row): bool => in_array(strtolower($row["category"]), $managedCategories, true)
        || str_starts_with(strtoupper($row["code"]), "OP");
    $isVisible = static fn(array $row): bool => $row["deleted"] === null && $row["hidden"] === null;

    $visible = array_values(array_filter(
        $rows,
        static fn(array $row): bool => $isManaged($row) && $isVisible($row)
    ));
    $statusCounts = ["PENDING" => 0, "APPROVED" => 0, "ONGOING" => 0, "FOR PICK-UP" => 0];
    foreach ($visible as $row) {
        $status = $normalizeStatus($row["status"]);
        if (isset($statusCounts[$status])) {
            $statusCounts[$status]++;
        }
    }

    return [
        "activeRequests" => count(array_filter($visible, static fn(array $row): bool => in_array($normalizeStatus($row["status"]), $activeStatuses, true))),
        "activeQueue" => count(array_filter($visible, static fn(array $row): bool => $row["stage"] === "QUEUE" && in_array($normalizeStatus($row["status"]), $activeStatuses, true))),
        "visibleOrders" => count(array_filter($visible, static fn(array $row): bool => $row["stage"] === "ORDER")),
        "status" => $statusCounts,
        "newToday" => count(array_filter($visible, static fn(array $row): bool => $row["created"] === $today)),
        "completedToday" => count(array_filter($visible, static fn(array $row): bool => $normalizeStatus($row["status"]) === "DONE" && $row["completed"] === $today)),
        "cancelledToday" => count(array_filter($visible, static fn(array $row): bool => $normalizeStatus($row["status"]) === "CANCELLED" && $row["closed"] === $today)),
        "visibleManaged" => count($visible),
    ];
}

$rows = [
    ["id" => 1, "category" => "printing", "code" => "P001", "stage" => "QUEUE", "status" => "PENDING", "created" => "2026-06-24", "completed" => null, "closed" => null, "deleted" => null, "hidden" => null],
    ["id" => 2, "category" => "repair", "code" => "R001", "stage" => "QUEUE", "status" => "ONGOING", "created" => "2026-06-24", "completed" => null, "closed" => null, "deleted" => null, "hidden" => null],
    ["id" => 3, "category" => "installation", "code" => "I001", "stage" => "ORDER", "status" => "FOR PICK UP", "created" => "2026-06-23", "completed" => null, "closed" => null, "deleted" => null, "hidden" => null],
    ["id" => 4, "category" => "printing", "code" => "P002", "stage" => "ORDER", "status" => "DONE", "created" => "2026-06-23", "completed" => "2026-06-24", "closed" => "2026-06-24", "deleted" => null, "hidden" => null],
    ["id" => 5, "category" => "printing_online", "code" => "OP001", "stage" => "ORDER", "status" => "CANCELED", "created" => "2026-06-23", "completed" => null, "closed" => "2026-06-24", "deleted" => null, "hidden" => null],
    ["id" => 6, "category" => "printing", "code" => "P003", "stage" => "ORDER", "status" => "PENDING", "created" => "2026-06-22", "completed" => null, "closed" => null, "deleted" => "2026-06-24", "hidden" => null],
    ["id" => 7, "category" => "xerox", "code" => "P004", "stage" => "ORDER", "status" => "COMPLETED", "created" => "2026-06-22", "completed" => "2026-06-22", "closed" => "2026-06-22", "deleted" => null, "hidden" => null],
    ["id" => 8, "category" => "general", "code" => "G001", "stage" => "QUEUE", "status" => "PENDING", "created" => "2026-06-24", "completed" => null, "closed" => null, "deleted" => null, "hidden" => null],
];

$initial = dashboard_fixture_metrics($rows);
dashboard_analytics_assert($initial["activeRequests"] === 3, "Active Requests must include all non-final visible records across both management stages.");
dashboard_analytics_assert($initial["activeQueue"] === 2, "Active Queue must match visible active Queue Management records.");
dashboard_analytics_assert($initial["visibleOrders"] === 4, "Visible Orders must match the non-binned Order Management union.");
dashboard_analytics_assert($initial["newToday"] === 2 && $initial["completedToday"] === 1 && $initial["cancelledToday"] === 1, "Today metrics must use their correct event fields and exclude unmanaged/deleted records.");

// A. Create a request: active, queue, Pending, and New Today all increase.
$rows[] = ["id" => 9, "category" => "printing", "code" => "P005", "stage" => "QUEUE", "status" => "PENDING PAYMENT", "created" => "2026-06-24", "completed" => null, "closed" => null, "deleted" => null, "hidden" => null];
$created = dashboard_fixture_metrics($rows);
dashboard_analytics_assert($created["activeRequests"] === 4 && $created["activeQueue"] === 3 && $created["newToday"] === 3, "A newly created visible request must update all applicable metrics.");
dashboard_analytics_assert($created["status"]["PENDING"] === 2, "Pending Payment must normalize to Pending.");

// B. Status update: move between status buckets without changing active totals.
foreach ($rows as &$row) {
    if ($row["id"] === 9) $row["status"] = "APPROVED";
}
unset($row);
$approved = dashboard_fixture_metrics($rows);
dashboard_analytics_assert($approved["activeRequests"] === 4 && $approved["status"]["PENDING"] === 1 && $approved["status"]["APPROVED"] === 1, "A status update must move exactly one request between buckets.");

// C. Completion: leave active/queue metrics, enter Order Management and Completed Today.
foreach ($rows as &$row) {
    if ($row["id"] === 9) {
        $row["stage"] = "ORDER";
        $row["status"] = "DONE";
        $row["completed"] = "2026-06-24";
        $row["closed"] = "2026-06-24";
    }
}
unset($row);
$completed = dashboard_fixture_metrics($rows);
dashboard_analytics_assert($completed["activeRequests"] === 3 && $completed["activeQueue"] === 2, "Completing a request must remove it from active metrics.");
dashboard_analytics_assert($completed["visibleOrders"] === 5 && $completed["completedToday"] === 2, "Completion must add the record to visible orders and Completed Today.");

// D. Cancellation follows the same final-state movement but uses closed_at.
foreach ($rows as &$row) {
    if ($row["id"] === 1) {
        $row["stage"] = "ORDER";
        $row["status"] = "CANCELLED";
        $row["closed"] = "2026-06-24";
    }
}
unset($row);
$cancelled = dashboard_fixture_metrics($rows);
dashboard_analytics_assert($cancelled["activeRequests"] === 2 && $cancelled["activeQueue"] === 1, "Cancelling a request must remove it from active metrics.");
dashboard_analytics_assert($cancelled["visibleOrders"] === 6 && $cancelled["cancelledToday"] === 2, "Cancellation must appear in Order Management and Cancelled Today while visible.");

// E. Moving to Bin removes the record from every visible analytic, including today/history charts.
foreach ($rows as &$row) {
    if ($row["id"] === 9) $row["deleted"] = "2026-06-24";
}
unset($row);
$binned = dashboard_fixture_metrics($rows);
dashboard_analytics_assert($binned["visibleOrders"] === 5, "A binned order must no longer inflate the visible Order Management metric.");
dashboard_analytics_assert($binned["newToday"] === 2 && $binned["completedToday"] === 1, "A binned record must no longer appear in visible Today analytics.");

// F. Cross-card invariants make the dashboard manually cross-checkable.
dashboard_analytics_assert($binned["activeRequests"] === array_sum($binned["status"]), "Active Requests must equal the four active status buckets.");
dashboard_analytics_assert($binned["activeQueue"] === 1 && $binned["visibleOrders"] === 5, "Queue and Order cards must align with their corresponding active views.");

$statsSource = file_get_contents(__DIR__ . "/../pages/admin/_includes/dashboard_stats.php") ?: "";
$dashboardPage = file_get_contents(__DIR__ . "/../pages/admin/admin_dashboard.php") ?: "";
$dashboardScript = file_get_contents(__DIR__ . "/../pages/admin/admin_dashboard.js") ?: "";
$queuePages = [
    file_get_contents(__DIR__ . "/../pages/admin/queue_list/printing.php") ?: "",
    file_get_contents(__DIR__ . "/../pages/admin/queue_list/repair.php") ?: "",
    file_get_contents(__DIR__ . "/../pages/admin/queue_list/installation.php") ?: "",
];

dashboard_analytics_assert(admin_dashboard_visibility_predicate("q") === "q.deleted_at IS NULL AND q.permanently_hidden_at IS NULL", "The canonical visibility rule must exclude both Bin states.");
dashboard_analytics_assert(str_contains(admin_dashboard_printing_scope_predicate("q"), "'scanning'"), "All real print-side services must remain in the managed scope.");
dashboard_analytics_assert(str_contains(admin_dashboard_service_expression("q"), "service_name_snapshot"), "Actual service snapshots must be the primary service-label source.");
dashboard_analytics_assert(substr_count($statsSource, "FROM visible_managed v") === 3, "Summary, category, and top-service queries must share one canonical record scope.");
dashboard_analytics_assert(!str_contains($dashboardPage, "All-time requests"), "The rebuilt UI must not claim an all-time scope.");
dashboard_analytics_assert(str_contains($dashboardPage, "Active Requests by Status") && str_contains($dashboardPage, "Visible activity by Manila calendar day"), "Analytics labels must state their real scopes.");
dashboard_analytics_assert(str_contains($dashboardScript, 'cache: "no-store"') && str_contains($dashboardScript, "dashboardRefreshStorageKey"), "Dashboard refreshes must be uncached and mutation-aware.");
foreach ($queuePages as $queuePage) {
    dashboard_analytics_assert(str_contains($queuePage, "q.deleted_at IS NULL AND q.permanently_hidden_at IS NULL"), "Every Queue Management tab must use the same visibility rule.");
}

$unavailable = admin_dashboard_empty_stats("schema unavailable");
dashboard_analytics_assert($unavailable["available"] === false && $unavailable["error"] === "schema unavailable", "Schema failures must be disclosed instead of returning believable-looking fallback totals.");

echo "Admin dashboard analytics rebuild tests passed.\n";
